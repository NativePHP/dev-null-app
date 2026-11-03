<?php

namespace App\Devnull\NataNullers;

use Native\Mobile\Skia\Animation;
use Native\Mobile\Skia\Node;
use Native\Mobile\Skia\Paint;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Everything behind the street, over a day (see DayCycle): the sky getting
 * light and dark again, the sun and moon going over, stars twinkling and the
 * odd shooting star, clouds drifting by, now and then one that rains, planes,
 * and the rooftops with their lights coming on and off (see Skyline).
 *
 * It all moves on the canvas's clock, so nothing here needs the game to tell
 * it anything. What looks random is, but from a fixed seed: the scene comes
 * out the same every time it's built, so the canvas never has to take it again.
 *
 * Back to front: the day's sky, the sun, clouds and rain, the night falling
 * over all three, the glow at either end of the day, stars, the moon, planes,
 * then the rooftops.
 */
final readonly class Sky
{
    public const int Seed = 28;

    /**
     * Where the sun and moon park below the street while they're down.
     */
    private const float BelowStreet = 60;

    /**
     * The sky runs from the top of the scene down to $ground. The sun, moon,
     * clouds and planes keep below $top, clear of whatever the game draws
     * over the top of the screen; the stars go all the way up.
     */
    public function __construct(
        private float $width,
        private float $ground,
        private float $top = 0,
        private int $seed = self::Seed,
    ) {}

    /**
     * @return list<Node>
     */
    public function nodes(): array
    {
        $random = new Randomizer(new Mt19937($this->seed));

        return [
            Node::rect(0, 0, $this->width, $this->ground, Paint::fill()->linearGradient(0, 0, 0, $this->ground, ['#4FAFEA', '#A9DDF6', '#E3F4FC'], [0, 0.65, 1])),
            $this->sun(),
            ...$this->clouds($random),
            ...$this->rain($random),
            DayCycle::fade(Node::rect(0, 0, $this->width, $this->ground, Paint::fill()->linearGradient(0, 0, 0, $this->ground, ['#060B22F2', '#141C45F0', '#2B3566EB'], [0, 0.6, 1]))->id('sky-night'), DayCycle::Night),
            DayCycle::fade(Node::rect(0, 0, $this->width, $this->ground, Paint::fill()->linearGradient(0, $this->ground * 0.3, 0, $this->ground, ['#FF8A5C00', '#FF9A6E80', '#FFC27ECC'], [0, 0.6, 1]))->id('sky-glow'), DayCycle::Glow),
            DayCycle::fade(Node::group([...$this->stars($random), ...$this->shootingStars($random)])->id('sky-stars'), DayCycle::Stars),
            $this->moon(),
            ...$this->planes($random),
            ...(new Skyline($this->width, $this->ground))->nodes($random),
        ];
    }

    /**
     * Up over the rooftops at sunrise, highest at noon, down at sunset.
     */
    private function sun(): Node
    {
        $below = $this->ground + self::BelowStreet;
        $top = $this->at(0.1);

        return Node::group([
            Node::circle(0, 0, 70, Paint::fill()->radialGradient(0, 0, 70, ['#FFE48C99', '#FFE48C00'])),
            Node::circle(0, 0, 26, Paint::fill()->radialGradient(-6, -6, 32, ['#FFF6C2', '#FFD45C', '#F6B431'])),
        ])
            ->id('sky-sun')
            ->animate('translateX', DayCycle::loop([
                [0, $this->width * 0.08],
                [DayCycle::Sunrise, $this->width * 0.08],
                [DayCycle::Sunset, $this->width * 0.92],
                [1, $this->width * 0.92],
            ]))
            ->animate('translateY', DayCycle::loop([
                [0, $below],
                [DayCycle::Sunrise - 0.02, $below],
                [DayCycle::Noon, $top, 'easeOut'],
                [DayCycle::Sunset + 0.02, $below, 'easeIn'],
                [1, $below],
            ]));
    }

    /**
     * Highest when you arrive, setting as it gets light, and rising again for
     * the evening to be highest when the day comes round.
     */
    private function moon(): Node
    {
        $below = $this->ground + self::BelowStreet;
        $top = $this->at(0.07);
        $paper = '#F4F0DC';

        return Node::group([
            Node::circle(0, 0, 60, Paint::fill()->radialGradient(0, 0, 60, ['#F4F0DC59', '#F4F0DC00'])),
            Node::circle(0, 0, 19, Paint::fill($paper)),
            Node::circle(-6, -4, 4.5, Paint::fill('#DED8BE')),
            Node::circle(6, 5, 3, Paint::fill('#DED8BE')),
            Node::circle(3, -9, 2, Paint::fill('#DED8BE')),
        ])
            ->id('sky-moon')
            ->animate('translateX', DayCycle::loop([
                [0, $this->width * 0.5],
                [0.25, $this->width + 40],
                [0.25, -40],
                [0.75, -40],
                [1, $this->width * 0.5],
            ]))
            ->animate('translateY', DayCycle::loop([
                [0, $top],
                [0.25, $below, 'easeIn'],
                [0.75, $below],
                [1, $top, 'easeOut'],
            ]));
    }

    /**
     * Fair-weather clouds drifting left to right, each at its own height and pace.
     *
     * @return list<Node>
     */
    private function clouds(Randomizer $random): array
    {
        $clouds = [];

        foreach ([[0.06, 92], [0.2, 64], [0.34, 108], [0.48, 74]] as [$height, $size]) {
            $speed = $random->getFloat(4, 9);
            $start = $random->getFloat(-$size, $this->width);
            $from = -$size;
            $to = $this->width + $size;
            $period = ($to - $from) / $speed;

            $clouds[] = Node::path($this->cloud($size, $random), Paint::fill('#FFFFFFE6'))
                ->transform(0, $this->at($height))
                ->animate('translateX', DayCycle::sequence([
                    [0, $start],
                    [($to - $start) / ($to - $from), $to],
                    [($to - $start) / ($to - $from), $from],
                    [1, $start],
                ], $period));
        }

        return $clouds;
    }

    /**
     * Now and then a grey cloud comes over and rains on the street: each is
     * out of sight for most of its round and takes most of a minute to cross.
     *
     * @return list<Node>
     */
    private function rain(Randomizer $random): array
    {
        $showers = [];

        foreach ([[0.12, 150, 0.32], [0.24, 217, 0.7]] as $index => [$height, $period, $arrives]) {
            $size = 120;
            $top = $this->at($height);
            $fall = $this->ground - $top - 10;
            $crossing = 46;
            $from = -$size;
            $to = $this->width + $size;
            $leaves = $arrives + $crossing / $period;

            $showers[] = Node::group([
                Node::group([$this->drops($size, $fall, $random)])->clip(sprintf('M%.1F 6 L%.1F 6 L%.1F %.1F L%.1F %.1F Z', -$size * 0.42, $size * 0.42, $size * 0.42, $fall, -$size * 0.42, $fall)),
                Node::path($this->cloud($size, $random), Paint::fill('#8E98A9')),
                Node::path($this->cloud($size * 0.8, $random), Paint::fill('#A3ACBB'))->transform(-$size * 0.06, -4),
            ])
                ->id("sky-shower{$index}")
                ->transform(0, $top)
                ->animate('translateX', DayCycle::sequence([
                    [0, $from],
                    [$arrives, $from],
                    [$leaves, $to],
                    [$leaves, $from],
                    [1, $from],
                ], $period));
        }

        return $showers;
    }

    /**
     * Rain under a cloud $size across, falling $fall to the street: one path
     * of slanted streaks, a block of them stacked to the height, scrolled down
     * one block a loop so it never seems to repeat.
     */
    private function drops(float $size, float $fall, Randomizer $random): Node
    {
        $block = 90;
        $streaks = [];

        for ($drop = 0; $drop < 16; $drop++) {
            $streaks[] = [$random->getFloat(-$size * 0.42, $size * 0.42), $random->getFloat(0, $block)];
        }

        $path = '';

        for ($offset = -$block; $offset < $fall; $offset += $block) {
            foreach ($streaks as [$x, $y]) {
                $path .= sprintf('M%.1F %.1F l-2 9 ', $x, $y + $offset);
            }
        }

        return Node::path(trim($path), Paint::stroke('#B9D3F0CC', 1.4)->cap('round'))
            ->animate('translateY', Animation::between(0, $block, 0.4, 'linear', 'loop'));
    }

    /**
     * Stars, each twinkling at its own pace, and a few bright four-pointed ones.
     *
     * @return list<Node>
     */
    private function stars(Randomizer $random): array
    {
        $stars = [];

        for ($star = 0; $star < 46; $star++) {
            $twinkle = Animation::between(round($random->getFloat(0.25, 0.6), 2), round($random->getFloat(1, 1.25), 2), round($random->getFloat(0.9, 3.4), 2), 'easeInOut', 'mirror');
            $stars[] = Node::circle(0, 0, round($random->getFloat(0.7, 1.6), 1), Paint::fill($star % 5 === 0 ? '#FFEFC7' : '#FFFFFF'))
                ->transform(round($random->getFloat(0, $this->width), 1), round($random->getFloat($this->ground * 0.03, $this->ground * 0.62), 1))
                ->animate('scaleX', $twinkle)
                ->animate('scaleY', $twinkle);
        }

        for ($star = 0; $star < 5; $star++) {
            $twinkle = Animation::between(0.4, 1.1, round($random->getFloat(1.6, 3), 2), 'easeInOut', 'mirror');
            $stars[] = Node::path('M0 -5 Q0.8 -0.8 5 0 Q0.8 0.8 0 5 Q-0.8 0.8 -5 0 Q-0.8 -0.8 0 -5 Z', Paint::fill('#FFFFFF'))
                ->transform(round($random->getFloat($this->width * 0.05, $this->width * 0.95), 1), round($random->getFloat($this->ground * 0.05, $this->ground * 0.4), 1))
                ->animate('scaleX', $twinkle)
                ->animate('scaleY', $twinkle);
        }

        return $stars;
    }

    /**
     * Two shooting stars, each streaking across once every half a minute or
     * so: a line drawn in from its tail to its head and then rubbed out after
     * it, and nothing to draw in between.
     *
     * @return list<Node>
     */
    private function shootingStars(Randomizer $random): array
    {
        $shooting = [];

        foreach ([[29, 1], [43, -1]] as [$period, $direction]) {
            $x = $random->getFloat($this->width * 0.2, $this->width * 0.8);
            $y = $random->getFloat($this->ground * 0.06, $this->ground * 0.3);
            $length = 110;
            $at = $random->getFloat(0.2, 0.8);
            $streak = 0.55 / $period;
            $tail = 0.18 / $period;

            $shooting[] = Node::path(
                sprintf('M%.1F %.1F L%.1F %.1F', $x, $y, $x + $length * $direction, $y + $length * 0.45),
                Paint::stroke('#FFFFFF', 2.4)->cap('round')->linearGradient($x, $y, $x + $length * $direction, $y + $length * 0.45, ['#FFFFFF00', '#FFFFFF']),
            )
                ->animate('trimEnd', DayCycle::sequence([[0, 0], [$at, 0], [$at + $streak, 1, 'easeIn'], [1, 1]], $period))
                ->animate('trimStart', DayCycle::sequence([[0, 0], [$at + $tail, 0], [$at + $tail + $streak, 1, 'easeIn'], [1, 1]], $period));
        }

        return $shooting;
    }

    /**
     * Two planes on their own rounds, one each way, with a blinking light.
     *
     * @return list<Node>
     */
    private function planes(Randomizer $random): array
    {
        $planes = [];

        foreach ([[0.03, 97, -1, 0.25], [0.1, 151, 1, 0.6]] as [$height, $period, $direction, $arrives]) {
            $crossing = $random->getFloat(24, 32);
            $from = $direction < 0 ? $this->width + 60 : -60;
            $to = $direction < 0 ? -60 : $this->width + 60;
            $leaves = $arrives + $crossing / $period;
            $body = Paint::fill('#4B5262');

            $planes[] = Node::group([
                Node::line(10, 0, 58, 0, Paint::stroke('#FFFFFF', 1.6)->linearGradient(10, 0, 58, 0, ['#FFFFFF80', '#FFFFFF00'])->cap('round')),
                Node::path('M-12 0 Q-12 -2.4 -8 -2.4 L10 -2.4 L13 -6 L15 -6 L14 -1 Q14 1.6 11 1.6 L-9 1.6 Q-12 1.6 -12 0 Z', $body),
                Node::path('M-2 -1 L5 -1 L-1 6 L-4 6 Z', $body),
                Node::circle(-1, 3, 1.4, Paint::fill('#FF5A5A'))
                    ->animate('scaleX', DayCycle::sequence([[0, 1], [0.12, 1], [0.12, 0], [1, 0]], 1.1))
                    ->animate('scaleY', DayCycle::sequence([[0, 1], [0.12, 1], [0.12, 0], [1, 0]], 1.1)),
            ])
                ->transform(0, $this->at($height), 0, $direction < 0 ? 1 : -1)
                ->animate('translateX', DayCycle::sequence([
                    [0, $from],
                    [$arrives, $from],
                    [$leaves, $to],
                    [$leaves, $from],
                    [1, $from],
                ], $period));
        }

        return $planes;
    }

    /**
     * A height in the open sky, from 0 at its top to 1 at the street.
     */
    private function at(float $fraction): float
    {
        return round($this->top + ($this->ground - $this->top) * $fraction, 1);
    }

    /**
     * A cloud $size across as one path of overlapping puffs, flat-ish along
     * the bottom, centred on its origin. Every part runs clockwise, so where
     * they overlap they add up instead of cancelling into holes.
     */
    private function cloud(float $size, Randomizer $random): string
    {
        $puffs = [[0, 0, $size * 0.24]];

        foreach ([-0.3, -0.12, 0.12, 0.3] as $along) {
            $puffs[] = [$size * $along, $random->getFloat(-$size * 0.06, $size * 0.02), $size * $random->getFloat(0.14, 0.2)];
        }

        $puffs[] = [-$size * 0.4, $size * 0.06, $size * 0.1];
        $puffs[] = [$size * 0.4, $size * 0.06, $size * 0.1];

        $path = sprintf('M%.1F %.1F L%.1F %.1F L%.1F %.1F L%.1F %.1F Z ', -$size * 0.42, 0, $size * 0.42, 0, $size * 0.42, $size * 0.14, -$size * 0.42, $size * 0.14);

        foreach ($puffs as [$x, $y, $radius]) {
            $path .= sprintf('M%.1F %.1F a%.1F %.1F 0 1 1 %.1F 0 a%.1F %.1F 0 1 1 %.1F 0 Z ', $x - $radius, $y, $radius, $radius, $radius * 2, $radius, $radius, -$radius * 2);
        }

        return trim($path);
    }
}
