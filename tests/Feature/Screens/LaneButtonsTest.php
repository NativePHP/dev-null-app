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
        'tint_color' => config("native-ui.theme.light.lane-{$lane}"),
        'dark_tint_color' => config("native-ui.theme.dark.lane-{$lane}"),
        'label_color' => config("native-ui.theme.light.on-lane-{$lane}"),
        'dark_label_color' => config("native-ui.theme.dark.on-lane-{$lane}"),
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
        commitJson(['id' => 'c1', 'body' => 'Required reading.', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])]),
    ])));

    return Native::visit('/log', platform: 'ios');
}

dataset('main buttons', [
    'Send report' => [fn (): TestableComponent => laneLog()->tap('report-c1'), 'send-report'],
    'Follow, on a suggestion in an empty log' => [function (): TestableComponent {
        serveApi(fn (Request $request) => Http::response([...pageJson([]), 'suggestions' => [commitJson(['id' => 's1', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])])]]));

        return Native::visit('/log');
    }, 'follow-ada'],
    'Follow, on a profile' => [function (): TestableComponent {
        serveApi([
            'GET /api/v1/users/ada' => [['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']), 'is_following' => false]],
            'GET /api/v1/users/ada/commits' => [pageJson([])],
        ]);

        return Native::visit('/log/users/ada');
    }, 'follow'],
    'Follow, in a profile\'s followers' => [function (): TestableComponent {
        serveApi(['GET /api/v1/users/ada/followers' => [pageJson([userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace', 'is_followed' => false, 'follows_you' => false])])]]);

        return Native::visit('/log/users/ada/followers');
    }, 'follow-grace'],
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

it("fills the compose sheet's check with your lane, with a check that reads on it", function (Closure $open, int $lane) {
    signIn(['lane' => $lane]);
    $check = sheetButtonCircle($open()->input('sheet-body', 'Shipped it'), 'sheet-commit');
    $icon = findNode($check, fn (array $node): bool => $node['type'] === 'icon')['props'];

    expect(array_map(strtolower(...), [$check['style']['bg_color'], $check['props']['dark_bg_color'], $icon['color'], $icon['dark_color']]))->toBe(array_map(strtolower(...), [
        config("native-ui.theme.light.lane-{$lane}"),
        config("native-ui.theme.dark.lane-{$lane}"),
        config("native-ui.theme.light.on-lane-{$lane}"),
        config("native-ui.theme.dark.on-lane-{$lane}"),
    ]));
})->with([
    'composing' => fn (): TestableComponent => laneLog()->press('composeInSheet'),
    'quoting a commit' => fn (): TestableComponent => laneLog()->tap('quote-c1'),
])->with([
    'green, checked in ink' => 1,
    'rose, checked in white' => 4,
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

it('draws the underline under your profile\'s tabs in your lane too', function () {
    signIn(['lane' => 5]);
    serveApi([
        'GET /api/v1/users/local_dev' => [['data' => userWithCountsJson(['lane' => 5]), 'is_following' => false]],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
    ]);

    $pager = nodeByRef(Native::visit('/profile'), 'profile-pager');

    expect(strtolower($pager['props']['tab_indicator_color']))->toBe(strtolower(config('native-ui.theme.light.lane-5')))
        ->and(strtolower($pager['props']['dark_tab_indicator_color']))->toBe(strtolower(config('native-ui.theme.dark.lane-5')));
});

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
            ->not->toHaveKeys(['tint_color', 'label_color']);
    }
});

it('makes the full-width buttons large, and leaves the ones in a row their size', function () {
    signIn(['lane' => 4])->respondTo('NativephpSocialAuth.AppleSignIn', ['pending' => true]);
    serveApi(['GET /api/v1/me' => [currentUserJson(['lane' => 4], ['apple_linked' => false])]]);

    $account = Native::visit('/profile/device', platform: 'ios');

    foreach (['edit-on-github', 'sync-github', 'save-lane', 'link-apple', 'devices', 'sign-out', 'delete-account'] as $ref) {
        expect(buttonProps($account, $ref)['size'] ?? null)->toBe('lg', $ref);
    }

    $report = laneLog()->tap('report-c1');

    expect(buttonProps($report, 'send-report')['size'] ?? null)->toBe('lg');

    $report->selectRadio('report-reason', 'spam')->tap('send-report');

    expect(buttonProps($report, 'report-done')['size'] ?? null)->toBe('lg');
});

it('makes Unlink Apple ID large too', function () {
    signIn()->respondTo('NativephpSocialAuth.AppleSignIn', ['pending' => true]);
    serveApi(['GET /api/v1/me' => [currentUserJson(extra: ['apple_linked' => true])]]);

    expect(buttonProps(Native::visit('/profile/device', platform: 'ios'), 'unlink-apple')['size'] ?? null)->toBe('lg');
});
