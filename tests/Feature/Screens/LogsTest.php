<?php

use App\Devnull\Client;
use App\Devnull\Timeline;
use App\NativeComponents\CommitDetail;
use App\NativeComponents\Log;
use App\NativeComponents\Notifications;
use App\NativeComponents\Profile;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * The log requests sent so far, as "path?query".
 *
 * @return list<string>
 */
function logRequestsSent(): array
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/v1/log/'))
        ->map(fn (array $pair): string => substr($pair[0]->url(), strlen('http://devnull.test/api/v1/')))
        ->values()
        ->all();
}

/**
 * Commit ids in the order the log shows them.
 *
 * @return list<string>
 */
function shownLogIds(TestableComponent $screen): array
{
    $rows = Closure::bind(fn (): array => $this->frame()->getData()['rows'], $screen->instance(), Log::class)();

    return array_map(fn ($row): string => $row->commit->id, array_values(array_filter($rows, fn ($row): bool => $row->isCommit())));
}

/**
 * A ranked Trending page, as devnull sends it.
 *
 * @param  list<array<string, mixed>>  $commits
 * @param  list<array<string, mixed>>|null  $latest  only sent for ?since=
 * @return array<string, mixed>
 */
function trendingJson(array $commits, string $newest, ?array $latest = null, int $count = 0): array
{
    return [
        ...pageJson($commits, path: 'http://devnull.test/api/v1/log/trending'),
        'log' => 'trending',
        'is_ranked' => true,
        'newest_commit_id' => $newest,
        ...$latest === null ? [] : ['latest_commits' => $latest, 'new_commits_count' => $count],
        'suggestions' => [],
    ];
}

function ada(): array
{
    return userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada', 'lane' => 2]);
}

it('has the web\'s three logs, each asked for by name, and no Everyone', function () {
    serveApi(fn (Request $request) => Http::response(pageJson([commitJson(['id' => 'c-'.basename(parse_url($request->url(), PHP_URL_PATH)), 'body' => 'From '.basename(parse_url($request->url(), PHP_URL_PATH))])])));

    $screen = Native::visit('/log')->assertSee('From following');

    tapLogTab($screen, 'latest')->assertSet('log', 'latest');

    expect(treeShows(showingLog($screen), 'From latest'))->toBeTrue()
        ->and(treeShows(showingLog($screen), 'From following'))->toBeFalse();

    tapLogTab($screen, 'trending')->assertSet('log', 'trending');

    expect(treeShows(showingLog($screen), 'From trending'))->toBeTrue()
        ->and(treeShows(showingLog($screen), 'From latest'))->toBeFalse();

    tapLogTab($screen, 'following')->assertSet('log', 'following');

    expect(treeShows(showingLog($screen), 'From following'))->toBeTrue();

    expect(array_map(fn (string $sent): string => strtok($sent, '?'), logRequestsSent()))->toContain('log/following', 'log/latest', 'log/trending')
        ->and(implode(' ', logRequestsSent()))->not->toContain('everyone');

    // A log that isn't one of the three is never asked for.
    $before = count(Http::recorded());
    $screen->call('show', 'everyone')->assertSet('log', 'following');

    expect(count(Http::recorded()))->toBe($before);
});

it('gives each log its own list, showing what was kept with no spinner', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'f1', 'body' => 'Kept on Following'])]));
    keep('log/latest', pageJson([commitJson(['id' => 'l1', 'body' => 'Kept on Latest'])]));
    keep('log/trending', trendingJson([commitJson(['id' => 't1', 'body' => 'Kept on Trending'])], newest: 't1'));

    $screen = Native::visit('/log');
    $lists = fn (): array => array_map(fn (array $list): int => $list['id'], findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'list'));

    expect($lists())->toHaveCount(3)
        ->and(array_unique($lists()))->toHaveCount(3);

    foreach (['latest' => 'Kept on Latest', 'trending' => 'Kept on Trending', 'following' => 'Kept on Following'] as $log => $kept) {
        tapLogTab($screen, $log);

        expect(treeShows(showingLog($screen), $kept))->toBeTrue()
            ->and(showsLoading(showingLog($screen)))->toBeFalse()
            ->and(array_unique($lists()))->toHaveCount(3);
    }
});

