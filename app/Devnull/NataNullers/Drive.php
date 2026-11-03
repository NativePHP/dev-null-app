<?php

namespace App\Devnull\NataNullers;

/**
 * The bus going from one place to another along the street, gathering speed
 * from a stop (easeIn) or slowing into one (easeOut), as the canvas draws it.
 */
final readonly class Drive
{
    public function __construct(
        public float $startedAt,
        public float $from,
        public float $to,
        public float $seconds,
        public string $easing,
    ) {}

    public function x(float $time): float
    {
        $done = min(1.0, max(0.0, ($time - $this->startedAt) / $this->seconds));

        return $this->from + ($this->to - $this->from) * ($this->easing === 'easeIn' ? $done * $done : 1 - (1 - $done) ** 2);
    }

    public function endsAt(): float
    {
        return $this->startedAt + $this->seconds;
    }
}
