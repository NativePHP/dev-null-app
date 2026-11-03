<?php

namespace App\Devnull\NataNullers;

/**
 * The panel that comes up when you're knocked over, as data: what it says and
 * where its buttons are. Scenery draws it from this, and the screen checks a
 * finger against the same rectangles, so the two can't disagree.
 *
 * Sharing goes in steps: a new best offers to share, sharing shows the words
 * the commit will have ($preview, its first line), and posting says it's done.
 */
final readonly class Results
{
    public const string Offer = 'offer';

    public const string Confirm = 'confirm';

    public const string Shared = 'shared';

    public function __construct(
        public int $score,
        public ?int $best,
        public bool $newBest,
        public ?string $sharing = null,
        public ?string $error = null,
        public string $preview = '',
    ) {}

    public function title(): string
    {
        return $this->newBest && $this->score > 0 ? 'A new best!' : 'Knocked over';
    }

    public function unit(): string
    {
        return abs($this->score) === 1 ? 'pastel' : 'pastéis';
    }

    /**
     * The panel's rectangle: [x, y, width, height].
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function panel(): array
    {
        [$x, $y, $width] = Layout::Panel;

        return [$x, $y, $width, $this->rows()['end'] - $y];
    }

    /**
     * Each button's rectangle, [x, y, width, height], by its shape ID.
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public function buttons(): array
    {
        return $this->rows()['buttons'];
    }

    /**
     * Where the lines between the score and the buttons go: the preview of
     * the commit while confirming, the "shared" line, and the error.
     *
     * @return array{preview: ?array{0: float, 1: float, 2: float, 3: float}, shared: ?float, error: ?float}
     */
    public function lines(): array
    {
        return $this->rows()['lines'];
    }

    /**
     * Lays the panel out from the top down.
     *
     * @return array{buttons: array<string, array{0: float, 1: float, 2: float, 3: float}>, lines: array{preview: ?array{0: float, 1: float, 2: float, 3: float}, shared: ?float, error: ?float}, end: float}
     */
    private function rows(): array
    {
        [$x, $y, $width] = Layout::Panel;
        $left = $x + Layout::PanelPadding;
        $inner = $width - Layout::PanelPadding * 2;
        $cursor = $y + 182;
        $buttons = [];
        $lines = ['preview' => null, 'shared' => null, 'error' => null];

        if ($this->sharing === self::Offer) {
            $buttons['share'] = [$left, $cursor, $inner, Layout::ButtonHeight];
            $cursor += Layout::ButtonHeight + Layout::ButtonGap;
        } elseif ($this->sharing === self::Confirm) {
            $lines['preview'] = [$left, $cursor, $inner, 84];
            $cursor += 84 + Layout::ButtonGap;
            $buttons['post'] = [$left, $cursor, $inner, Layout::ButtonHeight];
            $cursor += Layout::ButtonHeight + Layout::ButtonGap;
        } elseif ($this->sharing === self::Shared) {
            $lines['shared'] = $cursor;
            $cursor += 32;
        }

        if ($this->error !== null) {
            $lines['error'] = $cursor;
            $cursor += 48;
        }

        $half = ($inner - Layout::ButtonGap) / 2;
        $buttons['play-again'] = [$left, $cursor, $half, Layout::ButtonHeight];
        $buttons['quit'] = [$left + $half + Layout::ButtonGap, $cursor, $half, Layout::ButtonHeight];

        return ['buttons' => $buttons, 'lines' => $lines, 'end' => $cursor + Layout::ButtonHeight + Layout::PanelPadding];
    }
}
