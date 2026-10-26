<?php

use App\NativeComponents\CommitDetail;
use App\NativeComponents\Profile;
use App\NativeComponents\Starred;
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
 * The ids of the commit rows and replies that $render builds in Blade, rather than takes from a row cache.
 *
 * @return list<string>
 */
function rowsBuiltBy(Closure $render): array
{
    $built = [];
    View::composer(['native.partials.commit-row', 'native.partials.thread-reply'], function ($view) use (&$built) {
        $built[] = $view->getData()[$view->name() === 'native.partials.thread-reply' ? 'reply' : 'commit']->id;
    });

    $render();

    return $built;
}

/**
 * Draws the screen again with its row cache emptied, and expects the same frame it drew from the rows it kept.
 */
function expectKeptRowsMatchFreshOnes(TestableComponent $screen, string $class, string $cache, mixed $empty): void
{
    $kept = $screen->tree();

    Closure::bind(fn () => $this->{$cache} = $empty, $screen->instance(), $class)();

    expect($screen->call('navTitle')->tree())->toBe($kept);
}

it("keeps a profile's unchanged rows between renders, on both its tabs", function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']);
    fakeApi([
        'GET /api/v1/users/ada' => [['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']), 'is_following' => false]],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson(['id' => 'a2', 'author' => $ada]), commitJson(['id' => 'a1', 'body' => 'Older', 'author' => $ada])])],
        'POST /api/v1/commits/a1/star' => [['data' => commitJson(['id' => 'a1', 'body' => 'Older', 'author' => $ada, 'stars_count' => 1, 'starred' => true])]],
    ]);
    $screen = Native::visit('/log/users/ada');

    expect(rowsBuiltBy(fn () => $screen->call('navTitle')))->toBe([])
        ->and(rowsBuiltBy(fn () => $screen->tap('star-a1')))->toBe(['a1']);

    expectKeptRowsMatchFreshOnes($screen, Profile::class, 'rowCaches', []);
});

it('keeps the unchanged rows of Starred between renders', function () {
    fakeApi([
        'GET /api/v1/starred' => [pageJson([
            commitJson(['id' => 's2', 'starred' => true, 'stars_count' => 1]),
            commitJson(['id' => 's1', 'starred' => true, 'stars_count' => 1, 'replies_count' => 1]),
        ], path: 'http://devnull.test/api/v1/starred')],
        'GET /api/v1/commits/s1/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 's1', 'body' => 'A reply in place'])])],
    ]);
    $screen = finishRefreshes(Native::visit('/profile/starred'));

    expect(rowsBuiltBy(fn () => $screen->call('navTitle')))->toBe([])
        ->and(rowsBuiltBy(fn () => $screen->tap('replies-s1')->assertSee('A reply in place')))->toBe(['s1', 'r1']);

    expectKeptRowsMatchFreshOnes($screen, Starred::class, 'rowCache', null);
});

it("keeps a thread's unchanged replies between renders, and only builds the ones a page of replies brings", function () {
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/v1/commits/c2/replies' => Http::response(str_contains($request->url(), 'cursor=page2')
                ? pageJson([replyJson(['id' => 'r3', 'parent_id' => 'c2', 'body' => 'Third reply'])])
                : pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply']), replyJson(['id' => 'r2', 'parent_id' => 'c2', 'body' => 'Second reply'])], 'page2', 'http://devnull.test/api/v1/commits/c2/replies')),
            default => Http::response(commitPageJson(commitJson(['id' => 'c2', 'replies_count' => 3]))),
        };
    });
    $screen = Native::visit('/log/commits/c2')->tap('replies-c2')->assertSee('Second reply');

    // The commits above the replies read what the screen shows of the thread, so they're built every time. The last
    // reply of a page changes when the next page comes, since it no longer carries "more".
    expect(rowsBuiltBy(fn () => $screen->call('navTitle')))->toBe(['c2'])
        ->and(rowsBuiltBy(fn () => $screen->call('loadMoreReplies')->assertSee('Third reply')))->toBe(['c2', 'r2', 'r3']);

    expectKeptRowsMatchFreshOnes($screen, CommitDetail::class, 'replyRows', null);
});
