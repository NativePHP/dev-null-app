<?php

use App\Devnull\Account;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * The log devnull sends now, and an unread count for the badge.
 *
 * @param  list<array<string, mixed>>  $commits
 */
function fakeFreshLog(array $commits, ?string $cursor = null, array $extra = []): void
{
    serveApi([
        'GET /api/v1/log/following' => [pageJson($commits, $cursor)],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
        ...$extra,
    ]);
}

function logRequests(): int
{
    return count(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/v1/log/following')));
}

/**
 * @return list<string>
 */
function shownCommitIds(TestableComponent $screen): array
{
    return array_map(fn ($commit): string => $commit->id, $screen->instance()->commits);
}

it('opens on the log kept last time, with no request and no spinner, then refreshes behind it', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'Kept from last time', 'stars_count' => 1])]));
    fakeFreshLog([commitJson(['id' => 'c1', 'body' => 'Kept from last time', 'stars_count' => 4])]);

    $frames = count(Native::fakeBridge()->publishes);
    $screen = Native::visit('/log')->assertSee('Kept from last time');

    // One frame, the kept log: no placeholder before it, no spinner in it, nothing asked of devnull yet.
    expect(count($screen->bridge()->publishes) - $frames)->toBe(1)
        ->and(showsLoading(showingLog($screen)))->toBeFalse()
        ->and(logRequests())->toBe(0)
        ->and(refreshesSent($screen))->toBeGreaterThan(0);

    finishRefreshes($screen);

    expect(logRequests())->toBe(1)
        ->and($screen->instance()->commits[0]->starsCount)->toBe(4);
});

it('shows skeleton rows the first time only, until the log lands', function () {
    holdRefreshes();
    fakeFreshLog([commitJson(['id' => 'c1', 'body' => 'Fresh commit'])]);

    $screen = Native::visit('/log')
        ->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-1')
        ->assertDontSee('fatal: your log does not have any commits yet');

    finishRefreshes($screen)->assertSee('Fresh commit');

    expect(showsLoading(showingLog($screen)))->toBeFalse();

    // Next time it opens on what it fetched.
    $again = Native::visit('/log');

    expect(showsLoading(showingLog($again)))->toBeFalse();
    $again->assertSee('Fresh commit');
});

it('pulses skeleton rows only on the log you are on', function () {
    holdRefreshes();
    fakeFreshLog([commitJson(['id' => 'c1', 'body' => 'Fresh commit'])]);

    $pulses = fn (array $list): bool => findNode($list, fn (array $node): bool => ($node['props']['animate-loop'] ?? false) === true) !== null;

    $screen = Native::visit('/log');

    expect($pulses(showingLog($screen)))->toBeTrue()
        ->and(showsLoading(nodeByRef($screen, 'log-latest')))->toBeTrue()
        ->and($pulses(nodeByRef($screen, 'log-latest')))->toBeFalse();

    $screen->call('showPage', 1);

    expect($pulses(showingLog($screen)))->toBeTrue()
        ->and($pulses(nodeByRef($screen, 'log-following')))->toBeFalse();
});

it('holds new commits in a pill with the right count, and puts them on top without a request', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'Seen before'])]));
    fakeFreshLog([
        commitJson(['id' => 'c3', 'body' => 'Newest', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])]),
        commitJson(['id' => 'c2', 'body' => 'Newer', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])]),
        commitJson(['id' => 'c1', 'body' => 'Seen before']),
    ]);

    $screen = finishRefreshes(Native::visit('/log'))
        ->assertSee('2 new commits')
        ->assertDontSee('Newest');

    expect(shownCommitIds($screen))->toBe(['c1']);

    $requests = count(Http::recorded());
    $listKey = $screen->get('listKey');

    $screen->tap('new-commits')
        ->assertSee('Newest')
        ->assertSee('Newer')
        ->assertDontSee('2 new commits');

    expect(shownCommitIds($screen))->toBe(['c3', 'c2', 'c1'])
        ->and(count(Http::recorded()))->toBe($requests)
        // The log, you for the unread badge, who to follow, and your settings for your timezone.
        ->and(refreshesSent($screen))->toBe(4)
        // A new list key is how the log gets back to the top.
        ->and($screen->get('listKey'))->toBe($listKey + 1);
});

