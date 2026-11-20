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

it('keeps the commit page on the phone in step while you open the thread and type a reply', function () {
    sendPartialFrames();
    $grace = userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace']);
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(
            commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'The focused commit', 'replies_count' => 3]),
            [
                commitJson(['id' => 'c0', 'body' => 'The root commit', 'author' => $grace]),
                commitJson(['id' => 'c1', 'parent_id' => 'c0', 'body' => 'The parent commit', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])]),
            ],
        )],
        'GET /api/v1/commits/c2/replies' => [pageJson(array_map(fn (int $n): array => replyJson(['id' => "r{$n}", 'parent_id' => 'c2', 'body' => "Reply {$n}", 'author' => $grace]), range(1, 3)))],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
    ]);

    $screen = Native::visit('/log/commits/c2');
    expectPhoneInStep($screen, 'opening');

    $steps = [
        'the earlier commits shown' => fn () => $screen->call('showEarlier'),
        'the replies opened' => fn () => $screen->call('toggleReplies'),
        // The scroll view mounts again at the top, under a new key.
        'a tap into the reply field' => fn () => $screen->call('replyTapped'),
        'a letter typed' => fn () => $screen->set('reply', 'N'),
        'another' => fn () => $screen->set('reply', 'Ni'),
        'the replies hidden' => fn () => $screen->call('toggleReplies'),
        'the reply field tapped again' => fn () => $screen->call('replyTapped'),
        'the replies opened again' => fn () => $screen->call('toggleReplies'),
    ];

    $sentInFull = [];
    foreach ($steps as $name => $step) {
        $step();
        $sentInFull[$name] = nodesSentInFull($screen);
        expectPhoneInStep($screen, $name);
    }

    // A letter typed sends the field and what it changes, not the thread above it.
    expect($sentInFull['another'])->toBeLessThan(40);
});

it('keeps Starred on the phone in step as rows leave from the middle', function () {
    sendPartialFrames();
    $starred = fn (int $n, bool $starred = true): array => commitJson(['id' => "s{$n}", 'body' => "Starred s{$n}", 'starred' => $starred, 'stars_count' => (int) $starred, 'replies_count' => $n === 6 ? 1 : 0, 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'lane' => 1])]);
    $unstarred = [];
    serveApi(function (Request $request) use ($starred, &$unstarred) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'DELETE' && preg_match('#^/api/v1/commits/(s\d+)/star$#', $path, $m)) {
            $unstarred[] = $m[1];

            return Http::response(['data' => $starred((int) substr($m[1], 1), false)]);
        }

        return match ($path) {
            '/api/v1/starred' => Http::response(pageJson(array_values(array_filter(array_map($starred, range(12, 1, -1)), fn (array $commit): bool => ! in_array($commit['id'], $unstarred, true))), null, 'http://devnull.test/api/v1/starred')),
            '/api/v1/commits/s6/replies' => Http::response(pageJson([replyJson(['id' => 'r1', 'parent_id' => 's6', 'body' => 'A reply'])])),
            default => Http::response(['message' => "No fake for {$path}."], 404),
        };
    });

    $screen = finishRefreshes(Native::visit('/profile/starred'));
    expectPhoneInStep($screen, 'opening');

    $steps = [
        'one unstarred from the middle' => fn () => finishRefreshes($screen->call('toggleStar', 's8')),
        'a thread opened under it' => fn () => finishRefreshes($screen->call('toggleReplies', 's6')),
        'another unstarred above the thread' => fn () => finishRefreshes($screen->call('toggleStar', 's7')),
        'pull to refresh' => fn () => finishRefreshes($screen->call('load')),
        'the top one unstarred' => fn () => finishRefreshes($screen->call('toggleStar', 's12')),
    ];

    $sentInFull = [];
    foreach ($steps as $name => $step) {
        $step();
        $sentInFull[$name] = nodesSentInFull($screen);
        expectPhoneInStep($screen, $name);
    }

    expect($sentInFull['one unstarred from the middle'])->toBeLessThan(40);
});

/**
 * A page of notifications, as devnull sends them.
 *
 * @param  list<array<string, mixed>>  $groups
 * @return array<string, mixed>
 */
function partialFrameInbox(array $groups, ?string $cursor = null): array
{
    return [...pageJson($groups, $cursor, path: 'http://devnull.test/api/v1/notifications'), 'filters' => ['type' => 'all'], 'read_at' => now()->toIso8601ZuluString()];
}

it('keeps Notifications on the phone in step as new ones come in, groups open and the kinds change', function () {
    sendPartialFrames();
    holdRefreshes();

    $notification = fn (string $id, string $type = 'starred', int $events = 1): array => notificationJson($type, [
        'id' => $id,
        'event_count' => $events,
        'actor' => userJson(['id' => "u-{$id}", 'handle' => "person{$id}", 'name' => "Person {$id}", 'lane' => 2]),
        'commit' => notificationCommitJson(['id' => "c-{$id}", 'body' => "About {$id}"]),
    ]);
    $state = ['new' => []];
    serveApi(function (Request $request) use ($notification, &$state) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $all = [...$state['new'], $notification('g1', events: 3), ...array_map(fn (int $n): array => $notification("n{$n}"), range(1, 15))];

        return match (true) {
            $path === '/api/v1/notifications' && str_contains($request->url(), 'cursor=page2') => Http::response(partialFrameInbox(array_map(fn (int $n): array => $notification("o{$n}"), range(1, 10)))),
            $path === '/api/v1/notifications' => Http::response(partialFrameInbox($all, 'page2')),
            $path === '/api/v1/notifications/g1/group' => Http::response(pageJson([$notification('g1-older'), $notification('g1-oldest')])),
            $path === '/api/v1/me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
            default => Http::response(['message' => "No fake for {$path}."], 404),
        };
    });

    $screen = finishRefreshes(Native::visit('/notifications'));
    expect(treeShows(phoneTree($screen), 'About n15'))->toBeTrue();
    expectPhoneInStep($screen, 'opening');

    $steps = [
        'a group opened' => fn () => finishRefreshes($screen->call('toggleGroup', 'g1')),
        'two new ones on top' => function () use ($screen, &$state, $notification) {
            $state['new'] = [$notification('new2', 'followed'), $notification('new1', 'mentioned')];
            finishRefreshes($screen->call('load'));
        },
        'the next page' => fn () => finishRefreshes($screen->call('loadMore')),
        'the group closed' => fn () => finishRefreshes($screen->call('toggleGroup', 'g1')),
        'every kind shown again, in a new list' => fn () => finishRefreshes($screen->call('showAll')),
    ];

    $sentInFull = [];
    foreach ($steps as $name => $step) {
        $step();
        $sentInFull[$name] = nodesSentInFull($screen);
        expectPhoneInStep($screen, $name);
    }

    expect(treeShows(phoneTree($screen), 'Person new1'))->toBeTrue()
        ->and($sentInFull['the group closed'])->toBeLessThan(40);
});
