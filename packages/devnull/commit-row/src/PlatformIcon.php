<?php

namespace Devnull\CommitRow;

use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\IconResolver;
use Native\Mobile\Icon\IosSymbol;

/**
 * An icon named once for each platform, as `<native:icon :ios="…" :android="…">`
 * takes it. The element sends the current platform's name, and on Android the
 * Material variant a symbol asks for.
 */
final readonly class PlatformIcon
{
    public function __construct(
        public IosSymbol|string $ios,
        public AndroidSymbol|string $android,
    ) {}

    /**
     * @return array{name: string, variant: string}
     */
    public function resolve(): array
    {
        $resolved = IconResolver::resolve(null, $this->ios, $this->android);

        return ['name' => (string) $resolved['icon'], 'variant' => (string) ($resolved['variant'] ?? '')];
    }
}
