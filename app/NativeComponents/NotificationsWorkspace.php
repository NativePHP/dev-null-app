<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\WatchesForCalls;
use Illuminate\View\View;

/**
 * Notifications on iOS: the list in the primary column, and the commit a notification opens beside it (see
 * Workspace). Profiles and organizations still open over the whole tab, as from the Log. A push about a commit opens
 * it here once the tab has drawn, as a tap on its notification would.
 */
class NotificationsWorkspace extends Workspace
{
    use WatchesForCalls;

    public function primary(): ?Notifications
    {
        foreach ($this->nativeChildComponents as $child) {
            if ($child instanceof Notifications) {
                return $child;
            }
        }

        return null;
    }

    public function paneFor(string $path): ?string
    {
        return str_starts_with($path, '/commits/') ? substr($path, strlen('/commits/')) : null;
    }

    protected function paneTag(): string
    {
        return 'commit-detail';
    }

    /**
     * The commit open from the list, whose notification's row shows as selected.
     */
    public function selectedCommit(): ?string
    {
        return $this->conversations[0]['id'] ?? null;
    }

    public function navTitle(): string
    {
        return 'Notifications';
    }

    public function render(): View
    {
        return view('native.notifications-workspace');
    }
}
