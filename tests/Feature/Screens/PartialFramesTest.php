<?php

use App\NativeComponents\Organization;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

/*
 * The timeline screens send only what changed: a node the phone already holds goes as a reuse marker. Each test
 * walks a screen through what used to draw it blank and expects the phone to hold what a full frame would send.
 */

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();

    // Frozen, so a relative time can't move between the phone's frame and the full one it's checked against.
    $this->freezeSecond();
});

/**
 * A page of the log's commits, newest first, from "{$prefix}{$first}" on.
 *
 * @return list<array<string, mixed>>
 */
function partialFrameCommits(string $prefix, int $first, int $count): array
{
    return array_map(fn (int $n): array => commitJson([
        'id' => "{$prefix}{$n}",
        'body' => "Commit {$prefix}{$n}",
        // Ada's, but c7 is yours, so you can delete it.
        'author' => "{$prefix}{$n}" === 'c7' ? userJson() : userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']),
        // One with a photo, for the image viewer.
        ...($n === 5 ? ['images' => [['id' => "{$prefix}{$n}-i1", 'url' => "https://images.example.com/{$prefix}{$n}.jpg", 'width' => 1200, 'height' => 900, 'description' => null]]] : []),
    ]), range($first, $first + $count - 1));
}

it('keeps the Log on the phone in step through refreshes, pages, threads, swipes and deletes', function () {
    sendPartialFrames();
    holdRefreshes();

    $state = ['new' => [], 'deleted' => []];
    serveApi(function (Request $request) use (&$state) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $served = fn (array $commits): array => array_values(array_filter($commits, fn (array $commit): bool => ! in_array($commit['id'], $state['deleted'], true)));

        return match (true) {
            $path === '/api/v1/log/following' && isset($query['cursor']) => Http::response(pageJson($served(partialFrameCommits('c', 20, 20)))),
            // As devnull answers ?since=: the first page, and how many on it are newer than the commit named.
            $path === '/api/v1/log/following' => Http::response([
                ...pageJson($first = $served([...$state['new'], ...partialFrameCommits('c', 0, 20)]), 'page2'),
                'new_commits_count' => isset($query['since']) ? max(0, (int) array_search($query['since'], array_column($first, 'id'), true)) : 0,
            ]),
            $path === '/api/v1/log/latest' => Http::response(pageJson(partialFrameCommits('l', 0, 20))),
            $path === '/api/v1/commits/c3/replies' => Http::response(pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c3', 'body' => 'A reply'])])),
            $request->method() === 'POST' && $path === '/api/v1/commits/c4/star' => Http::response(['data' => [...partialFrameCommits('c', 4, 1)[0], 'starred' => true, 'stars_count' => 1]]),
            $request->method() === 'DELETE' && str_starts_with($path, '/api/v1/commits/') => (function () use (&$state, $path) {
                $state['deleted'][] = substr($path, strlen('/api/v1/commits/'));

                return Http::response(null, 204);
            })(),
            $path === '/api/v1/me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
            default => Http::response(['message' => "No fake for {$path}."], 404),
        };
    });

    $screen = finishRefreshes(Native::visit('/log'));
    expectPhoneInStep($screen);

    // A star sends the starred row's changes, and every other row as a marker.
    finishRefreshes($screen->call('toggleStar', 'c4'));
    expectPhoneInStep($screen);
    expect(nodesSentInFull($screen))->toBeLessThan(40);

    $steps = [
        'open a thread' => fn () => finishRefreshes($screen->call('toggleReplies', 'c3')),
        'next page' => fn () => finishRefreshes($screen->call('loadMore')),
        'pull to refresh with new commits on top' => function () use ($screen, &$state) {
            $state['new'] = partialFrameCommits('n', 0, 3);
            finishRefreshes($screen->call('load'));
        },
        'back to the top in a new list' => fn () => $screen->call('backToTop', 0),
        'delete from the middle' => fn () => finishRefreshes($screen->call('deleteCommit', 'c7')),
        'new commits in the pill' => function () use ($screen, &$state) {
            $state['new'] = [...partialFrameCommits('m', 0, 2), ...$state['new']];
            finishRefreshes($screen->call('checkForNewCommits'));
        },
        'tap the pill' => fn () => finishRefreshes($screen->call('showNewCommits')),
        'close the thread' => fn () => finishRefreshes($screen->call('toggleReplies', 'c3')),
        'open the image viewer' => fn () => $screen->call('openImage', 'c5', 0)->assertSet('viewerOpen', true),
        'close it' => fn () => $screen->call('closeImageViewer'),
        'swipe to Latest' => fn () => finishRefreshes($screen->changeTab('showPage', 1)),
        'swipe back' => fn () => finishRefreshes($screen->changeTab('showPage', 0)),
    ];

    foreach ($steps as $name => $step) {
        $step();
        expectPhoneInStep($screen, $name);
    }
});

/**
 * Commits on a profile or an organization's page, by the author given, newest first.
 *
 * @param  array<string, mixed>  $author
 * @return list<array<string, mixed>>
 */
