<?php

use App\NativeComponents\Log;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * devnull's logs a page at a time, by "log/{log}" for the first page and
 * "log/{log}?cursor={cursor}" for the ones after it. Each answers with
 * [body, status], or a closure answers for itself. A later call swaps what
 * they answer. Anything else but `me` gets a 404.
 *
 * @param  array<string, array{0: mixed, 1?: int}|Closure(): mixed>  $pages
 */
function serveLogPages(array $pages): void
{
    serveApi(function (Request $request) use ($pages) {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1/'));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = isset($query['cursor']) ? "{$path}?cursor={$query['cursor']}" : $path;

        return match (true) {
            $path === 'me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
            ($pages[$page] ?? null) instanceof Closure => $pages[$page](),
            isset($pages[$page]) => Http::response($pages[$page][0], $pages[$page][1] ?? 200),
            default => Http::response(['message' => "No fake for {$page}."], 404),
        };
    });
}

/**
 * How many times devnull was asked for a path and query, such as "log/following?cursor=page2".
 */
function timesAskedFor(string $page): int
{
    return count(Http::recorded(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/'.$page));
}

/**
 * The ids of the commits in the log you're on, in order.
 *
 * @return list<string>
 */
function logCommitIds(TestableComponent $screen): array
{
    return array_map(fn ($commit): string => $commit->id, $screen->instance()->commits);
}

/**
 * Lets go of every background request still out, as NativePHP drops the
 * answers that land while another screen is on top.
 */
function dropRefreshes(TestableComponent $screen): void
{
    foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
        AsyncTaskTransport::forgetPayload($call['params']['id']);
    }
}

/**
 * Commits with just these ids, as devnull sends them.
 *
 * @param  list<string>  $ids
 * @return list<array<string, mixed>>
 */
function commitsWithIds(array $ids): array
{
    return array_map(fn (string $id): array => commitJson(['id' => $id]), $ids);
}

/**
 * A ranked Trending page, as devnull sends it.
 *
 * @param  list<string>  $ids
 * @return array<string, mixed>
 */
function rankingJson(array $ids, ?string $nextCursor = null): array
{
    return [
        ...pageJson(commitsWithIds($ids), $nextCursor, path: 'http://devnull.test/api/v1/log/trending'),
        'log' => 'trending',
        'is_ranked' => true,
        'newest_commit_id' => $ids[0] ?? null,
        'suggestions' => [],
    ];
}

it('fetches the next page in the background once the first lands, and puts it in at the end of the list without asking again', function () {
    holdRefreshes();
    keep('log/latest', pageJson(commitsWithIds(array_map(fn (int $n): string => "l{$n}", range(1, 10))), 'page2', path: 'http://devnull.test/api/v1/log/latest'));
    serveLogPages([
        'log/following' => [pageJson([commitJson(['id' => 'c2', 'body' => 'Newer commit'])], 'page2')],
        'log/following?cursor=page2' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Older commit'])], 'page3')],
        'log/following?cursor=page3' => [pageJson(commitsWithIds(['c0']))],
    ]);

    $screen = Native::visit('/log');

    // There's no cursor to ask with until the first page lands.
    expect(timesAskedFor('log/following?cursor=page2'))->toBe(0);

    finishRefreshes($screen)->assertSee('Newer commit')->assertDontSee('Older commit');

    // Only the log you're on fetches ahead: Latest, beside it, has a next page kept but never asks for it.
    expect(timesAskedFor('log/following?cursor=page2'))->toBe(1)
        ->and(timesAskedFor('log/latest?cursor=page2'))->toBe(0)
        ->and(timesAskedFor('log/latest?cursor=preview'))->toBe(0);

    $requests = count(Http::recorded());

    $screen->call('loadMore')->assertSee('Older commit');

    expect(logCommitIds($screen))->toBe(['c2', 'c1'])
        ->and(count(Http::recorded()))->toBe($requests)
        ->and($screen->get('nextCursor'))->toBe('page3');

    // The page after it is on its way in turn.
    finishRefreshes($screen);

    expect(timesAskedFor('log/following?cursor=page3'))->toBe(1);
});

it('waits for a next page already on its way rather than asking twice, and puts it in when it lands', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c2', 'body' => 'Newer commit'])], 'page2'));
    serveLogPages([
        'log/following' => [pageJson([commitJson(['id' => 'c2', 'body' => 'Newer commit'])], 'page2')],
        'log/following?cursor=page2' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Older commit'])])],
    ]);

    // What was kept shows at once, its next page is asked for behind it, and you reach the end before it lands.
    $screen = Native::visit('/log')->call('loadMore');

    expect(count(Http::recorded()))->toBe(0)
        ->and(logCommitIds($screen))->toBe(['c2'])
        ->and(findNode(showingLog($screen), fn (array $node): bool => ($node['ref'] ?? null) === 'loading-more'))->not->toBeNull();

    finishRefreshes($screen)->assertSee('Older commit');

    expect(logCommitIds($screen))->toBe(['c2', 'c1'])
        ->and(timesAskedFor('log/following?cursor=page2'))->toBe(1);
});

