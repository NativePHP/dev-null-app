<?php

use App\Devnull\NataNullers\Bus;
use App\Devnull\NataNullers\Simulation;

/**
 * Runs the bus on 20 beats a second until $until says stop, and returns every
 * change it reported on the way.
 *
 * @param  callable(Bus, float): bool  $until
 * @return list<array{event: string, slot: int, at: float}>
 */
function runBus(Bus $bus, float &$now, callable $until): array
{
    $changes = [];

    for ($beats = 0; $beats < 20 * 600 && ! $until($bus, $now); $beats++) {
        $now += 0.05;

        if (($change = $bus->advance($now)) !== null) {
            $changes[] = [...$change, 'at' => $now];
        }
    }

    return $changes;
}

function busIn(string $phase): callable
{
    return fn (Bus $bus): bool => $bus->phase === $phase;
}

it('starts where it was parked, fills every seat one at a time, then pulls away to the right', function () {
    $now = 100.0;
    $bus = new Bus($now, seed: 3);

    expect($bus->x($now))->toBe(Bus::StartX)
        ->and($bus->riders)->toBe([])
        ->and($bus->advance($now))->toBeNull();

    $changes = runBus($bus, $now, busIn(Bus::Leaving));
    $boarded = array_filter($changes, fn (array $change): bool => $change['event'] === 'boarded');

    expect($boarded)->toHaveCount(Bus::Seats)
        ->and(collect($boarded)->pluck('slot')->sort()->values()->all())->toBe(range(0, Bus::Seats - 1))
        ->and(end($changes)['event'])->toBe('pulled-away')
        ->and($now - 100)->toBeGreaterThan(4.9)->toBeLessThan(5.2)
        ->and($bus->drive->from)->toBe(Bus::StartX)
        ->and($bus->drive->to)->toBe(Bus::OffRight)
        ->and($bus->drive->easing)->toBe('easeIn');
});

it('comes back empty from the side it left by, within two minutes', function (int $seed) {
    $now = 100.0;
    $bus = new Bus($now, seed: $seed);

    runBus($bus, $now, busIn(Bus::Away));
    $left = $now;

    expect($bus->x($now))->toBe(Bus::OffRight)
        ->and($bus->riders)->toHaveCount(Bus::Seats);

    $changes = runBus($bus, $now, busIn(Bus::Arriving));

    expect(array_column($changes, 'event'))->toBe(['arriving'])
        ->and($now - $left)->toBeLessThan(120.0)
        ->and($bus->direction)->toBe(-1)
        ->and($bus->riders)->toBe([])
        ->and($bus->drive->from)->toBe(Bus::OffRight)
        ->and($bus->drive->easing)->toBe('easeOut');
})->with([1, 2, 3, 4, 5, 6, 7, 8]);

it('stops with all of it on the street, boards a few, and carries on the way it was going', function (int $seed) {
    $now = 100.0;
    $bus = new Bus($now, seed: $seed);

    runBus($bus, $now, busIn(Bus::Arriving));
    runBus($bus, $now, busIn(Bus::Boarding));
    $stopped = $now;
    $x = $bus->x($now);

    expect($x)->toBeGreaterThanOrEqual(Bus::StopMargin)->toBeLessThanOrEqual(Simulation::Width - Bus::StopMargin);

    $changes = runBus($bus, $now, busIn(Bus::Leaving));

    expect($now - $stopped)->toBeGreaterThanOrEqual(3.0)->toBeLessThan(5.2)
        ->and(count($bus->riders))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(Bus::Seats)
        ->and(array_count_values(array_column($changes, 'event'))['boarded'])->toBe(count($bus->riders))
        ->and(array_unique($bus->riders))->toBe($bus->riders)
        ->and($bus->direction)->toBe(-1)
        ->and($bus->drive->from)->toBe($x)
        ->and($bus->drive->to)->toBe(Bus::OffLeft);
})->with([1, 2, 3, 4, 5, 6, 7, 8]);

it('keeps going back and forth, returning from whichever side it left by', function () {
    $now = 100.0;
    $bus = new Bus($now, seed: 9);
    $directions = [];

    foreach (range(1, 4) as $trip) {
        runBus($bus, $now, busIn(Bus::Away));
        $off = $bus->x($now);

        runBus($bus, $now, busIn(Bus::Arriving));

        expect($bus->drive->from)->toBe($off)
            ->and($bus->riders)->toBe([]);

        $directions[] = $bus->direction;
        runBus($bus, $now, busIn(Bus::Leaving));
    }

    expect($directions)->toBe([-1, 1, -1, 1]);
});

it('gathers speed from a stop and slows into one', function () {
    $now = 100.0;
    $bus = new Bus($now, seed: 3);

    runBus($bus, $now, busIn(Bus::Leaving));
    $drive = $bus->drive;
    $half = $drive->startedAt + $drive->seconds / 2;

    expect($bus->x($drive->startedAt))->toBe($drive->from)
        ->and($bus->x($half) - $drive->from)->toBeLessThan(($drive->to - $drive->from) / 2)
        ->and($bus->x($drive->endsAt()))->toEqualWithDelta($drive->to, 0.001);

    runBus($bus, $now, busIn(Bus::Arriving));
    $drive = $bus->drive;
    $half = $drive->startedAt + $drive->seconds / 2;

    expect(abs($bus->x($half) - $drive->from))->toBeGreaterThan(abs($drive->to - $drive->from) / 2);
});