it('swipes between the logs both ways under the tabs the pager draws, and stops at the ends', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'f1', 'body' => 'Kept on Following'])]));
    keep('log/latest', pageJson([commitJson(['id' => 'l1', 'body' => 'Kept on Latest'])]));
    keep('log/trending', trendingJson([commitJson(['id' => 't1', 'body' => 'Kept on Trending'])], newest: 't1'));

    $screen = Native::visit('/log');
    $pager = fn (): array => nodeByRef($screen, 'log-pager');

    // The pager draws the tabs, and says where it is once a swipe has settled.
    expect($pager()['props'])->toMatchArray([
        'horizontal' => true,
        'count' => 3,
        'page' => 0,
        'tabs' => ['Following', 'Latest', 'Trending'],
        'report_on_settle' => true,
    ])->and($pager()['props'])->toHaveKey('on_tab_reselect');

    // A swipe to the left, then another.
    $screen->changeTab('showPage', 1)->assertSet('log', 'latest');

    expect($pager()['props']['page'])->toBe(1)
        ->and(treeShows(showingLog($screen), 'Kept on Latest'))->toBeTrue();

    $screen->changeTab('showPage', 2)->assertSet('log', 'trending');

    expect($pager()['props']['page'])->toBe(2)
        ->and(treeShows(showingLog($screen), 'Kept on Trending'))->toBeTrue();

    // Past the last log there's nothing, and it doesn't wrap round to the first.
    $screen->changeTab('showPage', 3)->assertSet('log', 'trending');

    // And back to the right.
    $screen->changeTab('showPage', 1)->assertSet('log', 'latest');
    $screen->changeTab('showPage', 0)->assertSet('log', 'following');

    expect($pager()['props']['page'])->toBe(0)
        ->and(treeShows(showingLog($screen), 'Kept on Following'))->toBeTrue();

    $screen->changeTab('showPage', -1)->assertSet('log', 'following');
});

it('moves the pager when a link shows another log, and takes the log you are on back to its top when its tab is tapped', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'f1'])]));

    $screen = Native::visit('/log');
    $pager = fn (): array => nodeByRef($screen, 'log-pager');
    $first = $pager()['id'];

    // The empty Following log's Trending link, say.
    $screen->call('show', 'trending')->assertSet('log', 'trending');

    expect($pager()['id'])->toBe($first)
        ->and($pager()['props']['page'])->toBe(2);

    $list = showingLog($screen)['id'];

    // The pager sends a tap on the tab you're on rather than moving.
    $screen->changeTab('backToTop', 2)->assertSet('log', 'trending');

    expect($pager()['id'])->toBe($first)
        ->and(showingLog($screen)['id'])->not->toBe($list);
});

it('changes nothing when the pager reports the log it is already on', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'f1'])]));

    $screen = Native::visit('/log');
    $sent = refreshesSent($screen);
    $list = showingLog($screen)['id'];

    $screen->changeTab('showPage', 0);

    expect(refreshesSent($screen))->toBe($sent)
        ->and(showingLog($screen)['id'])->toBe($list);
});

it('keeps the list a log was drawn in when you swipe to it, and starts the log you leave again in a fresh one', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'f1'])]));
    keep('log/latest', pageJson([commitJson(['id' => 'l1'])]));
    keep('log/trending', trendingJson([commitJson(['id' => 't1'])], newest: 't1'));

    $screen = Native::visit('/log');
    $listOf = fn (string $log): int => nodeByRef($screen, $screen->get('log') === $log ? 'log' : 'log-'.$log)['id'];

    $following = $listOf('following');
    $latest = $listOf('latest');
    $trending = $listOf('trending');

    // Swiped to, Latest fills the list its first commits were drawn in. Following, left, gets a fresh list at the top.
    $screen->changeTab('showPage', 1);

    expect($listOf('latest'))->toBe($latest)
        ->and($listOf('following'))->not->toBe($following)
        ->and($listOf('trending'))->toBe($trending);

    $screen->changeTab('showPage', 2);

    expect($listOf('trending'))->toBe($trending)
        ->and($listOf('latest'))->not->toBe($latest);
});

