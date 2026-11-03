<?php

namespace App\Devnull\NataNullers;

use Native\Mobile\Skia\Animation;
use Native\Mobile\Skia\Node;

/**
 * A day in Lisbon, four minutes long, on the canvas's own clock.
 *
 * You arrive in the small hours with the moon high. It sets as it gets light,
 * the sun is up by the end of the first minute and sets nearly three minutes
 * in, the lights come on, and the moon is back for the night. Then it goes
 * round again. Four minutes so that an ordinary game sees the dawn and a good
 * one sees the whole day: games last a minute or five, and the speeds stop
 * climbing at five (see Simulation::RampSeconds).
 *
 * Times through the day are fractions of it, 0 to 1. Everything that changes
 * with the day loops over exactly one day, so it all keeps time together
 * however long you play, and stops with the canvas when the game does.
 *
 * Colours can't change on the canvas, only opacity and transforms, so dark
 * falls by fading a night sky in over the day's (see fade()).
 */
final class DayCycle
{
    public const float Seconds = 240;

    /**
     * When the sun comes up over the rooftops, is highest, and goes down.
     */
    public const float Sunrise = 0.22;

    public const float Noon = 0.47;

    public const float Sunset = 0.72;

    /**
     * How dark it is: night until a little before sunrise, day until a
     * little after sunset.
     *
     * @var list<array{0: float, 1: float}>
     */
    public const array Night = [[0, 1], [0.16, 1], [0.27, 0], [0.68, 0], [0.79, 1], [1, 1]];

    /**
     * The warm glow low in the sky around sunrise and sunset.
     *
     * @var list<array{0: float, 1: float}>
     */
    public const array Glow = [[0, 0], [0.15, 0], [0.22, 1], [0.31, 0], [0.63, 0], [0.72, 1], [0.81, 0], [1, 0]];

    /**
     * The stars, which go a little before the dark does and come out after it.
     *
     * @var list<array{0: float, 1: float}>
     */
    public const array Stars = [[0, 1], [0.14, 1], [0.23, 0], [0.74, 0], [0.82, 1], [1, 1]];

    /**
     * Shows a node through the day as much as $keyframes say, from 0 (not at
     * all) to 1, easing between them.
     *
     * The canvas composites a node on its own layer whenever its opacity isn't
     * exactly 1, even at 0. So while the node is meant to be gone it's drawn at
     * full opacity but squashed flat instead, which costs nothing, and only
     * the fades in between are layered.
     *
     * @param  list<array{0: float, 1: float}>  $keyframes
     */
    public static function fade(Node $node, array $keyframes): Node
    {
        $opacity = [];
        $scale = [];

        foreach ($keyframes as $index => [$at, $to]) {
            if ($index === 0) {
                continue;
            }

            [$from, $was] = [$keyframes[$index - 1][1], $keyframes[$index - 1][0]];
            $seconds = ($at - $was) * self::Seconds;

            if ($seconds < 0.001) {
                continue;
            }

            $hidden = $from <= 0 && $to <= 0;
            $opacity[] = $hidden ? self::hold(1, $seconds) : Animation::between($from, $to, round($seconds, 3), 'easeInOut', 'once');
            $scale[] = self::hold($hidden ? 0 : 1, $seconds);
        }

        return $node->animate('opacity', Animation::sequence(...$opacity)->repeat('loop'))
            ->animate('scaleY', Animation::sequence(...$scale)->repeat('loop'));
    }

    /**
     * A value through the day, as [time, value] or [time, value, easing]
     * keyframes from 0 to 1, each easing into its value from the one before.
     * Two keyframes at the same time jump.
     *
     * @param  list<array{0: float, 1: float, 2?: string}>  $keyframes
     */
    public static function loop(array $keyframes): Animation
    {
        return self::sequence($keyframes, self::Seconds);
    }

    /**
     * The same, over any period in seconds rather than a day.
     *
     * @param  list<array{0: float, 1: float, 2?: string}>  $keyframes
     */
    public static function sequence(array $keyframes, float $period): Animation
    {
        $segments = [];

        foreach ($keyframes as $index => $keyframe) {
            if ($index === 0) {
                continue;
            }

            $previous = $keyframes[$index - 1];
            $seconds = ($keyframe[0] - $previous[0]) * $period;

            if ($seconds < 0.001) {
                continue;
            }

            $segments[] = Animation::between(round($previous[1], 2), round($keyframe[1], 2), round($seconds, 3), $keyframe[2] ?? 'linear', 'once');
        }

        return Animation::sequence(...$segments)->repeat('loop');
    }

    private static function hold(float $value, float $seconds): Animation
    {
        return Animation::between($value, $value, round($seconds, 3), 'linear', 'once');
    }
}
