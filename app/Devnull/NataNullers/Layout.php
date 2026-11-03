<?php

namespace App\Devnull\NataNullers;

/**
 * Where everything on the Nata Nu//ers screen goes, in the scene's units, so
 * the layout can be tuned in one place.
 *
 * The scene is 360 by 780, the shape of a modern phone (iPhones from the 12
 * on, and most Androids, are within a percent of it), and the canvas fits it
 * whole to the screen: a squarer phone gets bars at the sides rather than
 * losing the score or the controls. The game fills the screen, so the top
 * keeps clear of the status bar and the Dynamic Island, and the bottom of the
 * home indicator, and the controls keep clear of the edges, where the system
 * takes swipes for going back.
 */
final class Layout
{
    public const float Width = Simulation::Width;

    public const float Height = 780;

    public const float Ground = Simulation::Ground;

    /**
     * Below the status bar and the Dynamic Island.
     */
    public const float TopInset = 56;

    /**
     * Above the home indicator.
     */
    public const float BottomInset = 40;

    /**
     * The quit button, top left: [centre x, centre y, radius].
     */
    public const array Close = [36, 84, 20];

    /**
     * The score, top middle: the pill behind it [x, y, width, height], and
     * each digit's cell [width, height] and type size.
     */
    public const array ScorePill = [118, 62, 124, 46];

    public const array DigitCell = [19, 40];

    public const float DigitSize = 32;

    /**
     * The most digits a score shows; a minus sign goes in front of them.
     */
    public const int Digits = 5;

    /**
     * Your best, top right: [x, y, width, height].
     */
    public const array Best = [256, 68, 88, 34];

    /**
     * The rocker, bottom left, as its left and right halves: [x, y, width,
     * height]. It starts clear of the edge, where iOS takes a swipe to go back.
     */
    public const array RockerLeft = [24, 664, 80, 68];

    public const array RockerRight = [104, 664, 80, 68];

    /**
     * How far either side of the rocker's middle a thumb can rest and keep
     * walking the way it was, so it doesn't flicker on the line.
     */
    public const float RockerDeadZone = 8;

    /**
     * The jump button, bottom right: [centre x, centre y, radius].
     */
    public const array Jump = [290, 698, 44];

    /**
     * How to play, on the pavement above the controls for the first seconds.
     */
    public const array Hint = [24, 622, 312];

    /**
     * The results panel when you're knocked over: [x, y, width], with its
     * insides measured from its top-left corner.
     */
    public const array Panel = [28, 132, 304];

    public const float PanelPadding = 20;

    public const float ButtonHeight = 52;

    public const float ButtonGap = 12;

    /**
     * Whether a point is in a rectangle given as [x, y, width, height].
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     */
    public static function inRect(array $rect, float $x, float $y): bool
    {
        return $x >= $rect[0] && $x <= $rect[0] + $rect[2] && $y >= $rect[1] && $y <= $rect[1] + $rect[3];
    }

    /**
     * Which way a thumb at $thumb walks: the side of the rocker it's on, or
     * $current while it's in the middle.
     */
    public static function rockerDirection(float $thumb, int $current): int
    {
        $fromMiddle = $thumb - self::RockerRight[0];

        return abs($fromMiddle) <= self::RockerDeadZone ? $current : $fromMiddle <=> 0;
    }

    /**
     * The middle of the rocker's half for a direction, along its length.
     */
    public static function rockerHalfMiddle(int $direction): float
    {
        [$x, , $width] = $direction < 0 ? self::RockerLeft : self::RockerRight;

        return $x + $width / 2;
    }

    /**
     * Whether a point is in a circle given as [centre x, centre y, radius].
     *
     * @param  array{0: float, 1: float, 2: float}  $circle
     */
    public static function inCircle(array $circle, float $x, float $y): bool
    {
        return ($x - $circle[0]) ** 2 + ($y - $circle[1]) ** 2 <= $circle[2] ** 2;
    }
}
