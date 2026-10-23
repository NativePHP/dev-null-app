<?php

use App\NativeComponents\Notifications;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A page of notifications as devnull lists them, as the inbox tests build it.
 *
 * @param  list<array<string, mixed>>  $groups
 * @return array<string, mixed>
 */
function pollInboxJson(array $groups, ?string $readAt = null, ?string $cursor = null): array
{
    return [
        ...pageJson($groups, $cursor, path: 'http://devnull.test/api/v1/notifications'),
        'filters' => ['type' => 'all'],
        'read_at' => $readAt ?? now()->toIso8601ZuluString(),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pollPersonJson(string $username, string $name): array
{
    return userJson(['id' => 'u-'.$username, 'username' => $username, 'name' => $name, 'avatar_url' => "https://avatars.test/{$username}"]);
}

/**
 * A group that came in on a poll, as devnull answers a through= ask: newer
 * than everything showing, with its own group id.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function arrivedJson(string $id, string $groupId, array $overrides = []): array
{
    return notificationJson('starred', ['id' => $id, 'group_id' => $groupId, 'created_at' => now()->subMinute()->toIso8601ZuluString(), ...$overrides]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shownJson(string $groupId = 'g-shown', array $overrides = []): array
{
    return notificationJson('followed', ['id' => 'n-shown', 'group_id' => $groupId, 'created_at' => now()->subHours(3)->toIso8601ZuluString(), ...$overrides]);
}

/**
 * Whether a poll is still out, from inside the screen.
 */
function pollOut(TestableComponent $screen): bool
{
    return (new ReflectionProperty($screen->instance(), 'polling'))->getValue($screen->instance());
}

/**
 * The pill, once a poll has staged groups: "2 new notifications". A native
 * button, so its label rides as a prop.
 */
function pillLabel(TestableComponent $screen): ?string
{
    $pill = findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'new-notifications');

    return $pill === null ? null : (string) ($pill['props']['label'] ?? '');
}

it('asks every 30 seconds for everything since the oldest group showing, as the web does, never twice at once', function () {
    holdRefreshes();
    $oldest = now()->subHours(3)->toIso8601ZuluString();
    serveApi(fn () => Http::response(pollInboxJson([shownJson()])));

    $screen = finishRefreshes(Native::visit('/notifications'));
    $requests = refreshesSent($screen);

    // The timer comes due right after the same thing was asked for, and asks anyway.
    $screen->firePoll('checkForNewNotifications');
    $pollSent = refreshesSent($screen);

    // A poll still out is never sent beside itself.
    $screen->firePoll('checkForNewNotifications');

    expect(refreshesSent($screen))->toBe($pollSent)
        ->and($pollSent)->toBe($requests + 1);

    finishRefreshes($screen);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/notifications?through='.rawurlencode($oldest));

    // The next tick, with the last one answered, goes out again.
    $screen->firePoll('checkForNewNotifications');

    expect(refreshesSent($screen))->toBeGreaterThan($pollSent);
});

it('asks for the selection on, not everything, when a filter is narrowed', function () {
    holdRefreshes();
    serveApi(fn (Request $request) => Http::response(pollInboxJson(str_contains($request->url(), 'type=') ? [notificationJson('starred', ['group_id' => 'g-shown', 'id' => 'n-shown'])] : [])));

    $screen = Native::test(Notifications::class)->call('show', 'stars');
    finishRefreshes($screen)->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/notifications?type=stars&through='.rawurlencode(now()->subHours(3)->toIso8601ZuluString()));
});
it('counts new and moved-on groups, not events or people, and keeps what came in waiting for the pill', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return str_contains($request->url(), 'through=')
            // Two groups came in; the one showing gained an event, which is an update to it, not a new one beside it.
            ? Http::response(pollInboxJson([
                arrivedJson('n-new-2', 'g-new-2'),
                arrivedJson('n-new-1', 'g-new-1'),
                notificationJson('followed', ['id' => 'n-shown-again', 'group_id' => 'g-shown', 'event_count' => 3, 'unread_count' => 0]),
            ]))
            : Http::response(pollInboxJson([shownJson()]));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));
    $rows = $screen->instance()->notifications;
    $listKey = $screen->instance()->listKey;

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect(pillLabel($screen))->toBe('3 new notifications')
        // The rows showing never moved under the pill.
        ->and($screen->instance()->notifications)->toBe($rows)
        ->and($screen->instance()->listKey)->toBe($listKey)
        ->and(count($screen->instance()->staged))->toBe(3);
});

