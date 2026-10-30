<?php

namespace Devnull\Calls\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The call ended on its own, never because this phone hung up: `hung_up` when the partner did, `failed` when the
 * connection couldn't be made or dropped, `microphone` when the microphone wasn't allowed, and `error` otherwise.
 */
class Ended implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $reason = '',
    ) {}
}
