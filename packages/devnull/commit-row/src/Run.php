<?php

namespace Devnull\CommitRow;

/**
 * One stretch of a paragraph drawn in a single style. Anything left null
 * takes the paragraph's value (its font, size and colour), as a nested
 * `<native:text>` run inherits from the text around it.
 *
 * A run carries its own final style: where Blade nests runs (a named link's
 * muted owner, the wordmark's slash), each piece is a run of its own.
 */
final readonly class Run
{
    /**
     * @param  string|null  $font  A font token or name, as `font="…"` takes it ("semibold", "mono", "default").
     * @param  int|null  $weight  1 thin to 7 heavy, as `font-*` weight classes send it (5 is semibold).
     * @param  float|null  $size  Points.
     * @param  ColorPair|null  $background  The inline code wash.
     * @param  string|null  $press  The method a tap on the run calls, as `@press` takes it.
     * @param  float|null  $tracking  Letter spacing in em, as `tracking-*` sends it.
     */
    public function __construct(
        public string $text,
        public ?string $font = null,
        public ?int $weight = null,
        public ?float $size = null,
        public ?ColorPair $color = null,
        public ?ColorPair $background = null,
        public ?string $press = null,
        public ?float $tracking = null,
    ) {}
}
