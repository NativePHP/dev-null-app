<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\AuthorizationStatus;
use App\Devnull\DeviceKey;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\GitHubException;
use App\Devnull\Exceptions\GitHubUnreachable;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\GitHub;
use App\Devnull\GitHubStep;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Device;
use Native\Mobile\Facades\Share;
use SensitiveParameter;

/**
 * This phone has its own SSH key. Add it to GitHub once and devnull knows who you are.
 *
 * With a GitHub App configured, the app adds the key itself through GitHub's
 * device flow. Adding it by hand stays available either way.
 */
class Onboarding extends NativeComponent
{
    public const string GitHubNewKeyUrl = 'https://github.com/settings/ssh/new';

    /**
     * devnull re-reads someone's GitHub keys at most every 10 seconds, so a
     * retry waits a little longer than that.
     */
    public const int KeySyncRetrySeconds = 11;

    public const int KeySyncRetries = 3;

    /**
     * What key-syncs answers while the key isn't on the person's GitHub yet.
     */
    public const int KeyNotListedYet = 403;

    public string $publicKey = '';

    public string $fingerprint = '';

    public string $username = '';

    public ?string $error = null;

    public ?string $notice = null;

    public GitHubStep $step = GitHubStep::Start;

    public string $userCode = '';

    public int $codeMinutes = 15;

    public string $login = '';

    public ?string $githubError = null;

    public ?string $githubNotice = null;

    private string $deviceCode = '';

    private string $devicePage = '';

    private int $interval = 5;

    private int $expiresAt = 0;

    private int $nextAttemptAt = 0;

    private int $keySyncAttempts = 0;

