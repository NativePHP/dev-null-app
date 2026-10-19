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
 * A commit you saved, by Ada.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function savedCommit(string $id, array $overrides = []): array
{
    return commitJson(['id' => $id, 'body' => "Saved {$id}", 'is_saved' => true, 'author' => userJson(['id' => 'u2', 'username' => 'ada', 'lane' => 1]), ...$overrides]);
}

/**
 * `GET /saved` as devnull sends it.
 *
 * @param  list<array<string, mixed>>  $commits
 * @return array<string, mixed>
 */
function savedPage(array $commits, ?string $nextCursor = null): array
{
    return pageJson($commits, $nextCursor, 'http://devnull.test/api/v1/saved');
}

/**
 * The ids of the commits the Saved screen lists, top to bottom.
 *
 * @return list<string>
 */
function savedIds(TestableComponent $screen): array
{
    return array_map(fn ($commit): string => $commit->id, $screen->instance()->commits);
}

it('lists the commits you saved, last saved first, in the rows of the log', function () {
    fakeApi(['GET /api/v1/saved' => [savedPage([savedCommit('s2'), savedCommit('s1')])]]);

    $screen = finishRefreshes(Native::visit('/profile/saved'))
        ->assertNavTitle('Saved')
        ->assertTabActive('Profile')
        ->assertSee('Only you')
        ->assertSee('Commits you want to come back to. Most recently saved first.')
        ->assertSee('Saved s2')
        ->assertSee("You've reached the end of your saved commits.");

    expect(savedIds($screen))->toBe(['s2', 's1'])
        ->and(array_slice(menuItems($screen, 'more-s1')[0], 0, 2))->toBe(['save-s1', 'Unsave commit']);
});

it('opens on the saved commits kept last time, with no request and no skeleton, then refreshes behind them', function () {
    holdRefreshes();
    keep('saved', savedPage([savedCommit('s1')]));
    serveApi(['GET /api/v1/saved' => [savedPage([savedCommit('s2'), savedCommit('s1')])]]);

    $screen = Native::visit('/profile/saved')->assertSee('Saved s1')->assertDontSee('Saved s2');

    expect(showsLoading($screen->tree()))->toBeFalse()
        ->and(Http::recorded())->toHaveCount(0);

    finishRefreshes($screen);

    expect(savedIds($screen))->toBe(['s2', 's1'])
        ->and(array_column(kept('saved')['data'], 'id'))->toBe(['s2', 's1']);
});

it('shows skeleton rows the first time, until devnull answers', function () {
    holdRefreshes();
    serveApi(['GET /api/v1/saved' => [savedPage([savedCommit('s1')])]]);

    $screen = Native::visit('/profile/saved');

    expect(showsLoading($screen->tree()))->toBeTrue();

    finishRefreshes($screen)->assertSee('Saved s1');

    expect(showsLoading($screen->tree()))->toBeFalse();
});

it('says so as the web does when nothing is saved, and Explore Trending opens the Log on Trending', function () {
    fakeApi(['GET /api/v1/saved' => [savedPage([])]]);

    $screen = finishRefreshes(Native::visit('/profile/saved'))
        ->assertSee('fatal: no saved commits yet')
        ->assertSee('Save a commit to find it here. Your saved commits are private.')
        ->assertDontSee("You've reached the end of your saved commits.");

    $screen->press('exploreTrending')->assertReplacedWith('/log')->assertTransition('none');

    expect(app(ShownTabs::class)->get('0199e5c4-0000-7000-8000-000000000001', 'log', ['following', 'latest', 'trending'], 'following'))->toBe('trending');
});

it('takes a commit off the list once devnull says it is unsaved, and keeps it when devnull says no', function (int $status, array $ids) {
    keep('saved', savedPage([savedCommit('s2'), savedCommit('s1')]));
    fakeApi([
        'GET /api/v1/saved' => [savedPage([savedCommit('s2'), savedCommit('s1')])],
        'DELETE /api/v1/commits/s2/bookmark' => [$status === 200 ? ['data' => savedCommit('s2', ['is_saved' => false])] : ['message' => 'Server Error'], $status],
    ]);

    $screen = finishRefreshes(Native::visit('/profile/saved'))->tap('save-s2');

    expect(savedIds($screen))->toBe($ids)
        ->and(array_column(kept('saved')['data'], 'id'))->toBe($ids)
        ->and($screen->instance()->commits[0]->isSaved)->toBeTrue();
})->with([
    'unsaved' => [200, ['s1']],
    'refused' => [500, ['s2', 's1']],
]);

