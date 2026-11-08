<?php

namespace Devnull\AdaptiveLayout\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class AdaptiveFeed extends Element
{
    protected string $type = 'adaptive_feed';

    protected array $componentProps = ['depth' => 0, 'intent_revision' => 0];

    public static function make(): static
    {
        return new static;
    }

    public function depth(int $depth): static
    {
        $this->componentProps['depth'] = max(0, $depth);

        return $this;
    }

    public function onChange(string $method): static
    {
        $this->componentProps['on_change'] = $method;

        return $this;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['depth'])) {
            $this->depth((int) $attrs['depth']);
        }

        if (isset($attrs['intent-revision'])) {
            $this->componentProps['intent_revision'] = (int) $attrs['intent-revision'];
        }

        if (isset($attrs['_change'])) {
            $this->onChange($attrs['_change']);
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->componentProps;

        if (isset($props['on_change'])) {
            $props['on_change'] = $registry->register($props['on_change']);
        }

        return $props;
    }
}
