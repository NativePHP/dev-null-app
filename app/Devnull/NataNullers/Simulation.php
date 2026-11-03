<?php

namespace App\Devnull\NataNullers;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The rules of Nata Nu//ers, with no drawing in them.
 *
 * Everything moves in a straight line or a gravity arc from a known start, so
 * where anything is at any moment is a sum, not a frame. The canvas draws the
 * same paths on its own clock, and this is asked how things stand a few dozen
 * times a second (see advance()), with time in the canvas's seconds. It steps
 * through the gap since it was last asked at 120 steps a second, so nothing at
 * full speed slips through you between two asks.
 *
 * A pastel caught is a point, ten for a big one. A pastel that hits the
 * pavement costs a point, and the score can go below nothing: that's on you.
 * Anything crossing that touches you ends the game. Everything speeds up over
 * the first five minutes, then stays that fast.
 */
final class Simulation
{
    public const float Width = 360;

    /**
     * Where feet and pastéis meet the pavement, with the sky above it and
     * the controls on the pavement below (see Layout).
     */
    public const float Ground = 600;

    public const float PlayerWidth = 26;

    public const float PlayerHeight = 58;

    public const int PastelSlots = 8;

    public const int ObstacleSlots = 4;

    public const float RampSeconds = 300;

    /**
     * At most one big pastel in this many seconds.
     */
    public const float BigEvery = 60;

    private const float Step = 1 / 120;

    public int $score = 0;

    public ?float $endedAt = null;

    public ?Crossing $struckBy = null;

    /**
     * @var array<int, Pastel>
     */
    public array $pastels = [];

    /**
     * @var array<int, Crossing>
     */
    public array $crossings = [];

    public Walk $walk;

    public ?Jump $jump = null;

    private float $clock;

    private ?float $jumpHeldFrom = null;

    private float $nextPastelAt;

    private float $nextObstacleAt;

    private ?float $lastBigAt = null;

    private Randomizer $random;

    /**
     * The slot each pool handed out last. Slots go round, so a pastel that
     * splatted keeps its slot while the splat fades.
     *
     * @var array<string, int>
     */
    private array $lastSlot = ['pastels' => -1, 'crossings' => -1];

    /**
     * @var list<array{event: string, slot: int}>
     */
    private array $events = [];

    public function __construct(
        public readonly float $startedAt,
        ?int $seed = null,
        private readonly bool $spawning = true,
    ) {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->clock = $startedAt;
        $this->walk = new Walk($startedAt, self::Width / 2);
        $this->nextPastelAt = $startedAt + 1.5;
        $this->nextObstacleAt = $startedAt + 4;
    }

    public function over(): bool
    {
        return $this->endedAt !== null;
    }

    /**
     * How far through the speed-up the game is at $time, from 0 to 1.
     */
    public function level(float $time): float
    {
        return min(1.0, max(0.0, ($time - $this->startedAt) / self::RampSeconds));
    }

    public function fallSpeed(float $time): float
    {
        return 160 + (460 - 160) * $this->level($time);
    }

    public function crossingSpeed(float $time): float
    {
        return 110 + (300 - 110) * $this->level($time);
    }

