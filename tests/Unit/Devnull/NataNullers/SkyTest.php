<?php

use App\Devnull\NataNullers\DayCycle;
use App\Devnull\NataNullers\Sky;

beforeEach(fn () => skipWithoutSkia());

/**
 * The sky as the canvas gets it, for a 360 by 600 sky with the score's
 * bottom edge at $top.
 *
 * @return list<array<string, mixed>>
 */
function skyNodes(float $top = 108): array
{
    return json_decode(json_encode((new Sky(360, 600, top: $top))->nodes()), true);
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @return array<string, mixed>|null
 */
function skyNode(array $nodes, string $id): ?array
{
    foreach ($nodes as $node) {
        if (($node['id'] ?? null) === $id) {
            return $node;
        }

        $found = skyNode($node['children'] ?? [], $id);

        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * @param  list<array<string, mixed>>  $nodes
 */
function skyCount(array $nodes): int
{
    return array_sum(array_map(fn (array $node): int => 1 + skyCount($node['children'] ?? []), $nodes));
}

/**
 * An animation's value $seconds into the canvas's clock, worked out as the
 * canvas does it (see Tween in the plugin's Scene.cpp).
 *
 * @param  array<string, mixed>  $animation
 */
function skyValueAt(array $animation, float $seconds): float
{
    $duration = $animation['duration'];
    $time = match ($animation['repeat']) {
        'once' => min($duration, max(0, $seconds)),
        'mirror' => ($at = fmod($seconds, 2 * $duration)) > $duration ? 2 * $duration - $at : $at,
        default => fmod($seconds, $duration),
    };

    if (($animation['kind'] ?? 'tween') === 'sequence') {
        foreach ($animation['segments'] as $segment) {
            if ($time <= $segment['duration']) {
                return skyValueAt($segment, $time);
            }

            $time -= $segment['duration'];
        }

        return $animation['to'];
    }

    $progress = $time / $duration;
    $progress = match ($animation['easing']) {
        'easeIn' => $progress * $progress,
        'easeOut' => 1 - (1 - $progress) ** 2,
        'easeInOut' => $progress * $progress * (3 - 2 * $progress),
        default => $progress,
    };

    return $animation['from'] + ($animation['to'] - $animation['from']) * $progress;
}

function skyAt(float $fraction): float
{
    return $fraction * DayCycle::Seconds;
}

it('builds the same sky every time, so the canvas never has to take it again', function () {
    expect(json_encode((new Sky(360, 600, top: 108))->nodes()))->toBe(json_encode((new Sky(360, 600, top: 108))->nodes()));
});

it('starts at night with the moon up, and has the sun up by noon', function () {
    $nodes = skyNodes();
    $night = skyNode($nodes, 'sky-night')['animations'];
    $sun = skyNode($nodes, 'sky-sun')['animations'];
    $moon = skyNode($nodes, 'sky-moon')['animations'];

    expect(skyValueAt($night['opacity'], 0))->toBe(1.0)
        ->and(skyValueAt($night['scaleY'], 0))->toBe(1.0)
        ->and(skyValueAt($moon['translateY'], 0))->toBeLessThan(300.0)
        ->and(skyValueAt($sun['translateY'], 0))->toBeGreaterThan(600.0)
        ->and(skyValueAt($night['scaleY'], skyAt(DayCycle::Noon)))->toBe(0.0)
        ->and(skyValueAt($sun['translateY'], skyAt(DayCycle::Noon)))->toBeLessThan(300.0)
        ->and(skyValueAt($moon['translateY'], skyAt(DayCycle::Noon)))->toBeGreaterThan(600.0);
});

it('keeps the sun and the moon below the score all day', function () {
    $nodes = skyNodes(top: 108);

    foreach (['sky-sun' => 26, 'sky-moon' => 19] as $id => $radius) {
        $rise = skyNode($nodes, $id)['animations']['translateY'];
        $highest = min(array_map(fn (int $second): float => skyValueAt($rise, $second), range(0, (int) DayCycle::Seconds)));

        expect($highest - $radius)->toBeGreaterThanOrEqual(108.0);
    }
});

it('squashes the night flat while it is day, rather than fading it to nothing', function () {
    $night = skyNode(skyNodes(), 'sky-night')['animations'];
    $dawn = skyAt(0.215);

    expect(skyValueAt($night['opacity'], skyAt(DayCycle::Noon)))->toBe(1.0)
        ->and(skyValueAt($night['scaleY'], skyAt(DayCycle::Noon)))->toBe(0.0)
        ->and(skyValueAt($night['opacity'], $dawn))->toBeGreaterThan(0.0)->toBeLessThan(1.0)
        ->and(skyValueAt($night['scaleY'], $dawn))->toBe(1.0);
});

it('keeps everything that follows the day to exactly one day', function () {
    $nodes = skyNodes();
    $loops = [
        skyNode($nodes, 'sky-sun')['animations']['translateX'],
        skyNode($nodes, 'sky-sun')['animations']['translateY'],
        skyNode($nodes, 'sky-moon')['animations']['translateX'],
        skyNode($nodes, 'sky-moon')['animations']['translateY'],
        ...array_values(skyNode($nodes, 'sky-night')['animations']),
        ...array_values(skyNode($nodes, 'sky-glow')['animations']),
        ...array_values(skyNode($nodes, 'sky-stars')['animations']),
        ...array_values(skyNode($nodes, 'sky-dusk')['animations']),
        ...array_map(fn (array $light): array => $light['animations']['scaleY'], skyNode($nodes, 'sky-lights')['children']),
    ];

    foreach ($loops as $loop) {
        expect($loop['repeat'])->toBe('loop')
            ->and(array_sum(array_column($loop['segments'], 'duration')))->toEqualWithDelta(DayCycle::Seconds, 0.05);
    }
});

it('has more lights on at night than at noon, each ending the day as it started', function () {
    $lights = array_map(fn (array $light): array => $light['animations']['scaleY'], skyNode(skyNodes(), 'sky-lights')['children']);
    $on = fn (float $seconds): int => count(array_filter($lights, fn (array $light): bool => skyValueAt($light, $seconds) === 1.0));

    expect($on(skyAt(0.05)))->toBeGreaterThan($on(skyAt(DayCycle::Noon)))
        ->and($on(skyAt(0.9)))->toBeGreaterThan($on(skyAt(DayCycle::Noon)));

    foreach ($lights as $light) {
        expect($light['segments'][0]['from'])->toBe(end($light['segments'])['to']);
    }
});

it('stays a few hundred shapes', function () {
    expect(skyCount(skyNodes()))->toBeLessThan(400);
});
