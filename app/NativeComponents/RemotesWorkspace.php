<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\NativeComponents\Concerns\WatchesForCalls;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;

/**
 * Remotes on iOS: the inbox in the primary column, and the thread you open beside it, a person's or an org's (see
 * Workspace). Its history is the thread's path in the tab, /conversations/{username} or /orgs/{id}. Everything else
 * a thread or the inbox opens (profiles, members, New remote, a call) still goes over the whole tab. While you have
 * Remotes off there is nothing to open, so the inbox's page keeps the whole window.
 *
 * Only this screen's polls run, so it polls the inbox and the open thread for them, as they poll as screens.
 */
class RemotesWorkspace extends Workspace
{
    use WatchesForCalls;

    public function primary(): ?Remotes
    {
        foreach ($this->nativeChildComponents as $child) {
            if ($child instanceof Remotes) {
                return $child;
            }
        }

        return null;
    }

    public function paneFor(string $path): ?string
    {
        return preg_match('~^/(conversations|orgs)/[^/]+$~', $path) === 1 ? $path : null;
    }

    protected function paneTag(): string
    {
        return 'conversation';
    }

    /**
     * The path of the thread open from the inbox, whose row shows as selected.
     */
    public function selectedPath(): ?string
    {
        return $this->conversations[0]['id'] ?? null;
    }

    /**
     * Who a thread's path is with, as its route would bind them: a username, or an org's id.
     *
     * @return array{username: string, org: string}
     */
    public function threadOf(string $path): array
    {
        [, $kind, $who] = array_pad(explode('/', $path, 3), 3, '');

        return $kind === 'orgs'
            ? ['username' => '', 'org' => rawurldecode($who)]
            : ['username' => rawurldecode($who), 'org' => ''];
    }

    public function remotesOff(): bool
    {
        return $this->primary()?->off ?? app(Account::class)->remotes() === null;
    }

    #[Poll(15000)]
    public function pollInbox(): void
    {
        $this->primary()?->pollInbox();
    }

    #[Poll(2000)]
    public function pollThread(): void
    {
        $thread = $this->foregroundPane();

        if ($thread instanceof Conversation) {
            $thread->poll();
        }
    }

    public function navTitle(): string
    {
        return 'Remotes';
    }

    public function render(): View
    {
        return view('native.remotes-workspace');
    }
}
