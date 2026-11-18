<?php

use App\Devnull\Beat;
use App\Devnull\ResponseCache;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    Http::preventStrayRequests();
});

/**
 * @param  list<array<string, mixed>>  $related
 * @param  array<string, array{0: mixed, 1?: int}>  $extra
 */
function serveRelatedThread(array $related = [], array $extra = []): void
{
    serveApi([
        'GET /api/v1/commits/c1' => [commitPageJson(commitJson(['id' => 'c1', 'body' => 'The focused commit']))],
        'GET /api/v1/commits/c1/replies' => [pageJson([])],
        'GET /api/v1/commits/c1/related' => [['data' => $related]],
        ...$extra,
    ]);
}

/**
 * Lets every Beat the screen has dispatched come back, as the phone would once it had drawn the step before, and
 * leaves any other task (a held refresh) where it is. Like finishBeats() in LogThreadTest, except that it opens a
 * task's sealed payload without reading it through AsyncTaskTransport::readPayload(), which deletes it, so a held
 * refresh would have nothing left for finishRefreshes() to run.
 */
function landBeats(TestableComponent $screen): TestableComponent
{
    $done = [];

    while (true) {
        $beats = array_filter($screen->bridge()->callsTo('AsyncTask.Dispatch'), function (array $call) use (&$done): bool {
            $id = $call['params']['id'];
            $path = AsyncTaskTransport::directory('payload').DIRECTORY_SEPARATOR.$id.'.task';
            $unseal = Closure::bind(fn (string $id, string $sealed): ?string => static::unseal($id, $sealed), null, AsyncTaskTransport::class);

            return ! isset($done[$id]) && is_file($path) && str_contains((string) $unseal($id, (string) file_get_contents($path)), serialize(Beat::class));
        });

        if ($beats === []) {
            return $screen;
        }

        foreach ($beats as $call) {
            $done[$call['params']['id']] = true;
            AsyncTaskTransport::forgetPayload($call['params']['id']);
            $screen->emitNative(AsyncTaskFinished::class, ['id' => $call['params']['id'], 'result' => true]);
        }
    }
}

/**
 * Opens the related commits under their header, and lets them fade in.
 */
function openRelated(TestableComponent $screen): TestableComponent
{
    return landBeats($screen->tap('related-commits-toggle'));
}

it('shows related commits below the thread in relevance order on their own rail', function (string $platform) {
    serveRelatedThread([
        commitJson(['id' => 'c9', 'body' => 'Closest match', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])]),
        commitJson(['id' => 'c3', 'body' => 'Next match']),
        commitJson(['id' => 'c7', 'body' => 'Third match']),
    ], [
        'GET /api/v1/commits/c1/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'A reply'])])],
    ]);

    $screen = openRelated(Native::visit('/log/commits/c1', ['replies' => true], platform: $platform))
        ->assertSee('The focused commit')
        ->assertSee('A reply')
        ->assertSee('Related commits')
        ->assertSee('Closest match')
        ->assertSee('Next match')
        ->assertSee('Third match');

    expect(childRefs($screen, 'thread-page'))->toBe(['focused-commit', 'thread-reply-r1', 'related-commits', 'reply-bar-clearance'])
        ->and(childRefs($screen, 'related-commits'))->toBe(['related-commits-toggle', 'related-c9', 'related-c3', 'related-c7'])
        ->and(childRefs($screen, 'related-c9'))->toBe(['commit-c9'])
        ->and(railNode($screen, 'commit-c9'))->toBe('avatar')
        ->and(pressFeedbackInScroller($screen))->toBe([]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://devnull.test/api/v1/commits/c1/related'
        && $request->hasHeader('X-SSH-Signature'));
})->with(['ios', 'android']);

