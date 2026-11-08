<?php

namespace Devnull\AdaptiveLayout\Components;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class AdaptivePlaceholder extends NativeBladeComponent
{
    protected function elementType(): string
    {
        return 'adaptive_placeholder';
    }
}