it('puts what the pill holds on top, once, and goes back to the top', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return str_contains($request->url(), 'through=')
            ? Http::response(pollInboxJson([arrivedJson('n-new-1', 'g-new-1'), shownJson('g-shown', ['id' => 'n-shown-updated', 'event_count' => 2])]))
            : Http::response(pollInboxJson([shownJson()]));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));
    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen)->tap('new-notifications');

    $groups = array_map(fn ($group): string => $group->groupId, $screen->instance()->notifications);

    // The group that gained an event takes its own place, not a second one beside it.
    expect($groups)->toBe(['g-new-1', 'g-shown'])
        ->and($screen->instance()->staged)->toBe([])
        ->and(pillLabel($screen))->toBeNull()
        ->and($screen->instance()->listKey)->toBeGreaterThan(0);
});

it('keeps what a poll staged through a second poll and a pull to refresh', function () {
    holdRefreshes();
    $pollAnswers = 0;
    $firstPage = true;

    serveApi(function (Request $request) use (&$pollAnswers, &$firstPage) {
        if (str_contains($request->url(), 'through=')) {
            $pollAnswers++;

            return Http::response(pollInboxJson($pollAnswers === 1
                ? [arrivedJson('n-new-1', 'g-new-1')]
                : [arrivedJson('n-new-1', 'g-new-1'), arrivedJson('n-new-2', 'g-new-2')]));
        }

        // The first page holds only what was showing; a pull to refresh brings the newest of them into it.
        $page = $firstPage ? [shownJson()] : [arrivedJson('n-new-1', 'g-new-1'), shownJson()];
        $firstPage = false;

        return Http::response(pollInboxJson($page));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect(pillLabel($screen))->toBe('1 new notification');

    // The same groups again don't count twice; what else came in joins them.
    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect(pillLabel($screen))->toBe('2 new notifications');

    // A pull to refresh is at the top: what the fresh page brought in shows, and stops waiting.
    $screen->call('load');
    finishRefreshes($screen);

    expect(pillLabel($screen))->toBe('1 new notification')
        ->and(array_map(fn ($group): string => $group->groupId, $screen->instance()->notifications))->toBe(['g-new-1', 'g-shown']);

    $screen->tap('new-notifications');

    expect(array_map(fn ($group): string => $group->groupId, $screen->instance()->notifications))->toBe(['g-new-2', 'g-new-1', 'g-shown']);
});

it('leaves the pill away when nothing came in', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return Http::response(pollInboxJson([shownJson()]));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));
    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect(pillLabel($screen))->toBeNull()
        ->and($screen->instance()->staged)->toBe([]);
});

it('keeps the pages and groups loaded under the list through a poll and its pill', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return match (true) {
            str_contains($request->url(), 'cursor=older') => Http::response(pollInboxJson([notificationJson('mentioned', ['id' => 'n-older', 'group_id' => 'g-older', 'created_at' => now()->subDay()->toIso8601ZuluString()])], cursor: null)),
            str_contains($request->url(), 'through=') => Http::response(pollInboxJson([arrivedJson('n-new-1', 'g-new-1')])),
            default => Http::response(pollInboxJson([shownJson()], cursor: 'older')),
        };
    });

    $screen = finishRefreshes(Native::visit('/notifications'));
    $screen->call('loadMore');

    expect($screen->instance()->nextCursor)->toBeNull()
        ->and(count($screen->instance()->notifications))->toBe(2);

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen)->tap('new-notifications');

    $groups = array_map(fn ($group): string => $group->groupId, $screen->instance()->notifications);

    expect($groups)->toBe(['g-new-1', 'g-shown', 'g-older'])
        // The page loaded under the list is still there, and none of them twice.
        ->and(array_count_values($groups))->toBe(['g-new-1' => 1, 'g-shown' => 1, 'g-older' => 1])
        ->and($screen->instance()->nextCursor)->toBeNull();
});