    /**
     * Plays the game forward to $time and says what happened on the way, for
     * the canvas to show. A game that's over stays over.
     *
     * @return list<array{event: string, slot: int}>
     */
    public function advance(float $time): array
    {
        while (! $this->over() && $this->clock < $time) {
            $this->clock = min($time, $this->clock + self::Step);
            $this->spawn($this->clock);
            $this->resolve($this->clock);
        }

        if ($this->jump !== null && $this->clock >= $this->jump->landsAt()) {
            $this->jump = null;
        }

        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * An arrow went down (-1 left, 1 right) or every arrow came up (0): the
     * walk from here. The caller plays the game to $time first.
     */
    public function walkTowards(int $direction, float $time): Walk
    {
        return $this->walk = new Walk($time, $this->walk->x($time), $direction <=> 0);
    }

    /**
     * A finger went down on the jump button: a jump, unless you're already
     * in the air or the game's over.
     */
    public function press(float $time): ?Jump
    {
        if ($this->over() || $this->height($time) > 0 || ($this->jump !== null && $time < $this->jump->landsAt())) {
            return null;
        }

        $this->jumpHeldFrom = $time;

        return $this->jump = Jump::launch($time);
    }

    /**
     * The finger came up: the jump, cut short if it was a quick one, or null
     * when it doesn't change.
     */
    public function release(float $time): ?Jump
    {
        if ($this->jumpHeldFrom === null || $this->jump === null) {
            return null;
        }

        $cut = $this->jump->cut($time, $time - $this->jumpHeldFrom);
        $this->jumpHeldFrom = null;

        if ($cut === $this->jump) {
            return null;
        }

        return $this->jump = $cut;
    }

    public function x(float $time): float
    {
        return $this->walk->x($time);
    }

    public function height(float $time): float
    {
        return $this->jump?->height($time) ?? 0.0;
    }

    /**
     * Drops a pastel at $x. The game drops its own; this is for the first
     * one, and for tests. Null when every slot is taken.
     */
    public function drop(float $x, float $time, bool $big = false): ?Pastel
    {
        $slot = $this->freeSlot('pastels', $this->pastels, self::PastelSlots);

        if ($slot === null) {
            return null;
        }

        if ($big) {
            $this->lastBigAt = $time;
        }

        $this->pastels[$slot] = new Pastel($slot, $x, $time, $this->fallSpeed($time) * ($big ? 0.8 : 1), $big);
        $this->events[] = ['event' => 'dropped', 'slot' => $slot];

        return $this->pastels[$slot];
    }

    /**
     * Sends an obstacle across, from the left (1) or the right (-1).
     */
    public function send(Obstacle $obstacle, int $direction, float $time): ?Crossing
    {
        $slot = $this->freeSlot('crossings', $this->crossings, self::ObstacleSlots);

        if ($slot === null) {
            return null;
        }

        $this->crossings[$slot] = new Crossing($slot, $obstacle, $direction < 0 ? -1 : 1, $time, $this->crossingSpeed($time) * $obstacle->pace());
        $this->events[] = ['event' => 'sent', 'slot' => $slot];

        return $this->crossings[$slot];
    }

    /**
     * Whether a big pastel may drop at $time: half a minute in, and a minute
     * since the last.
     */
    public function bigAllowed(float $time): bool
    {
        return $time - $this->startedAt >= 30 && ($this->lastBigAt === null || $time - $this->lastBigAt >= self::BigEvery);
    }

    private function spawn(float $time): void
    {
        if (! $this->spawning) {
            return;
        }

        if ($time >= $this->nextPastelAt) {
            $this->drop($this->random->getFloat(24, self::Width - 24), $this->nextPastelAt, $this->bigAllowed($time) && $this->random->getFloat(0, 1) < 0.05);
            $this->nextPastelAt += (1.3 - 0.8 * $this->level($time)) * $this->random->getFloat(0.75, 1.25);
        }

        if ($time >= $this->nextObstacleAt) {
            $this->send($this->pickObstacle($time), $this->random->getInt(0, 1) === 0 ? -1 : 1, $this->nextObstacleAt);
            $this->nextObstacleAt += (5.0 - 3.2 * $this->level($time)) * $this->random->getFloat(0.75, 1.25);
        }
    }

    private function pickObstacle(float $time): Obstacle
    {
        $elapsed = $time - $this->startedAt;
        $weights = [];

        foreach (Obstacle::cases() as $obstacle) {
            [$from, $weight] = $obstacle->appearance();

            if ($elapsed >= $from) {
                $weights[$obstacle->value] = $weight;
            }
        }

        $pick = $this->random->getInt(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return Obstacle::from($value);
            }
        }

        return Obstacle::Cyclist;
    }

    private function resolve(float $time): void
    {
        $x = $this->x($time);
        $feet = self::Ground - $this->height($time);
        $left = $x - self::PlayerWidth / 2;
        $right = $x + self::PlayerWidth / 2;
        $top = $feet - self::PlayerHeight;

        foreach ($this->pastels as $slot => $pastel) {
            $y = $pastel->y($time);
            $nearestX = max($left - 4, min($pastel->x, $right + 4));
            $nearestY = max($top - 4, min($y, $feet));

            if (($pastel->x - $nearestX) ** 2 + ($y - $nearestY) ** 2 <= $pastel->radius() ** 2) {
                $this->score += $pastel->points();
                $this->events[] = ['event' => 'caught', 'slot' => $slot];
                unset($this->pastels[$slot]);
            } elseif ($y >= $pastel->landing()) {
                $this->score--;
                $this->events[] = ['event' => 'splatted', 'slot' => $slot];
                unset($this->pastels[$slot]);
            }
        }

        foreach ($this->crossings as $slot => $crossing) {
            $obstacle = $crossing->obstacle;
            $middle = $crossing->x($time);
            $bottom = $crossing->bottom() - 3;

            // Three units of grace all round, so a graze isn't a crash.
            $overlaps = $middle - $obstacle->width() / 2 + 3 < $right
                && $middle + $obstacle->width() / 2 - 3 > $left
                && $bottom - $obstacle->height() + 6 < $feet
                && $bottom > $top;

            if ($overlaps) {
                $this->endedAt = $time;
                $this->struckBy = $crossing;
                $this->events[] = ['event' => 'struck', 'slot' => $slot];

                return;
            }

            if ($time >= $crossing->endsAt()) {
                $this->events[] = ['event' => 'passed', 'slot' => $slot];
                unset($this->crossings[$slot]);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $taken
     */
    private function freeSlot(string $pool, array $taken, int $slots): ?int
    {
        for ($offset = 1; $offset <= $slots; $offset++) {
            $slot = ($this->lastSlot[$pool] + $offset) % $slots;

            if (! isset($taken[$slot])) {
                return $this->lastSlot[$pool] = $slot;
            }
        }

        return null;
    }
}
