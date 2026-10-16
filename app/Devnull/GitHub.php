<?php

namespace App\Devnull;

use App\Devnull\Data\AuthorizationStatus;
use App\Devnull\Data\DeviceAuthorization;
use App\Devnull\Data\DeviceCode;
use App\Devnull\Exceptions\GitHubException;
use App\Devnull\Exceptions\GitHubUnreachable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Adds this phone's signing key to GitHub through a GitHub App's device flow.
 *
 * A GitHub App's token can only do what the app's settings allow, and this
 * app only needs the "SSH signing keys" account permission. The token is
 * passed in for the two calls that add the key and is never kept: not here,
 * not in the Keychain, not on disk.
 *
 * @see https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-user-access-token-for-a-github-app#using-the-device-flow-to-generate-a-user-access-token
 * @see https://docs.github.com/en/rest/users/ssh-signing-keys
 */
final readonly class GitHub
{
    public const string ApiVersion = '2022-11-28';

    private const string Web = 'https://github.com';

    private const string Api = 'https://api.github.com';

    private const string DeviceGrant = 'urn:ietf:params:oauth:grant-type:device_code';

    public function __construct(private string $clientId) {}

    /**
     * Without a client ID there's no GitHub App to ask, so only the manual flow is offered.
     */
    public function enabled(): bool
    {
        return trim($this->clientId) !== '';
    }

    public function requestDeviceCode(): DeviceCode
    {
        $response = $this->send(fn (): Response => $this->oauth()->post(self::Web.'/login/device/code', [
            'client_id' => $this->clientId,
        ]), self::Web);

        if (! $response->successful() || $response->json('error') !== null) {
            throw GitHubException::refused($response);
        }

        return DeviceCode::fromArray((array) $response->json())
            ?? throw new GitHubException('fatal: github.com sent a device code this app could not read.');
    }

    /**
     * Asks once whether the person has entered the code yet. Call it no more
     * often than the interval GitHub gave, or it answers `slow_down`.
     */
    public function requestToken(string $deviceCode): DeviceAuthorization
    {
        $response = $this->send(fn (): Response => $this->oauth()->post(self::Web.'/login/oauth/access_token', [
            'client_id' => $this->clientId,
            'device_code' => $deviceCode,
            'grant_type' => self::DeviceGrant,
        ]), self::Web);

        $token = $response->json('access_token');

        if ($response->successful() && is_string($token) && $token !== '') {
            return DeviceAuthorization::granted($token);
        }

        $error = $response->json('error');
        $status = is_string($error) ? AuthorizationStatus::fromError($error) : null;

        if ($status === null) {
            throw GitHubException::refused($response);
        }

        $interval = $response->json('interval');

        return DeviceAuthorization::of($status, is_numeric($interval) ? (int) $interval : null);
    }

    /**
     * Adds the key as a signing key and returns the account it went to. A key
     * that's already there counts as added.
     */
    public function addSigningKey(#[SensitiveParameter] string $token, string $title, string $publicKey): string
    {
        $user = $this->send(fn (): Response => $this->api($token)->get(self::Api.'/user'), self::Api);
        $login = $user->json('login');

        if (! $user->successful() || ! is_string($login) || $login === '') {
            throw GitHubException::fromApi($user, 'could not read your GitHub account');
        }

        $key = $this->send(fn (): Response => $this->api($token)->post(self::Api.'/user/ssh_signing_keys', [
            'title' => $title,
            'key' => $publicKey,
        ]), self::Api);

        if ($key->status() === 201 || ($key->status() === 422 && $this->alreadyAdded($key))) {
            return $login;
        }

        throw GitHubException::fromApi($key, "could not add the signing key to {$login}'s GitHub account");
    }

    private function oauth(): PendingRequest
    {
        return Http::asForm()->acceptJson()->withUserAgent('devnull-mobile')->timeout(15);
    }

    private function api(#[SensitiveParameter] string $token): PendingRequest
    {
        return Http::withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => self::ApiVersion])
            ->withUserAgent('devnull-mobile')
            ->timeout(15);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request, string $origin): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw GitHubUnreachable::at($origin);
        }

        if ($response->serverError()) {
            throw GitHubUnreachable::failing($origin, $response->status());
        }

        return $response;
    }

    private function alreadyAdded(Response $response): bool
    {
        $messages = [$response->json('message'), ...array_column((array) $response->json('errors'), 'message')];

        foreach ($messages as $message) {
            if (is_string($message) && str_contains(strtolower($message), 'already in use')) {
                return true;
            }
        }

        return false;
    }
}