it('keeps a group opened in place through a poll and its pill', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/group') => Http::response(pageJson([notificationJson('followed', ['id' => 'n-follow-2', 'actor' => pollPersonJson('grace', 'Grace')])], 'older')),
            str_contains($request->url(), 'through=') => Http::response(pollInboxJson([arrivedJson('n-new-1', 'g-new-1')])),
            default => Http::response(pollInboxJson([shownJson('g-shown', ['event_count' => 2, 'actors_count' => 2, 'actor' => pollPersonJson('grace', 'Grace'), 'actors' => [pollPersonJson('grace', 'Grace')]])])),
        };
    });

    $screen = finishRefreshes(Native::visit('/notifications'));
    $screen->tap('group-n-shown');

    expect(array_keys($screen->instance()->expanded))->toBe(['n-shown'])
        ->and($screen->instance()->expandedCursors)->toBe(['n-shown' => 'older']);

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen)->tap('new-notifications')->assertSee('Hide followers');

    expect(array_keys($screen->instance()->expanded))->toBe(['n-shown'])
        ->and($screen->instance()->expandedCursors)->toBe(['n-shown' => 'older']);
});

it('drops what a poll staged when the selection changes, and its late answer changes nothing', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return str_contains($request->url(), 'through=')
            ? Http::response(pollInboxJson([arrivedJson('n-new-1', 'g-new-1')]))
            : Http::response(pollInboxJson(str_contains($request->url(), 'type=stars') ? [notificationJson('starred', ['id' => 'n-star', 'group_id' => 'g-star'])] : [shownJson()]));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    // A poll for everything, still out when the selection narrows to stars.
    $screen->firePoll('checkForNewNotifications');
    $screen->call('show', 'stars');

    expect($screen->instance()->staged)->toBe([])
        ->and(pollOut($screen))->toBeFalse();

    finishRefreshes($screen);

    // Its answer was for the selection before, so neither the rows nor the pill change.
    expect(pillLabel($screen))->toBeNull()
        ->and(array_map(fn ($group): string => $group->groupId, $screen->instance()->notifications))->toBe(['g-star']);
});

it('keeps what is showing, and says nothing, when a poll cannot get through', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return str_contains($request->url(), 'through=')
            ? Http::response(['message' => 'Server Error'], 500)
            : Http::response(pollInboxJson([shownJson()], cursor: 'older'));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    $screen->firePoll('checkForNewNotifications');
    // A server error is asked once more before it gives up, as any refresh is.
    finishRefreshes($screen);

    expect($screen->instance()->refreshFailed)->toBeFalse()
        ->and($screen->instance()->error)->toBeNull()
        ->and(pillLabel($screen))->toBeNull()
        ->and(array_map(fn ($group): string => $group->groupId, $screen->instance()->notifications))->toBe(['g-shown'])
        ->and($screen->instance()->nextCursor)->toBe('older')
        ->and(pollOut($screen))->toBeFalse();
});

