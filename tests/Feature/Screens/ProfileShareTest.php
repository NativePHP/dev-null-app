<?php

use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Ada's signed profile, with the link devnull sends for it.
 *
 * @return array<string, mixed>
 */
function shareProfileJson(string $handle = 'ada', string $htmlUrl = 'https://dev-null.sh/@ada'): array
{
    return [
        'data' => userWithCountsJson(['id' => 'u2', 'handle' => $handle, 'name' => 'Ada Lovelace', 'lane' => 1, 'html_url' => $htmlUrl]),
        'is_following' => false,
        'follows_you' => false,
        'is_muting' => false,
        'activity' => ['scale' => 'week', 'buckets' => []],
        'streaks' => ['longest' => 0, 'current' => 0],
        'pinned_commit' => null,
    ];
}

/**
 * The ids of the profile's ⋯ menu, in order, or null when it has none.
 *
 * @return list<string>|null
 */
function shareMenuIds(TestableComponent $screen): ?array
{
    $menu = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action' && ($node['props']['id'] ?? null) === 'more');

    return $menu === null ? null : array_map(fn (array $item): string => $item['props']['id'], $menu['children'] ?? []);
}

it("starts someone else's ⋯ with Share profile, under share on iOS and share-2 on Android", function (string $platform, string $icon) {
    fakeApi(['GET /api/v1/users/ada' => [shareProfileJson()], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    $screen = Native::visit('/log/users/ada', platform: $platform);
    $share = findNode($screen->tree(), fn (array $node): bool => ($node['props']['id'] ?? null) === 'share');

    expect(shareMenuIds($screen))->toBe(['share', 'mute', 'block'])
        ->and($share['props'])->toMatchArray(['label' => 'Share profile', 'icon' => $icon]);
})->with([
    'iOS' => ['ios', 'custom:share'],
    'Android' => ['android', 'custom:share-2'],
]);

it('puts Scheduled commits and Starred in the nav bar of your own profile, then a ⋯ with Share profile and Account', function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [shareProfileJson('local_dev', 'https://dev-null.sh/@local_dev')],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
    ]);

    $screen = Native::visit('/profile', platform: 'ios');
    $actions = array_map(fn (array $node): string => $node['props']['id'], findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action'));
    $share = findNode($screen->tree(), fn (array $node): bool => ($node['props']['id'] ?? null) === 'share');

    expect(array_values(array_diff($actions, shareMenuIds($screen) ?? [])))->toBe(['scheduled', 'starred', 'more'])
        ->and(shareMenuIds($screen))->toBe(['share', 'account'])
        ->and($share['props'])->toMatchArray(['label' => 'Share profile', 'icon' => 'custom:share']);

    $screen->press('shareProfile')
        ->assertNativeCalled('Share.Url', fn (array $params): bool => $params['url'] === 'https://dev-null.sh/@local_dev')
        ->assertNoNavigation();
});

it('shares the profile link devnull sends', function () {
    fakeApi(['GET /api/v1/users/ada' => [shareProfileJson()], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    Native::visit('/log/users/ada')
        ->press('shareProfile')
        ->assertNativeCalled('Share.Url', fn (array $params): bool => $params['url'] === 'https://dev-null.sh/@ada')
        ->assertNoNavigation();
});

it("still offers Share profile, with the web's link, on a profile that blocked you and before devnull answers", function () {
    fakeApi([
        'GET /api/v1/users/grace' => [['message' => "This profile isn't available.", 'code' => 'user_unavailable'], 403],
        'GET /api/v1/users/grace/commits' => [['message' => "This profile isn't available.", 'code' => 'user_unavailable'], 403],
    ]);

    $screen = Native::visit('/log/users/grace');

    expect(shareMenuIds($screen))->toBe(['share']);

    $screen->press('shareProfile')
        ->assertNativeCalled('Share.Url', fn (array $params): bool => $params['url'] === 'http://devnull.test/@grace');

    holdRefreshes();

    expect(shareMenuIds(Native::visit('/log/users/ada')))->toBe(['share']);
});
