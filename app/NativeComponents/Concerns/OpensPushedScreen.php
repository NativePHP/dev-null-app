<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\FirstFrame;

/**
 * A tab opened from a push opens the push's screen on top of itself, so back from it lands on the tab. The screen
 * is told it came from a push, so it can go back to the tab if what the push was about has gone since (see
 * LeavesGonePush).
 *
 * It waits for the tab's own first frame to come back first. iOS reconciles a tab's stack only when it redraws the
 * tabs, so of two frames that land together it sees the second. Pushing as the tab mounted sent the tab's frame and
 * the pushed screen's back to back, iOS never saw the tab arrive on the stack, and it stacked two or three copies of
 * the pushed screen, each with its own slide in and out.
 */
trait OpensPushedScreen
{
    use OpensInTab;

    protected function openPushedScreen(): void
    {
        $path = $this->pushedScreen();

        if ($path === null) {
            return;
        }

        $open = fn () => $this->openInTab($path, ['pushed' => true]);

        FirstFrame::dispatch()
            ->timeout(10)
            ->finished($open)
            ->failed($open);
    }

    /**
     * The screen the push asked this tab to open on top of itself, if any.
     */
    protected function pushedScreen(): ?string
    {
        $path = $this->data('open');

        return is_string($path) && $path !== '' ? $path : null;
    }
}