it('keeps nothing of a poll in the cache, and the first page it would have shadowed kept as it was', function () {
    holdRefreshes();
    $oldest = now()->subHours(3)->toIso8601ZuluString();

    serveApi(function (Request $request) {
        return str_contains($request->url(), 'through=')
            ? Http::response(pollInboxJson([arrivedJson('n-new-1', 'g-new-1'), shownJson()]))
            : Http::response(pollInboxJson([shownJson()]));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    expect(kept('notifications'))->not->toBeNull();

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect(kept('notifications', ['through' => $oldest]))->toBeNull()
        ->and(kept('notifications'))->not->toBeNull();
});

it('keeps the visit the poll is measured against, and asks for the badge again since a listing marks it read', function () {
    holdRefreshes();
    $visit = now()->toIso8601ZuluString();
    $me = 0;

    serveApi(function (Request $request) use (&$me, $visit) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/v1/me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => $me++ === 0 ? 1 : 0]),
            default => Http::response(str_contains($request->url(), 'through=')
                ? pollInboxJson([arrivedJson('n-new-1', 'g-new-1'), shownJson()], readAt: now()->addSecond()->toIso8601ZuluString())
                : pollInboxJson([shownJson()], readAt: $visit)),
        };
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    expect($screen->instance()->readAt)->toBe($visit);

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect($screen->instance()->readAt)->toBe($visit)
        ->and(Http::recorded(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/v1/me'))->toHaveCount(2);
});

it('asks quietly for the first page when there is nothing showing yet', function () {
    holdRefreshes();
    $firstPage = true;

    serveApi(function (Request $request) use (&$firstPage) {
        if (str_contains($request->url(), 'through=')) {
            return Http::response(pollInboxJson([arrivedJson('n-new-1', 'g-new-1')]));
        }

        // The first ask finds nothing; the poll's quiet ask for the page finds one.
        $page = $firstPage ? [] : [shownJson()];
        $firstPage = false;

        return Http::response(pollInboxJson($page));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    expect($screen->instance()->notifications)->toBe([]);

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'through='));
    expect(pillLabel($screen))->toBeNull()
        ->and(array_map(fn ($group): string => $group->groupId, $screen->instance()->notifications))->toBe(['g-shown']);
});

it('stages a group already showing whose newest event moved on, and puts its fresh copy on top once', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return str_contains($request->url(), 'through=')
            // The group showing gained two events: its newest is n-shown-3 now, its group still g-shown.
            ? Http::response(pollInboxJson([notificationJson('followed', ['id' => 'n-shown-3', 'group_id' => 'g-shown', 'event_count' => 3])]))
            : Http::response(pollInboxJson([shownJson()], cursor: 'older'));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));
    $rows = $screen->instance()->notifications;

    // Regression: an update to a group showing used to be dropped on the floor forever.
    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect(pillLabel($screen))->toBe('1 new notification')
        ->and($screen->instance()->notifications)->toBe($rows);

    // The same answer again changes nothing: one pending update to the group, counted once.
    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect(pillLabel($screen))->toBe('1 new notification');

    $screen->tap('new-notifications');

    $groups = array_map(fn ($group): string => $group->groupId.'#'.$group->id, $screen->instance()->notifications);

    expect($groups)->toBe(['g-shown#n-shown-3'])
        ->and($screen->instance()->staged)->toBe([])
        // The page loaded under the list keeps its place and its cursor, with the group only once.
        ->and($screen->instance()->nextCursor)->toBe('older');
});

it('follows a group opened in place to its new newest event, keeping it open and asking under the id devnull knows now', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/n2/group') => Http::response(pageJson([
                notificationJson('starred', ['id' => 'n2', 'group_id' => 'g-star']),
                notificationJson('starred', ['id' => 'n1', 'group_id' => 'g-star', 'unread_count' => 0]),
                notificationJson('starred', ['id' => 'n-old', 'group_id' => 'g-star', 'unread_count' => 0]),
            ], 'older')),
            str_contains($url, '/group') => Http::response(pageJson([
                notificationJson('starred', ['id' => 'n1', 'group_id' => 'g-star']),
                notificationJson('starred', ['id' => 'n-old', 'group_id' => 'g-star']),
            ], 'older')),
            str_contains($url, 'through=') => Http::response(pollInboxJson([
                notificationJson('starred', ['id' => 'n2', 'group_id' => 'g-star', 'event_count' => 4]),
            ])),
            default => Http::response(pollInboxJson([notificationJson('starred', ['id' => 'n1', 'group_id' => 'g-star', 'event_count' => 3])])),
        };
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    $screen->tap('group-n1');

    expect(array_keys($screen->instance()->expanded))->toBe(['n1'])
        ->and($screen->instance()->expandedCursors)->toBe(['n1' => 'older']);

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen)->tap('new-notifications');

    // The group stays open under its new newest event, with its older cursor, and is asked for again there.
    expect(array_keys($screen->instance()->expanded))->toBe(['n2'])
        ->and($screen->instance()->expandedCursors)->toBe(['n2' => 'older'])
        ->and($screen->assertSee('Hide stars'));

    finishRefreshes($screen);

    expect(array_map(fn ($item): string => $item->id, $screen->instance()->expanded['n2']))->toBe(['n2', 'n1', 'n-old'])
        ->and($screen->instance()->expandedCursors)->toBe(['n2' => 'older']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/notifications/n2/group'));

    // A member of it opens who gave the star, as ever.
    $screen->tap('group-item-n-old')->assertNavigatedTo('/notifications/users/ada');
});