function partialFramePageCommits(string $prefix, array $author): array
{
    return array_map(fn (int $n): array => commitJson([
        'id' => "{$prefix}{$n}",
        'body' => "Commit {$prefix}{$n}",
        'author' => $author,
        'replies_count' => $n === 3 ? 1 : 0,
    ]), range(0, 19));
}

/**
 * The API for a page of commits that can be starred and have a thread: $routes answers the rest by path.
 *
 * @param  array<string, mixed>  $routes
 */
function servePartialFramePage(array $routes, string $starred): void
{
    serveApi(function (Request $request) use ($routes, $starred) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $thread = str_replace('4', '3', $starred);

        return match (true) {
            $request->method() === 'POST' && $path === "/api/v1/commits/{$starred}/star" => Http::response(['data' => commitJson(['id' => $starred, 'body' => "Commit {$starred}", 'starred' => true, 'stars_count' => 1])]),
            $path === "/api/v1/commits/{$thread}/replies" => Http::response(pageJson([replyJson(['id' => 'r1', 'parent_id' => $thread, 'body' => 'A reply'])])),
            $path === '/api/v1/me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
            default => is_callable($routes[$path] ?? null) ? $routes[$path]($request) : Http::response($routes[$path] ?? ['message' => "No fake for {$path}."], isset($routes[$path]) ? 200 : 404),
        };
    });
}

it('keeps a profile on the phone in step through stars, threads, its tabs and back to the top', function () {
    sendPartialFrames();
    holdRefreshes();

    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1]);
    servePartialFramePage([
        '/api/v1/users/ada' => [
            'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1], commits: 20),
            'is_following' => false, 'activity' => null, 'streaks' => ['longest' => 0, 'current' => 0], 'pinned_commit' => null,
        ],
        '/api/v1/users/ada/commits' => fn (Request $request) => Http::response(pageJson(partialFramePageCommits(str_contains($request->url(), 'replies') ? 'r' : 'p', $ada))),
    ], starred: 'p4');

    $screen = finishRefreshes(Native::visit('/log/users/ada'));
    expectPhoneInStep($screen, 'opening');

    $steps = [
        'a star' => fn () => finishRefreshes($screen->call('toggleStar', 'p4')),
        'open a thread' => fn () => finishRefreshes($screen->call('toggleReplies', 'p3')),
        'the tab tapped again, back to the top in a new list' => fn () => tapProfileTab($screen, 'commits'),
        'swipe to Replies' => fn () => finishRefreshes(tapProfileTab($screen, 'replies')),
        'swipe back' => fn () => finishRefreshes(tapProfileTab($screen, 'commits')),
        'close the thread' => fn () => finishRefreshes($screen->call('toggleReplies', 'p3')),
    ];

    foreach ($steps as $name => $step) {
        $step();
        expectPhoneInStep($screen, $name);
    }

    expect(nodesSentInFull($screen))->toBeLessThan(40);
});

it('keeps an organization on the phone in step through stars, threads, its tabs and back to the top', function () {
    sendPartialFrames();
    holdRefreshes();

    $laravel = ['type' => 'organization', 'id' => 'o-laravel', 'handle' => 'laravel', 'name' => 'Laravel', 'lane' => 0];
    servePartialFramePage([
        '/api/v1/organizations/laravel' => [
            'data' => [
                'handle' => 'laravel', 'name' => 'Laravel', 'avatar_url' => 'https://avatars.githubusercontent.com/u/958072', 'bio' => 'The PHP framework for web artisans.',
                'location' => null, 'website' => 'https://laravel.com', 'github_url' => 'https://github.com/laravel', 'html_url' => 'http://devnull.test/@laravel',
                'lane' => 0, 'followers_count' => 45, 'admins' => [userJson(['id' => 'u-taylor', 'handle' => 'taylorotwell', 'name' => 'Taylor Otwell'])],
            ],
            'is_following' => false, 'can_claim' => false, 'settings_url' => null,
        ],
        '/api/v1/organizations/laravel/commits' => pageJson(partialFramePageCommits('o', $laravel)),
        '/api/v1/organizations/laravel/mentions' => pageJson(partialFramePageCommits('m', userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']))),
    ], starred: 'o4');

    $screen = finishRefreshes(Native::visit('/log/organizations/laravel'));
    expectPhoneInStep($screen, 'opening');
    $tab = fn (string $tab) => $screen->changeTab($screen->get('tab') === $tab ? 'backToTop' : 'showPage', (int) array_search($tab, Organization::Tabs, true));

    $steps = [
        'a star' => fn () => finishRefreshes($screen->call('toggleStar', 'o4')),
        'open a thread' => fn () => finishRefreshes($screen->call('toggleReplies', 'o3')),
        'the tab tapped again, back to the top in a new list' => fn () => $tab('commits'),
        'swipe to Mentions' => fn () => finishRefreshes($tab('mentions')),
        'swipe back' => fn () => finishRefreshes($tab('commits')),
        'close the thread' => fn () => finishRefreshes($screen->call('toggleReplies', 'o3')),
    ];

    foreach ($steps as $name => $step) {
        $step();
        expectPhoneInStep($screen, $name);
    }

    expect(nodesSentInFull($screen))->toBeLessThan(40);
});
