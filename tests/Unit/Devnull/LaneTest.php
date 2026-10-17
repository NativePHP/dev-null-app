<?php

use App\Devnull\Lane;

it('puts authors in the same lane as the web', function (string $id, int $lane) {
    expect(Lane::for($id))->toBe($lane);
})->with([
    // Lanes computed with laneFor() from resources/js/lib/utils.ts.
    ['9b2f6c1e-1d3a-4e5f-8a7b-0c1d2e3f4a5b', 3],
    ['01J9ZX3F7Q8N2K4M6P0R5T7V9X', 4],
    ['local_dev', 5],
    ['1', 1],
    ['naïve-ü', 0],
    ['😀x', 1],
    [str_repeat('a', 40), 4],
]);

it('reads the lane the API sends', function (mixed $value, ?int $lane) {
    expect(Lane::from($value))->toBe($lane);
})->with([
    [0, 0],
    [5, 5],
    ['3', 3],
    [6, null],
    [-1, null],
    [null, null],
    ['blue', null],
    [2.5, null],
]);

it('names the theme token your colour is drawn in, Commit Blue before devnull says who you are', function (?int $lane, string $token) {
    expect(Lane::token($lane))->toBe($token);
})->with([
    [0, 'lane-0'],
    [5, 'lane-5'],
    [null, 'primary'],
]);

/**
 * WCAG 2's contrast ratio between two #rrggbb colours.
 */
function contrastRatio(string $first, string $second): float
{
    $luminance = function (string $hex): float {
        $channels = array_map(function (string $pair): float {
            $channel = hexdec($pair) / 255;

            return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };

    [$lighter, $darker] = [max($luminance($first), $luminance($second)), min($luminance($first), $luminance($second))];

    return ($lighter + 0.05) / ($darker + 0.05);
}

it('labels a button on each lane in whichever of white and ink reads better', function (string $mode, string $ink) {
    $theme = (require dirname(__DIR__, 3).'/config/native-ui.php')['theme'][$mode];

    foreach (range(0, Lane::Count - 1) as $lane) {
        $fill = $theme["lane-{$lane}"];
        $better = contrastRatio('#ffffff', $fill) >= contrastRatio($theme[$ink], $fill) ? '#ffffff' : $theme[$ink];

        expect($theme["on-lane-{$lane}"])->toBe($better, "lane-{$lane} in {$mode}");
    }
})->with([
    'light, against ink' => ['light', 'ink'],
    'dark, against graphite' => ['dark', 'on-primary'],
]);

it('reads at 4.5:1 or better on every lifted lane in dark mode', function () {
    $theme = (require dirname(__DIR__, 3).'/config/native-ui.php')['theme']['dark'];

    foreach (range(0, Lane::Count - 1) as $lane) {
        expect(contrastRatio($theme["on-lane-{$lane}"], $theme["lane-{$lane}"]))->toBeGreaterThanOrEqual(4.5);
    }
});