it('shows no section when there are no related commits or the optional request fails', function (int $status) {
    serveRelatedThread(extra: ['GET /api/v1/commits/c1/related' => [['data' => []], $status]]);

    Native::visit('/log/commits/c1')
        ->assertSee('The focused commit')
        ->assertDontSee('Related commits')
        ->assertSet('error', null)
        ->assertSet('refreshFailed', false);
})->with(['empty' => 200, 'not found' => 404, 'server error' => 500]);

it('opens the thread before the related commits arrive and does not refetch while typing', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'The focused commit'])));
    serveRelatedThread([commitJson(['id' => 'c9', 'body' => 'Closest match'])]);

    $screen = Native::visit('/log/commits/c1')
        ->assertSee('The focused commit')
        ->assertDontSee('Related commits');

    Http::assertNothingSent();

    openRelated(finishRefreshes($screen))->assertSee('Closest match')->input('reply-input', 'A draft');

    expect(collect(Http::recorded())->filter(fn (array $sent): bool => str_ends_with($sent[0]->url(), '/related')))->toHaveCount(1);
});

it('shows kept related commits at once and replaces them with the fresh ranking', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'The focused commit'])));
    keep('commits/c1/related', ['data' => [commitJson(['id' => 'c9', 'body' => 'Old match'])]]);
    serveRelatedThread([commitJson(['id' => 'c3', 'body' => 'Fresh match'])]);

    $screen = openRelated(Native::visit('/log/commits/c1'))->assertSee('Old match');

    finishRefreshes($screen)->assertSee('Fresh match')->assertDontSee('Old match');

    expect(array_column(kept('commits/c1/related')['data'], 'id'))->toBe(['c3']);
});

it('hides stale suggestions silently when their refresh cannot connect', function () {
    keep('commits/c1/related', ['data' => [commitJson(['id' => 'c9', 'body' => 'Old match'])]]);
    serveApi(fn (Request $request) => str_ends_with($request->url(), '/related')
        ? Http::failedConnection()
        : Http::response(str_ends_with($request->url(), '/replies')
            ? pageJson([])
            : commitPageJson(commitJson(['id' => 'c1', 'body' => 'The focused commit']))));

    Native::visit('/log/commits/c1')
        ->assertSee('The focused commit')
        ->assertDontSee('Related commits')
        ->assertDontSee('Old match')
        ->assertSet('error', null)
        ->assertSet('refreshFailed', false);

    expect(kept('commits/c1/related'))->toBeNull();
});

it('does not let related commits arriving clear a failure to refresh the thread', function () {
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'Kept thread'])));
    serveRelatedThread([commitJson(['id' => 'c9', 'body' => 'Closest match'])], [
        'GET /api/v1/commits/c1' => [['message' => 'Unavailable'], 503],
        'GET /api/v1/commits/c1/replies' => [['message' => 'Unavailable'], 503],
    ]);

    Native::visit('/log/commits/c1')
        ->assertSee('Kept thread')
        ->assertSee("warning: couldn't refresh")
        ->assertSet('refreshFailed', true);
});

it('opens a related commit and its replies in the current tab', function (string $tab, string $ref, bool $replies) {
    serveRelatedThread([commitJson(['id' => 'c9', 'body' => 'Closest match', 'replies_count' => 2])], [
        'GET /api/v1/commits/c9' => [commitPageJson(commitJson(['id' => 'c9', 'body' => 'Closest match', 'replies_count' => 2]))],
        'GET /api/v1/commits/c9/replies' => [pageJson([replyJson(['id' => 'r9', 'parent_id' => 'c9', 'body' => 'Answer to the match'])])],
    ]);

    openRelated(Native::visit("/{$tab}/commits/c1"))
        ->tap($ref)
        ->assertNavigatedTo("/{$tab}/commits/c9")
        ->follow()
        ->assertSee('Closest match')
        ->assertSet('repliesShown', $replies);
})->with([
    'log commit' => ['log', 'commit-c9', false],
    'profile replies' => ['profile', 'replies-c9', true],
]);

