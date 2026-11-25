<?php

use App\Devnull\Account;
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
            $path === '/api/v1/commits/c2/related' => Http::response(['data' => []]),
            default => Http::response(commitPageJson(commitJson(['id' => 'c2', 'replies_count' => 3]))),
        };
    });
    $screen = Native::visit('/log/commits/c2')->tap('replies-c2')->assertSee('Second reply');

    // The commits above the replies are kept like the replies, and "Show more" is a row of its own, so the last reply
    // of a page stays as it was when the next page comes. Only the reply that page brings is built.
    expect(rowsBuiltBy(fn () => $screen->call('navTitle')))->toBe([])
        ->and(rowsBuiltBy(fn () => $screen->call('loadMoreReplies')->assertSee('Third reply')))->toBe(['r3']);

    expectKeptRowsMatchFreshOnes($screen, CommitDetail::class, 'replyRows', null);
});

it("keeps a commit page's commits between renders, and builds the commit again when what it shows of its thread changes", function () {
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'replies_count' => 1, 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])]), [commitJson(['id' => 'c1', 'body' => 'What it replies to'])])],
        'GET /api/v1/commits/c2/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply'])])],
        'GET /api/v1/commits/c2/related' => [['data' => [commitJson(['id' => 'x1', 'body' => 'Another thread'])]]],
        'POST /api/v1/commits/c2/star' => [['data' => commitJson(['id' => 'c2', 'replies_count' => 1, 'stars_count' => 1, 'starred' => true, 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])])]],
    ]);
    $screen = finishRefreshes(Native::visit('/log/commits/c2'))->tap('related-commits-toggle');

    // Typing a reply only builds the commit it replies to once, when the draft is first kept, which its row marks.
    expect(rowsBuiltBy(fn () => $screen->input('reply-input', 'N')))->toBe(['c2'])
        ->and(rowsBuiltBy(fn () => $screen->input('reply-input', 'Nice')))->toBe([]);

    $changes = [
        'its replies opened' => fn () => $screen->tap('replies-c2')->assertSee('First reply'),
        'a star' => fn () => $screen->tap('star-c2'),
        'its replies closed' => fn () => $screen->tap('replies-c2'),
        'the time' => function () use ($screen) {
            $this->travel(2)->hours();
            $screen->call('navTitle')->assertSee('2h');
        },
        'your timezone' => function () use ($screen) {
            app(Account::class)->rememberTimezone('Asia/Tokyo');
            $screen->call('navTitle');
        },
    ];

    foreach ($changes as $change) {
        $change();
        expectKeptRowsMatchFreshOnes($screen, CommitDetail::class, 'replyRows', null);
    }
});

it("keeps a commit page's commits right as the replies of a commit above open and fold, and as the page moves", function () {
    $person = fn (string $handle, int $lane): array => userJson(['id' => 'u-'.$handle, 'handle' => $handle, 'name' => ucfirst($handle), 'lane' => $lane]);
    $c1 = commitJson(['id' => 'c1', 'body' => 'The commit above', 'replies_count' => 2, 'author' => $person('ada', 1)]);
    $c2 = commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'The focused commit', 'replies_count' => 1, 'author' => $person('grace', 2)]);
    $x1 = replyJson(['id' => 'x1', 'parent_id' => 'c1', 'body' => 'Another reply above', 'replies_count' => 1, 'author' => $person('linus', 4)]);
    $x2 = replyJson(['id' => 'x2', 'parent_id' => 'x1', 'body' => 'A reply to the other reply', 'author' => $person('alan', 5)]);
    $r1 = replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'A reply below', 'author' => $person('ken', 6)]);
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson($c2, [$c1])],
        'GET /api/v1/commits/c1' => [commitPageJson($c1)],
        'GET /api/v1/commits/r1' => [commitPageJson($r1, [$c1, $c2])],
        'GET /api/v1/commits/c1/related' => [['data' => []]],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
        'GET /api/v1/commits/r1/related' => [['data' => []]],
        'GET /api/v1/commits/c1/replies' => [pageJson([$c2, $x1])],
        'GET /api/v1/commits/c2/replies' => [pageJson([$r1])],
        'GET /api/v1/commits/x1/replies' => [pageJson([$x2])],
        'GET /api/v1/commits/r1/replies' => [pageJson([])],
        'GET /api/v1/commits/x2/replies' => [pageJson([])],
    ]);
    // Held, so the frame while the replies above fade out is drawn too.
    holdRefreshes();
    $screen = finishRefreshes(Native::visit('/log/commits/c2', ['replies' => true]))->assertSee('A reply below');

    finishRefreshes($screen->tap('replies-c1'))->assertSee('Another reply above');
    expectKeptRowsMatchFreshOnes($screen, CommitDetail::class, 'replyRows', null);

    // With the replies above open, a frame builds none of the commits, and typing a reply only builds the commit it
    // replies to, once, when the draft is first kept.
    expect(rowsBuiltBy(fn () => $screen->call('navTitle')))->toBe([])
        ->and(rowsBuiltBy(fn () => $screen->input('reply-input', 'N')))->toBe(['c2'])
        ->and(rowsBuiltBy(fn () => $screen->input('reply-input', 'Nice')))->toBe([]);

    $changes = [
        'a reply above opens its own' => fn () => finishRefreshes($screen->tap('replies-x1'))->assertSee('A reply to the other reply'),
        'the replies above fading out' => fn () => expect($screen->tap('replies-c1')->get('closingThreads'))->toBe(['c1']),
        'the replies above folded' => fn () => finishRefreshes($screen)->assertDontSee('Another reply above'),
        'the page moved up to the commit above' => fn () => finishRefreshes($screen->tap('commit-c1'))->assertSet('id', 'c1'),
        'the page moved back down' => fn () => finishRefreshes($screen->tap('thread-reply-c2'))->assertSet('id', 'c2'),
        'the page moved down to a reply' => fn () => finishRefreshes($screen->tap('thread-reply-r1'))->assertSet('id', 'r1'),
        'the replies above opened over the reply' => fn () => finishRefreshes($screen->tap('replies-c1'))->assertSee('Another reply above'),
    ];

    foreach ($changes as $change) {
        $change();
        expectKeptRowsMatchFreshOnes($screen, CommitDetail::class, 'replyRows', null);
    }
});

