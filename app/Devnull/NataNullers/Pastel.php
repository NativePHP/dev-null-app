<?php

namespace App\Devnull\NataNullers;

/**
 * A pastel de nata falling straight down from where it appeared, at the speed
 * the game was going when it did. A big one is worth ten.
 */
final readonly class Pastel
{
    public const float Radius = 13;

    public const int BigScale = 2;

    public function __construct(
        public int $slot,
        public float $x,
        public float $droppedAt,
        public float $speed,
        public bool $big = false,
    ) {}

    public function radius(): float
    {
        return $this->big ? self::Radius * self::BigScale : self::Radius;
    }

    public function points(): int
    {
        return $this->big ? 10 : 1;
    }

    /**
     * Where its middle is: it starts just above the top.
     */
    public function y(float $time): float
    {
        return $this->top() + $this->speed * max(0.0, $time - $this->droppedAt);
    }

    public function top(): float
    {
        return -$this->radius();
    }

    /**
     * Where its middle is when it hits the pavement.
     */
    public function landing(): float
    {
        return Simulation::Ground - $this->radius();
    }

    public function landsAt(): float
    {
        return $this->droppedAt + ($this->landing() - $this->top()) / $this->speed;
    }
}
