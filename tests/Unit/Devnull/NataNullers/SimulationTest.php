<?php

use App\Devnull\NataNullers\Jump;
use App\Devnull\NataNullers\Obstacle;
use App\Devnull\NataNullers\Simulation;

/**
 * A game with nothing dropping or crossing but what the test sends.
 */
function quietGame(): Simulation
{
    return new Simulation(100.0, seed: 1, spawning: false);
}

/**
 * @param  list<array{event: string, slot: int}>  $events
 * @return list<string>
 */
function eventNames(array $events): array
{
    return array_column($events, 'event');
}

it('scores a point for a pastel that falls on you', function () {
    $game = quietGame();
    $game->drop(Simulation::Width / 2, 100);

    expect(eventNames($game->advance(105)))->toContain('caught')
        ->and($game->score)->toBe(1)
        ->and($game->pastels)->toBe([]);
});

it('takes a point for a pastel that splats on the pavement', function () {
    $game = quietGame();
    $game->drop(30, 100);

    expect(eventNames($game->advance(105)))->toContain('splatted')
        ->and($game->score)->toBe(-1);
});

it('scores ten for a big pastel', function () {
    $game = quietGame();
    $game->drop(Simulation::Width / 2, 100, big: true);
    $game->advance(105);

    expect($game->score)->toBe(10);
});

it('drops a big pastel at most once a minute', function () {
    $game = new Simulation(0.0, seed: 7);
    $bigAt = [];

    for ($time = 0.05; $time < 600; $time += 0.05) {
        $game->advance($time);

        foreach ($game->pastels as $pastel) {
            if ($pastel->big) {
                $bigAt[(string) $pastel->droppedAt] = $pastel->droppedAt;
            }
        }

        // Stay out of the way so the game runs its ten minutes.
        $game->crossings = [];
    }

    $bigAt = array_values($bigAt);

    expect($bigAt)->not->toBeEmpty();

    for ($index = 1; $index < count($bigAt); $index++) {
        expect($bigAt[$index] - $bigAt[$index - 1])->toBeGreaterThanOrEqual(Simulation::BigEvery);
    }
});

it('ends the game when a cyclist rides into you', function () {
    $game = quietGame();
    $game->send(Obstacle::Cyclist, 1, 100);

    expect(eventNames($game->advance(110)))->toContain('struck')
        ->and($game->over())->toBeTrue()
        ->and($game->struckBy->obstacle)->toBe(Obstacle::Cyclist)
        ->and($game->advance(120))->toBe([]);
});

it('lets a gull fly over you standing', function () {
    $game = quietGame();
    $game->send(Obstacle::Gull, -1, 100);

    expect(eventNames($game->advance(110)))->toBe(['sent', 'passed'])
        ->and($game->over())->toBeFalse();
});

it('clears a cyclist with a full jump at the right moment', function () {
    $game = quietGame();
    $cyclist = $game->send(Obstacle::Cyclist, 1, 100);

    // Jump so the top of the arc comes as the cyclist reaches you.
    $meets = 100 + (Simulation::Width / 2 - $cyclist->from()) / $cyclist->speed;
    $takeOff = $meets - sqrt(2 * Jump::FullHeight / Jump::Gravity);
    $game->advance($takeOff);
    $game->press($takeOff);
    $game->advance($takeOff + Jump::HoldCap);
    $game->release($takeOff + Jump::HoldCap);

    expect(eventNames($game->advance(110)))->toContain('passed')
        ->and($game->over())->toBeFalse();
});

it('hits a gull when you jump into it', function () {
    $game = quietGame();
    $gull = $game->send(Obstacle::Gull, 1, 100);

    $meets = 100 + (Simulation::Width / 2 - $gull->from()) / $gull->speed;
    $takeOff = $meets - 0.2;
    $game->advance($takeOff);
    $game->press($takeOff);

    expect(eventNames($game->advance(110)))->toContain('struck');
});

it('jumps lower for a quick tap than a long press', function () {
    $tap = quietGame();
    $tap->press(100);
    $tap->release(100.05);

    $hold = quietGame();
    $hold->press(100);
    $hold->release(100.5);

    $highest = fn (Simulation $game): float => max(array_map(fn (int $step): float => $game->height(100 + $step / 100), range(0, 100)));

    expect($highest($tap))->toBeLessThan(Jump::FullHeight * 0.7)
        ->and($highest($hold))->toEqualWithDelta(Jump::FullHeight, 0.5);
});

it('does not jump again in the air', function () {
    $game = quietGame();
    $game->press(100);

    expect($game->press(100.2))->toBeNull()
        ->and($game->press(101.5))->not->toBeNull();
});

it('walks while an arrow is held and stops at the edge', function () {
    $game = quietGame();
    $game->walkTowards(-1, 100);

    expect($game->x(100.5))->toBeLessThan(Simulation::Width / 2)
        ->and($game->x(110))->toBe(22.0);

    $game->walkTowards(0, 100.2);

    expect($game->x(105))->toBe($game->x(100.2));
});

it('speeds up for five minutes and then stays that fast', function () {
    $game = quietGame();

    expect($game->fallSpeed(100))->toBeLessThan($game->fallSpeed(250))
        ->and($game->fallSpeed(250))->toBeLessThan($game->fallSpeed(400))
        ->and($game->fallSpeed(400))->toBe($game->fallSpeed(1000))
        ->and($game->crossingSpeed(400))->toBe($game->crossingSpeed(1000));
});

it('catches a pastel at full speed even when asked rarely', function () {
    $game = new Simulation(0.0, seed: 1, spawning: false);
    $game->advance(400);
    $game->drop(Simulation::Width / 2, 400);

    // One ask, long after it fell: the steps in between still see it pass through you.
    $game->advance(410);

    expect($game->score)->toBe(1);
});
