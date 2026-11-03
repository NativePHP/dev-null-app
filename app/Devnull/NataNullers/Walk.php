<?php

namespace App\Devnull\NataNullers;

/**
 * Walking along the pavement at a steady pace while an arrow is held, from
 * where you were when it went down, until the edge. Standing still is a walk
 * in no direction.
 */
final readonly class Walk
{
    public const float Speed = 210;

    public const float MinimumX = 22;

    public const float MaximumX = Simulation::Width - 22;

    public function __construct(
        public float $startedAt,
        public float $from,
        public int $direction = 0,
    ) {}

    public function x(float $time): float
    {
        $x = $this->from + $this->direction * self::Speed * max(0.0, $time - $this->startedAt);

        return min(self::MaximumX, max(self::MinimumX, $x));
    }

    /**
     * Where the walk stops: the edge it's heading for.
     */
    public function to(): float
    {
        return match (true) {
            $this->direction < 0 => self::MinimumX,
            $this->direction > 0 => self::MaximumX,
            default => $this->from,
        };
    }

    public function seconds(): float
    {
        return abs($this->to() - $this->from) / self::Speed;
    }
}
