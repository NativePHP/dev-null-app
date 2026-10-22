<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\AppleSignIn;
use App\Devnull\Client;
use App\Devnull\Data\CurrentUser;
use App\Devnull\Data\Remotes;
use App\Devnull\DeviceKey;
use App\Devnull\Display;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Hsl;
use App\Devnull\Lane;
use App\Devnull\ResponseCache;
use App\Devnull\WhoToFollow;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\SignsInWithApple;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Concerns\WritesInBackground;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;
use SensitiveParameter;

/**
 * Your account, reached from your own profile, laid out like the web's profile
 * settings: your profile as devnull mirrors it from GitHub, with a sync, your
 * colour, pastéis mode, Remotes and their settings, the people you mute and
 * block, then this phone's
 * fingerprint, signing out and deleting the account.
 * Where the app talks to only shows when it isn't dev-null.sh.
 *
 * It opens on you as devnull last said (or as this phone remembers you) and
 * asks again behind it. A sync or a new colour updates the screen when devnull
 * answers, and this phone remembers you as it said, so your profile and the
 * tab bar's colour follow.
 *
 * On iOS it also links an Apple ID to your account, as a second way in on a
 * new phone. The account stays the GitHub one: Apple only signs you into it.
 */
class ThisDevice extends NativeComponent
{
    use RefreshesInBackground;
    use SignsInWithApple;
    use SyncsOutbox;
    use TalksToDevnull;
    use WritesInBackground;

    public const string SignOutAlert = 'sign-out';

    public const string UnlinkAppleAlert = 'unlink-apple';

    public const string DeleteAccountAlert = 'delete-account';

    /**
     * Where Edit on GitHub goes, as on the web.
     */
    public const string GitHubSettingsUrl = 'https://github.com/settings/profile';

    /**
     * The colours, as the web's lane picker names them, lanes 0 to 5.
     */
    public const array LaneNames = ['Orange', 'Green', 'Violet', 'Teal', 'Rose', 'Ochre'];

    public string $fingerprint = '';

    /**
     * You, as devnull last said, or as this phone remembers you until it does.
     */
    public ?CurrentUser $current = null;

    /**
     * The colour picked on the screen, which the preview shows until it's saved: a lane, a custom `#rrggbb` colour,
     * or null for Automatic.
     */
    public int|string|null $chosenLane = null;

    /**
     * Whether the sheet for mixing a custom colour is open, as the web's Custom opens the system's colour picker.
     */
    public bool $mixingColour = false;

    /**
     * The custom colour being mixed, as `#rrggbb`, and the hue, saturation and lightness its sliders show. It starts
     * as the colour it was opened on, exactly, until a slider moves.
     */
    public string $mixedColour = '#000000';

    public float $hue = 0;

    public float $saturation = 0;

    public float $lightness = 0;

    public bool $syncing = false;

    public ?string $syncError = null;

    public bool $savingLane = false;

    public ?string $laneError = null;

    public bool $savingPasteisMode = false;

    public ?string $pasteisModeError = null;

    /**
     * What the web says when the switch couldn't be saved.
     */
    public const string PasteisModeFailed = 'Could not save pastéis mode. Please try again.';

    public bool $savingRemotes = false;

    public ?string $remotesError = null;

    public bool $savingRemotePreferences = false;

    public ?string $remotePreferencesError = null;

    /**
     * What the web says when the Remotes switch, or the settings on its Remotes page, couldn't be saved.
     */
    public const string RemotesFailed = 'Could not save remotes. Please try again.';

    public const string RemotePreferencesFailed = 'Could not save your remote settings. Try again.';

    /**
     * Whether devnull has an Apple ID linked to you, or null until it says.
     */
    public ?bool $appleLinked = null;

