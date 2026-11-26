<?php

namespace Devnull\AdaptiveLayout\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * One page of the detail column's history: the component it shows, then that
 * component's adaptive-bar. Like the placeholder, it is a descriptor the feed
 * host places, not a screen of its own.
 */
class AdaptiveDetail extends Element
{
    protected string $type = 'adaptive_detail';

    protected array $componentProps = [];

    public static function make(): static
    {
        return new static;
    }

    public function identity(string $identity): static
    {
        $this->componentProps['identity'] = $identity;

        return $this;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['identity'])) {
            $this->identity((string) $attrs['identity']);
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->componentProps;
    }
}