it("keeps a commit page's commits right on a thread its author wrote as replies to themselves", function () {
    $ada = userJson(['id' => 'u-ada', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 2]);
    $part = fn (string $id, ?string $parent, int $minutes, array $overrides = []): array => commitJson(['id' => $id, 'parent_id' => $parent, 'body' => "Ada's {$id}", 'author' => $ada, 'created_at' => now()->subMinutes($minutes)->toIso8601ZuluString(), ...$overrides]);
    fakeApi([
        'GET /api/v1/commits/c1' => [commitPageJson($part('c1', null, 30, ['replies_count' => 2]))],
        'GET /api/v1/commits/p2' => [commitPageJson($part('p2', 'c1', 25), [$part('c1', null, 30, ['replies_count' => 2])])],
        'GET /api/v1/commits/c1/related' => [['data' => []]],
        'GET /api/v1/commits/p2/related' => [['data' => []]],
        'GET /api/v1/commits/c1/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'Grace answers', 'created_at' => now()->subMinutes(28)->toIso8601ZuluString()]), $part('p2', 'c1', 25)])],
        'GET /api/v1/commits/p2/replies' => [pageJson([replyJson(['id' => 'x1', 'parent_id' => 'p2', 'body' => 'Under part two']), $part('p3', 'p2', 20)])],
        'GET /api/v1/commits/p3/replies' => [pageJson([replyJson(['id' => 'y1', 'parent_id' => 'p3', 'body' => 'Under the last part'])])],
        'GET /api/v1/commits/r1/replies' => [pageJson([])],
        'GET /api/v1/commits/x1/replies' => [pageJson([])],
        'GET /api/v1/commits/y1/replies' => [pageJson([])],
    ]);
    $screen = finishRefreshes(Native::visit('/log/commits/c1'))->assertSee("Ada's p3")->assertSee('Under the last part');

    // Typing a reply builds the commit the page is about once, for its draft, and none of the parts after it.
    expect(rowsBuiltBy(fn () => $screen->input('reply-input', 'N')))->toBe(['c1'])
        ->and(rowsBuiltBy(fn () => $screen->input('reply-input', 'Nice')))->toBe([]);

    $changes = [
        'its replies opened' => fn () => $screen->tap('replies-c1')->assertSee('Grace answers'),
        'its replies closed' => fn () => finishRefreshes($screen->tap('replies-c1')),
        'the time' => function () use ($screen) {
            $this->travel(2)->hours();
            $screen->call('navTitle');
        },
        'the page moved to a later part' => fn () => finishRefreshes($screen->tap('commit-p2')->assertSet('id', 'p2')),
    ];

    foreach ($changes as $change) {
        $change();
        expectKeptRowsMatchFreshOnes($screen, CommitDetail::class, 'replyRows', null);
    }
});
