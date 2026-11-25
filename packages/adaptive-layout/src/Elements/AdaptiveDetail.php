<?php

namespace Devnull\AdaptiveLayout\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class AdaptiveDetail extends Element
{
    protected string $type = 'adaptive_detail';

    protected array $componentProps = ['title' => 'Commit', 'subtitle' => ''];

    public static function make(): static
    {
        return new static;
    }

    public function title(string $title): static
    {
        $this->componentProps['title'] = $title;

        return $this;
    }

    public function subtitle(string $subtitle): static
    {
        $this->componentProps['subtitle'] = $subtitle;

        return $this;
    }

    public function identity(string $identity): static
    {
        $this->componentProps['identity'] = $identity;

        return $this;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['title'])) {
            $this->title((string) $attrs['title']);
        }

        if (isset($attrs['subtitle'])) {
            $this->subtitle((string) $attrs['subtitle']);
        }

        if (isset($attrs['identity'])) {
            $this->identity((string) $attrs['identity']);
        }

        $this->componentProps['can_delete'] = (bool) ($attrs['can-delete'] ?? $attrs['can_delete'] ?? false);
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->componentProps;
    }
}
