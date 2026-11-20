<?php

namespace Devnull\AdaptiveLayout\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * What the detail column shows while no conversation is selected. Like the
 * detail tag, it is a descriptor the feed host places, not a screen of its own.
 */
class AdaptivePlaceholder extends Element
{
    protected string $type = 'adaptive_placeholder';

    public static function make(): static
    {
        return new static;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return [];
    }
}
