<?php

namespace App\Devnull\NataNullers;

/**
 * A jump as two arcs: up to the top easing out, and back to the ground easing
 * in. Those easings are exactly constant gravity, so this height is the one
 * the canvas draws from the same two segments, with no frames from PHP.
 *
 * Holding on makes it higher. A jump always sets off for the full height,
 * since nobody knows yet how long the finger stays down, and letting go early
 * cuts it short (see cut()).
 */
final readonly class Jump
{
    /**
     * Units per second squared: a full jump takes 0.43s to the top.
     */
    public const float Gravity = 1250;

    public const float FullHeight = 115;

    /**
     * How high the quickest tap goes.
     */
    public const float MinimumHeight = 40;

    /**
     * Held this long or longer, it's a full jump.
     */
    public const float HoldCap = 0.35;

    public function __construct(
        public float $startedAt,
        public float $from,
        public float $apex,
        public float $rise,
        public float $fall,
    ) {}

    public static function launch(float $time): self
    {
        return new self($time, 0, self::FullHeight, self::secondsToFall(self::FullHeight), self::secondsToFall(self::FullHeight));
    }

    /**
     * The jump after letting go at $time, having held for $held seconds: a
     * shorter tap tops out lower. On its way down, or held to the cap, it
     * stays as it is.
     */
    public function cut(float $time, float $held): self
    {
        if ($held >= self::HoldCap || $time >= $this->startedAt + $this->rise) {
            return $this;
        }

        $apex = self::MinimumHeight + (self::FullHeight - self::MinimumHeight) * max(0.0, $held) / self::HoldCap;
        $height = $this->height($time);

        if ($apex <= $height) {
            return new self($time, $height, $height, 0, self::secondsToFall($height));
        }

        return new self($time, $height, $apex, self::secondsToFall($apex - $height), self::secondsToFall($apex));
    }

    /**
     * How far the feet are off the ground.
     */
    public function height(float $time): float
    {
        $seconds = $time - $this->startedAt;

        if ($seconds <= 0) {
            return $this->from;
        }

        if ($seconds < $this->rise) {
            $progress = $seconds / $this->rise;

            return $this->from + ($this->apex - $this->from) * (1 - (1 - $progress) ** 2);
        }

        $seconds -= $this->rise;

        if ($seconds < $this->fall) {
            return $this->apex * (1 - ($seconds / $this->fall) ** 2);
        }

        return 0;
    }

    public function landsAt(): float
    {
        return $this->startedAt + $this->rise + $this->fall;
    }

    private static function secondsToFall(float $height): float
    {
        return sqrt(2 * max(0.0, $height) / self::Gravity);
    }
}