it('follows a group opened in place to its new newest event through a pull to refresh too', function () {
    holdRefreshes();
    $pulled = false;

    serveApi(function (Request $request) use (&$pulled) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/n2/group') => Http::response(pageJson([
                notificationJson('starred', ['id' => 'n2', 'group_id' => 'g-star']),
                notificationJson('starred', ['id' => 'n1', 'group_id' => 'g-star', 'unread_count' => 0]),
            ])),
            str_contains($url, '/group') => Http::response(pageJson([
                notificationJson('starred', ['id' => 'n1', 'group_id' => 'g-star']),
            ])),
            default => Http::response(pollInboxJson($pulled
                ? [notificationJson('starred', ['id' => 'n2', 'group_id' => 'g-star', 'event_count' => 3])]
                : [notificationJson('starred', ['id' => 'n1', 'group_id' => 'g-star', 'event_count' => 2])])),
        };
    });

    $screen = finishRefreshes(Native::visit('/notifications'))->tap('group-n1');

    expect(array_keys($screen->instance()->expanded))->toBe(['n1']);

    $pulled = true;
    $screen->call('load');
    finishRefreshes($screen);

    expect(array_keys($screen->instance()->expanded))->toBe(['n2'])
        ->and($screen->assertSee('Hide stars'));

    finishRefreshes($screen);

    expect(array_map(fn ($item): string => $item->id, $screen->instance()->expanded['n2']))->toBe(['n2', 'n1']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/notifications/n2/group'));
});

it('ignores a poll from a selection left behind, even back on the selection it asked under, once a newer one is under way', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'through=') && str_contains($url, 'type=stars') => Http::response(pollInboxJson([
                arrivedJson('n-star-new', 'g-star-new'),
            ])),
            str_contains($url, 'through=') => Http::response(pollInboxJson([
                arrivedJson('n-all-new', 'g-all-new'),
            ])),
            str_contains($url, 'type=stars') => Http::response(pollInboxJson([
                notificationJson('starred', ['id' => 'n-star', 'group_id' => 'g-star', 'created_at' => now()->subHours(2)->toIso8601ZuluString()]),
            ])),
            default => Http::response(pollInboxJson([shownJson()])),
        };
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    // A poll for everything, still out when the selection narrows to stars.
    $screen->firePoll('checkForNewNotifications');
    $screen->call('show', 'stars');

    // Under stars, a poll of its own goes out and lands.
    finishRefreshes($screen);
    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    // The stale poll's answer is in by now too: it staged nothing, freed nothing, cleared nothing.
    expect(array_map(fn ($group): string => $group->groupId, $screen->instance()->staged))->toBe(['g-star-new'])
        ->and(pillLabel($screen))->toBe('1 new notification')
        ->and(pollOut($screen))->toBeFalse();

    // The guard the stale poll tried to clear is the newer one's, which already went: another goes out.
    $sent = refreshesSent($screen);
    $screen->firePoll('checkForNewNotifications');

    expect(refreshesSent($screen))->toBeGreaterThan($sent);
});

it('drops a poll that was under way when the screen went under another, even once its answer lands', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return Http::response(pollInboxJson(str_contains($request->url(), 'through=')
            ? [arrivedJson('n-new-1', 'g-new-1'), shownJson()]
            : [shownJson()]));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    $screen->firePoll('checkForNewNotifications');
    $screen->call('onResume');

    finishRefreshes($screen);

    expect(pillLabel($screen))->toBeNull()
        ->and(pollOut($screen))->toBeFalse()
        ->and(array_map(fn ($group): string => $group->groupId, $screen->instance()->notifications))->toBe(['g-shown']);
});

it('pins the arrivals control over the list rather than in it, reachable however far down you have read', function (string $platform) {
    holdRefreshes();
    serveApi(function (Request $request) {
        return Http::response(pollInboxJson(str_contains($request->url(), 'through=')
            ? [arrivedJson('n-new-1', 'g-new-1'), shownJson()]
            : [shownJson()]));
    });

    $screen = finishRefreshes(Native::visit('/notifications', platform: $platform));
    $list = showingNotifications($screen)['id'];

    expect(findNode(nodeByRef($screen, 'notifications'), fn (array $node): bool => ($node['ref'] ?? null) === 'new-notifications'))->toBeNull();

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    // Over the list, under the filters — never a row of it — with the list's identity held still meanwhile.
    expect(nodeByRef($screen, 'new-notifications'))->not->toBeNull()
        ->and(nodeByRef($screen, 'new-notifications')['props']['a11y_label'] ?? null)->toBe('Show 1 new notification')
        ->and(findNode(nodeByRef($screen, 'notifications'), fn (array $node): bool => ($node['ref'] ?? null) === 'new-notifications'))->toBeNull()
        ->and(findNode(nodeByRef($screen, 'notifications'), fn (array $node): bool => ($node['ref'] ?? null) === 'new-notifications-slot'))->toBeNull()
        ->and(showingNotifications($screen)['id'])->toBe($list);

    // A tap puts what it holds on top, at the top of a fresh list.
    $screen->tap('new-notifications');

    expect(showingNotifications($screen)['id'])->not->toBe($list);
})->with(['ios', 'android']);

