<?php

namespace Devnull\Calls\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The two ends can hear each other.
 */
class Connected implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;
}
