<?php

use App\Devnull\Account;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
});

afterEach(fn () => Platform::set(null));

/**
 * The colours a button in a lane is sent with: the lane's light and dark
 * shades, and its label's.
 *
 * @return array<string, string>
 */
function laneButtonColours(int $lane): array
{
    return [
        'variant' => "lane-{$lane}",
        'tint' => config("native-ui.theme.light.lane-{$lane}"),
        'dark_tint' => config("native-ui.theme.dark.lane-{$lane}"),
        'on_tint' => config("native-ui.theme.light.on-lane-{$lane}"),
        'dark_on_tint' => config("native-ui.theme.dark.on-lane-{$lane}"),
    ];
}

/**
 * The props of the button with this ref, lower-cased for comparing colours.
 *
 * @return array<string, mixed>
 */
function buttonProps(TestableComponent $screen, string $ref): array
{
    return array_map(fn (mixed $value): mixed => is_string($value) ? strtolower($value) : $value, nodeByRef($screen, $ref)['props']);
}

/**
 * A log with one commit of Ada's, which you can quote and report.
 */
function laneLog(): TestableComponent
{
    serveApi(fn (Request $request) => Http::response(pageJson([
        commitJson(['id' => 'c1', 'body' => 'Required reading.', 'author' => userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada'])]),
    ])));

    return Native::visit('/log', platform: 'ios');
}

dataset('main buttons', [
    'Commit, in the composer' => [fn (): TestableComponent => laneLog()->pressUp('composer-field'), 'commit'],
    'Commit, quoting a commit' => [fn (): TestableComponent => laneLog()->tap('quote-c1'), 'send-quote'],
    'Send report' => [fn (): TestableComponent => laneLog()->tap('more-c1')->tap('sheet-report'), 'send-report'],
    'Follow, on a suggestion in an empty log' => [function (): TestableComponent {
        serveApi(fn (Request $request) => Http::response([...pageJson([]), 'suggestions' => [commitJson(['id' => 's1', 'author' => userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada'])])]]));

        return Native::visit('/log');
    }, 'follow-ada'],
    'Follow, back from a follow' => [function (): TestableComponent {
        $follow = notificationJson('followed', ['actor' => userJson(['id' => 'u-linus', 'username' => 'linus', 'name' => 'Linus', 'is_followed' => false])]);
        serveApi(['GET /api/v1/notifications' => [[
            ...pageJson([$follow], path: 'http://devnull.test/api/v1/notifications'),
            'filters' => ['type' => 'all'],
            'read_at' => now()->toIso8601ZuluString(),
        ]]]);

        return Native::visit('/notifications');
    }, 'follow-back-linus'],
    'Follow, on a profile' => [function (): TestableComponent {
        serveApi([
            'GET /api/v1/users/ada' => [['data' => userWithCountsJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada']), 'is_following' => false]],
            'GET /api/v1/users/ada/commits' => [pageJson([])],
        ]);

        return Native::visit('/log/users/ada');
    }, 'follow'],
    'Reply' => [function (): TestableComponent {
        serveApi([
            'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2']))],
            'GET /api/v1/commits/c2/replies' => [pageJson([])],
        ]);

        return Native::visit('/log/commits/c2');
    }, 'send-reply'],
    'Edit on GitHub, on the Account screen' => [function (): TestableComponent {
        serveApi(['GET /api/v1/me' => [currentUserJson(['lane' => app(Account::class)->user()?->lane()])]]);

        return Native::visit('/profile/device');
    }, 'edit-on-github'],
]);

it('draws the main buttons in your lane, with a label that reads on it', function (Closure $screen, string $ref, int $lane) {
    signIn(['lane' => $lane]);

    expect(buttonProps($screen(), $ref))->toMatchArray(array_map(strtolower(...), laneButtonColours($lane)));
})->with('main buttons')->with([
    'green, labelled in ink' => 1,
    'rose, labelled in white' => 4,
]);

it('draws the tab underline in your lane, in its light and dark shades', function (string $path, int $lane) {
    signIn(['lane' => $lane]);
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    $pager = findNode(Native::visit($path)->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'pager');

    expect(strtolower($pager['props']['tab_indicator_color']))->toBe(strtolower(config("native-ui.theme.light.lane-{$lane}")))
        ->and(strtolower($pager['props']['dark_tab_indicator_color']))->toBe(strtolower(config("native-ui.theme.dark.lane-{$lane}")))
        // Only the underline: the labels stay ink.
        ->and($pager['props']['tab_active_color'])->toBe(config('native-ui.theme.light.ink'));
})->with(['/log', '/notifications'])->with([2, 5]);

it('draws Save color in the colour you picked, before you save it', function () {
    signIn(['lane' => 3]);
    serveApi(['GET /api/v1/me' => [currentUserJson(['lane' => 3, 'preferred_lane' => 3, 'automatic_lane' => 3])]]);

    $screen = Native::visit('/profile/device');

    expect(buttonProps($screen, 'save-lane')['variant'])->toBe('lane-3');

    $screen->tap('lane-1');

    expect(buttonProps($screen, 'save-lane'))->toMatchArray(array_map(strtolower(...), laneButtonColours(1)))
        // Everything else waits for the save.
        ->and(buttonProps($screen, 'edit-on-github')['variant'])->toBe('lane-3');
});

it('leaves the other buttons in their own colours', function () {
    signIn(['lane' => 4])->respondTo('NativephpSocialAuth.AppleSignIn', ['pending' => true]);
    serveApi(['GET /api/v1/me' => [currentUserJson(['lane' => 4], ['apple_linked' => false])]]);

    $screen = Native::visit('/profile/device', platform: 'ios');

    foreach (['sync-github' => 'secondary', 'devices' => 'secondary', 'sign-out' => 'secondary', 'delete-account' => 'destructive', 'link-apple' => 'accent'] as $ref => $variant) {
        expect(buttonProps($screen, $ref))
            ->toMatchArray(['variant' => $variant])
            ->not->toHaveKeys(['tint', 'on_tint']);
    }
});

it('makes the full-width buttons large, and leaves the ones in a row their size', function () {
    signIn(['lane' => 4])->respondTo('NativephpSocialAuth.AppleSignIn', ['pending' => true]);
    serveApi(['GET /api/v1/me' => [currentUserJson(['lane' => 4], ['apple_linked' => false])]]);

    $account = Native::visit('/profile/device', platform: 'ios');

    foreach (['edit-on-github', 'sync-github', 'save-lane', 'link-apple', 'devices', 'sign-out', 'delete-account'] as $ref) {
        expect(buttonProps($account, $ref)['size'] ?? null)->toBe('lg', $ref);
    }

    $report = laneLog()->tap('more-c1')->tap('sheet-report');

    expect(buttonProps($report, 'send-report')['size'] ?? null)->toBe('lg');

    $report->selectRadio('report-reason', 'spam')->tap('send-report');

    expect(buttonProps($report, 'report-done')['size'] ?? null)->toBe('lg');

    expect(buttonProps(laneLog()->pressUp('composer-field'), 'commit'))->not->toHaveKey('size')
        ->and(buttonProps(laneLog()->tap('quote-c1'), 'send-quote'))->not->toHaveKey('size');
});

it('makes Unlink Apple ID large too', function () {
    signIn()->respondTo('NativephpSocialAuth.AppleSignIn', ['pending' => true]);
    serveApi(['GET /api/v1/me' => [currentUserJson(extra: ['apple_linked' => true])]]);

    expect(buttonProps(Native::visit('/profile/device', platform: 'ios'), 'unlink-apple')['size'] ?? null)->toBe('lg');
});
