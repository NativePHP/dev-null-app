<?php

use App\Devnull\Hsl;

it('reads the hue, saturation and lightness of a colour in whole steps', function (string $hex, float $hue, float $saturation, float $lightness) {
    $hsl = Hsl::fromHex($hex);

    expect([$hsl->hue, $hsl->saturation, $hsl->lightness])->toBe([$hue, $saturation, $lightness]);
})->with([
    ['#ff0000', 0.0, 100.0, 50.0],
    ['#00ff00', 120.0, 100.0, 50.0],
    ['#0000ff', 240.0, 100.0, 50.0],
    ['#ff00ff', 300.0, 100.0, 50.0],
    ['#1a2b3c', 210.0, 40.0, 17.0],
    ['#ffffff', 0.0, 0.0, 100.0],
    ['#000000', 0.0, 0.0, 0.0],
]);

it('mixes a colour as lowercase #rrggbb', function (float $hue, float $saturation, float $lightness, string $hex) {
    expect((new Hsl($hue, $saturation, $lightness))->hex())->toBe($hex);
})->with([
    [0, 100, 50, '#ff0000'],
    [60, 100, 50, '#ffff00'],
    [120, 60, 50, '#33cc33'],
    [180, 100, 25, '#008080'],
    [240, 100, 50, '#0000ff'],
    [300, 100, 50, '#ff00ff'],
    [210, 40, 17, '#1a2b3d'],
    [0, 0, 100, '#ffffff'],
]);

it('holds each value to its range', function () {
    expect((new Hsl(360, 150, 50))->hex())->toBe('#ff0000')
        ->and((new Hsl(-120, 100, 50))->hex())->toBe('#0000ff')
        ->and((new Hsl(0, -10, 120))->hex())->toBe('#ffffff');
});
