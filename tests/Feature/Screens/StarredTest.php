<?php

use App\Devnull\ShownTabs;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function starredCommit(string $id, array $overrides = []): array
{
    return commitJson(['id' => $id, 'body' => "Starred {$id}", 'starred' => true, 'stars_count' => 1, 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'lane' => 1]), ...$overrides]);
}

/**
 * @param  list<array<string, mixed>>  $commits
 * @return array<string, mixed>
 */
function starredPage(array $commits, ?string $nextCursor = null): array
{
    return pageJson($commits, $nextCursor, 'http://devnull.test/api/v1/starred');
}

/** @return list<string> */
function starredIds(TestableComponent $screen): array
{
    return array_map(fn ($commit): string => $commit->id, $screen->instance()->commits);
}

it('lists your starred commits newest first with the same copy as the web', function (string $platform) {
    fakeApi(['GET /api/v1/starred' => [starredPage([starredCommit('s2'), starredCommit('s1')])]]);

    $screen = finishRefreshes(Native::visit('/profile/starred', platform: $platform))
        ->assertNavTitle('Starred')
        ->assertTabActive('Profile')
        ->assertSee('Only you')
        ->assertSee('Commits you starred, newest first.')
        ->assertSee('Starred s2')
        ->assertSee("You've reached the end of your starred commits.");

    expect(starredIds($screen))->toBe(['s2', 's1'])
        ->and(nodeByRef($screen, 'star-s1')['props']['a11y_label'])->toBe('Unstar commit, 1 star')
        ->and(menuItems($screen, 'more-s1')[0][0])->toBe('share-s1');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/saved') || str_contains($request->url(), '/bookmark'));
})->with(['ios', 'android']);

it('opens on the starred commits kept last time and refreshes behind them', function () {
    holdRefreshes();
    keep('starred', starredPage([starredCommit('s1')]));
    serveApi(['GET /api/v1/starred' => [starredPage([starredCommit('s2'), starredCommit('s1')])]]);

    $screen = Native::visit('/profile/starred')->assertSee('Starred s1')->assertDontSee('Starred s2');

    expect(showsLoading($screen->tree()))->toBeFalse()
        ->and(Http::recorded())->toHaveCount(0);

    finishRefreshes($screen);

    expect(starredIds($screen))->toBe(['s2', 's1'])
        ->and(array_column(kept('starred')['data'], 'id'))->toBe(['s2', 's1']);
});

it('shows skeleton rows the first time until devnull answers', function () {
    holdRefreshes();
    serveApi(['GET /api/v1/starred' => [starredPage([starredCommit('s1')])]]);
    $screen = Native::visit('/profile/starred');
    expect(showsLoading($screen->tree()))->toBeTrue();
    finishRefreshes($screen)->assertSee('Starred s1');
    expect(showsLoading($screen->tree()))->toBeFalse();
});

it('shows the empty starred state and opens Trending from Explore Trending', function () {
    fakeApi(['GET /api/v1/starred' => [starredPage([])]]);
    $screen = finishRefreshes(Native::visit('/profile/starred'))
        ->assertSee('fatal: no starred commits yet')
        ->assertSee('Star a commit to find it here.')
        ->assertDontSee("You've reached the end of your starred commits.");

    $screen->press('exploreTrending')->assertReplacedWith('/log')->assertTransition('none');
    expect(app(ShownTabs::class)->get('0199e5c4-0000-7000-8000-000000000001', 'log', ['following', 'latest', 'trending'], 'following'))->toBe('trending');
});

it('removes a row only after unstar succeeds and keeps it when devnull refuses', function (int $status, array $ids) {
    keep('starred', starredPage([starredCommit('s2'), starredCommit('s1')]));
    fakeApi([
        'GET /api/v1/starred' => [starredPage([starredCommit('s2'), starredCommit('s1')])],
        'DELETE /api/v1/commits/s2/star' => [$status === 200 ? ['data' => starredCommit('s2', ['starred' => false])] : ['message' => 'Server Error'], $status],
    ]);
    $screen = finishRefreshes(Native::visit('/profile/starred'))->tap('star-s2');

    expect(starredIds($screen))->toBe($ids)
        ->and(array_column(kept('starred')['data'], 'id'))->toBe($ids)
        ->and($screen->instance()->commits[0]->starred)->toBeTrue();
})->with(['unstarred' => [200, ['s1']], 'refused' => [500, ['s2', 's1']]]);