it('stars a related commit in place and updates its kept copies', function () {
    $related = commitJson(['id' => 'c9', 'body' => 'Closest match', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])]);
    keep('log/following', pageJson([$related]));
    serveRelatedThread([$related], [
        'POST /api/v1/commits/c9/star' => [['data' => [...$related, 'starred' => true, 'stars_count' => 1]]],
    ]);

    $screen = openRelated(Native::visit('/log/commits/c1'))->tap('star-c9');

    expect($screen->instance()->related[0]->starred)->toBeTrue()
        ->and(kept('commits/c1/related')['data'][0]['starred'])->toBeTrue()
        ->and(kept('log/following')['data'][0]['stars_count'])->toBe(1);
});

it('rolls a refused star back off the related commit and its kept copy', function () {
    serveRelatedThread([commitJson(['id' => 'c9', 'body' => 'Closest match', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])])], [
        'POST /api/v1/commits/c9/star' => [['message' => 'Cannot star now.'], 422],
    ]);

    $screen = openRelated(Native::visit('/log/commits/c1'))->tap('star-c9');

    expect($screen->instance()->related[0]->starred)->toBeFalse()
        ->and(kept('commits/c1/related')['data'][0]['starred'])->toBeFalse();
});

it('removes a related commit deleted on its own page when returning to the thread', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'The focused commit'])));
    keep('commits/c1/related', ['data' => [commitJson(['id' => 'c9', 'body' => 'Closest match'])]]);

    $screen = openRelated(Native::visit('/log/commits/c1'))->assertSee('Closest match');
    app(ResponseCache::class)->commitDeleted('c9');

    $screen->call('onResume')->assertDontSee('Closest match')->assertDontSee('Related commits');
});

it('keeps related commits closed under a header with their count until it is tapped', function (string $platform) {
    serveRelatedThread([
        commitJson(['id' => 'c9', 'body' => 'Closest match']),
        commitJson(['id' => 'c3', 'body' => 'Next match']),
    ]);

    $screen = Native::visit('/log/commits/c1', platform: $platform)
        ->assertSee('Related commits')
        ->assertDontSee('Closest match')
        ->assertDontSee('Next match');

    expect(childRefs($screen, 'related-commits'))->toBe(['related-commits-toggle'])
        ->and(nodeByRef($screen, 'related-commits-count')['props']['text'])->toBe('2')
        ->and(nodeByRef($screen, 'related-commits-toggle')['props']['a11y_label'] ?? null)->toBe('2 related commits')
        ->and(nodeByRef($screen, 'related-commits-chevron')['props']['rotate'] ?? 0.0)->toEqual(0.0);

    openRelated($screen)->assertSee('Closest match')->assertSee('Next match');

    expect(nodeByRef($screen, 'related-commits-chevron')['props']['rotate'])->toEqual(90.0);

    landBeats($screen->tap('related-commits-toggle'))->assertDontSee('Closest match')->assertSee('Related commits');

    expect(nodeByRef($screen, 'related-commits-chevron')['props']['rotate'] ?? 0.0)->toEqual(0.0);
})->with(['ios', 'android']);

it("draws one full-width line above related commits, with the replies shut or open, and no hairline from the commit's text", function (string $platform) {
    serveRelatedThread([commitJson(['id' => 'c9', 'body' => 'Closest match'])], [
        'GET /api/v1/commits/c1' => [commitPageJson(commitJson(['id' => 'c1', 'body' => 'The focused commit', 'replies_count' => 1]))],
        'GET /api/v1/commits/c1/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'A reply'])])],
    ]);

    $screen = Native::visit('/log/commits/c1', platform: $platform)->assertSee('Related commits');
    $commitLine = fn (): float => (float) (nodeByRef($screen, 'focused-commit')['children'][0]['props']['border_b'] ?? 0);
    $relatedLine = fn (): float => (float) (nodeByRef($screen, 'related-commits')['props']['border_t'] ?? 0);

    // Shut, the commit sits right on the section, whose line is the only one.
    expect(childRefs($screen, 'thread-page'))->toBe(['focused-commit', 'related-commits', 'reply-bar-clearance'])
        ->and($commitLine())->toBe(0.0)
        ->and($relatedLine())->toBe(1.0);

    // Open, the last reply ends with no hairline of its own, so the section's line is still the only one.
    $screen->tap('replies-c1')->assertSee('A reply');
    expect(childRefs($screen, 'thread-page'))->toBe(['focused-commit', 'thread-reply-r1', 'related-commits', 'reply-bar-clearance'])
        ->and($commitLine())->toBe(0.0)
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'thread-end-r1'))->toBeNull()
        ->and($relatedLine())->toBe(1.0);
})->with(['ios', 'android']);