it('opens the log you swipe back to from what was kept, at the top, and asks what is new since', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'f1', 'body' => 'First page'])], 'page2'));
    keep('log/latest', pageJson([commitJson(['id' => 'l1'])]));
    serveApi(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/v1/log/following' => Http::response(str_contains($request->url(), 'cursor=page2')
            ? pageJson([commitJson(['id' => 'f2', 'body' => 'Second page'])])
            : pageJson([commitJson(['id' => 'f1', 'body' => 'First page'])], 'page2')),
        '/api/v1/me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
        default => Http::response(['message' => 'No fake.'], 404),
    });

    // The second page was already on its way, so it goes in when it lands.
    $screen = finishRefreshes(Native::visit('/log')->call('loadMore'));

    expect(shownLogIds($screen))->toBe(['f1', 'f2']);

    $screen->changeTab('showPage', 1);

    // Off to the side, Following shows the first of what was kept, ready for a swipe back.
    expect(treeShows(nodeByRef($screen, 'log-following'), 'First page'))->toBeTrue()
        ->and(treeShows(nodeByRef($screen, 'log-following'), 'Second page'))->toBeFalse();

    $sent = count(logRequestsSent());
    $screen->changeTab('showPage', 0);

    // No skeleton rows: only the one standing in for the next page, under what was kept.
    expect(shownLogIds($screen))->toBe(['f1'])
        ->and(findNode(showingLog($screen), fn (array $node): bool => ($node['ref'] ?? null) === 'loading-1'))->toBeNull();

    finishRefreshes($screen);

    expect(array_slice(logRequestsSent(), $sent))->toContain('log/following?since=f1');
});

it('shows the first few commits kept for a log you are not on, and has only the log you are on ask for its next page', function () {
    holdRefreshes();
    keep('log/latest', pageJson(array_map(fn (int $n): array => commitJson(['id' => "l{$n}", 'body' => "Latest {$n}"]), range(1, 10)), 'page2'));

    $screen = Native::visit('/log');
    $latest = nodeByRef($screen, 'log-latest');
    $trending = nodeByRef($screen, 'log-trending');

    expect(treeShows($latest, 'Latest '.Log::PreviewCommits))->toBeTrue()
        ->and(treeShows($latest, 'Latest '.(Log::PreviewCommits + 1)))->toBeFalse()
        // Nothing kept for Trending yet: skeleton rows, as it would open.
        ->and(findNode($trending, fn (array $node): bool => ($node['ref'] ?? null) === 'loading-1'))->not->toBeNull();

    // Pull to refresh stays on every list: iOS rebuilds a list that gains or loses it, at the top.
    foreach ([showingLog($screen), $latest, $trending] as $list) {
        expect($list['props'])->toHaveKey('on_refresh')
            ->and(array_key_exists('on_end_reached', $list['props']))->toBe($list['ref'] === 'log');
    }
});

it('leaves the log two swipes away as skeleton rows until you are beside it', function () {
    holdRefreshes();
    keep('log/trending', pageJson(array_map(fn (int $n): array => commitJson(['id' => "t{$n}", 'body' => "Trending {$n}"]), range(1, 5))));

    // From Following, Trending is two swipes away, so none of what was kept for it is sent.
    $screen = Native::visit('/log');

    expect(treeShows(nodeByRef($screen, 'log-trending'), 'Trending 1'))->toBeFalse()
        ->and(findNode(nodeByRef($screen, 'log-trending'), fn (array $node): bool => ($node['ref'] ?? null) === 'loading-1'))->not->toBeNull();

    // Beside it, on Latest, it shows the first of what was kept.
    $screen->changeTab('showPage', 1);

    expect(treeShows(nodeByRef($screen, 'log-trending'), 'Trending '.Log::PreviewCommits))->toBeTrue()
        ->and(treeShows(nodeByRef($screen, 'log-trending'), 'Trending '.(Log::PreviewCommits + 1)))->toBeFalse();
});

it('holds new commits in the pill after a swipe, over what was kept, with no spinner', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'f1'])]));
    keep('log/latest', pageJson([commitJson(['id' => 'l1', 'body' => 'Seen before'])]));
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'f1'])])],
        'GET /api/v1/log/latest' => [[...pageJson([commitJson(['id' => 'l2', 'body' => 'Just in', 'author' => ada()]), commitJson(['id' => 'l1', 'body' => 'Seen before'])]), 'new_commits_count' => 1]],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = finishRefreshes(Native::visit('/log'))->changeTab('showPage', 1);

    expect(shownLogIds($screen))->toBe(['l1'])
        ->and(showsLoading(showingLog($screen)))->toBeFalse();

    finishRefreshes($screen)->assertSee('1 new commit');

    expect(logRequestsSent())->toContain('log/latest?since=l1');

    $screen->tap('new-commits');

    expect(shownLogIds($screen))->toBe(['l2', 'l1']);
});

