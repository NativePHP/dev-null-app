<?php

namespace App\NativeComponents\Concerns;

/**
 * A tab opened from a push opens the push's screen on top of itself, so back from it lands on the tab.
 */
trait OpensPushedScreen
{
    use OpensInTab;

    protected function openPushedScreen(): void
    {
        $path = $this->data('open');

        if (is_string($path) && $path !== '') {
            $this->openInTab($path);
        }
    }
}
