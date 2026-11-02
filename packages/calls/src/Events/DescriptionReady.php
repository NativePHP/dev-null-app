<?php

namespace Devnull\Calls\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The offer or answer to hand the partner, with every way to reach this phone already in it.
 */
class DescriptionReady implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sdp = '',
    ) {}
}
