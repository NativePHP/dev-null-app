<?php

use App\Devnull\Account;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * @param  list<array<string, mixed>>  $groups
 */
function serveNotifications(array $groups, int $unread = 0): void
{
    serveApi([
        'GET /api/v1/notifications' => [pageJson($groups)],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => $unread]],
    ]);
}

it('keeps notifications as read, since devnull marked them read as it sent them, and nothing kept shows as new', function () {
    serveApi([
        'GET /api/v1/notifications' => [[...pageJson([notificationJson('followed')]), 'filters' => ['type' => 'all'], 'read_at' => now()->toIso8601ZuluString()]],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    // New on this visit, so its node is ringed in Commit Blue.
    Native::visit('/notifications')->assertElement('stack', fn (array $node): bool => ($node['ref'] ?? null) === 'node-n-followed'
        && ($node['style']['border_color'] ?? $node['style']['borderColor'] ?? null) === config('native-ui.theme.light.primary'));

    expect(kept('notifications')['data'][0]['unread_count'])->toBe(0)
        ->and(kept('notifications'))->not->toHaveKey('read_at');
});

it('opens on the notifications kept last time, with no request and no spinner, then puts new ones on top quietly', function () {
    holdRefreshes();
    keep('notifications', pageJson([notificationJson('followed')]));
    serveNotifications([notificationJson('mentioned'), notificationJson('followed', ['unread_count' => 0])], unread: 0);
    app(Account::class)->rememberUnreadNotifications(1);

    $screen = Native::visit('/notifications')->assertSee('followed you');

    expect(showsLoading(showingNotifications($screen)))->toBeFalse()
        ->and(count(Http::recorded()))->toBe(0)
        // Kept as read: it's been seen.
        ->and($screen->instance()->notifications[0]->isUnread)->toBeFalse();

    finishRefreshes($screen)->assertSee('mentioned you');

    expect(array_map(fn ($group): string => $group->id, $screen->instance()->notifications))->toBe(['n-mentioned', 'n-followed'])
        ->and($screen->instance()->notifications[0]->isUnread)->toBeTrue()
        ->and(app(Account::class)->unreadNotifications())->toBe(0);
});

it('shows a spinner only the first time', function () {
    holdRefreshes();
    serveNotifications([notificationJson('followed')]);

    $screen = Native::visit('/notifications')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-notifications')
        ->assertDontSee('Nothing here yet.');

    finishRefreshes($screen)->assertSee('followed you');
});

it('takes a fresh copy of a group in place, however its newest event moved on, and keeps the pages under it', function () {
    holdRefreshes();
    // Two groups kept, with a page under them.
    keep('notifications', pageJson([
        notificationJson('starred', ['id' => 'a1', 'group_id' => 'g-a']),
        notificationJson('followed', ['id' => 'b1', 'group_id' => 'g-b']),
    ], 'older'));
    serveApi(function (Request $request) {
        if (str_contains($request->url(), 'cursor=older')) {
            return Http::response(pageJson([notificationJson('mentioned', ['id' => 'c-mentioned', 'group_id' => 'g-older'])], 'oldest'));
        }

        // The star group gained an event: its newest event is a2 now, its group still g-a.
        return Http::response(pageJson([
            notificationJson('starred', ['id' => 'a2', 'group_id' => 'g-a', 'event_count' => 2]),
            notificationJson('followed', ['id' => 'b1', 'group_id' => 'g-b', 'unread_count' => 0]),
        ], 'older'));
    });

    $screen = Native::visit('/notifications')->call('loadMore');

    expect(array_map(fn ($group): string => $group->id, $screen->instance()->notifications))->toBe(['a1', 'b1', 'c-mentioned']);

    finishRefreshes($screen);

    $groups = array_map(fn ($group): string => $group->groupId.'#'.$group->id, $screen->instance()->notifications);

    // The group's fresh copy takes its own place, not a second one above it, and the page under it stays.
    expect($groups)->toBe(['g-a#a2', 'g-b#b1', 'g-older#c-mentioned'])
        ->and($screen->instance()->nextCursor)->toBe('oldest');
});

it('keeps them and says it could not refresh when offline', function () {
    holdRefreshes();
    keep('notifications', pageJson([notificationJson('followed')]));
    fakeOffline();

    finishRefreshes(Native::visit('/notifications'))
        ->assertSee('followed you')
        ->assertSee("warning: couldn't refresh");
});
