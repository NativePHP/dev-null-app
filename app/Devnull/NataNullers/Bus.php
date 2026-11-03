<?php

namespace App\Devnull\NataNullers;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The tram, which is only there to make you look. It can't touch you: nothing
 * in Simulation knows about it, and you can walk straight through it.
 *
 * It starts parked where it always was, fills up and pulls away across the
 * street. Within two minutes it's back from the side it left by, empty, stops
 * somewhere for a few seconds to fill up again, and carries on the way it was
 * going. So it goes right, comes back heading left, leaves to the left, comes
 * back heading right, and so on.
 *
 * Like the game it runs on the canvas's clock and is asked how things stand on
 * every beat (see advance()). A change starts on the beat that notices it, so
 * the canvas's path and this one begin together.
 */
final class Bus
{
    public const string Boarding = 'boarding';

    public const string Leaving = 'leaving';

    public const string Away = 'away';

    public const string Arriving = 'arriving';

    public const float StartX = 64;

    /**
     * How many windows it has, with a seat at each.
     */
    public const int Seats = 4;

    /**
     * Past the edges of the street, where all of it is out of sight.
     */
    public const float OffLeft = -48;

    public const float OffRight = Simulation::Width + 48;

    /**
     * How close to an edge it stops, so all of it stays on the street.
     */
    public const float StopMargin = 44;

    /**
     * Its average speed along a drive: a good deal slower than anything that
     * can hit you, but quick enough to catch your eye.
     */
    public const float Speed = 80;

    private const float FirstStopSeconds = 5;

    private const float MinimumStopSeconds = 3;

    private const float MaximumStopSeconds = 5;

    private const float MinimumAwaySeconds = 30;

    private const float MaximumAwaySeconds = 110;

    public string $phase = self::Boarding;

    /**
     * The way it's facing: 1 is right, -1 is left.
     */
    public int $direction = 1;

    public ?Drive $drive = null;

    /**
     * The seats taken, in the order they filled.
     *
     * @var list<int>
     */
    public array $riders = [];

    /**
     * The seats still to fill at this stop.
     *
     * @var list<int>
     */
    private array $waiting;

    private float $x = self::StartX;

    private float $stoppedAt;

    private float $stopSeconds = self::FirstStopSeconds;

    private int $stopSeats = self::Seats;

    private float $backAt = 0;

    private Randomizer $random;

    public function __construct(float $startedAt, ?int $seed = null)
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->stoppedAt = $startedAt;
        $this->waiting = $this->random->shuffleArray(range(0, self::Seats - 1));
    }

    public function x(float $time): float
    {
        return $this->drive?->x($time) ?? $this->x;
    }

    /**
     * Moves on to $now, and says what changed, if anything: a passenger
     * "boarded" (the slot is their seat), it "pulled-away" from a stop, or
     * it's "arriving", empty, from off the street.
     *
     * @return array{event: string, slot: int}|null
     */
    public function advance(float $now): ?array
    {
        return match ($this->phase) {
            self::Boarding => $this->board($now),
            self::Leaving => $this->leave($now),
            self::Away => $this->wait($now),
            self::Arriving => $this->arrive($now),
        };
    }

    /**
     * @return array{event: string, slot: int}|null
     */
    private function board(float $now): ?array
    {
        if ($this->waiting !== []) {
            $next = $this->stopSeats - count($this->waiting) + 1;

            if ($now < $this->stoppedAt + $this->stopSeconds * $next / ($this->stopSeats + 1)) {
                return null;
            }

            $seat = array_shift($this->waiting);
            $this->riders[] = $seat;

            return ['event' => 'boarded', 'slot' => $seat];
        }

        if ($now < $this->stoppedAt + $this->stopSeconds) {
            return null;
        }

        $this->phase = self::Leaving;
        $this->drive = $this->driving($now, $this->x, $this->direction > 0 ? self::OffRight : self::OffLeft, 'easeIn');

        return ['event' => 'pulled-away', 'slot' => -1];
    }

    /**
     * @return null
     */
    private function leave(float $now): ?array
    {
        if ($now >= $this->drive->endsAt()) {
            $this->x = $this->drive->to;
            $this->drive = null;
            $this->phase = self::Away;
            $this->backAt = $now + $this->random->getFloat(self::MinimumAwaySeconds, self::MaximumAwaySeconds);
        }

        return null;
    }

    /**
     * @return array{event: string, slot: int}|null
     */
    private function wait(float $now): ?array
    {
        if ($now < $this->backAt) {
            return null;
        }

        $this->direction = -$this->direction;
        $this->riders = [];
        $this->phase = self::Arriving;
        $this->drive = $this->driving($now, $this->x, $this->random->getFloat(self::StopMargin, Simulation::Width - self::StopMargin), 'easeOut');

        return ['event' => 'arriving', 'slot' => -1];
    }

    /**
     * @return null
     */
    private function arrive(float $now): ?array
    {
        if ($now >= $this->drive->endsAt()) {
            $this->x = $this->drive->to;
            $this->drive = null;
            $this->phase = self::Boarding;
            $this->stoppedAt = $now;
            $this->stopSeconds = $this->random->getFloat(self::MinimumStopSeconds, self::MaximumStopSeconds);
            $this->stopSeats = $this->random->getInt(2, self::Seats);
            $this->waiting = array_slice($this->random->shuffleArray(range(0, self::Seats - 1)), 0, $this->stopSeats);
        }

        return null;
    }

    private function driving(float $now, float $from, float $to, string $easing): Drive
    {
        return new Drive($now, $from, $to, abs($to - $from) / self::Speed, $easing);
    }
}