it('keeps a commit when only a reply under it is unstarred', function () {
    $reply = replyJson(['id' => 'r1', 'parent_id' => 's1', 'starred' => true]);
    fakeApi([
        'GET /api/v1/starred' => [starredPage([starredCommit('s1', ['replies_count' => 1, 'reply_preview' => ['data' => [$reply], 'next_cursor' => null]])])],
        'DELETE /api/v1/commits/r1/star' => [['data' => [...$reply, 'starred' => false]]],
    ]);
    $screen = finishRefreshes(Native::visit('/profile/starred'))->call('toggleStar', 'r1');
    expect(starredIds($screen))->toBe(['s1']);
});

it('reloads from the top when the last visible commit is unstarred and another page exists', function () {
    holdRefreshes();
    keep('starred', starredPage([starredCommit('s3')], nextCursor: 'page2'));
    serveApi([
        'DELETE /api/v1/commits/s3/star' => [['data' => starredCommit('s3', ['starred' => false])]],
        'GET /api/v1/starred' => [starredPage([starredCommit('s2'), starredCommit('s1')])],
    ]);
    $screen = Native::visit('/profile/starred')->tap('star-s3')->assertDontSee('fatal: no starred commits yet');
    expect(showsLoading($screen->tree()))->toBeTrue();
    finishRefreshes($screen);
    expect(starredIds($screen))->toBe(['s2', 's1']);
});

it('loads the next page at the end of the list and pulls to refresh', function () {
    serveApi(fn ($request) => Http::response(str_contains($request->url(), 'cursor=page2')
        ? starredPage([starredCommit('s1')])
        : starredPage([starredCommit('s3'), starredCommit('s2')], nextCursor: 'page2')));
    $screen = finishRefreshes(Native::visit('/profile/starred'))->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-more');
    $screen->call('loadMore')->assertSee("You've reached the end of your starred commits.");
    expect(starredIds($screen))->toBe(['s3', 's2', 's1'])
        ->and(kept('starred', ['cursor' => 'page2']))->toBeNull();

    serveApi(fn () => Http::response(starredPage([starredCommit('s4'), starredCommit('s3')], nextCursor: 'page2')));
    finishRefreshes($screen->call('load'));
    expect(starredIds($screen))->toBe(['s4', 's3', 's2', 's1']);
});

it('lays a fresh first page over the list keeping the pages below it', function (array $fresh, ?string $cursor, array $ids) {
    holdRefreshes();
    keep('starred', starredPage([starredCommit('s5'), starredCommit('s4'), starredCommit('s3')], nextCursor: 'page2'));
    serveApi(['GET /api/v1/starred' => [starredPage([starredCommit('s2'), starredCommit('s1')], nextCursor: 'page3')]]);
    $screen = Native::visit('/profile/starred')->call('loadMore');
    expect(starredIds($screen))->toBe(['s5', 's4', 's3', 's2', 's1']);

    serveApi(['GET /api/v1/starred' => [starredPage(array_map(fn (string $id): array => starredCommit($id), $fresh), $cursor)]]);
    finishRefreshes($screen->call('load'));
    expect(starredIds($screen))->toBe($ids);
})->with([
    'one unstarred' => [['s5', 's3', 's2'], 'page2', ['s5', 's3', 's2', 's1']],
    'one starred again' => [['s2', 's5', 's4'], 'page2', ['s2', 's5', 's4', 's3', 's1']],
    'the whole list' => [['s5', 's4'], null, ['s5', 's4']],
    'all new' => [['s9', 's8'], 'page2', ['s9', 's8']],
]);

