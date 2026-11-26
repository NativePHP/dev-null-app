<?php

use App\Devnull\Data\Commit;
use App\Devnull\LastVisit;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();

    // At midday, so the commits and notifications from an hour or two ago are always today's.
    $this->travelTo(now()->setTime(12, 0));
    $this->freezeSecond();
});

/**
 * The marker as drawn, without the ids and hashes that come from where it sits, or what it says to a screen reader.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>
 */
function markerShape(array $node): array
{
    unset($node['id'], $node['_hash'], $node['props']['a11y_label']);

    foreach ($node['children'] ?? [] as $index => $child) {
        $node['children'][$index] = markerShape($child);
    }

    return $node;
}

/**
 * A partial on its own, as a screen's render draws it.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function renderPartial(string $view, array $data): array
{
    $wasActive = NativeTagPrecompiler::setActive(true);

    try {
        $element = NativeElementCollector::capture(fn () => view($view, $data)->render());
    } finally {
        NativeTagPrecompiler::setActive($wasActive);
    }

    return $element->toArray(new CallbackRegistry);
}

it('draws HEAD@{last visit} as the same mono band on the Log and Notifications', function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']);
    app(LastVisit::class)->remember(userJson()['id'], 'following', [Commit::fromArray(commitJson(['created_at' => now()->subHour()->toIso8601String()]))]);
    $readAt = now()->subSecond()->toIso8601ZuluString();

    fakeApi([
        'GET /api/v1/log/following' => [pageJson([
            commitJson(['id' => 'c2', 'author' => $ada, 'created_at' => now()->subMinutes(10)->toIso8601String()]),
            commitJson(['id' => 'c1', 'author' => $ada, 'created_at' => now()->subHour()->toIso8601String()]),
        ])],
        'GET /api/v1/notifications' => [[
            ...pageJson([
                notificationJson('mentioned', ['read_at' => $readAt]),
                notificationJson('followed', ['read_at' => now()->subDay()->toIso8601ZuluString()]),
            ], path: 'http://devnull.test/api/v1/notifications'),
            'filters' => ['type' => 'all'],
            'read_at' => $readAt,
        ]],
    ]);

    $onLog = nodeByRef(Native::visit('/log'), 'last-visit');
    $onNotifications = nodeByRef(Native::visit('/notifications'), 'last-visit');
    $text = findNode($onNotifications, fn (array $node): bool => ($node['props']['text'] ?? null) === 'HEAD@{last visit}');

    expect(markerShape($onLog))->toBe(markerShape($onNotifications))
        ->and($text['props']['font_name'])->toBe('mono-medium')
        ->and(config('native-ui.fonts.mono-medium'))->toBe('GeistMono-Medium')
        // The weight comes from the face, not a class.
        ->and($text['props'])->not->toHaveKey('font_weight')
        // The wash at 40%.
        ->and($onNotifications['children'][0]['style']['bg_color'])->toBe('#66EEF0F2')
        ->and($onNotifications['props']['a11y_label'])->toBe('Notifications above are new since your last visit')
        ->and($onLog['props']['a11y_label'])->toBe('Commits above are new since your last visit');
});

it('runs a lane down the marker over the band and the room around it', function () {
    $marker = renderPartial('native.partials.last-visit-marker', ['lane' => 3]);

    // The band first, so the rail is drawn over it: the segment a row with no node gets, from the marker's top edge
    // to its bottom edge. The room around the band is its margin, not the marker's padding: iOS lays an absolute child
    // out inside its parent's padding, which left the rail 12pt short at each end.
    expect($marker['layout'])->not->toHaveKey('padding')
        ->and($marker['children'][0]['layout']['margin'])->toEqual([12, 0, 12, 0])
        ->and($marker['children'])->toHaveCount(2)
        ->and($marker['children'][0]['style']['bg_color'])->toBe('#66EEF0F2')
        ->and(markerShape($marker['children'][1]))->toBe(markerShape(renderPartial('native.partials.rail', ['previous' => 3])))
        ->and($marker['children'][1]['style']['bg_color'])->toBe(config('native-ui.theme.light.lane-3'));
});

it('draws no rail on the marker without a lane', function () {
    expect(renderPartial('native.partials.last-visit-marker', [])['children'])->toHaveCount(1);
});
