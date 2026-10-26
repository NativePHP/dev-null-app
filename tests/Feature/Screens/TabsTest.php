<?php

use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Each tab as label => [icon, Material variant].
 *
 * @return array<string, array{0: ?string, 1: ?string}>
 */
function tabIcons(TestableComponent $screen): array
{
    return collect(findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_nav_item'))
        ->mapWithKeys(fn (array $tab): array => [$tab['props']['label'] => [$tab['props']['icon'] ?? null, $tab['props']['material_variant'] ?? null]])
        ->all();
}

it('has Log, Notifications, Remotes and Profile tabs with the Lucide icons of the web nav', function (string $platform) {
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    // The same drawings on both platforms. A custom icon has no Material font variant.
    expect(tabIcons(Native::visit('/log', platform: $platform)))->toBe([
        'Log' => ['custom:git-commit-vertical', null],
        'Notifications' => ['custom:bell', null],
        'Remotes' => ['custom:messages-square', null],
        'Profile' => ['custom:user-round', null],
    ]);
})->with(['ios', 'android']);

it('picks out the tab you are on in the colour you chose for your lane', function (int $lane) {
    signIn(['lane' => $lane]);
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    $tabBar = findNode(Native::visit('/log', platform: 'ios')->tree(), fn (array $node): bool => isset($node['props']['active_color']));

    expect(strtolower($tabBar['props']['active_color']))->toBe(strtolower(config("native-ui.theme.light.lane-{$lane}")));
})->with([1, 4]);

it('pushes commits and profiles onto the tab they were opened from, so they slide in', function () {
    // Native tabs only push a URI under the tab's own; anything else swaps the whole screen without a slide.
    Http::fake([
        'devnull.test/api/v1/log/following' => Http::response(pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])])),
        'devnull.test/api/v1/commits/c1' => Http::response(commitPageJson(commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])]))),
        'devnull.test/*' => Http::response(pageJson([])),
    ]);

    Native::visit('/log')
        ->tap('commit-c1')
        ->assertNavigatedTo('/log/commits/c1')
        ->follow()
        ->assertTabActive('Log')
        ->assertNavTitle('Commit')
        ->call('openProfile', 'ada')
        ->assertNavigatedTo('/log/users/ada');

    Native::visit('/log/commits/c1')->call('openCommit', 'c0')->assertNavigatedTo('/log/commits/c0');

    Native::visit('/profile/commits/c1')->call('openProfile', 'ada')->assertNavigatedTo('/profile/users/ada');
    Native::visit('/notifications/commits/c1')->call('openCommit', 'c0')->assertNavigatedTo('/notifications/commits/c0');
    Native::visit('/remotes/commits/c1')->call('openProfile', 'ada')->assertNavigatedTo('/remotes/users/ada');
    Native::visit('/remotes/users/ada')->call('openCommit', 'c0')->assertNavigatedTo('/remotes/commits/c0');
    Native::visit('/profile/starred')->call('openCommit', 'c0')->assertNavigatedTo('/profile/commits/c0');
});

it('stays put when a tap leads to the commit already showing', function () {
    fakeApi([
        'GET /api/v1/commits/c1' => [commitPageJson(commitJson(['id' => 'c1']))],
        'GET /api/v1/commits/c1/replies' => [pageJson([])],
    ]);

    // Pushing a copy would leave the router a screen deeper than the native stack, needing a back tap per copy.
    Native::visit('/log/commits/c1')->call('openCommit', 'c1')->assertNoNavigation();
    Native::visit('/log/commits/c1')->call('openCommit', 'c1/')->assertNoNavigation();
    Native::visit('/log/commits/c1')->call('openCommit', 'c0')->assertNavigatedTo('/log/commits/c0');
});

it('stays put when a tap leads to the profile already showing', function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']);
    $profile = ['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']), 'is_following' => false, 'activity' => ['scale' => 'week', 'buckets' => []], 'streaks' => ['longest' => 0, 'current' => 0], 'pinned_commit' => null];

    fakeApi([
        'GET /api/v1/users/ada' => [$profile],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson([
            'id' => 'c1',
            'author' => $ada,
            'body' => 'Pairing with @ada and @grace',
            'mentions' => [['handle' => 'ada', 'offset' => 13, 'length' => 4], ['handle' => 'grace', 'offset' => 22, 'length' => 6]],
        ])])],
        'GET /api/v1/users/local_dev' => [['data' => userWithCountsJson(commits: 1, followers: 0, following: 0), 'is_following' => false, 'activity' => ['scale' => 'week', 'buckets' => []], 'streaks' => ['longest' => 0, 'current' => 0], 'pinned_commit' => null]],
        'GET /api/v1/users/local_dev/commits' => [pageJson([commitJson(['id' => 'c2'])])],
    ]);

    // Her avatar on her own commit, and a mention of her, on her profile.
    Native::visit('/log/users/ada')->press("openProfile('ada')")->assertNoNavigation();
    Native::visit('/log/users/ada')->press("openProfile('grace')")->assertNavigatedTo('/log/users/grace');

    // Your own avatar on the Profile tab, which is your profile at /profile.
    Native::visit('/profile')->press("openProfile('local_dev')")->assertNoNavigation();
    Native::visit('/profile')->tap('commit-c2')->assertNavigatedTo('/profile/commits/c2');
});
