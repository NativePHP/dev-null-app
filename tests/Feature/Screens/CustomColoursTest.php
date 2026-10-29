<?php

use App\Devnull\Lane;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Native\Mobile\UI\Theme;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
});

/**
 * Every node drawn in this colour, as its fill, text or border, in light mode,
 * and what each draws in dark mode.
 *
 * @return list<array{light: string, dark: ?string}>
 */
function drawnIn(TestableComponent $screen, string $colour): array
{
    $found = [];

    foreach (findNodes($screen->tree(), fn (array $node): bool => true) as $node) {
        foreach (['bg_color', 'color', 'border_color'] as $key) {
            if (strtolower((string) ($node['style'][$key] ?? '')) === $colour) {
                $found[] = ['light' => $colour, 'dark' => isset($node['style']["dark_{$key}"]) ? strtolower($node['style']["dark_{$key}"]) : null];
            }
        }
    }

    return $found;
}

it('adds a custom colour to the theme the first time it is drawn, the same in light and dark, with a label that reads on it', function (string $colour, string $lightLabel, string $darkLabel) {
    $token = Lane::token($colour);

    expect(strtolower((string) Theme::get("light.{$token}")))->toBe($colour)
        ->and(strtolower((string) Theme::get("dark.{$token}")))->toBe($colour)
        ->and(strtolower((string) Theme::get("light.on-{$token}")))->toBe($lightLabel)
        ->and(strtolower((string) Theme::get("dark.on-{$token}")))->toBe($darkLabel)
        ->and(Lane::token($colour))->toBe($token);
})->with([
    'a dark colour, in white' => ['#1a2b3c', '#ffffff', '#ffffff'],
    'a pale colour, in ink' => ['#fff3bf', '#121417', '#111112'],
]);

it('draws someone who picked a custom colour on the web in it, on the rail beside their commits', function () {
    signIn();
    serveApi(fn (Request $request) => Http::response(pageJson([
        commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => '#1a2b3c'])]),
    ])));

    $drawn = drawnIn(Native::visit('/log'), '#1a2b3c');

    // Their avatar's ring and the rail under it, the same in dark mode, as on the web.
    expect($drawn)->not->toBeEmpty()
        ->and(array_unique(array_column($drawn, 'dark')))->toBe([null]);
});

it('draws a profile in its custom colour', function () {
    signIn();
    serveApi([
        'GET /api/v1/users/ada' => [['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => '#c0ffee']), 'is_following' => false]],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    $screen = Native::visit('/log/users/ada');
    $ring = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'stack' && (float) ($node['layout']['width'] ?? 0) === 92.0);

    expect(strtolower($ring['style']['bg_color']))->toBe('#c0ffee');
});

it('wears your custom colour on the tab bar and the main buttons', function () {
    signIn(['lane' => '#1a2b3c']);
    serveApi(fn (Request $request) => Http::response(pageJson([])));

    $screen = Native::visit('/log', platform: 'ios')->press('composeInSheet')->input('sheet-body', 'Shipped it');
    $check = sheetButtonCircle($screen, 'sheet-commit');

    expect(strtolower(findNode($screen->tree(), fn (array $node): bool => isset($node['props']['active_color']))['props']['active_color']))->toBe('#1a2b3c')
        ->and(strtolower($check['style']['bg_color']))->toBe('#1a2b3c')
        ->and($check['props'] ?? [])->not->toHaveKey('dark_bg_color')
        ->and(strtolower(findNode($check, fn (array $node): bool => $node['type'] === 'icon')['props']['color']))->toBe('#ffffff');
});
