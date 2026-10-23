<?php

use App\NativeComponents\Log;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * The bottom navigation bar's tab for a section, as TabsLayout publishes it in
 * the active screen's chrome: the bottom_nav_item Android's tab bar renders.
 *
 * @return array<string, mixed>
 */
function retapTab(TestableComponent $screen, string $label): array
{
    $tab = findNode($screen->tree(), function (array $node) use ($label): bool {
        return ($node['type'] ?? null) === 'bottom_nav_item'
            && ($node['props']['label'] ?? null) === $label;
    });

    expect($tab)->not->toBeNull("The bottom navigation must publish a [{$label}] tab.");

    return $tab;
}

/**
 * The scroll targets the Log's pager lists register under, one per log's own
 * list, in pager order. Android's reselect seam looks the current log's list
 * up by exactly these keys.
 *
 * @return list<string>
 */
function retapListTargets(TestableComponent $screen): array
{
    $targets = array_map(
        fn (array $list): string => (string) ($list['props']['scroll_target'] ?? ''),
        findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'list'),
    );

    return array_values(array_filter($targets, fn (string $target): bool => $target !== ''));
}

/**
 * The callback id the screen's registry resolves a method name to, as the
 * runloop does when a press lands from the device (the harness's registry
 * accessor is protected; the suite binds into component internals the same
 * way, see LogsTest's shownLogIds).
 */
function retapCallbackId(TestableComponent $screen, string $method): ?int
{
    return Closure::bind(fn (): ?int => $this->callbacks()->lookup($method), $screen, TestableComponent::class)();
}

/**
 * How many GETs have gone to one of the logs, not counting the next pages a
 * list asks for with a cursor.
 */
function retapRequestsTo(string $log): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), "/api/v1/log/{$log}")
        && ! str_contains($request->url(), 'cursor='))->count();
}

/**
 * The three logs as devnull sends them, plus the account the badges read.
 *
 * @param  list<array<string, mixed>>|null  $trending
 */
function serveRetapFeeds(?array $trending = null): void
{
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'f1', 'body' => 'From following'])], path: 'http://devnull.test/api/v1/log/following')],
        'GET /api/v1/log/latest' => [pageJson([commitJson(['id' => 'l1', 'body' => 'From latest'])], path: 'http://devnull.test/api/v1/log/latest')],
        'GET /api/v1/log/trending' => [$trending ?? [
            ...pageJson([commitJson(['id' => 't1', 'body' => 'From trending'])], path: 'http://devnull.test/api/v1/log/trending'),
            'log' => 'trending',
            'is_ranked' => true,
            'newest_commit_id' => 't1',
            'suggestions' => [],
        ]],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);
}

it('wires the Log tab reselect on Android to a scroll target per log, following the log that is showing', function (string $log) {
    holdRefreshes();
    serveRetapFeeds();

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'));
    $screen = finishRefreshes(tapLogTab($screen, $log));

    // The Log tab carries the reselect handler, registered to the screen's
    // refresh method, and names the current log's list as its scroll target:
    // the published on_reselect id is the id the registry resolves the
    // method to, which is what Android's tab bar sends a press at.
    $tab = retapTab($screen, 'Log');

    expect($tab['props']['on_reselect'] ?? null)->toBeInt()
        ->and(retapCallbackId($screen, 'refreshLog'))->toBe($tab['props']['on_reselect'])
        ->and($tab['props']['reselect_scroll_target'] ?? null)->toBe("log-feed-{$log}");

    // Each of the pager's three lists registers under its own key, so the
    // neighbors composed beside the log you're on never fight over one entry.
    expect(retapListTargets($screen))->toBe(['log-feed-following', 'log-feed-latest', 'log-feed-trending']);

    // Only the Log tab carries the reselect: the other sections' tabs keep
    // their plain URL navigation.
    expect(retapTab($screen, 'Notifications')['props']['on_reselect'] ?? null)->toBeNull()
        ->and(retapTab($screen, 'Remotes')['props']['on_reselect'] ?? null)->toBeNull()
        ->and(retapTab($screen, 'Profile')['props']['on_reselect'] ?? null)->toBeNull();
})->with(Log::Logs);

