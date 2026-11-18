<?php

namespace Devnull\Photos\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

/**
 * The image is in the photo library.
 */
class Saved implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;
}
