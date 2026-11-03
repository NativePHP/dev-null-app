<?php

namespace App\Devnull\NataNullers;

/**
 * An obstacle crossing from one side to the other in a straight line.
 * Direction 1 comes from the left, -1 from the right.
 */
final readonly class Crossing
{
    public function __construct(
        public int $slot,
        public Obstacle $obstacle,
        public int $direction,
        public float $startedAt,
        public float $speed,
    ) {}

    /**
     * Where its middle is: it starts just off the side it comes from.
     */
    public function x(float $time): float
    {
        return $this->from() + $this->direction * $this->speed * max(0.0, $time - $this->startedAt);
    }

    public function from(): float
    {
        $offscreen = $this->obstacle->width() / 2 + 8;

        return $this->direction > 0 ? -$offscreen : Simulation::Width + $offscreen;
    }

    public function to(): float
    {
        $offscreen = $this->obstacle->width() / 2 + 8;

        return $this->direction > 0 ? Simulation::Width + $offscreen : -$offscreen;
    }

    public function endsAt(): float
    {
        return $this->startedAt + abs($this->to() - $this->from()) / $this->speed;
    }

    /**
     * Where its underside is.
     */
    public function bottom(): float
    {
        return Simulation::Ground - $this->obstacle->altitude();
    }
}
