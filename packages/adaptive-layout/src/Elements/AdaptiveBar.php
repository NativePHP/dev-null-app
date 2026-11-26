<?php

namespace Devnull\AdaptiveLayout\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;

/**
 * The navigation bar of one page of the feed host: the primary column's, or a
 * detail's. It sits inside that page's tag, after the component it is the bar
 * for, and the host puts it on the page's UIKit navigation item rather than
 * drawing it.
 *
 * Its buttons are the component's own navigationOptions() actions, menus
 * included, as top_bar_action children. Given that component's callback
 * registry, they register there, so a press calls the component that owns
 * them, as a tap inside it would. A title image takes the title's place, with
 * a long press when one is given.
 */
class AdaptiveBar extends Element
{
    protected string $type = 'adaptive_bar';

    /** @var array<string, string> */
    protected array $componentProps = [];

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

    /**
     * An image from the app's public folder in place of the title, with its dark appearance's own.
     */
    public function titleImage(string $light, ?string $dark = null): static
    {
        $this->componentProps['title_image'] = $light;
        $this->componentProps['title_image_dark'] = $dark ?? $light;

        return $this;
    }

    /**
     * The bar's buttons: a screen's navigationOptions() actions, in the order the screen gives them.
     */
    public function options(?NavBarOptions $options): static
    {
        if ($options === null) {
            return $this;
        }

        if ($options->subtitle !== null && ! isset($this->componentProps['subtitle'])) {
            $this->subtitle($options->subtitle);
        }

        foreach ($options->actions as $action) {
            $this->addChild($action->toElement());
        }

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

        if (isset($attrs['title-image'])) {
            $this->titleImage((string) $attrs['title-image'], isset($attrs['title-image-dark']) ? (string) $attrs['title-image-dark'] : null);
        }

        if (($attrs['options'] ?? null) instanceof NavBarOptions) {
            $this->options($attrs['options']);
        }

        if (is_string($attrs['long-press'] ?? null) && $attrs['long-press'] !== '') {
            $this->onLongPress($attrs['long-press']);
        }

        if (($attrs['callbacks'] ?? null) instanceof CallbackRegistry) {
            $this->ownCallbacks($attrs['callbacks']);
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->componentProps;
    }
}
