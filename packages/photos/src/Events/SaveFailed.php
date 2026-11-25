<?php

namespace Devnull\Photos\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The image didn't go in the photo library: `denied` when the app isn't allowed to add to it, `unreadable` when the
 * file isn't an image the phone can read, and `error` otherwise.
 */
class SaveFailed implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $reason = 'error',
    ) {}
}
