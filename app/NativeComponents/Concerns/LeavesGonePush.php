<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Exceptions\ApiException;
use App\Devnull\Toasts;

/**
 * A screen a push opened, whose commit or thread devnull no longer has, goes back to the tab beneath it and says
 * so, rather than leaving devnull's error on an empty screen. A push can be tapped long after it came, and the
 * commit deleted or the org left in between. Opened any other way, the screen shows the error as before.
 */
trait LeavesGonePush
{
    protected function leaveGonePush(ApiException $exception, string $message): bool
    {
        if ($exception->status !== 404 || $this->data('pushed') !== true || $this->showsContent()) {
            return false;
        }

        // The thread and its replies can both come back 404; one toast and one back is enough.
        if (! $this->hasNavigationIntent()) {
            Toasts::say($message);
            $this->back();
        }

        return true;
    }
}