it('keeps a commit when only a reply under it is unsaved', function () {
    $reply = replyJson(['id' => 'r1', 'parent_id' => 's1', 'is_saved' => true]);
    fakeApi([
        'GET /api/v1/saved' => [savedPage([savedCommit('s1', ['replies_count' => 1, 'reply_preview' => ['data' => [$reply], 'next_cursor' => null]])])],
        'DELETE /api/v1/commits/r1/bookmark' => [['data' => [...$reply, 'is_saved' => false]]],
    ]);

    $screen = finishRefreshes(Native::visit('/profile/saved'))->call('toggleBookmark', 'r1');

    expect(savedIds($screen))->toBe(['s1']);
});

it('starts again from the top when the last commit showing is unsaved and there are more', function () {
    holdRefreshes();
    keep('saved', savedPage([savedCommit('s3')], nextCursor: 'page2'));
    serveApi([
        'DELETE /api/v1/commits/s3/bookmark' => [['data' => savedCommit('s3', ['is_saved' => false])]],
        'GET /api/v1/saved' => [savedPage([savedCommit('s2'), savedCommit('s1')])],
    ]);

    $screen = Native::visit('/profile/saved')->tap('save-s3')->assertDontSee('fatal: no saved commits yet');

    expect(showsLoading($screen->tree()))->toBeTrue();

    finishRefreshes($screen);

    expect(savedIds($screen))->toBe(['s2', 's1']);
});

it('loads the next page at the end of the list, and pulls to refresh', function () {
    serveApi(fn ($request) => Http::response(str_contains($request->url(), 'cursor=page2')
        ? savedPage([savedCommit('s1')])
        : savedPage([savedCommit('s3'), savedCommit('s2')], nextCursor: 'page2')));

    $screen = finishRefreshes(Native::visit('/profile/saved'))->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-more');

    $screen->call('loadMore')->assertSee("You've reached the end of your saved commits.");

    expect(savedIds($screen))->toBe(['s3', 's2', 's1'])
        ->and(kept('saved', ['cursor' => 'page2']))->toBeNull();

    // Saved on the web since: a pull puts it on top and keeps the page loaded below.
    serveApi(fn () => Http::response(savedPage([savedCommit('s4'), savedCommit('s3')], nextCursor: 'page2')));
    finishRefreshes($screen->call('load'));

    expect(savedIds($screen))->toBe(['s4', 's3', 's2', 's1']);
});

it('lays a fresh first page over the list, keeping the pages below it in place', function (array $fresh, ?string $cursor, array $ids) {
    holdRefreshes();
    keep('saved', savedPage([savedCommit('s5'), savedCommit('s4'), savedCommit('s3')], nextCursor: 'page2'));
    serveApi(['GET /api/v1/saved' => [savedPage([savedCommit('s2'), savedCommit('s1')], nextCursor: 'page3')]]);

    $screen = Native::visit('/profile/saved')->call('loadMore');
    expect(savedIds($screen))->toBe(['s5', 's4', 's3', 's2', 's1']);

    serveApi(['GET /api/v1/saved' => [savedPage(array_map(fn (string $id): array => savedCommit($id), $fresh), $cursor)]]);
    finishRefreshes($screen->call('load'));

    expect(savedIds($screen))->toBe($ids);
})->with([
    // s4 unsaved elsewhere, so s2 now ends the first page.
    'one unsaved' => [['s5', 's3', 's2'], 'page2', ['s5', 's3', 's2', 's1']],
    // s2 saved again: it's the last saved now, and leaves its place below.
    'one saved again' => [['s2', 's5', 's4'], 'page2', ['s2', 's5', 's4', 's3', 's1']],
    // Nothing more to load, so the page is the whole list.
    'the whole list' => [['s5', 's4'], null, ['s5', 's4']],
    // Nothing in common: the list starts again from the fresh page.
    'all new' => [['s9', 's8'], 'page2', ['s9', 's8']],
]);

