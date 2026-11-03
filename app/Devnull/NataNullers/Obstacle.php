<?php

namespace App\Devnull\NataNullers;

/**
 * What comes along the pavement to knock you over. Cyclists, Nuno and the
 * octocat stay on the ground and have to be jumped. Gulls fly at head height,
 * just over you standing, so they're only trouble once you jump into them.
 */
enum Obstacle: string
{
    case Cyclist = 'cyclist';
    case Gull = 'gull';
    case Nuno = 'nuno';
    case Octocat = 'octocat';

    public function width(): float
    {
        return match ($this) {
            self::Cyclist => 46,
            self::Gull => 30,
            self::Nuno => 28,
            self::Octocat => 38,
        };
    }

    public function height(): float
    {
        return match ($this) {
            self::Cyclist => 42,
            self::Gull => 18,
            self::Nuno => 60,
            self::Octocat => 32,
        };
    }

    /**
     * How far above the ground its underside goes.
     */
    public function altitude(): float
    {
        return $this === self::Gull ? Simulation::PlayerHeight + 12 : 0;
    }

    /**
     * Its pace against the game's current crossing speed.
     */
    public function pace(): float
    {
        return match ($this) {
            self::Cyclist => 1.2,
            self::Gull => 1.0,
            self::Nuno => 0.75,
            self::Octocat => 1.4,
        };
    }

    /**
     * How long into a game it starts turning up, and how often it does after that.
     *
     * @return array{0: float, 1: int}
     */
    public function appearance(): array
    {
        return match ($this) {
            self::Cyclist => [0, 50],
            self::Gull => [15, 30],
            self::Nuno => [40, 12],
            self::Octocat => [60, 8],
        };
    }
}