it('keeps a follow back made while a newer copy of the same person waited in the pill, and its rollback', function () {
    holdRefreshes();
    $copies = 0;
    $followed = false;

    serveApi(function (Request $request) use (&$copies, &$followed) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/users/ada/follow') && $request->method() === 'POST' => tap(Http::response(null, 204), function () use (&$followed): void {
                $followed = true;
            }),
            str_contains($url, '/users/ada/follow') => Http::response(['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429),
            // The poll's copy of the group: ada again, as the server last knew it.
            str_contains($url, 'through=') => Http::response(pollInboxJson([
                notificationJson('followed', ['id' => 'n-shown-'.++$copies, 'group_id' => 'g-shown', 'event_count' => 2, 'actors_count' => 2,
                    'actor' => userJson(['id' => 'u-ada', 'username' => 'ada', 'name' => 'Ada', 'avatar_url' => 'https://avatars.test/ada', 'is_followed' => $followed]),
                    'actors' => [userJson(['username' => 'ada', 'is_followed' => $followed])]]),
            ])),
            default => Http::response(pollInboxJson([notificationJson('followed', ['id' => 'n-shown', 'group_id' => 'g-shown',
                'actor' => userJson(['id' => 'u-ada', 'username' => 'ada', 'name' => 'Ada', 'avatar_url' => 'https://avatars.test/ada', 'is_followed' => false])])])),
        };
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    // A poll stages the group's newer copy first, as the server then knew it: not followed.
    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect($screen->instance()->staged[0]->actorIsFollowed)->toBeFalse();

    // You follow ada back: the row says so, devnull agrees, and the copy waiting in the pill goes with it.
    $screen->tap('follow-back-ada');

    expect($screen->instance()->notifications[0]->actorIsFollowed)->toBeTrue()
        ->and($screen->instance()->staged[0]->actorIsFollowed)->toBeTrue();

    // A later poll replaces what waits with its own answer, and its rollback:
    // tapping to unfollow, devnull says no, and the copy waiting rolls back with the row.
    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen);

    expect($screen->instance()->staged[0]->actorIsFollowed)->toBeTrue();

    $screen->tap('follow-back-ada');

    expect($screen->instance()->notifications[0]->actorIsFollowed)->toBeTrue()
        ->and($screen->instance()->staged[0]->actorIsFollowed)->toBeTrue();

    // Applied, the group's newer copy still says you follow ada.
    $screen->tap('new-notifications');

    expect($screen->instance()->notifications[0]->actorIsFollowed)->toBeTrue();
});

it('carries a follow back onto a newer copy only of the same person, not whoever joined their group', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        return str_contains($request->url(), 'through=')
            // The group's newer copy is led by grace, who sent no is_followed at all.
            ? Http::response(pollInboxJson([
                notificationJson('followed', ['id' => 'n-shown-2', 'group_id' => 'g-shown', 'event_count' => 2, 'actors_count' => 2,
                    'actor' => userJson(['id' => 'u-grace', 'username' => 'grace', 'name' => 'Grace', 'avatar_url' => 'https://avatars.test/grace']),
                    'actors' => [userJson(['username' => 'grace']), userJson(['username' => 'linus', 'is_followed' => true])]]),
            ]))
            : Http::response(pollInboxJson([notificationJson('followed', ['id' => 'n-shown', 'group_id' => 'g-shown',
                'actor' => userJson(['id' => 'u-linus', 'username' => 'linus', 'name' => 'Linus', 'avatar_url' => 'https://avatars.test/linus', 'is_followed' => true])])]));
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    expect($screen->instance()->notifications[0]->actor->username)->toBe('linus')
        ->and($screen->instance()->notifications[0]->actorIsFollowed)->toBeTrue();

    $screen->firePoll('checkForNewNotifications');
    finishRefreshes($screen)->tap('new-notifications');

    // Grace's copy says nothing of you following anyone: linus's word is not hers to inherit.
    expect($screen->instance()->notifications[0]->actor->username)->toBe('grace')
        ->and($screen->instance()->notifications[0]->actorIsFollowed)->toBeNull();
});