it('asks for the next page itself when the one on its way fails while the end of the list waits, and prints why as before', function (bool $threadFails) {
    $asked = 0;

    serveLogPages([
        'log/following' => [pageJson(commitsWithIds(['c2']), 'page2')],
        'log/following?cursor=page2' => [pageJson(commitsWithIds(['c1']), 'page3')],
        'log/following?cursor=page3' => function () use (&$asked, $threadFails) {
            if (++$asked === 1 && $threadFails) {
                throw new RuntimeException('The background thread went away.');
            }

            return Http::response(['message' => 'Too Many Attempts.'], 429);
        },
    ]);

    // The second page is here. From now on, answers wait to be let through.
    $screen = Native::visit('/log');
    holdRefreshes();

    // It goes in, the third is asked for, and the end of the list comes before that lands.
    $screen->call('loadMore')->call('loadMore');

    expect($asked)->toBe(0)
        ->and(logCommitIds($screen))->toBe(['c2', 'c1']);

    // It fails quietly, so the end of the list asks itself, and says why that failed.
    finishRefreshes($screen)->assertSee('Too Many Attempts.');

    expect($asked)->toBe(2)
        ->and(logCommitIds($screen))->toBe(['c2', 'c1'])
        ->and($screen->get('nextCursor'))->toBe('page3');
})->with([
    'devnull turns it down' => false,
    'the background thread fails' => true,
]);

it('forgets a next page that failed with nothing waiting for it, and asks for it at the end of the list', function () {
    $asked = 0;

    serveLogPages([
        'log/following' => [pageJson(commitsWithIds(['c2']), 'page2')],
        'log/following?cursor=page2' => function () use (&$asked) {
            return ++$asked === 1
                ? Http::response(['message' => 'Too Many Attempts.'], 429)
                : Http::response(pageJson(commitsWithIds(['c1'])));
        },
    ]);

    // Fetched ahead, a failure says nothing.
    $screen = Native::visit('/log')->assertDontSee('Too Many Attempts.');

    expect($asked)->toBe(1);

    $screen->call('loadMore');

    expect(logCommitIds($screen))->toBe(['c2', 'c1'])
        ->and($asked)->toBe(2);
});

it('never puts a page held from before under a list that has been replaced since', function (string $replacedBy) {
    $log = $replacedBy === 'a fresh ranking' ? 'trending' : 'following';

    serveLogPages([
        'log/following' => [pageJson(commitsWithIds(['f1']), 'page2')],
        'log/latest' => [pageJson(commitsWithIds(['l1']))],
        'log/trending' => [rankingJson(['t1'], 'page2')],
        'log/following?cursor=page2' => [pageJson(commitsWithIds(['from-before']))],
        'log/trending?cursor=page2' => [pageJson(commitsWithIds(['from-before']))],
    ]);

    $screen = Native::visit('/log');

    if ($log === 'trending') {
        tapLogTab($screen, 'trending');
    }

    // The next page, held. Its cursor reads the same after, but the list it points into won't be the same list.
    expect(timesAskedFor("log/{$log}?cursor=page2"))->toBe(1);

    serveLogPages([
        // Nothing in common with what's showing, so the pill puts these in place of it.
        'log/following' => [$replacedBy === 'the pill' ? [...pageJson(commitsWithIds(['n2', 'n1']), 'page2'), 'new_commits_count' => 2] : pageJson(commitsWithIds(['f2', 'f1']), 'page2')],
        'log/latest' => [pageJson(commitsWithIds(['l1']))],
        'log/trending' => [rankingJson(['t2', 't1'], 'page2')],
        'log/following?cursor=page2' => [pageJson(commitsWithIds(['from-now']))],
        'log/trending?cursor=page2' => [pageJson(commitsWithIds(['from-now']))],
    ]);

    match ($replacedBy) {
        'a fresh ranking', 'pull to refresh' => $screen->call('load'),
        'another log' => tapLogTab(tapLogTab($screen, 'latest'), 'following'),
        'the pill' => $screen->call('checkForNewCommits')->tap('new-commits'),
    };

    $screen->call('loadMore');

    expect(logCommitIds($screen))->toContain('from-now')
        ->not->toContain('from-before')
        ->and(timesAskedFor("log/{$log}?cursor=page2"))->toBe(2);
})->with(['a fresh ranking', 'another log', 'pull to refresh', 'the pill']);