    public function mount(DeviceKey $deviceKey): void
    {
        $this->notice = $this->data('message');

        try {
            $key = $deviceKey->current();
        } catch (KeychainUnavailable $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->publicKey = $key->openSsh();
        $this->fingerprint = $key->fingerprint();

        if (config('app.debug')) {
            Log::info('devnull device key', ['public_key' => $this->publicKey, 'fingerprint' => $this->fingerprint]);
        }
    }

    public function shareKey(): void
    {
        Share::file('', $this->publicKey, '');
    }

    public function openGitHub(): void
    {
        Browser::inApp(self::GitHubNewKeyUrl);
    }

    public function connect(): void
    {
        $username = ltrim(trim($this->username), '@');

        if ($username === '' || $this->publicKey === '') {
            return;
        }

        try {
            $user = app(Client::class)->syncKey($username);
        } catch (ApiException $exception) {
            $this->error = $exception->firstError();

            return;
        } catch (KeychainUnavailable $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        app(Account::class)->remember($user);
        $this->replace('/log');
    }

    /**
     * Starts GitHub's device flow: asks for a code to show here and type on github.com.
     */
    public function connectGitHub(): void
    {
        $github = app(GitHub::class);

        if (! $github->enabled() || $this->publicKey === '') {
            return;
        }

        try {
            $code = $github->requestDeviceCode();
        } catch (GitHubException $exception) {
            $this->startOver(error: $exception->getMessage());

            return;
        }

        $this->startOver();
        $this->step = GitHubStep::Authorizing;
        $this->deviceCode = $code->deviceCode;
        $this->userCode = $code->userCode;
        $this->devicePage = $code->pageToOpen();
        $this->codeMinutes = max(1, intdiv($code->expiresIn, 60));
        $this->interval = $code->interval;
        $this->expiresAt = $this->now() + $code->expiresIn * 1000;
        $this->waitSeconds($this->interval);
    }

    public function openDevicePage(): void
    {
        if ($this->devicePage !== '') {
            Browser::inApp($this->devicePage);
        }
    }

    public function shareCode(): void
    {
        if ($this->userCode !== '') {
            Share::file('', $this->userCode, '');
        }
    }

    public function cancelGitHub(): void
    {
        $this->startOver();
    }

    /**
     * GitHub sets the pace of the device flow and devnull the pace of key
     * syncs, so both wait between requests. Ticking every second to see if
     * either is due keeps the screen responsive, where sleeping would freeze it.
     */
    #[Poll(1000)]
    public function tick(): void
    {
        if ($this->now() < $this->nextAttemptAt) {
            return;
        }

        if ($this->step === GitHubStep::Authorizing) {
            $this->checkAuthorization();
        } elseif ($this->step === GitHubStep::Listing) {
            $this->syncKeyWithDevnull();
        }
    }

    public function render(): View
    {
        return view('native.onboarding', ['canUseGitHub' => app(GitHub::class)->enabled()]);
    }

    private function checkAuthorization(): void
    {
        if ($this->now() >= $this->expiresAt) {
            $this->expire();

            return;
        }

        try {
            $authorization = app(GitHub::class)->requestToken($this->deviceCode);
        } catch (GitHubUnreachable $exception) {
            $this->githubError = $exception->getMessage();
            $this->waitSeconds($this->interval);

            return;
        } catch (GitHubException $exception) {
            $this->startOver(error: $exception->getMessage());

            return;
        }

        $this->githubError = null;

        switch ($authorization->status) {
            case AuthorizationStatus::Granted:
                $this->addKeyToGitHub((string) $authorization->token);
                break;
            case AuthorizationStatus::Pending:
                $this->waitSeconds($this->interval);
                break;
            case AuthorizationStatus::SlowDown:
                $this->interval = max($this->interval + 5, $authorization->interval ?? 0);
                $this->waitSeconds($this->interval);
                break;
            case AuthorizationStatus::Expired:
                $this->expire();
                break;
            case AuthorizationStatus::Denied:
                $this->startOver(notice: 'You cancelled on GitHub, so nothing was added. Connect again whenever you like.');
                break;
        }
    }

    /**
     * The token is used for the two calls that add the key and is gone when
     * this returns. It's never put on the screen, in the Keychain or on disk.
     */
    private function addKeyToGitHub(#[SensitiveParameter] string $token): void
    {
        try {
            $login = app(GitHub::class)->addSigningKey($token, $this->keyTitle(), $this->publicKey);
        } catch (GitHubException $exception) {
            $this->startOver(error: $exception->getMessage());

            return;
        }

        $this->startOver();
        $this->step = GitHubStep::Listing;
        $this->login = $login;
        $this->syncKeyWithDevnull();
    }

    /**
     * Asks devnull to find the key on GitHub. A new key can take a moment to
     * show up in GitHub's public list, so a 403 (the key isn't on their
     * GitHub yet) is tried again a few times. A 404 means they've never
     * signed in to devnull, which waiting can't fix, so it stops at once.
     */
    private function syncKeyWithDevnull(): void
    {
        $this->keySyncAttempts++;

        try {
            $user = app(Client::class)->syncKey($this->login);
        } catch (ApiException $exception) {
            if ($exception->status === self::KeyNotListedYet && $this->keySyncAttempts <= self::KeySyncRetries) {
                $this->waitSeconds(self::KeySyncRetrySeconds);

                return;
            }

            $this->username = $this->login;
            $this->startOver(error: $exception->firstError());

            return;
        } catch (KeychainUnavailable $exception) {
            $this->startOver(error: $exception->getMessage());

            return;
        }

        app(Account::class)->remember($user);
        $this->replace('/log');
    }

    private function expire(): void
    {
        $this->startOver();
        $this->step = GitHubStep::Expired;
    }

    private function startOver(?string $error = null, ?string $notice = null): void
    {
        $this->step = GitHubStep::Start;
        $this->deviceCode = '';
        $this->devicePage = '';
        $this->userCode = '';
        $this->nextAttemptAt = 0;
        $this->keySyncAttempts = 0;
        $this->githubError = $error;
        $this->githubNotice = $notice;
    }

    /**
     * Names the key on GitHub after the phone, so it's clear what to remove later.
     */
    private function keyTitle(): string
    {
        $info = json_decode((string) Device::getInfo(), true);
        $model = is_array($info) && is_string($info['model'] ?? null) ? trim($info['model']) : '';

        return 'devnull on '.($model === '' ? 'a phone' : $model);
    }

    private function waitSeconds(int $seconds): void
    {
        $this->nextAttemptAt = $this->now() + $seconds * 1000;
    }

    private function now(): int
    {
        return now()->getTimestampMs();
    }
}