it('says one new commit, and puts the last-visit marker under it once shown', function () {
    $seen = commitJson(['id' => 'c1', 'body' => 'Seen before', 'created_at' => now()->subHour()->toIso8601ZuluString()]);

    // The visit before this one.
    fakeFreshLog([$seen]);
    Native::visit('/log');

    holdRefreshes();
    fakeFreshLog([commitJson(['id' => 'c2', 'body' => 'Just in', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])]), $seen]);

    finishRefreshes(Native::visit('/log'))
        ->assertSee('1 new commit')
        ->assertDontSee('HEAD@{last visit}')
        ->tap('new-commits')
        ->assertSee('Just in')
        ->assertSee('HEAD@{last visit}');
});

it('says when more came in than a page holds', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'Long ago'])]));
    fakeFreshLog([commitJson(['id' => 'c9', 'body' => 'All new']), commitJson(['id' => 'c8'])], cursor: 'page2');

    $screen = finishRefreshes(Native::visit('/log'))->assertSee('2+ new commits');

    $screen->tap('new-commits')->assertSee('All new')->assertDontSee('Long ago');

    expect(shownCommitIds($screen))->toBe(['c9', 'c8'])
        ->and($screen->get('nextCursor'))->toBe('page2');
});

it('changes counts and stars on rows already showing in place, with no pill', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'Popular', 'stars_count' => 1, 'replies_count' => 0])]));
    fakeFreshLog([commitJson(['id' => 'c1', 'body' => 'Popular', 'stars_count' => 7, 'replies_count' => 2])]);

    $screen = finishRefreshes(Native::visit('/log'))
        ->assertSee('2 replies')
        ->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'new-commits');

    expect($screen->instance()->commits[0]->starsCount)->toBe(7);
});

it('puts new commits straight in on pull to refresh', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1'])]));
    fakeFreshLog([commitJson(['id' => 'c2', 'body' => 'Pulled in']), commitJson(['id' => 'c1'])]);

    $screen = finishRefreshes(Native::visit('/log'))->assertSee('1 new commit');

    finishRefreshes($screen->call('load'))
        ->assertSee('Pulled in')
        ->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'new-commits');

    expect(shownCommitIds($screen))->toBe(['c2', 'c1']);
});

it('checks for new commits every 30 seconds, as the web polls', function () {
    fakeFreshLog([commitJson(['id' => 'c1'])]);

    $screen = Native::visit('/log');

    fakeFreshLog([commitJson(['id' => 'c2', 'body' => 'Polled in']), commitJson(['id' => 'c1'])]);

    $screen->firePoll('checkForNewCommits')->assertSee('1 new commit')->assertDontSee('Polled in');
});

it('keeps the kept log and says quietly that it could not refresh when offline, with a retry', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'Still here'])]));
    fakeOffline();

    $screen = finishRefreshes(Native::visit('/log'))
        ->assertSee('Still here')
        ->assertSee("warning: couldn't refresh")
        ->assertSet('error', null);

    fakeFreshLog([commitJson(['id' => 'c1', 'body' => 'Still here'])]);

    finishRefreshes($screen->tap('refresh-failed'))
        ->assertDontSee("warning: couldn't refresh")
        ->assertSee('Still here');
});

it('asks once more after a server error, signed afresh, before saying it could not refresh', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'Kept'])]));
    $answers = [500, 200];
    serveApi(function (Request $request) use (&$answers) {
        if (str_contains($request->url(), '/log/following')) {
            $status = array_shift($answers) ?? 500;

            return $status === 200
                ? Http::response(pageJson([commitJson(['id' => 'c1', 'body' => 'Kept', 'stars_count' => 9])]))
                : Http::response(['message' => 'Server Error'], $status);
        }

        return Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]);
    });

    $screen = finishRefreshes(Native::visit('/log'))->assertDontSee("warning: couldn't refresh");

    $nonces = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/log/following'))
        ->map(fn (array $pair): string => $pair[0]->header('X-Devnull-Nonce')[0]);

    expect($screen->instance()->commits[0]->starsCount)->toBe(9)
        ->and($nonces)->toHaveCount(2)
        ->and($nonces->unique())->toHaveCount(2);

    // Twice in a row, it gives up and says so.
    $answers = [500, 500];

    finishRefreshes($screen->firePoll('checkForNewCommits'))->assertSee("warning: couldn't refresh");
});

