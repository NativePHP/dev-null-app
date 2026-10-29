<?php

use App\Devnull\ResponseCache;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

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

it('shows related commits below the thread in relevance order on their own rail', function (string $platform) {
    serveRelatedThread([
        commitJson(['id' => 'c9', 'body' => 'Closest match', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])]),
        commitJson(['id' => 'c3', 'body' => 'Next match']),
        commitJson(['id' => 'c7', 'body' => 'Third match']),
    ], [
        'GET /api/v1/commits/c1/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'A reply'])])],
    ]);

    $screen = Native::visit('/log/commits/c1', ['replies' => true], platform: $platform)
        ->assertSee('The focused commit')
        ->assertSee('A reply')
        ->assertSee('Related commits')
        ->assertSee('Closest match')
        ->assertSee('Next match')
        ->assertSee('Third match');

    expect(childRefs($screen, 'thread-page'))->toBe(['focused-commit', 'thread-reply-r1', 'related-commits', 'reply-bar-clearance'])
        ->and(childRefs($screen, 'related-commits'))->toBe(['related-commits-heading', 'commit-c9', 'commit-c3', 'commit-c7'])
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

    finishRefreshes($screen)->assertSee('Closest match')->input('reply-input', 'A draft');

    expect(collect(Http::recorded())->filter(fn (array $sent): bool => str_ends_with($sent[0]->url(), '/related')))->toHaveCount(1);
});

it('shows kept related commits at once and replaces them with the fresh ranking', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'The focused commit'])));
    keep('commits/c1/related', ['data' => [commitJson(['id' => 'c9', 'body' => 'Old match'])]]);
    serveRelatedThread([commitJson(['id' => 'c3', 'body' => 'Fresh match'])]);

    $screen = Native::visit('/log/commits/c1')->assertSee('Old match');

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

    Native::visit("/{$tab}/commits/c1")
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
    $related = commitJson(['id' => 'c9', 'body' => 'Closest match']);
    keep('log/following', pageJson([$related]));
    serveRelatedThread([$related], [
        'POST /api/v1/commits/c9/star' => [['data' => [...$related, 'starred' => true, 'stars_count' => 1]]],
    ]);

    $screen = Native::visit('/log/commits/c1')->tap('star-c9');

    expect($screen->instance()->related[0]->starred)->toBeTrue()
        ->and(kept('commits/c1/related')['data'][0]['starred'])->toBeTrue()
        ->and(kept('log/following')['data'][0]['stars_count'])->toBe(1);
});

it('rolls a refused star back off the related commit and its kept copy', function () {
    serveRelatedThread([commitJson(['id' => 'c9', 'body' => 'Closest match'])], [
        'POST /api/v1/commits/c9/star' => [['message' => 'Cannot star now.'], 422],
    ]);

    $screen = Native::visit('/log/commits/c1')->tap('star-c9');

    expect($screen->instance()->related[0]->starred)->toBeFalse()
        ->and(kept('commits/c1/related')['data'][0]['starred'])->toBeFalse();
});

it('removes a related commit deleted on its own page when returning to the thread', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'The focused commit'])));
    keep('commits/c1/related', ['data' => [commitJson(['id' => 'c9', 'body' => 'Closest match'])]]);

    $screen = Native::visit('/log/commits/c1')->assertSee('Closest match');
    app(ResponseCache::class)->commitDeleted('c9');

    $screen->call('onResume')->assertDontSee('Closest match')->assertDontSee('Related commits');
});