it('keeps the older members a group had loaded when its newest event moves and it is asked for again', function (bool $viaPill) {
    holdRefreshes();
    $pulled = false;

    // A group of 26 events: the first page holds 20 of them, the rest come by cursor, and its newest moves on.
    $member = fn (int $n): array => notificationJson('starred', [
        'id' => "m{$n}", 'group_id' => 'g-star', 'created_at' => now()->subHours(3)->subMinutes($n)->toIso8601ZuluString(),
        'actor' => userJson(['id' => "u-{$n}", 'username' => 'dev'.$n, 'name' => "Dev {$n}", 'avatar_url' => "https://avatars.test/{$n}"]),
    ]);

    serveApi(function (Request $request) use (&$pulled, $member) {
        $url = $request->url();

        return match (true) {
            // The first page under the group's new newest: it, and the members after it, with a cursor of its own.
            str_contains($url, '/n0/group') => Http::response(pageJson(
                [notificationJson('starred', ['id' => 'n0', 'group_id' => 'g-star', 'event_count' => 26]), ...array_map($member, range(1, 19))],
                'older-fresh'
            )),
            // The older members, under the cursor the first page left.
            str_contains($url, 'cursor=older2') => Http::response(pageJson(array_map($member, range(20, 24)), 'oldest')),
            // The first page under the group's newest as it opened.
            str_contains($url, '/group') => Http::response(pageJson(
                [notificationJson('starred', ['id' => 'n1', 'group_id' => 'g-star', 'event_count' => 25]), ...array_map($member, range(1, 19))],
                'older2'
            )),
            str_contains($url, 'through=') => Http::response(pollInboxJson([
                notificationJson('starred', ['id' => 'n0', 'group_id' => 'g-star', 'event_count' => 26]),
            ])),
            default => Http::response(pollInboxJson($pulled
                ? [notificationJson('starred', ['id' => 'n0', 'group_id' => 'g-star', 'event_count' => 26])]
                : [notificationJson('starred', ['id' => 'n1', 'group_id' => 'g-star', 'event_count' => 25])])),
        };
    });

    $screen = finishRefreshes(Native::visit('/notifications'));

    $screen->tap('group-n1');
    $screen->call('loadGroup', 'n1', true);

    // 20 on the first page, five more under its cursor, which says there are older still.
    expect(count($screen->instance()->expanded['n1']))->toBe(25)
        ->and($screen->instance()->expandedCursors)->toBe(['n1' => 'oldest']);

    if ($viaPill) {
        $screen->firePoll('checkForNewNotifications');
        finishRefreshes($screen)->tap('new-notifications');
    } else {
        $pulled = true;
        $screen->call('load');
    }

    finishRefreshes($screen);

    $items = $screen->instance()->expanded['n0'] ?? [];

    // The fresh first page, and the older members it doesn't cover still under it — none twice.
    expect(array_keys($screen->instance()->expanded))->toBe(['n0'])
        ->and(count($items))->toBe(25)
        ->and($items[0]->id)->toBe('n0')
        ->and(end($items)->id)->toBe('m24')
        ->and(array_count_values(array_map(fn ($item): string => $item->id, $items)))->toBe(array_fill_keys(array_map(fn ($item): string => $item->id, $items), 1))
        // The cursor the older members were paged on from stays; the fresh page's own is not theirs.
        ->and($screen->instance()->expandedCursors)->toBe(['n0' => 'oldest']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/notifications/n0/group'));

    // An older member is still on the screen, and still opens who gave the star.
    $screen->assertSee('Dev 24')->tap('group-item-m24')->assertNavigatedTo('/notifications/users/dev24');
})->with([
    'through the pill' => [true],
    'through a pull to refresh' => [false],
]);