it('keeps the hairline under the commit when nothing comes after it, or "No replies yet" does', function (array $commit, array $related) {
    serveRelatedThread($related, [
        'GET /api/v1/commits/c1' => [commitPageJson(commitJson(['id' => 'c1', 'body' => 'The focused commit', ...$commit]))],
    ]);

    $screen = Native::visit('/log/commits/c1');

    expect((float) (nodeByRef($screen, 'focused-commit')['children'][0]['props']['border_b'] ?? 0))->toBe(1.0);
})->with([
    'no related commits' => [['replies_count' => 1], []],
    'no replies yet' => [['replies_count' => 0], [commitJson(['id' => 'c9', 'body' => 'Closest match'])]],
]);

it('fades related commits in one after another as they open, and out together as they close', function () {
    holdRefreshes();
    serveRelatedThread([
        commitJson(['id' => 'c9', 'body' => 'Closest match']),
        commitJson(['id' => 'c3', 'body' => 'Next match']),
    ]);

    $screen = finishRefreshes(Native::visit('/log/commits/c1', platform: 'ios'));

    // Drawn see-through and nudged up first, with the animation each eases in by, the second a beat later.
    $screen->tap('related-commits-toggle');
    expect(nodeByRef($screen, 'related-c9')['style']['opacity'] ?? null)->toBe(0.0)
        ->and(nodeByRef($screen, 'related-c9')['props']['translate-y'] ?? null)->toBe(-8.0)
        ->and(nodeByRef($screen, 'related-c9')['props']['animate-duration'] ?? null)->toBe(220.0)
        ->and(nodeByRef($screen, 'related-c9')['props']['animate-delay'] ?? 0.0)->toEqual(0.0)
        ->and(nodeByRef($screen, 'related-c3')['props']['animate-delay'] ?? null)->toEqual(40.0)
        ->and(nodeByRef($screen, 'related-commits-chevron')['props']['rotate'])->toEqual(90.0)
        ->and(nodeByRef($screen, 'related-commits-chevron')['props']['animate-duration'] ?? null)->toBe(220.0);

    landBeats($screen);
    expect(nodeByRef($screen, 'related-c9')['style']['opacity'] ?? 1.0)->toBe(1.0)
        ->and(nodeByRef($screen, 'related-c9')['props']['translate-y'] ?? null)->toBe(0.0);

    // Closing fades them out together, chevron back first, and they go once that's done. A tap meanwhile does nothing.
    $screen->tap('related-commits-toggle')->tap('related-commits-toggle')->assertSee('Closest match');
    expect(nodeByRef($screen, 'related-c9')['style']['opacity'] ?? null)->toBe(0.0)
        ->and(nodeByRef($screen, 'related-c3')['props']['animate-delay'] ?? 0.0)->toEqual(0.0)
        ->and(nodeByRef($screen, 'related-commits-chevron')['props']['rotate'] ?? 0.0)->toEqual(0.0);

    landBeats($screen)->assertDontSee('Closest match');
    expect($screen->instance()->relatedShown)->toBeFalse()
        ->and($screen->instance()->relatedFaded)->toBeFalse();
});