it('keys each row by what it shows, so a commit put on top leaves the rows under it as they were', function () {
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Already here'])])],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'mine', 'body' => 'Fresh from the composer'])], 201],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = Native::visit('/log');
    $row = nodeByRef($screen, 'commit-c1')['id'];

    $screen->press('composeInSheet')->input('sheet-body', 'Fresh from the composer')->tap('sheet-commit');

    expect(shownLogIds($screen))->toBe(['mine', 'c1'])
        ->and(nodeByRef($screen, 'commit-c1')['id'])->toBe($row);
});

it('sends the Log, Notifications, profiles and commit pages in full every frame, as EDGE advises for lists that change often', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    foreach ([Log::class, Notifications::class, Profile::class, CommitDetail::class] as $screen) {
        $instance = Native::test($screen)->instance();

        expect((fn (): bool => $this->forceFullFrames)->call($instance))->toBeTrue();
    }
});

it('asks Following and Latest what came in since the newest commit showing, and counts the pill by devnull', function () {
    holdRefreshes();
    keep('log/latest', pageJson([commitJson(['id' => 'c1', 'body' => 'Seen before'])]));

    // 25 came in: a page of 20, none of them showing yet.
    $newer = array_map(fn (int $n): array => commitJson(['id' => "n{$n}", 'body' => "New {$n}", 'author' => ada()]), range(1, 20));
    serveApi([
        'GET /api/v1/log/following' => [pageJson([])],
        'GET /api/v1/log/latest' => [[...pageJson($newer, 'page2'), 'new_commits_count' => 25]],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = finishRefreshes(tapLogTab(Native::visit('/log'), 'latest'))
        ->assertSee('Seen before')
        ->assertSee('25 new commits')
        ->assertDontSee('20+');

    expect(logRequestsSent())->toContain('log/latest?since=c1');

    $screen->tap('new-commits')->assertSee('New 1')->assertDontSee('25 new commits');

    expect(shownLogIds($screen)[0])->toBe('n1');
});

it('keeps a log without the since it was asked with, or the counts that came with it', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'body' => 'Seen before'])]));
    serveApi([
        'GET /api/v1/log/following' => [[...pageJson([commitJson(['id' => 'c2', 'body' => 'Newer', 'author' => ada()]), commitJson(['id' => 'c1', 'body' => 'Seen before'])]), 'new_commits_count' => 1]],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    finishRefreshes(Native::visit('/log'))->assertSee('1 new commit');

    expect(kept('log/following'))->not->toHaveKey('new_commits_count')
        ->and(array_column(kept('log/following')['data'], 'id'))->toBe(['c2', 'c1'])
        ->and(kept('log/following', ['since' => 'anything']))->toBe(kept('log/following'));
});

it('suggests people to follow on an empty Following log, and follows one in place', function () {
    $suggestion = commitJson(['id' => 's1', 'body' => 'Shipping a CLI today', 'author' => ada()]);
    $followed = false;

    serveApi(function (Request $request) use ($suggestion, &$followed) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && $path === '/api/v1/users/ada/follow') {
            $followed = true;

            return Http::response(null, 204);
        }

        return match (true) {
            $path === '/api/v1/log/following' => Http::response($followed
                ? pageJson([commitJson(['id' => 'c1', 'body' => 'Now in your log', 'author' => ada()])])
                : [...pageJson([]), 'suggestions' => [$suggestion]]),
            default => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
        };
    });

    $screen = Native::visit('/log')
        ->assertSee('fatal: your log does not have any commits yet')
        ->assertSee('Here is what a few developers committed lately:')
        ->assertSee('Shipping a CLI today')
        ->assertSee('@ada')
        ->assertSee('Trending');

    $screen->tap('follow-ada')
        ->assertSee('Now in your log')
        ->assertDontSee('Here is what a few developers committed lately:');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://devnull.test/api/v1/users/ada/follow');
});

