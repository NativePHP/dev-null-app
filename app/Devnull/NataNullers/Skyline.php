<?php

namespace App\Devnull\NataNullers;

use Native\Mobile\Skia\Node;
use Native\Mobile\Skia\Paint;
use Random\Randomizer;

/**
 * Lisbon's rooftops along the back of the street: painted fronts, terracotta
 * roofs and rows of windows, drawn once and kept, as they never change.
 *
 * Over them, the dusk that falls on the sky (see DayCycle::Night), and then
 * the lights in the windows, which keep their own hours: most go out around
 * sunrise and come on through the evening, some stay up late, and the odd one
 * is on in the middle of the day.
 */
final readonly class Skyline
{
    private const array Fronts = ['#F2C14E', '#F4A7A3', '#8EC5E8', '#F7E1B5', '#B9D7A8', '#E9B872'];

    private const string Roof = '#C8553D';

    private const string Glass = '#FFFFFFB3';

    private const string Lit = '#F9D77E';

    public function __construct(
        private float $width,
        private float $ground,
    ) {}

    /**
     * @return list<Node>
     */
    public function nodes(Randomizer $random): array
    {
        $buildings = [];
        $silhouette = '';
        $lights = [];
        $x = -6;
        $front = 0;

        while ($x < $this->width + 6) {
            $width = round($random->getFloat(34, 54), 1);
            $height = round($random->getFloat(62, 108), 1);
            $top = $this->ground - $height;
            $buildings[] = Node::rect($x, $top, $width, $height, Paint::fill(self::Fronts[$front++ % count(self::Fronts)]));
            $buildings[] = Node::path(sprintf('M%.1F %.1F L%.1F %.1F L%.1F %.1F Z', $x - 2, $top, $x + $width / 2, $top - 12, $x + $width + 2, $top), Paint::fill(self::Roof));
            $silhouette .= sprintf(
                'M%.1F %.1F L%.1F %.1F L%.1F %.1F L%.1F %.1F L%.1F %.1F L%.1F %.1F L%.1F %.1F Z ',
                $x, $this->ground, $x, $top, $x - 2, $top, $x + $width / 2, $top - 12, $x + $width + 2, $top, $x + $width, $top, $x + $width, $this->ground,
            );

            for ($row = $top + 12; $row < $this->ground - 18; $row += 20) {
                $panes = '';

                for ($column = $x + 7; $column < $x + $width - 10; $column += 14) {
                    $buildings[] = Node::rect($column, $row, 7, 10, Paint::fill(self::Glass), 1.5);
                    $panes .= sprintf('M%.1F 0 h7 v10 h-7 Z ', $column - $x);
                }

                if ($panes !== '') {
                    $lights[] = $this->light(trim($panes), $x, $row, $random);
                }
            }

            $x += $width;
        }

        return [
            Node::group($buildings)->cache(),
            DayCycle::fade(Node::path(trim($silhouette), Paint::fill('#0E1536A6'))->id('sky-dusk'), DayCycle::Night),
            Node::group($lights)->id('sky-lights'),
        ];
    }

    /**
     * The lights of one floor's windows, one flat to a floor, over the day:
     * switched with their height, so that lights that are off cost nothing to
     * draw. $panes is the windows' path from the floor's left edge.
     *
     * Whether they're on in the small hours is also whether they're on at the
     * end of the day, so the day loops without a flicker.
     */
    private function light(string $panes, float $x, float $y, Randomizer $random): Node
    {
        $upAllNight = $random->nextFloat() < 0.45;
        $switches = [];

        if ($upAllNight) {
            $switches[] = [$random->getFloat(0.13, 0.3), 0];
        }

        if ($random->nextFloat() < 0.12) {
            $on = $random->getFloat(0.32, 0.56);
            $switches[] = [$on, 1];
            $switches[] = [$on + $random->getFloat(0.03, 0.08), 0];
        }

        if ($upAllNight || $random->nextFloat() < 0.75) {
            $evening = $random->getFloat(0.66, 0.84);
            $switches[] = [$evening, 1];

            if (! $upAllNight) {
                $switches[] = [$random->getFloat($evening + 0.04, 0.99), 0];
            }
        }

        $state = $upAllNight ? 1 : 0;
        $keyframes = [[0, $state]];

        foreach ($switches as [$at, $to]) {
            $keyframes[] = [$at, $state];
            $keyframes[] = [$at + 0.0005, $to];
            $state = $to;
        }

        $keyframes[] = [1, $state];

        return Node::path($panes, Paint::fill(self::Lit))
            ->transform($x, $y)
            ->animate('scaleY', DayCycle::loop($keyframes));
    }
}