it('refreshes the log you are on from the reselect callback, without remounting it or navigating', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'Kept row'])], path: 'http://devnull.test/api/v1/log/following'));
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c2', 'body' => 'Retapped in']), commitJson(['id' => 'c1', 'body' => 'Kept row'])], path: 'http://devnull.test/api/v1/log/following')],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'))->assertSee('Kept row');

    $listKey = $screen->get('listKey');
    $list = showingLog($screen)['id'];
    $asks = retapRequestsTo('following');
    $onReselect = retapTab($screen, 'Log')['props']['on_reselect'] ?? null;

    // The confirmed-top press the tab bar sends: a press at the published
    // on_reselect id, which the registry resolves to the screen's method.
    expect($onReselect)->toBeInt()
        ->and(retapCallbackId($screen, 'refreshLog'))->toBe($onReselect);

    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    // Forced: another reselect while the first answer is still on its way
    // still asks, as pull to refresh does.
    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    finishRefreshes($screen);

    // Two fresh GETs of the log that's showing — the mount's ask and nothing
    // else besides the two reselects — and the fresh rows are laid over the
    // list in place: same list key, same list node, no navigation, still the
    // log you were on.
    expect(retapRequestsTo('following'))->toBe($asks + 2)
        ->and($screen->get('log'))->toBe('following')
        ->and($screen->get('listKey'))->toBe($listKey)
        ->and(showingLog($screen)['id'])->toBe($list)
        ->and($screen->navigationIntent())->toBeNull();

    $screen->assertSee('Retapped in')->assertSee('Kept row');
});

it('still crosses sections on the tab URL, which the Log reselect leaves alone', function () {
    fakeApi([
        'GET /api/v1/notifications' => [[
            ...pageJson([], path: 'http://devnull.test/api/v1/notifications'),
            'filters' => ['type' => 'all'],
            'read_at' => now()->toIso8601ZuluString(),
        ]],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = Native::visit('/notifications', platform: 'android');

    expect(retapTab($screen, 'Log')['props']['on_reselect'] ?? null)->toBeInt()
        ->and(retapTab($screen, 'Log')['props']['reselect_scroll_target'] ?? null)->toBeNull();

    $screen->tap('Log')->assertReplacedWith('/log');
});

it('wires a child screen Log reselect to pop back without scrolling or refreshing the feed', function () {
    fakeApi([
        'GET /api/v1/commits/c1' => [commitPageJson(commitJson(['id' => 'c1', 'body' => 'A commit']), [commitJson(['id' => 'c0', 'body' => 'Its parent'])])],
        'GET /api/v1/commits/c1/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'A reply', 'author' => userJson(['id' => 'u3', 'username' => 'grace', 'name' => 'Grace'])])])],
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'f1'])])],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = Native::visit('/log/commits/c1', platform: 'android')
        ->assertTabActive('Log');
    $tab = retapTab($screen, 'Log');
    $callback = 'returnToLog';

    expect($tab['props']['on_reselect'] ?? null)->toBeInt()
        ->and(retapCallbackId($screen, $callback))->toBe($tab['props']['on_reselect'])
        ->and($tab['props']['reselect_scroll_target'] ?? null)->toBeNull();

    $screen->fireEvent($callback, TestableComponent::EVENT_PRESS)
        ->assertPoppedTo('/log');
});

it('leaves the Log tab unwired on iOS', function () {
    serveRetapFeeds();

    $screen = Native::visit('/log', platform: 'ios');

    expect(retapTab($screen, 'Log')['props']['on_reselect'] ?? null)->toBeNull()
        ->and(retapTab($screen, 'Log')['props']['reselect_scroll_target'] ?? null)->toBeNull();
});

it('asks once for whichever log is showing when its tab is retapped, and for nothing else', function (string $log) {
    holdRefreshes();
    serveRetapFeeds();

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'));
    $screen = finishRefreshes(tapLogTab($screen, $log));

    $before = array_combine(Log::Logs, array_map(retapRequestsTo(...), Log::Logs));

    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    finishRefreshes($screen);

    // The reselect asks for the log that's showing once more, whichever of
    // the three it is, and none of the others, and it stays where it was.
    foreach (Log::Logs as $other) {
        expect(retapRequestsTo($other))->toBe($before[$other] + ($other === $log ? 1 : 0));
    }

    expect($screen->get('log'))->toBe($log)
        ->and($screen->navigationIntent())->toBeNull();
})->with(Log::Logs);
