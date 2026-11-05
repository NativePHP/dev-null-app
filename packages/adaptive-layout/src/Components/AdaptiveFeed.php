<?php

namespace Devnull\AdaptiveLayout\Components;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class AdaptiveFeed extends NativeBladeComponent
{
    protected function elementType(): string
    {
        return 'adaptive_feed';
    }
}
