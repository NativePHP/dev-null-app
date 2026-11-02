<?php

use App\Devnull\Account;
use App\Devnull\Data\Remotes;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Ada's profile, following you or not.
 *
 * @return array<string, mixed>
 */
function adaRemoteProfile(bool $followsYou): array
{
    return [
        'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']),
        'is_following' => false,
        'follows_you' => $followsYou,
        'is_muting' => false,
        'activity' => ['scale' => 'week', 'buckets' => []],
        'streaks' => ['longest' => 0, 'current' => 0],
        'pinned_commit' => null,
    ];
}

it("offers the web's Remote beside Follow to someone who follows you, opening your thread with them", function () {
    fakeApi([
        'GET /api/v1/users/ada' => [adaRemoteProfile(followsYou: true)],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    $screen = finishRefreshes(Native::visit('/log/users/ada'));

    expect(nodeByRef($screen, 'remote')['props']['a11y_label'] ?? null)->toBe('Remote @ada');

    $screen->tap('remote')->assertNavigatedTo('/log/conversations/ada');
});

it('hides Remote where the web would not offer it', function (bool $followsYou, ?Remotes $remotes) {
    app(Account::class)->rememberRemotes($remotes);
    fakeApi([
        'GET /api/v1/users/ada' => [adaRemoteProfile($followsYou)],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    $screen = finishRefreshes(Native::visit('/log/users/ada'));

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'remote'))->toBeNull();
})->with([
    "they don't follow you, while requests are off" => [false, new Remotes],
    'you have Remotes off' => [true, null],
]);

it('offers Remote to anyone while devnull takes requests', function () {
    app(Account::class)->rememberRemotes(new Remotes(requests: true));
    fakeApi([
        'GET /api/v1/users/ada' => [adaRemoteProfile(followsYou: false)],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    expect(nodeByRef(finishRefreshes(Native::visit('/log/users/ada')), 'remote'))->not->toBeEmpty();
});

it('opens Starred from your own profile, and the account beside it', function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [['data' => userWithCountsJson(), 'is_following' => false, 'activity' => ['scale' => 'week', 'buckets' => []], 'streaks' => ['longest' => 0, 'current' => 0], 'pinned_commit' => null]],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
    ]);

    $screen = finishRefreshes(Native::visit('/profile'))->assertTabActive('Profile');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'remote'))->toBeNull();

    $screen->press('openStarred')->assertNavigatedTo('/profile/starred');
});
