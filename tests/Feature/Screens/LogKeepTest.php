<?php

use App\Devnull\Client;
use App\Devnull\RowWindow;
use App\NativeComponents\Log;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * devnull's logs by "log/{log}" for the first page and "log/{log}?cursor={cursor}"
 * for the ones after it, any other GET by its path, such as "commits/c1/replies",
 * and writes by "POST commits/c1/star". Each answers with its body, or with
 * the response given. A later call swaps what they answer. `me` always
 * answers, and anything else gets a 404.
 *
 * @param  array<string, mixed>  $answers
 */
function serveKeptLogs(array $answers): void
{
    serveApi(function (Request $request) use ($answers) {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1/'));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $asked = match (true) {
            $request->method() !== 'GET' => $request->method().' '.$path,
            isset($query['cursor']) => "{$path}?cursor={$query['cursor']}",
            default => $path,
        };
        $answer = $answers[$asked] ?? null;

        return match (true) {
            $path === 'me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
            $answer instanceof PromiseInterface => $answer,
            $answer !== null => Http::response($answer),
            default => Http::response(['message' => "No fake for {$asked}."], 404),
        };
    });
}

/**
 * How many times devnull was asked for a path and query, such as "log/following?cursor=page2".
 */
function timesKeptLogAskedFor(string $asked): int
{
    return count(Http::recorded(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/'.$asked));
}

/**
 * A page of a log with commits "{$prefix}{$from}" to "{$prefix}{$to}", newest first.
 *
 * @return array<string, mixed>
 */
function keptLogPage(string $log, string $prefix, int $from, int $to, ?string $nextCursor = null): array
{
    $page = pageJson(
        array_map(fn (int $n): array => commitJson(['id' => "{$prefix}{$n}", 'body' => "Commit {$prefix}{$n}"]), range($from, $to)),
        $nextCursor,
        path: "http://devnull.test/api/v1/log/{$log}",
    );

    return $log === Log::Ranked
        ? [...$page, 'log' => 'trending', 'is_ranked' => true, 'newest_commit_id' => "{$prefix}{$from}", 'suggestions' => []]
        : $page;
}

/**
 * The commit ids of the log you're on, in order.
 *
 * @return list<string>
 */
function keptCommitIds(TestableComponent $screen): array
{
    return array_map(fn ($commit): string => $commit->id, [...$screen->instance()->latestCommits, ...$screen->instance()->commits]);
}

/**
 * Each row of a log's list as its ref, or "placeholder", for the log you're on or one beside it.
 *
 * @return list<string|null>
 */
function keptListRows(TestableComponent $screen, string $log): array
{
    $list = nodeByRef($screen, $screen->get('log') === $log ? 'log' : 'log-'.$log);

    return array_map(fn (array $row): ?string => $row['type'] === 'list_placeholder' ? 'placeholder' : $row['ref'] ?? null, $list['children']);
}

/**
 * How many nodes a tree has, itself included.
 *
 * @param  array<string, mixed>  $node
 */
function nodesIn(array $node): int
{
    return 1 + array_sum(array_map(nodesIn(...), $node['children'] ?? []));
}

it('keeps each log as you left it when you swipe away and back: its commits, next page, open threads and list', function () {
    holdRefreshes();
    $following = keptLogPage('following', 'f', 0, 59, 'page2');
    $following['data'][1]['replies_count'] = 1;
    serveKeptLogs([
        'log/following' => $following,
        'log/following?cursor=page2' => keptLogPage('following', 'f', 60, 79),
        'log/latest' => keptLogPage('latest', 'l', 0, 19),
        'commits/f1/replies' => pageJson([replyJson(['id' => 'r1', 'parent_id' => 'f1', 'body' => 'A reply in place'])]),
    ]);

    // Following's first page, the next one held behind it, and a thread opened.
    $screen = finishRefreshes(Native::visit('/log'));
    finishRefreshes($screen->tap('replies-f1'))->assertSee('A reply in place');

    $list = showingLog($screen)['id'];
    $listKey = $screen->get('listKey');

    finishRefreshes(tapLogTab($screen, 'latest'));

    // Latest is itself, and Following waits to the side as it was.
    expect(keptCommitIds($screen))->toBe(array_map(fn (int $n): string => "l{$n}", range(0, 19)))
        ->and($screen->get('threads'))->toBe([])
        ->and(nodeByRef($screen, 'log-following')['id'])->toBe($list);

    tapLogTab($screen, 'following');

    expect(keptCommitIds($screen))->toBe(array_map(fn (int $n): string => "f{$n}", range(0, 59)))
        ->and($screen->get('nextCursor'))->toBe('page2')
        ->and(array_keys($screen->get('threads')))->toBe(['f1'])
        ->and($screen->get('listKey'))->toBe($listKey)
        ->and(showingLog($screen)['id'])->toBe($list);

    // The page held for it came back with it, so the end of the list puts it in without asking again.
    $screen->call('loadMore');

    expect(keptCommitIds($screen))->toHaveCount(80)
        ->and(timesKeptLogAskedFor('log/following?cursor=page2'))->toBe(1);
});

it('keeps the list key of a log you swipe away from, and of the one you swipe back to, however often you swipe', function () {
    holdRefreshes();
    serveKeptLogs([
        'log/following' => keptLogPage('following', 'f', 0, 19),
        'log/latest' => keptLogPage('latest', 'l', 0, 19),
        'log/trending' => keptLogPage('trending', 't', 0, 19),
    ]);

    // Each log opened once.
    $screen = finishRefreshes(Native::visit('/log'));
    finishRefreshes(tapLogTab($screen, 'latest'));
    finishRefreshes(tapLogTab($screen, 'trending'));
    finishRefreshes(tapLogTab($screen, 'following'));

    $keys = fn (): array => array_map(fn (string $log): int => nodeByRef($screen, $screen->get('log') === $log ? 'log' : 'log-'.$log)['id'], Log::Logs);
    $drawnIn = $keys();

    foreach (['trending', 'latest', 'following', 'latest', 'trending', 'following'] as $log) {
        finishRefreshes(tapLogTab($screen, $log));

        expect($keys())->toBe($drawnIn);
    }

    // A tap on the tab you're on still takes that log, and only that one, back to the top of a fresh list.
    tapLogTab($screen, 'following');

    expect($keys()[0])->not->toBe($drawnIn[0])
        ->and(array_slice($keys(), 1))->toBe(array_slice($drawnIn, 1));
});

it('keeps Trending as it was ranked when you swipe back, with what was put above it, and only asks what is new since', function () {
    holdRefreshes();
    serveKeptLogs([
        'log/following' => keptLogPage('following', 'f', 0, 2),
        'log/trending' => [...keptLogPage('trending', 't', 0, 2), 'newest_commit_id' => 't0', 'latest_commits' => [commitJson(['id' => 'n1', 'author' => userJson(['id' => 'u2', 'username' => 'ada'])])], 'new_commits_count' => 1],
    ]);

    $screen = finishRefreshes(tapLogTab(finishRefreshes(Native::visit('/log')), 'trending'));
    finishRefreshes($screen->call('checkForNewCommits'))->tap('new-commits');

    expect(keptCommitIds($screen))->toBe(['n1', 't0', 't1', 't2']);

    $listKey = $screen->get('listKey');

    // Devnull would rank it differently by now.
    serveKeptLogs([
        'log/following' => keptLogPage('following', 'f', 0, 2),
        'log/trending' => keptLogPage('trending', 'x', 0, 2),
    ]);

    finishRefreshes(tapLogTab($screen, 'following'));
    $sent = count(Http::recorded());
    finishRefreshes(tapLogTab($screen, 'trending'));

    $asked = array_map(fn (array $pair): string => substr($pair[0]->url(), strlen('http://devnull.test/api/v1/')), array_slice(Http::recorded()->all(), $sent));

    expect(keptCommitIds($screen))->toBe(['n1', 't0', 't1', 't2'])
        ->and($screen->get('rankedSince'))->toBe('n1')
        ->and($screen->get('listKey'))->toBe($listKey)
        ->and($asked)->toContain('log/trending?since=n1')
        ->not->toContain('log/trending');

    // Pull to refresh still ranks it afresh.
    finishRefreshes($screen->call('load'));

    expect(keptCommitIds($screen))->toBe(['x0', 'x1', 'x2']);
});

it('opens a log afresh that you left before it loaded, and ranks Trending then', function () {
    holdRefreshes();
    serveKeptLogs([
        'log/following' => keptLogPage('following', 'f', 0, 2),
        'log/trending' => keptLogPage('trending', 't', 0, 2),
    ]);

    // Trending's ranking hadn't come when you swiped away, and lands for a log you're not on.
    $screen = tapLogTab(finishRefreshes(Native::visit('/log')), 'trending');
    finishRefreshes(tapLogTab($screen, 'following'));

    expect(timesKeptLogAskedFor('log/trending'))->toBe(1);

    finishRefreshes(tapLogTab($screen, 'trending'));

    expect(keptCommitIds($screen))->toBe(['t0', 't1', 't2'])
        ->and(timesKeptLogAskedFor('log/trending'))->toBe(2);
});

it('draws a log you have left only around where you left it, so a frame with a few hundred commits in each log stays small', function () {
    serveKeptLogs([
        'log/following' => keptLogPage('following', 'f', 0, 299),
        'log/latest' => keptLogPage('latest', 'l', 0, 299),
        'log/trending' => keptLogPage('trending', 't', 0, 299),
    ]);

    // Each log scrolled a long way down, then left.
    $screen = Native::visit('/log')->call('showRows', 'following', 200, 206);
    tapLogTab($screen, 'latest')->call('showRows', 'latest', 150, 156);
    tapLogTab($screen, 'trending')->call('showRows', 'trending', 100, 106);
    $showing = nodesIn(showingLog($screen));
    tapLogTab($screen, 'following');

    // Latest: a day marker, then l0 to l299 as rows 1 to 300, on screen from row 150, then the root commit's end.
    // Only the rows around the screen are drawn.
    $latest = keptListRows($screen, 'latest');
    $drawn = array_keys(array_filter($latest, fn (?string $row): bool => str_starts_with((string) $row, 'commit-')));

    expect($latest)->toHaveCount(302)
        ->and($drawn[0])->toBe(150 - RowWindow::Resting)
        ->and(end($drawn))->toBe(156 + RowWindow::Resting)
        ->and(count($drawn))->toBe(156 - 150 + 1 + 2 * RowWindow::Resting)
        ->and($latest[0])->toBe('placeholder')
        ->and(array_count_values($latest)['placeholder'])->toBe(301 - count($drawn));

    // Each page you're not on costs a fraction of the one you're on, which draws 250 rows round the screen.
    $sizes = array_map(fn (string $log): int => nodesIn(nodeByRef($screen, 'log-'.$log)), ['latest', 'trending']);

    expect($sizes[0])->toBeLessThan(intdiv($showing, 3))
        ->and($sizes[1])->toBeLessThan(intdiv($showing, 3));
});

it('draws a log you swipe back to from the rows it kept, building only those that changed', function () {
    holdRefreshes();
    serveKeptLogs([
        'log/following' => keptLogPage('following', 'f', 0, 29),
        'log/latest' => keptLogPage('latest', 'l', 0, 29),
    ]);

    $screen = finishRefreshes(Native::visit('/log'));
    finishRefreshes(tapLogTab($screen, 'latest'));

    $built = [];
    View::composer('native.partials.commit-row', function ($view) use (&$built) {
        $built[] = $view->getData()['commit']->id;
    });

    // Following kept every row it drew while you were away, though it only drew those on its screen.
    tapLogTab($screen, 'following');

    expect(array_filter($built, fn (string $id): bool => str_starts_with($id, 'f')))->toBe([]);
});

it('only loads more of the log you are on, whichever list reaches its end', function () {
    holdRefreshes();
    serveKeptLogs([
        'log/following' => keptLogPage('following', 'f', 0, 59, 'page2'),
        'log/following?cursor=page2' => keptLogPage('following', 'f', 60, 79),
        'log/latest' => keptLogPage('latest', 'l', 0, 59, 'page2'),
        'log/latest?cursor=page2' => keptLogPage('latest', 'l', 60, 79),
    ]);

    $screen = finishRefreshes(Native::visit('/log'));
    finishRefreshes(tapLogTab($screen, 'latest'));

    // Following's list reached its end as you swiped away from it.
    $screen->call('loadMore', 'following');

    expect(keptCommitIds($screen))->toHaveCount(60);

    $screen->call('loadMore', 'latest');

    expect(keptCommitIds($screen))->toHaveCount(80);
});

it('puts the next page in as soon as it lands when you swipe back to a log you left near its end', function () {
    holdRefreshes();
    keep('log/following', keptLogPage('following', 'f', 0, 99, 'page2'));
    serveKeptLogs([
        'log/following' => keptLogPage('following', 'f', 0, 99, 'page2'),
        'log/following?cursor=page2' => keptLogPage('following', 'f', 100, 119),
        'log/latest' => keptLogPage('latest', 'l', 0, 19),
    ]);

    // Rows 1 to 100 are f0 to f99. You leave Following with f90 to f96 on screen, before its next page lands.
    $screen = Native::visit('/log')->call('showRows', 'following', 20, 26)->call('showRows', 'following', 91, 97);
    finishRefreshes(tapLogTab($screen, 'latest'));

    // Its answer was dropped while you were away, so coming back asks again, and the end of the list waits for it:
    // it may be on screen already, where the list won't say it reached it again.
    tapLogTab($screen, 'following');

    expect(keptCommitIds($screen))->toHaveCount(100);

    finishRefreshes($screen);

    expect(keptCommitIds($screen))->toHaveCount(120)
        ->and(timesKeptLogAskedFor('log/following?cursor=page2'))->toBe(2);
});

it('takes a commit you delete, or the commits of someone you mute, out of the logs you have left too', function () {
    $ada = userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada']);
    serveKeptLogs([
        'log/following' => pageJson([commitJson(['id' => 'mine', 'body' => 'Mine']), commitJson(['id' => 'by-ada', 'author' => $ada]), commitJson(['id' => 'f1'])]),
        'log/latest' => pageJson([commitJson(['id' => 'mine', 'body' => 'Mine']), commitJson(['id' => 'by-ada', 'author' => $ada]), commitJson(['id' => 'l1'])], path: 'http://devnull.test/api/v1/log/latest'),
        'DELETE commits/mine' => Http::response(null, 204),
        'POST users/ada/mute' => ['data' => $ada, 'is_muting' => true],
    ]);

    $screen = tapLogTab(Native::visit('/log'), 'latest');

    // Both are done on other screens, then you come back to the Log.
    app(Client::class)->deleteCommit('mine');
    app(Client::class)->mute('ada');
    $screen->call('onResume');

    expect(keptCommitIds($screen))->toBe(['l1']);

    holdRefreshes();
    tapLogTab($screen, 'following');

    expect(keptCommitIds($screen))->toBe(['f1']);
});

it('stars a commit in the logs you have left too, past the first page kept for them', function () {
    holdRefreshes();
    serveKeptLogs([
        'log/following' => keptLogPage('following', 'f', 0, 59, 'page2'),
        'log/following?cursor=page2' => pageJson([commitJson(['id' => 'both'])]),
        'log/latest' => pageJson([commitJson(['id' => 'both']), commitJson(['id' => 'l1'])], path: 'http://devnull.test/api/v1/log/latest'),
        'POST commits/both/star' => ['data' => commitJson(['id' => 'both', 'stars_count' => 1, 'starred' => true])],
    ]);

    $screen = finishRefreshes(Native::visit('/log'))->call('loadMore');
    finishRefreshes(tapLogTab($screen, 'latest'))->tap('star-both');

    tapLogTab($screen, 'following');

    $both = array_values(array_filter($screen->instance()->commits, fn ($commit): bool => $commit->id === 'both'))[0];

    expect($both->starred)->toBeTrue()
        ->and($both->starsCount)->toBe(1);
});
