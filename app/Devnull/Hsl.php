<?php

namespace App\Devnull;

/**
 * A colour as hue (0 to 360), saturation and lightness (0 to 100), which is
 * how the Account screen's custom colour sliders mix one. devnull takes it as
 * lowercase `#rrggbb`.
 */
final readonly class Hsl
{
    public function __construct(
        public float $hue,
        public float $saturation,
        public float $lightness,
    ) {}

    /**
     * The hue, saturation and lightness of a `#rrggbb` colour, rounded to
     * whole steps as the sliders move.
     */
    public static function fromHex(string $hex): self
    {
        [$red, $green, $blue] = array_map(fn (string $pair): float => hexdec($pair) / 255, str_split(substr($hex, 1, 6), 2));

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;

        if ($delta == 0.0) {
            return new self(0.0, 0.0, round($lightness * 100));
        }

        $saturation = $delta / (1 - abs(2 * $lightness - 1));

        $hue = match ($max) {
            $red => fmod(($green - $blue) / $delta + 6, 6),
            $green => ($blue - $red) / $delta + 2,
            default => ($red - $green) / $delta + 4,
        };

        return new self(fmod(round($hue * 60), 360), round($saturation * 100), round($lightness * 100));
    }

    /**
     * The colour as lowercase `#rrggbb`, with each value held to its range.
     */
    public function hex(): string
    {
        $hue = fmod(fmod($this->hue, 360) + 360, 360);
        $saturation = max(0, min(100, $this->saturation)) / 100;
        $lightness = max(0, min(100, $this->lightness)) / 100;

        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $second = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $match = $lightness - $chroma / 2;

        [$red, $green, $blue] = match (intdiv((int) $hue, 60)) {
            0 => [$chroma, $second, 0],
            1 => [$second, $chroma, 0],
            2 => [0, $chroma, $second],
            3 => [0, $second, $chroma],
            4 => [$second, 0, $chroma],
            default => [$chroma, 0, $second],
        };

        return '#'.implode('', array_map(fn (float|int $channel): string => sprintf('%02x', (int) round(($channel + $match) * 255)), [$red, $green, $blue]));
    }
}
