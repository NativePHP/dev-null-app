<?php

namespace Devnull\CommitRow;

/**
 * One row of More's menu, or a divider between rows.
 */
final readonly class MenuItem
{
    /**
     * @param  string  $id  What the item does ("share", "delete"), for tests and refs.
     * @param  string|null  $press  The method a tap calls, as `@press` takes it.
     */
    public function __construct(
        public string $id,
        public string $label = '',
        public ?PlatformIcon $icon = null,
        public ?string $press = null,
        public bool $destructive = false,
        public bool $divider = false,
    ) {}

    public static function divider(): self
    {
        return new self('divider', divider: true);
    }
}