it('keeps the kept log, its next page and what was kept while dev-null.sh answers with a maintenance page', function (int $status, string $error) {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c2', 'body' => 'Kept newer']), commitJson(['id' => 'c1', 'body' => 'Kept older'])], 'page2'));
    $kept = kept('log/following');
    // Every URL, the API's included, as dev-null.sh answered on 2026-10-21.
    serveApi(fn () => Http::response('<!DOCTYPE html><html><head><title>Maintenance - /dev/null</title></head><body>Back soon.</body></html>', $status, ['Content-Type' => 'text/html; charset=UTF-8']));

    $screen = finishRefreshes(Native::visit('/log'))
        ->assertSee('Kept newer')
        ->assertSee("warning: couldn't refresh")
        ->assertSet('error', null);

    expect(shownCommitIds($screen))->toBe(['c2', 'c1'])
        ->and($screen->get('nextCursor'))->toBe('page2')
        ->and(kept('log/following'))->toBe($kept);

    // The next page fails too, and the log doesn't end there.
    $screen->call('loadMore')->assertSet('error', $error);

    expect(shownCommitIds($screen))->toBe(['c2', 'c1'])
        ->and($screen->get('nextCursor'))->toBe('page2');

    // Back from maintenance, it carries on from where it was.
    fakeFreshLog([commitJson(['id' => 'c0', 'body' => 'Next page'])]);

    $screen->call('loadMore')->assertSet('error', null);

    expect(shownCommitIds($screen))->toBe(['c2', 'c1', 'c0']);
})->with([
    'a 200' => [200, "fatal: unable to access 'http://devnull.test': unexpected answer (HTTP 200)"],
    'a 503' => [503, 'fatal: the remote end hung up unexpectedly (HTTP 503)'],
]);

it('prints the error when there is nothing kept to show', function () {
    holdRefreshes();
    fakeOffline();

    $screen = finishRefreshes(Native::visit('/log'))
        ->assertSee("fatal: unable to access 'http://devnull.test': could not connect to the server")
        ->assertDontSee("warning: couldn't refresh");

    expect(showsLoading(showingLog($screen)))->toBeFalse();
});

it('signs out and empties the cache when a background refresh gets a 401', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson()]));
    Http::fake(['devnull.test/*' => Http::response(['message' => 'This key is not approved.', 'code' => 'device_not_approved'], 401)]);

    finishRefreshes(Native::visit('/log'))->assertReplacedWith('/onboarding');

    signIn();

    expect(kept('log/following'))->toBeNull()
        ->and(app(Account::class)->unreadNotifications())->toBe(0);
});

it('opens the other log at once when it was kept', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'From following'])]));
    keep('log/latest', pageJson([commitJson(['id' => 'c2', 'body' => 'From everyone'])]));

    $screen = tapLogTab(Native::visit('/log'), 'latest');

    expect(treeShows(showingLog($screen), 'From everyone'))->toBeTrue()
        ->and(treeShows(showingLog($screen), 'From following'))->toBeFalse()
        ->and(showsLoading(showingLog($screen)))->toBeFalse()
        ->and(count(Http::recorded()))->toBe(0);
});