it('shows a commit starred elsewhere next time it opens and leaves out one unstarred there', function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'lane' => 1]);
    keep('starred', starredPage([starredCommit('s1')]));
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Worth keeping', 'author' => $ada]), starredCommit('s1')])],
        'POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'body' => 'Worth keeping', 'author' => $ada, 'starred' => true])]],
        'DELETE /api/v1/commits/s1/star' => [['data' => starredCommit('s1', ['starred' => false])]],
    ]);
    finishRefreshes(Native::visit('/log'))->tap('star-c1');
    holdRefreshes();
    $starred = Native::visit('/profile/starred')->assertSee('Worth keeping');
    expect(starredIds($starred))->toBe(['c1', 's1']);
    Native::visit('/log')->tap('star-s1');
    holdRefreshes();
    expect(starredIds(Native::visit('/profile/starred')))->toBe(['c1']);
});

it('drops a commit unstarred on its own page on return even past the kept first page', function () {
    holdRefreshes();
    keep('starred', starredPage([starredCommit('s2')], nextCursor: 'page2'));
    keep('commits/s1', commitPageJson(starredCommit('s1')));
    keep('commits/s1/replies', pageJson([]));
    serveApi([
        'GET /api/v1/starred' => [starredPage([starredCommit('s1')])],
        'DELETE /api/v1/commits/s1/star' => [['data' => starredCommit('s1', ['starred' => false])]],
    ]);
    $starred = Native::visit('/profile/starred')->call('loadMore');
    expect(starredIds($starred))->toBe(['s2', 's1']);
    $commit = $starred->tap('commit-s1')->assertNavigatedTo('/profile/commits/s1')->follow()->tap('star-s1');
    expect(starredIds($commit->goBack()))->toBe(['s2']);
});

it('keeps a commit starred again after it was unstarred', function () {
    keep('starred', starredPage([starredCommit('s1')]));
    fakeApi([
        'DELETE /api/v1/commits/s1/star' => [['data' => starredCommit('s1', ['starred' => false])]],
        'POST /api/v1/commits/s1/star' => [['data' => starredCommit('s1')]],
        'GET /api/v1/commits/s1' => [commitPageJson(starredCommit('s1'))],
        'GET /api/v1/commits/s1/replies' => [pageJson([])],
    ]);
    Native::visit('/profile/commits/s1')->tap('star-s1')->tap('star-s1');
    holdRefreshes();
    expect(starredIds(Native::visit('/profile/starred')))->toBe(['s1']);
});

it('deletes your own starred commit from its menu and lets its row go', function () {
    $deleted = false;
    serveApi(function (Request $request) use (&$deleted) {
        if ($request->method() === 'DELETE') {
            $deleted = true;

            return Http::response(null, 204);
        }

        return Http::response(starredPage([
            ...($deleted ? [] : [starredCommit('s2', ['body' => 'Delete me', 'author' => userJson()])]),
            starredCommit('s1'),
        ]));
    });
    $screen = finishRefreshes(Native::visit('/profile/starred', platform: 'ios'))
        ->tap('delete-s2')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit'])
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'rm s2 → /dev/null')
        ->assertNoNavigation()->assertDontSee('Delete me');
    expect(starredIds(finishRefreshes($screen)))->toBe(['s1'])
        ->and(array_column(kept('starred')['data'], 'id'))->toBe(['s1']);
});

it('quotes and reports from Starred with your lane colour on the sheet', function () {
    signIn(['lane' => 4]);
    fakeApi(['GET /api/v1/starred' => [starredPage([starredCommit('s1')])]]);
    $quote = finishRefreshes(Native::visit('/profile/starred', platform: 'ios'))->tap('quote-s1')->input('sheet-body', 'Worth a read');
    $report = finishRefreshes(Native::visit('/profile/starred', platform: 'ios'))->tap('report-s1');

    expect(strtolower(sheetButtonCircle($quote, 'sheet-commit')['style']['bg_color']))->toBe(strtolower(config('native-ui.theme.light.lane-4')))
        ->and(nodeByRef($report, 'send-report')['props']['variant'] ?? null)->toBe('lane-4');
});

it('opens commits and profiles under your profile where Starred opens', function () {
    fakeApi(['GET /api/v1/starred' => [starredPage([starredCommit('s1')])]]);
    finishRefreshes(Native::visit('/profile/starred'))->tap('commit-s1')->assertNavigatedTo('/profile/commits/s1');
    finishRefreshes(Native::visit('/profile/starred'))->call('openProfile', 'ada')->assertNavigatedTo('/profile/users/ada');
});
