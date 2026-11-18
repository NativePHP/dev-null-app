<?php

namespace App\NativeComponents\Concerns;

/**
 * A tab opened from a push opens the push's screen on top of itself, so back from it lands on the tab. The screen
 * is told it came from a push, so it can go back to the tab if what the push was about has gone since (see
 * LeavesGonePush).
 */
trait OpensPushedScreen
{
    use OpensInTab;

    protected function openPushedScreen(): void
    {
        $path = $this->data('open');

        if (is_string($path) && $path !== '') {
            $this->openInTab($path, ['pushed' => true]);
        }
    }
}