it('ignores an answer for the log you have since left', function () {
    holdRefreshes();
    keep('log/latest', pageJson([commitJson(['id' => 'c2', 'body' => 'From everyone'])]));
    fakeFreshLog([commitJson(['id' => 'c1', 'body' => 'From following'])], extra: [
        'GET /api/v1/log/latest' => [pageJson([commitJson(['id' => 'c2', 'body' => 'From everyone'])])],
    ]);

    $screen = finishRefreshes(tapLogTab(Native::visit('/log'), 'latest'))->assertSet('error', null);

    expect(treeShows(showingLog($screen), 'From everyone'))->toBeTrue()
        ->and(treeShows(showingLog($screen), 'From following'))->toBeFalse();

    // It's still kept for when you go back, and the page beside shows it.
    expect(kept('log/following')['data'][0]['body'])->toBe('From following')
        ->and(treeShows(nodeByRef($screen, 'log-following'), 'From following'))->toBeTrue();
});

it('keeps your new commit, so the log opens with it next time', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Older'])])],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'c9', 'body' => 'Just shipped'])], 201],
    ]);

    Native::visit('/log')->press('composeInSheet')->input('sheet-body', 'Just shipped')->tap('sheet-commit');

    holdRefreshes();

    Native::visit('/log')->assertSee('Just shipped')->assertSee('Older');

    expect(array_column(kept('log/following')['data'], 'id'))->toBe(['c9', 'c1']);
});

it('shows a star made on a commit as soon as you come back to the log', function () {
    holdRefreshes();
    $ada = userJson(['id' => 'u2', 'handle' => 'ada']);
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'Star me', 'stars_count' => 0, 'author' => $ada])]));
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'Star me', 'stars_count' => 0, 'author' => $ada])));
    keep('commits/c1/replies', pageJson([]));
    fakeApi(['POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'starred' => true, 'stars_count' => 1, 'author' => $ada])]]]);

    $log = Native::visit('/log');
    $commit = $log->tap('commit-c1')->follow()->tap('star-c1');

    $log = $commit->goBack();

    expect($log->instance()->commits[0]->starred)->toBeTrue()
        ->and($log->instance()->commits[0]->starsCount)->toBe(1);
});

it('drops a commit deleted on its page as soon as you come back to the log', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c2', 'body' => 'Delete me']), commitJson(['id' => 'c1', 'body' => 'Keep me'])]));
    keep('commits/c2', commitPageJson(commitJson(['id' => 'c2', 'body' => 'Delete me'])));
    fakeApi(['DELETE /api/v1/commits/c2' => [null, 204]]);

    $log = Native::visit('/log');
    $commit = $log->tap('commit-c2')->follow()->call('deleteCommit', 'c2');

    $commit->goBack()->assertDontSee('Delete me')->assertSee('Keep me');
});

it('floats the pill over the commits, hung under the tabs in the pager, there before it shows', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1'])]));
    fakeFreshLog([commitJson(['id' => 'c2', 'body' => 'Newer']), commitJson(['id' => 'c1'])]);

    $screen = Native::visit('/log', platform: 'ios');
    $overlay = fn (): array => findNode(nodeByRef($screen, 'log-pager'), fn (array $node): bool => ($node['type'] ?? null) === 'pager_overlay');
    $firstInTheList = fn (): ?string => array_values(array_filter(childRefs($screen, 'log'), fn (?string $ref): bool => $ref !== null && ! str_starts_with($ref, 'day-')))[0] ?? null;

    // Hidden and empty until there are new commits, but already in its place, outside the lists.
    expect(findNode($overlay(), fn (array $node): bool => ($node['ref'] ?? null) === 'new-commits-slot'))->not->toBeNull()
        ->and(nodeByRef($screen, 'new-commits-slot')['layout']['display'] ?? null)->toBe(1)
        ->and(nodeByRef($screen, 'new-commits-slot')['children'] ?? [])->toBe([])
        ->and($firstInTheList())->toBe('commit-c1');

    finishRefreshes($screen)->assertSee('1 new commit');

    // Over the commits, not a row above them.
    expect(nodeByRef($screen, 'new-commits-slot')['layout']['display'] ?? null)->toBeNull()
        ->and(findNode($overlay(), fn (array $node): bool => ($node['ref'] ?? null) === 'new-commits'))->not->toBeNull()
        ->and($firstInTheList())->toBe('commit-c1');
});
