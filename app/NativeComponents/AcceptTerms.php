<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\CurrentUser;
use App\Devnull\Display;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\NativeComponents\Concerns\LeavesDevnull;
use App\NativeComponents\Concerns\OpensLegalPages;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;

/**
 * Stands in front of the app until you accept the terms and privacy policy in force, as the web's terms dialog does.
 * The app opens on it when devnull last said you owe them, and any screen that hears so from devnull sends you here
 * at once (see RefreshesInBackground::takeTerms()). It says when they changed, or, when you never accepted any, only
 * asks you to.
 *
 * Accepting names the version devnull said, so terms that change again while this is open aren't accepted unseen:
 * devnull refuses, and the screen asks again for the new ones. The ways out are signing out and deleting the
 * account. It asks devnull again as it opens, so terms accepted on the web since let you straight through.
 */
class AcceptTerms extends NativeComponent
{
    use LeavesDevnull;
    use OpensLegalPages;
    use RefreshesInBackground;
    use TalksToDevnull;

    public const string Path = '/accept-terms';

    public const string ChangedAgain = 'The terms changed again while this was open. Read them, then accept them.';

    /**
     * The version devnull is waiting for you to accept, exactly as it said it.
     */
    public ?string $version = null;

    /**
     * Whether you accepted an earlier version, so these are terms that changed rather than ones you never saw.
     */
    public bool $acceptedBefore = false;

    public function mount(): void
    {
        $this->version = app(Account::class)->termsToAccept();

        if ($this->version === null) {
            $this->replace('/log');

            return;
        }

        $kept = $this->cached('me');
        $this->acceptedBefore = $kept !== null && CurrentUser::fromArray($kept)->termsAcceptedAt !== null;

        $this->revalidate();
    }

    protected function revalidate(): void
    {
        $this->refresh('me', [], fn (array $json) => $this->takeTerms(CurrentUser::fromArray($json)), quiet: true);
    }

    protected function showsContent(): bool
    {
        return true;
    }

    public function accept(): void
    {
        if ($this->version === null) {
            return;
        }

        try {
            $current = app(Client::class)->acceptTerms($this->version);
        } catch (Unauthenticated $exception) {
            $this->signOut($exception);

            return;
        } catch (ApiException $exception) {
            if ($exception->status !== 422) {
                $this->error = $exception->firstError();

                return;
            }

            // The version in force moved on since devnull said which, so this asks it for the new one.
            $this->error = self::ChangedAgain;
            $this->retry();

            return;
        } catch (KeychainUnavailable $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->error = null;
        $this->takeTerms($current);
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
    }

    public function render(): View
    {
        $tookEffect = Commit::date($this->version);

        return view('native.accept-terms', [
            'logo' => TabsLayout::logo(),
            'title' => $this->acceptedBefore ? 'We updated our terms' : 'Accept our terms',
            'explanation' => $this->acceptedBefore && $tookEffect !== null
                ? 'Our terms of service changed on '.Display::day($tookEffect).'. Read them, and accept them to keep using /dev/null.'
                : 'Read the terms of service and privacy policy, and accept them to keep using /dev/null.',
        ]);
    }

    /**
     * Takes devnull's word on the terms: on to the log once you're up to date with them, as after accepting them
     * here or on the web, or else the version it's waiting for now.
     */
    protected function takeTerms(CurrentUser $current): void
    {
        if ($current->termsAccepted === null || $current->user->username === '') {
            return;
        }

        app(Account::class)->remember($current->user);
        app(Account::class)->rememberTermsToAccept($current->termsToAccept());

        if ($current->termsToAccept() === null) {
            $this->replace('/log');

            return;
        }

        $this->version = $current->termsToAccept();
        $this->acceptedBefore = $current->termsAcceptedAt !== null;
    }
}