it('points an empty Following log at Trending when there is nobody to suggest', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    Native::visit('/log')
        ->assertSee('Find someone worth following on ')
        ->tap('empty-trending')
        ->assertSet('log', 'trending');
});

it('ranks Trending afresh when it opens, over the ranking kept last time', function () {
    holdRefreshes();
    keep('log/trending', trendingJson([commitJson(['id' => 'old-top', 'body' => 'Top yesterday']), commitJson(['id' => 'c2', 'body' => 'Second'])], newest: 'c2'));
    serveApi([
        'GET /api/v1/log/trending' => [trendingJson([commitJson(['id' => 'c2', 'body' => 'Second']), commitJson(['id' => 'new-top', 'body' => 'Top today'])], newest: 'n9')],
        'GET /api/v1/log/following' => [pageJson([])],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = tapLogTab(Native::visit('/log'), 'trending');

    // The kept ranking paints at once, with no spinner.
    expect(shownLogIds($screen))->toBe(['old-top', 'c2'])
        ->and(showsLoading(showingLog($screen)))->toBeFalse();

    finishRefreshes($screen);

    // Opening asks for the ranking itself, not what's new since it.
    expect(shownLogIds($screen))->toBe(['c2', 'new-top'])
        ->and(logRequestsSent())->toContain('log/trending')
        ->and($screen->get('rankedSince'))->toBe('n9');
});

it('never re-ranks Trending under you: later answers only change rows in place, and newer commits wait in the pill', function () {
    holdRefreshes();
    serveApi([
        'GET /api/v1/log/trending' => [trendingJson([commitJson(['id' => 'a', 'body' => 'First']), commitJson(['id' => 'b', 'body' => 'Second', 'stars_count' => 1])], newest: 'b')],
        'GET /api/v1/log/following' => [pageJson([])],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = finishRefreshes(tapLogTab(Native::visit('/log'), 'trending'));

    expect(shownLogIds($screen))->toBe(['a', 'b']);

    // Half a minute later the ranking has moved, b has stars, and two commits are newer than b.
    serveApi([
        'GET /api/v1/log/trending' => [trendingJson(
            [commitJson(['id' => 'b', 'body' => 'Second', 'stars_count' => 9]), commitJson(['id' => 'a', 'body' => 'First']), commitJson(['id' => 'z', 'body' => 'Climbing'])],
            newest: 'n2',
            latest: [
                commitJson(['id' => 'n2', 'body' => 'Newest', 'author' => ada(), 'created_at' => now()->subSeconds(10)->toIso8601ZuluString()]),
                commitJson(['id' => 'n1', 'body' => 'Newer', 'author' => ada(), 'created_at' => now()->subMinute()->toIso8601ZuluString()]),
            ],
            count: 2,
        )],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    finishRefreshes($screen->call('checkForNewCommits'))
        ->assertSee('2 new commits')
        ->assertDontSee('Climbing')
        ->assertDontSee('Newest');

    expect(shownLogIds($screen))->toBe(['a', 'b'])
        ->and($screen->instance()->commits[1]->starsCount)->toBe(9)
        ->and(logRequestsSent())->toContain('log/trending?since=b');

    $requests = count(Http::recorded());
    $listKey = $screen->get('listKey');

    // The pill puts them above the ranking with no request, and counting starts again from the newest of them.
    $screen->tap('new-commits')->assertDontSee('2 new commits');

    expect(shownLogIds($screen))->toBe(['n2', 'n1', 'a', 'b'])
        ->and(dayMarkers($screen))->toBe([Timeline::JustNow, Timeline::TopCommits])
        ->and(count(Http::recorded()))->toBe($requests)
        ->and($screen->get('rankedSince'))->toBe('n2')
        ->and($screen->get('listKey'))->toBe($listKey + 1);

    finishRefreshes($screen->call('checkForNewCommits'));

    expect(logRequestsSent())->toContain('log/trending?since=n2');
});

it('ranks Trending again on pull to refresh, which clears what was put above it', function () {
    holdRefreshes();
    serveApi([
        'GET /api/v1/log/trending' => [trendingJson([commitJson(['id' => 'a'])], newest: 'a', latest: [commitJson(['id' => 'n1', 'author' => ada()])], count: 1)],
        'GET /api/v1/log/following' => [pageJson([])],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = finishRefreshes(tapLogTab(Native::visit('/log'), 'trending'));
    finishRefreshes($screen->call('checkForNewCommits'))->tap('new-commits');

    expect(shownLogIds($screen))->toBe(['n1', 'a']);

    serveApi([
        'GET /api/v1/log/trending' => [trendingJson([commitJson(['id' => 'n1', 'author' => ada()]), commitJson(['id' => 'a'])], newest: 'n1')],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    finishRefreshes($screen->call('load'));

    expect(shownLogIds($screen))->toBe(['n1', 'a'])
        ->and($screen->get('latestCommits'))->toBe([])
        ->and(dayMarkers($screen))->toBe([]);
});

it('draws Trending with no day markers and no last-visit marker', function () {
    Http::fake(['devnull.test/*' => Http::response(trendingJson([
        commitJson(['id' => 'a', 'created_at' => now()->subDays(3)->toIso8601ZuluString()]),
        commitJson(['id' => 'b', 'created_at' => now()->subMinute()->toIso8601ZuluString(), 'author' => ada()]),
    ], newest: 'b'))]);

    $screen = Native::test(Log::class)->call('show', 'trending');

    expect(dayMarkers($screen))->toBe([])
        ->and(shownLogIds($screen))->toBe(['a', 'b']);

    expect(findNode(showingLog($screen), fn (array $node): bool => ($node['ref'] ?? null) === 'last-visit'))->toBeNull();
});

it('puts a commit made on Trending above the ranking, under Just now', function () {
    serveApi([
        'GET /api/v1/log/trending' => [trendingJson([commitJson(['id' => 'a', 'author' => ada()])], newest: 'a')],
        'GET /api/v1/log/following' => [pageJson([])],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'mine', 'body' => 'Fresh from the composer', 'status' => 'pending'])], 201],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = tapLogTab(Native::visit('/log'), 'trending')
        ->press('composeInSheet')
        ->input('sheet-body', 'Fresh from the composer')
        ->tap('sheet-commit');

    expect(shownLogIds($screen))->toBe(['mine', 'a'])
        ->and(dayMarkers($screen))->toBe([Timeline::JustNow, Timeline::TopCommits])
        ->and($screen->get('rankedSince'))->toBe('a');
});

it('lists a ranked commit once, even when it moves to the next page', function () {
    Http::fake(function (Request $request) {
        return str_contains($request->url(), 'cursor=page2')
            ? Http::response(pageJson([commitJson(['id' => 'b']), commitJson(['id' => 'c'])]))
            : Http::response([...trendingJson([commitJson(['id' => 'a']), commitJson(['id' => 'b'])], newest: 'c'), ...pageJson([commitJson(['id' => 'a']), commitJson(['id' => 'b'])], 'page2')]);
    });

    $screen = Native::test(Log::class)->call('show', 'trending')->call('loadMore');

    expect(shownLogIds($screen))->toBe(['a', 'b', 'c']);
});

it('drops a commit you deleted from Trending when you come back to it', function () {
    serveApi([
        'GET /api/v1/log/trending' => [trendingJson([commitJson(['id' => 'mine', 'body' => 'Mine']), commitJson(['id' => 'a', 'author' => ada()])], newest: 'a')],
        'GET /api/v1/log/following' => [pageJson([])],
        'DELETE /api/v1/commits/mine' => [null, 204],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = tapLogTab(Native::visit('/log'), 'trending');
    app(Client::class)->deleteCommit('mine');

    $screen->call('onResume');

    expect(shownLogIds($screen))->toBe(['a']);
});

it('opens on skeleton rows, and fills in what it kept straight after, so a tab tap shows at once', function () {
    config(['devnull.skeleton_first' => true]);
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'f1', 'body' => 'Kept following'])]));
    keep('log/latest', pageJson([commitJson(['id' => 'l1', 'body' => 'Kept latest'])]));
    serveApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'f1', 'body' => 'Kept following'])])]]);

    $screen = Native::visit('/log')
        ->assertSet('skeletonFrame', true)
        ->assertDontSee('Kept following')
        ->assertDontSee('Kept latest');

    expect(showsLoading(showingLog($screen)))->toBeTrue();

    finishRefreshes($screen)
        ->assertSet('skeletonFrame', false)
        ->assertSee('Kept following')
        ->assertSee('Kept latest');
});