    public function mount(): void
    {
        if (($kept = $this->cached('me')) !== null) {
            $this->showMe($kept);
            $this->appleLinked = CurrentUser::fromArray($kept)->appleLinked;
        } elseif (($me = $this->me()) !== null) {
            $this->current = CurrentUser::remembered($me);
            $this->chosenLane = $this->current->preferredLane;
        }

        try {
            $this->fingerprint = app(DeviceKey::class)->current()->fingerprint();
        } catch (KeychainUnavailable $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->revalidate();
    }

    protected function revalidate(): void
    {
        $this->refresh('me', [], function (array $json): void {
            $this->takeMe($json);

            if (AppleSignIn::isAvailable()) {
                $this->appleLinked = CurrentUser::fromArray($json)->appleLinked;
            }
        });
    }

    protected function showsContent(): bool
    {
        return $this->current !== null;
    }

    public function navTitle(): string
    {
        return 'Account';
    }

    public function editOnGitHub(): void
    {
        Browser::inApp(self::GitHubSettingsUrl);
    }

    /**
     * Asks devnull to sync your profile from GitHub again, which waits on
     * GitHub, so the button spins until devnull answers. When it can't, the
     * section says why.
     */
    public function syncProfile(): void
    {
        if ($this->syncing) {
            return;
        }

        $this->syncing = true;
        $this->syncError = null;

        $this->writeInBackground('PUT', Client::GitHubPath, null, function (array $json): void {
            $this->syncing = false;
            $this->takeMe($json);
        }, function (string $message): void {
            $this->syncing = false;
            $this->syncError = $message;
        });
    }

    /**
     * Previews a colour, 'auto' or a lane. Nothing changes on devnull until it's saved.
     * 'custom' opens the sheet that mixes a colour of your own.
     */
    public function chooseLane(string $lane): void
    {
        if ($lane === 'custom') {
            $this->mixColour();

            return;
        }

        $chosen = $lane === 'auto' ? null : Lane::palette($lane);

        if ($lane !== 'auto' && $chosen === null) {
            return;
        }

        $this->chosenLane = $chosen;
        $this->laneError = null;
    }

    /**
     * Opens the custom colour sheet on the custom colour you have, or on the
     * one you're previewing, as the web opens its colour picker.
     */
    public function mixColour(): void
    {
        $colour = $this->customColour() ?? (string) theme('lane-'.(Lane::palette($this->chosenLane) ?? $this->current?->automaticLane ?? 0));

        $this->mixedColour = strtolower($colour);
        $hsl = Hsl::fromHex($this->mixedColour);
        $this->hue = $hsl->hue;
        $this->saturation = $hsl->saturation;
        $this->lightness = $hsl->lightness;
        $this->mixingColour = true;
    }

    public function updatedHue(): void
    {
        $this->mixedColour = (new Hsl($this->hue, $this->saturation, $this->lightness))->hex();
    }

    public function updatedSaturation(): void
    {
        $this->updatedHue();
    }

    public function updatedLightness(): void
    {
        $this->updatedHue();
    }

    /**
     * Previews the colour mixed, which Save color then saves like any other.
     */
    public function useMixedColour(): void
    {
        $this->chosenLane = $this->mixedColour;
        $this->laneError = null;
        $this->mixingColour = false;
    }

    public function colourSheetClosed(): void
    {
        $this->mixingColour = false;
    }

    /**
     * Saves the colour picked. Once devnull has it, this phone remembers you
     * with it, so the tab bar and your profile take it at once. A custom
     * colour goes as the web's colour picker sends it, with no lane.
     */
    public function saveLane(): void
    {
        if ($this->savingLane || $this->current === null || $this->chosenLane === $this->current->preferredLane) {
            return;
        }

        $this->savingLane = true;
        $this->laneError = null;

        $body = Lane::isCustom($this->chosenLane) ? ['lane' => null, 'color' => $this->chosenLane] : ['lane' => $this->chosenLane];

        $this->writeInBackground('PUT', Client::LanePath, $body, function (array $json): void {
            $this->savingLane = false;
            $this->takeMe($json);
        }, function (string $message): void {
            $this->savingLane = false;
            $this->laneError = $message;
        });
    }

    /**
     * The web's Show and Hide for who to follow, kept on this phone as the web keeps it in a cookie.
     */
    public function setShowsWhoToFollow(bool $shows): void
    {
        app(WhoToFollow::class)->show($this->me()?->id, $shows);
    }

    /**
     * The people you mute and block, as the web's Safety settings list them.
     */
    public function openSafety(): void
    {
        $this->navigate('/profile/safety');
    }

    /**
     * Switches pastéis mode, as the web's switch does. The switch, and every
     * screen's commits, take it at once, and devnull is told from the
     * background. Flipped again before devnull answers, it's saved again once
     * it has, so the last flip wins. When devnull says no, the switch goes
     * back to what devnull has and the section says so in the web's words.
     */
    public function setPasteisMode(bool $enabled): void
    {
        if ($this->current === null || $this->current->pasteisMode === $enabled) {
            return;
        }

        $this->pasteisModeError = null;
        $this->current = $this->current->withPasteisMode($enabled);
        app(Account::class)->rememberPasteis($this->current->pasteis());

        if (! $this->savingPasteisMode) {
            $this->savePasteisMode($enabled);
        }
    }

    private function savePasteisMode(bool $enabled): void
    {
        $this->savingPasteisMode = true;

        $this->writeInBackground('PUT', Client::PasteisModePath, ['enabled' => $enabled], function (array $json) use ($enabled): void {
            $this->savingPasteisMode = false;

            if ($this->current !== null && $this->current->pasteisMode !== $enabled) {
                $this->savePasteisMode($this->current->pasteisMode);

                return;
            }

            $this->takeMe($json);
        }, function () use ($enabled): void {
            $this->savingPasteisMode = false;
            $this->pasteisModeError = self::PasteisModeFailed;

            if ($this->current !== null) {
                $this->current = $this->current->withPasteisMode(! $enabled);
                app(Account::class)->rememberPasteis($this->current->pasteis());
            }
        });
    }

    /**
     * Switches Remotes, as the web's switch does. The switch and the Remotes
     * tab take it at once, and devnull is told from the background; flipped
     * again before devnull answers, it's saved again once it has, so the last
     * flip wins. When devnull says no, it goes back, saying so in the web's words.
     */
    public function setRemotes(bool $enabled): void
    {
        if ($this->current === null || ($this->current->remotes !== null) === $enabled) {
            return;
        }

        $this->remotesError = null;
        $this->showRemotes($enabled ? new Remotes : null);

        if (! $this->savingRemotes) {
            $this->saveRemotes($enabled);
        }
    }

    private function saveRemotes(bool $enabled): void
    {
        $this->savingRemotes = true;

        $this->writeInBackground('PUT', Client::RemotesSwitchPath, ['enabled' => $enabled], function (array $json) use ($enabled): void {
            $this->savingRemotes = false;

            if ($this->current !== null && ($this->current->remotes !== null) !== $enabled) {
                $this->saveRemotes($this->current->remotes !== null);

                return;
            }

            $this->takeMe($json);
        }, function () use ($enabled): void {
            $this->savingRemotes = false;
            $this->remotesError = self::RemotesFailed;
            $this->showRemotes($enabled ? null : new Remotes);
        });
    }

    /**
     * Share pulls, as the web's checkbox: whether others see when you pull their messages, and you theirs.
     */
    public function setPullReceipts(bool $pullReceipts): void
    {
        $remotes = $this->current?->remotes;

        if ($remotes !== null && $remotes->pullReceipts !== $pullReceipts) {
            $this->saveRemotePreferences($remotes->with($remotes->from, $pullReceipts), $remotes);
        }
    }

    /**
     * Who can push to you straight away, which the web only offers while devnull lets people pick everyone.
     */
    public function setRemotesFrom(string $from): void
    {
        $remotes = $this->current?->remotes;

        if ($remotes !== null && $remotes->everyone && in_array($from, [Remotes::Following, Remotes::Everyone], true) && $remotes->from !== $from) {
            $this->saveRemotePreferences($remotes->with($from, $remotes->pullReceipts), $remotes);
        }
    }

    private function saveRemotePreferences(Remotes $remotes, Remotes $before): void
    {
        $this->remotePreferencesError = null;
        $this->savingRemotePreferences = true;
        $this->showRemotes($remotes);

        $this->writeInBackground('PUT', Client::RemotePreferencesPath, ['from' => $remotes->from, 'pull_receipts' => $remotes->pullReceipts], function (array $json): void {
            $this->savingRemotePreferences = false;
            $this->takeMe($json);
        }, function () use ($before): void {
            $this->savingRemotePreferences = false;
            $this->remotePreferencesError = self::RemotePreferencesFailed;
            $this->showRemotes($before);
        });
    }

    private function showRemotes(?Remotes $remotes): void
    {
        if ($this->current === null) {
            return;
        }

        $this->current = $this->current->withRemotes($remotes);
        app(Account::class)->rememberRemotes($remotes);
    }

    /**
     * The devices page on dev-null.sh, where this phone is listed once approved.
     */
    public function openDevices(): void
    {
        Browser::inApp(rtrim((string) config('devnull.url'), '/').'/settings/devices');
    }

    public function confirmSignOut(): void
    {
        Dialog::alert(
            'Sign out?',
            'This deletes the key from this phone. The phone stays listed on dev-null.sh until you remove it there, and signing in again makes a new key.',
            [
                ['label' => 'Cancel', 'style' => 'cancel'],
                ['label' => 'Sign out', 'style' => 'destructive'],
            ],
        )->id(self::SignOutAlert);
    }

    public function confirmDeleteAccount(): void
    {
        Dialog::alert(
            'Delete your account?',
            'Your profile, commits, replies, stars and follows are removed from /dev/null. There is no undo.',
            [
                ['label' => 'Cancel', 'style' => 'cancel'],
                ['label' => 'Delete', 'style' => 'destructive'],
            ],
        )->id(self::DeleteAccountAlert);
    }

    /**
     * Opens Apple's sheet. Its answer arrives as an event (see SignsInWithApple).
     */
    public function linkApple(): void
    {
        if (! AppleSignIn::isAvailable()) {
            return;
        }

        $this->error = null;
        $this->startAppleSignIn();
    }

    public function confirmUnlinkApple(): void
    {
        Dialog::alert(
            'Unlink your Apple ID?',
            'Sign in with Apple stops working for your account until you link it again. Your GitHub sign-in and this phone stay as they are.',
            [
                ['label' => 'Cancel', 'style' => 'cancel'],
                ['label' => 'Unlink', 'style' => 'destructive'],
            ],
        )->id(self::UnlinkAppleAlert);
    }

    public function unlinkApple(): void
    {
        if (! AppleSignIn::isAvailable()) {
            return;
        }

        $linked = $this->attempt(fn (Client $devnull): bool => $devnull->unlinkApple());

        if ($linked !== null) {
            $this->appleLinked = $linked;
        }
    }

    #[On(ButtonPressed::class)]
    public function onAlertButton(string $label = '', ?string $id = null): void
    {
        if ($id === self::SignOutAlert && $label === 'Sign out') {
            $this->forget();
        }

        if ($id === self::DeleteAccountAlert && $label === 'Delete') {
            $this->deleteAccount();
        }

        if ($id === self::UnlinkAppleAlert && $label === 'Unlink') {
            $this->unlinkApple();
        }
    }

    /**
     * Signs out: the key goes from this phone, and with it who you are and
     * every answer kept for you (see Account::forget).
     */
    public function forget(): void
    {
        app(DeviceKey::class)->forget();
        app(Account::class)->forget();

        $this->replace('/onboarding');
    }

    /**
     * Deletes the account on devnull, which takes its devices with it, then
     * signs out. When devnull can't, the error shows and you stay signed in.
     */
    public function deleteAccount(): void
    {
        $deleted = $this->attempt(function (Client $devnull): bool {
            $devnull->deleteAccount();

            return true;
        });

        if ($deleted === true) {
            $this->forget();
        }
    }

    public function render(): View
    {
        $current = $this->current;

        return view('native.this-device', [
            'myColour' => $this->myColour(),
            'user' => $current?->user,
            'fields' => $current === null ? [] : self::fields($current),
            'syncedAt' => $current?->githubSyncedAt === null ? 'Last synced when you signed in' : 'Last synced '.Display::ago($current->githubSyncedAt),
            'savedLane' => $current?->user->lane(),
            'previewLane' => $this->chosenLane ?? $current?->automaticLane,
            'lanes' => $current === null ? [] : $this->laneOptions($current),
            'laneChanged' => $current !== null && $this->chosenLane !== $current->preferredLane,
            'pasteisMode' => $current?->pasteisMode ?? false,
            'showsWhoToFollow' => app(WhoToFollow::class)->shows($this->me()?->id),
            'remotes' => $current?->remotes,
            'remote' => self::remote(),
            'offersApple' => AppleSignIn::isAvailable(),
            'appleStatus' => match ($this->appleLinked) {
                true => 'linked',
                false => 'not linked',
                null => 'unknown',
            },
        ]);
    }

    /**
     * What devnull mirrors from GitHub, in the web's order, with null for what GitHub doesn't have.
     *
     * @return list<array{label: string, value: ?string}>
     */
    private static function fields(CurrentUser $current): array
    {
        $user = $current->user;

        return [
            ['label' => 'name', 'value' => $user->name],
            ['label' => 'username', 'value' => $user->handle()],
            ['label' => 'bio', 'value' => $user->bio],
            ['label' => 'company', 'value' => $user->company],
            ['label' => 'location', 'value' => $user->location],
            ['label' => 'website', 'value' => $user->website],
            ['label' => 'sponsors', 'value' => $current->sponsorsLabel()],
        ];
    }

    /**
     * Automatic, then the six lanes, then Custom, as the web's picker offers
     * them. Automatic shows the lane it gives you, and says which for
     * VoiceOver. Custom shows your custom colour once you have one.
     *
     * @return list<array{key: string, label: string, a11y: string, lane: int|string|null, auto: bool, chosen: bool}>
     */
    private function laneOptions(CurrentUser $current): array
    {
        $options = [[
            'key' => 'auto',
            'label' => 'Auto',
            'a11y' => 'Automatic ('.strtolower(self::LaneNames[$current->automaticLane]).')',
            'lane' => $current->automaticLane,
            'auto' => true,
            'chosen' => $this->chosenLane === null,
        ]];

        foreach (self::LaneNames as $lane => $name) {
            $options[] = ['key' => (string) $lane, 'label' => $name, 'a11y' => $name, 'lane' => $lane, 'auto' => false, 'chosen' => $this->chosenLane === $lane];
        }

        $custom = $this->customColour();

        $options[] = [
            'key' => 'custom',
            'label' => 'Custom',
            'a11y' => $custom === null ? 'Custom color' : "Custom color ({$custom})",
            'lane' => $custom,
            'auto' => false,
            'chosen' => Lane::isCustom($this->chosenLane),
        ];

        return $options;
    }

    /**
     * The custom colour you're previewing, or else the one you have.
     */
    private function customColour(): ?string
    {
        foreach ([$this->chosenLane, $this->current?->preferredLane] as $lane) {
            if (is_string($lane) && Lane::isCustom($lane)) {
                return $lane;
            }
        }

        return null;
    }

    /**
     * Shows you as devnull sent you. A colour picked and not saved yet stays
     * picked; otherwise the picker follows the one devnull has. While the
     * pastéis or Remotes switches are saving, they keep what they show.
     * Whether Apple is linked only comes with `GET /me`, so it's left to the
     * refresh.
     *
     * @param  array<string, mixed>  $json
     */
    private function showMe(array $json): ?CurrentUser
    {
        $current = CurrentUser::fromArray($json);

        if ($current->user->username === '') {
            return null;
        }

        if ($this->current === null || $this->chosenLane === $this->current->preferredLane) {
            $this->chosenLane = $current->preferredLane;
        }

        if ($this->savingPasteisMode && $this->current !== null) {
            $current = $current->withPasteisMode($this->current->pasteisMode);
        }

        if (($this->savingRemotes || $this->savingRemotePreferences) && $this->current !== null) {
            $current = $current->withRemotes($this->current->remotes);
        }

        return $this->current = $current;
    }

    /**
     * Shows you as devnull just sent you, and remembers you so, for the tab bar
     * and every other screen, giving every kept copy of you the change.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeMe(array $json): void
    {
        if (($current = $this->showMe($json)) === null) {
            return;
        }

        app(Account::class)->remember($current->user);
        app(Account::class)->rememberUnreadNotifications($current->unreadNotificationsCount);
        app(Account::class)->rememberPasteis($current->pasteis());
        app(Account::class)->rememberRemotes($current->remotes);
        app(ResponseCache::class)->meChanged($json);
    }

    /**
     * Where the app talks to, or null when it's dev-null.sh itself.
     */
    private static function remote(): ?string
    {
        $url = rtrim((string) config('devnull.url'), '/');

        return $url === Client::ProductionUrl ? null : $url;
    }

    /**
     * Links the Apple ID Apple just vouched for. devnull says so when it's
     * already linked to another account, and that lands in $error.
     */
    protected function useAppleToken(#[SensitiveParameter] string $identityToken, #[SensitiveParameter] string $nonce): void
    {
        $linked = $this->attempt(fn (Client $devnull): bool => $devnull->linkApple($identityToken, $nonce));

        if ($linked !== null) {
            $this->appleLinked = $linked;
        }
    }

    protected function appleFailed(string $message): void
    {
        $this->error = $message;
    }
}
