<?php

use App\Devnull\ShownTabs;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A profile as devnull sends it to a signed request.
 *
 * @return array<string, mixed>
 */
function shownTabsProfileJson(string $username): array
{
    return [
        'data' => userWithCountsJson(['handle' => $username, 'id' => $username === 'local_dev' ? '0199e5c4-0000-7000-8000-000000000001' : 'u2']),
        'is_following' => false,
        'activity' => ['scale' => 'week', 'buckets' => []],
        'streaks' => ['longest' => 0, 'current' => 0],
        'pinned_commit' => null,
    ];
}

it('opens the Log on the tab you last had open, after switching away from it at the bottom', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    tapLogTab(Native::visit('/log')->assertSet('log', 'following'), 'latest')->assertSet('log', 'latest');

    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    Native::visit('/log')->assertSet('log', 'latest');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://devnull.test/api/v1/log/latest'));
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://devnull.test/api/v1/log/following'));
});

it('opens Notifications on the filter you last had open', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => [], 'next_cursor' => null, 'read_at' => null, 'filters' => ['type' => 'all']])]);

    tapFilter(Native::visit('/notifications'), 'mentions')->assertSet('type', 'mentions');

    Native::visit('/notifications')->assertSet('type', 'mentions');
});

it("opens your own profile on the tab you last had open, but someone else's on their commits", function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [shownTabsProfileJson('local_dev')],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
        'GET /api/v1/users/ada' => [shownTabsProfileJson('ada')],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    tapProfileTab(Native::visit('/profile'), 'replies')->assertSet('tab', 'replies');

    Native::visit('/profile')->assertSet('tab', 'replies');

    // Someone else's profile opens on their commits, and switching it leaves yours alone.
    $ada = tapProfileTab(Native::visit('/log/users/ada')->assertSet('tab', 'commits'), 'replies');
    tapProfileTab($ada, 'commits');

    expect(app(ShownTabs::class)->get('0199e5c4-0000-7000-8000-000000000001', 'profile', ['commits', 'replies'], 'commits'))->toBe('replies');
});

it('keeps each person their own tabs, and opens on the first tab when what was kept is no tab', function () {
    $tabs = app(ShownTabs::class);

    $tabs->remember('u1', 'log', 'trending');
    $tabs->remember('u2', 'log', 'nonsense');

    expect($tabs->get('u1', 'log', ['following', 'latest', 'trending'], 'following'))->toBe('trending')
        ->and($tabs->get('u2', 'log', ['following', 'latest', 'trending'], 'following'))->toBe('following')
        ->and($tabs->get('u3', 'log', ['following', 'latest', 'trending'], 'following'))->toBe('following')
        ->and($tabs->get(null, 'log', ['following', 'latest', 'trending'], 'following'))->toBe('following');
});