it('shows a commit saved on another screen the next time it opens, and leaves out one unsaved there', function () {
    $ada = userJson(['id' => 'u2', 'username' => 'ada', 'lane' => 1]);
    keep('saved', savedPage([savedCommit('s1')]));
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Worth keeping', 'author' => $ada]), savedCommit('s1')])],
        'PUT /api/v1/commits/c1/bookmark' => [['data' => commitJson(['id' => 'c1', 'body' => 'Worth keeping', 'author' => $ada, 'is_saved' => true])]],
        'DELETE /api/v1/commits/s1/bookmark' => [['data' => savedCommit('s1', ['is_saved' => false])]],
    ]);

    finishRefreshes(Native::visit('/log'))->tap('save-c1');

    holdRefreshes();
    $saved = Native::visit('/profile/saved')->assertSee('Worth keeping');
    expect(savedIds($saved))->toBe(['c1', 's1']);

    Native::visit('/log')->tap('save-s1');

    holdRefreshes();
    expect(savedIds(Native::visit('/profile/saved')))->toBe(['c1']);
});

it('drops a commit unsaved on its own page as soon as you come back, even past the first page', function () {
    holdRefreshes();
    keep('saved', savedPage([savedCommit('s2')], nextCursor: 'page2'));
    keep('commits/s1', commitPageJson(savedCommit('s1')));
    keep('commits/s1/replies', pageJson([]));
    serveApi([
        'GET /api/v1/saved' => [savedPage([savedCommit('s1')])],
        'DELETE /api/v1/commits/s1/bookmark' => [['data' => savedCommit('s1', ['is_saved' => false])]],
    ]);

    $saved = Native::visit('/profile/saved')->call('loadMore');
    expect(savedIds($saved))->toBe(['s2', 's1']);

    $commit = $saved->tap('commit-s1')->assertNavigatedTo('/profile/commits/s1')->follow()->tap('save-s1');

    expect(savedIds($commit->goBack()))->toBe(['s2']);
});

it('keeps a commit saved again after it was unsaved', function () {
    keep('saved', savedPage([savedCommit('s1')]));
    fakeApi([
        'DELETE /api/v1/commits/s1/bookmark' => [['data' => savedCommit('s1', ['is_saved' => false])]],
        'PUT /api/v1/commits/s1/bookmark' => [['data' => savedCommit('s1')]],
        'GET /api/v1/commits/s1' => [commitPageJson(savedCommit('s1'))],
        'GET /api/v1/commits/s1/replies' => [pageJson([])],
    ]);

    $commit = Native::visit('/profile/commits/s1');
    $commit->tap('save-s1')->tap('save-s1');

    holdRefreshes();
    expect(savedIds(Native::visit('/profile/saved')))->toBe(['s1']);
});

it('deletes your own saved commit from its menu, and lets its row go', function () {
    $deleted = false;

    serveApi(function (Request $request) use (&$deleted) {
        if ($request->method() === 'DELETE') {
            $deleted = true;

            return Http::response(null, 204);
        }

        return Http::response(savedPage([
            ...($deleted ? [] : [savedCommit('s2', ['body' => 'Delete me', 'author' => userJson()])]),
            savedCommit('s1'),
        ]));
    });

    $screen = finishRefreshes(Native::visit('/profile/saved', platform: 'ios'))
        ->tap('delete-s2')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit'])
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'rm s2 → /dev/null')
        ->assertNoNavigation()
        ->assertDontSee('Delete me');

    expect(savedIds(finishRefreshes($screen)))->toBe(['s1'])
        ->and(array_column(kept('saved')['data'], 'id'))->toBe(['s1']);
});

it('quotes and reports from Saved with your lane colour on the sheet', function (string $item, string $button) {
    signIn(['lane' => 4]);
    fakeApi(['GET /api/v1/saved' => [savedPage([savedCommit('s1')])]]);

    $screen = finishRefreshes(Native::visit('/profile/saved', platform: 'ios'))->tap("{$item}-s1");

    expect(nodeByRef($screen, $button)['props']['variant'] ?? null)->toBe('lane-4');
})->with([
    'Quote' => ['quote', 'sheet-commit'],
    'Report commit' => ['report', 'send-report'],
]);

it('opens commits and profiles under your profile, where Saved opens', function () {
    fakeApi(['GET /api/v1/saved' => [savedPage([savedCommit('s1')])]]);

    finishRefreshes(Native::visit('/profile/saved'))->tap('commit-s1')->assertNavigatedTo('/profile/commits/s1');
    finishRefreshes(Native::visit('/profile/saved'))->call('openProfile', 'ada')->assertNavigatedTo('/profile/users/ada');
});
