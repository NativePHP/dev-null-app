<?php

namespace App\Devnull;

/**
 * How a star or a pastel looks at each step of the pop it gives when tapped,
 * after the web's: the star grows to 1.35 tilted 12 degrees and springs back
 * (`star-pop`), the pastel grows to 1.25 and settles (its button's
 * `animate()`). See Concerns\PopsReactions for the steps.
 */
final class Pop
{
    /**
     * Drawn as it is, with the animation that the next step uses, so the phone
     * has a node to animate from.
     */
    public const string Ready = 'ready';

    public const string Up = 'up';

    public const string Down = 'down';

    /**
     * The icon's transform at $step, or nulls when it isn't popping.
     *
     * @return array{scale: float|null, rotate: float|null, duration: int|null, easing: string|null}
     */
    public static function transform(?string $step, string $button): array
    {
        $star = $button === 'star';

        return match ($step) {
            self::Ready => ['scale' => 1.0, 'rotate' => 0.0, 'duration' => 150, 'easing' => 'ease-out'],
            self::Up => ['scale' => $star ? 1.35 : 1.25, 'rotate' => $star ? -12.0 : 0.0, 'duration' => 150, 'easing' => 'ease-out'],
            self::Down => ['scale' => 1.0, 'rotate' => 0.0, 'duration' => $star ? 320 : 180, 'easing' => $star ? 'spring' : 'ease-out'],
            default => ['scale' => null, 'rotate' => null, 'duration' => null, 'easing' => null],
        };
    }
}