it('lets go of a next page that lands after the list it was for was replaced', function () {
    holdRefreshes();
    keep('log/following', pageJson(commitsWithIds(['f1']), 'page2'));
    keep('log/latest', pageJson(commitsWithIds(['l1']), path: 'http://devnull.test/api/v1/log/latest'));
    $asked = 0;

    serveLogPages([
        'log/following' => [pageJson(commitsWithIds(['f1']), 'page2')],
        'log/following?cursor=page2' => function () use (&$asked) {
            return Http::response(pageJson(commitsWithIds([++$asked === 1 ? 'from-before' : 'from-now'])));
        },
        'log/latest' => [pageJson(commitsWithIds(['l1']), path: 'http://devnull.test/api/v1/log/latest')],
    ]);

    // Following's next page is asked for, you swipe away and back before it lands, and reach the end.
    $screen = Native::visit('/log');
    tapLogTab(tapLogTab($screen, 'latest'), 'following')->call('loadMore');

    // The first answer lands first, for a list that's gone.
    finishRefreshes($screen);

    expect($asked)->toBe(2)
        ->and(logCommitIds($screen))->toBe(['f1', 'from-now']);
});

it('puts a next page that is already here in a little before the end of the list, and never asks for one itself', function () {
    serveLogPages([
        'log/following' => [pageJson(commitsWithIds(array_map(fn (int $n): string => "c{$n}", range(0, 99))), 'page2')],
        'log/following?cursor=page2' => [pageJson(commitsWithIds(['older']), 'page3')],
        'log/following?cursor=page3' => [['message' => 'Too Many Attempts.'], 429],
    ]);

    // A day marker, then c0 to c99 as timeline rows 1 to 100, with the next page held.
    $screen = Native::visit('/log');
    $edge = 100 - Log::NextPageWithin;

    // Far from the end, then one row short of near enough, it waits.
    $screen->call('showRows', 10, 15)->call('showRows', $edge - 6, $edge - 1);

    expect($screen->get('nextCursor'))->toBe('page2')
        ->and(logCommitIds($screen))->not->toContain('older');

    $screen->call('showRows', $edge - 5, $edge);

    expect(logCommitIds($screen))->toContain('older')
        ->and($screen->get('nextCursor'))->toBe('page3')
        ->and(timesAskedFor('log/following?cursor=page2'))->toBe(1);

    // The page after it failed to come ahead, so there's nothing here to put in, and nothing is asked for.
    $requests = count(Http::recorded());

    $screen->call('showRows', 95, 101);

    expect(count(Http::recorded()))->toBe($requests)
        ->and($screen->get('nextCursor'))->toBe('page3');
});

it('tops the log up while it is at rest once you have scrolled, and holds what lands while it moves', function () {
    holdRefreshes();
    $page = fn (int $n, ?string $next): array => [pageJson(commitsWithIds(array_map(fn (int $i): string => "p{$n}-{$i}", range(1, 30))), $next)];
    serveLogPages([
        'log/following' => $page(1, 'page2'),
        'log/following?cursor=page2' => $page(2, 'page3'),
        'log/following?cursor=page3' => $page(3, 'page4'),
        'log/following?cursor=page4' => $page(4, 'page5'),
        'log/following?cursor=page5' => $page(5, null),
    ]);

    // Opening the log fetches one page ahead, and holds it.
    $screen = finishRefreshes(Native::visit('/log'));

    expect(logCommitIds($screen))->toHaveCount(30)
        ->and(timesAskedFor('log/following?cursor=page3'))->toBe(0);

    // At rest near the top, pages go in as they land, until 84 timeline rows are below the screen.
    finishRefreshes($screen->call('scrollEnded', 1, 6));

    expect(logCommitIds($screen))->toHaveCount(90)
        ->and(timesAskedFor('log/following?cursor=page4'))->toBe(1)
        ->and(timesAskedFor('log/following?cursor=page5'))->toBe(0);

    // Moving, clear of the end, the page held waits for the list to stop.
    $screen->call('showRows', 40, 45);

    expect(logCommitIds($screen))->toHaveCount(90);

    $screen->call('scrollEnded', 40, 45);

    expect(logCommitIds($screen))->toHaveCount(120);

    // And so does one that lands while it moves.
    finishRefreshes($screen->call('showRows', 50, 55));

    expect(logCommitIds($screen))->toHaveCount(120);

    $screen->call('scrollEnded', 50, 55);

    expect(logCommitIds($screen))->toHaveCount(150)
        ->and($screen->get('nextCursor'))->toBeNull();
});

it('asks again for a next page dropped while another screen was on top, and puts it in if the end of the list was waiting', function () {
    holdRefreshes();
    keep('log/following', pageJson(commitsWithIds(['c2']), 'page2'));
    serveLogPages([
        'log/following' => [pageJson(commitsWithIds(['c2']), 'page2')],
        'log/following?cursor=page2' => [pageJson(commitsWithIds(['c1']))],
    ]);

    // You reach the end, then open a commit before the next page lands, and its answer is dropped.
    $screen = Native::visit('/log')->call('loadMore');
    dropRefreshes($screen);

    finishRefreshes($screen->call('onResume'));

    expect(logCommitIds($screen))->toBe(['c2', 'c1'])
        ->and(timesAskedFor('log/following?cursor=page2'))->toBe(1);
});
