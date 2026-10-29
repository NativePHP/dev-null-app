<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

function grace(): array
{
    return userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace', 'lane' => 4]);
}

/**
 * A commit by Ada in a list, with the newest reply devnull previews under it.
 *
 * @param  list<array<string, mixed>>  $replies
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function previewedJson(array $replies, bool $more = false, array $overrides = []): array
{
    return commitJson([
        'id' => 'c1',
        'body' => 'Ship it?',
        'replies_count' => count($replies) + ($more ? 2 : 0),
        'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 1]),
        'reply_preview' => ['data' => $replies, 'next_cursor' => $more ? 'eyJjcmVhdGVkX2F0IjoiMjAyNiJ9' : null],
        ...$overrides,
    ]);
}

/**
 * The colours of the dashed lines in a node, in order.
 *
 * @return list<string>
 */
function dashColours(array $node): array
{
    return array_values(array_unique(array_map(
        fn (array $line): string => $line['style']['border_color'] ?? '',
        findNodes($node, fn (array $child): bool => ($child['props']['border_style'] ?? null) === 1),
    )));
}

it('hangs the newest reply under a commit, on a dashed elbow in its lane, instead of a replies toggle', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([previewedJson([
        replyJson(['id' => 'r9', 'parent_id' => 'c1', 'body' => 'The newest reply', 'author' => grace()]),
    ])]))]);

    $screen = Native::visit('/log', platform: 'ios')
        ->assertSee('Ship it?')
        ->assertSee('The newest reply')
        ->assertDontSee('1 reply')
        ->assertDontSee('Show more');

    $reply = nodeByRef($screen, 'thread-reply-r9');
    $elbow = findNode($reply, fn (array $node): bool => ($node['ref'] ?? null) === 'thread-elbow');

    expect($elbow)->not->toBeNull()
        ->and(dashColours($elbow))->toBe([config('native-ui.theme.light.lane-1')])
        // One dashed border down from the rail, round a 12pt corner and across to 1pt short of the avatar.
        ->and($elbow['props'])->toMatchArray(['border_l' => 1.0, 'border_b' => 1.0, 'border_t' => 0.0, 'border_r' => 0.0, 'radius_bl' => 12.0])
        ->and($elbow['layout'])->toMatchArray(['width' => 31.5, 'height' => 22.5, 'position' => [0.0, 0.0, 0.0, 31.5]])
        // A reply's own actions: the star and Reply, but no Quote.
        ->and(findNode($reply, fn (array $node): bool => ($node['ref'] ?? null) === 'reply-to-r9'))->not->toBeNull()
        ->and(findNode($reply, fn (array $node): bool => ($node['ref'] ?? null) === 'quote-r9'))->toBeNull();

    // The commit ends its rail in a dot, and the dashes carry on from it to the reply.
    expect(findNode(nodeByRef($screen, 'commit-c1'), fn (array $node): bool => ($node['ref'] ?? null) === 'end-dot'))->not->toBeNull();

    $screen->tap('thread-reply-r9')->assertNavigatedTo('/log/commits/r9');
});

it('frames a reply avatar in its own lane without shifting the thread', function (string $platform) {
    Http::fake(['devnull.test/*' => Http::response(pageJson([previewedJson([
        replyJson(['id' => 'r9', 'parent_id' => 'c1', 'author' => grace()]),
    ])]))]);

    $screen = Native::visit('/log', platform: $platform);
    $reply = nodeByRef($screen, 'thread-reply-r9');
    $ring = findNode($reply, fn (array $node): bool => ($node['style']['border_color'] ?? null) === config('native-ui.theme.light.lane-4')
        && ($node['layout']['width'] ?? null) === 24.0
        && ($node['layout']['height'] ?? null) === 24.0);

    expect($ring)->not->toBeNull()
        ->and($ring['style']['border_radius'])->toBeGreaterThanOrEqual(12.0)
        ->and($ring['style'])->toMatchArray(['border_width' => 1.0, 'bg_color' => config('native-ui.theme.light.surface')])
        ->and($ring['children'])->toHaveCount(1)
        ->and($ring['children'][0]['layout'])->toMatchArray(['width' => 20.0, 'height' => 20.0]);
})->with(['ios', 'android']);

it('opens the rest of the thread in place, newest first, from Show more', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([previewedJson([replyJson(['id' => 'r3', 'parent_id' => 'c1', 'body' => 'Third', 'author' => grace()])], more: true)])],
        'GET /api/v1/commits/c1/replies' => [pageJson([
            replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'First']),
            replyJson(['id' => 'r2', 'parent_id' => 'c1', 'body' => 'Second', 'author' => grace()]),
            replyJson(['id' => 'r3', 'parent_id' => 'c1', 'body' => 'Third', 'author' => grace()]),
        ])],
    ]);

    $screen = Native::visit('/log', platform: 'ios')
        ->assertSee('Third')
        ->assertDontSee('Second')
        ->assertSee('Show more');

    $screen->tap('more-replies-c1')
        ->assertNoNavigation()
        ->assertSee('First')
        ->assertSee('Second')
        ->assertDontSee('Show more');

    expect(array_map(fn ($reply): string => $reply->id, $screen->instance()->threads['c1']))->toBe(['r3', 'r2', 'r1']);

    // Each reply after the first hangs on a short link in the lane of the reply above it.
    expect(dashColours(nodeByRef($screen, 'thread-reply-r2')))->toContain(config('native-ui.theme.light.lane-4'))
        ->and(findNode(nodeByRef($screen, 'thread-reply-r2'), fn (array $node): bool => ($node['ref'] ?? null) === 'thread-elbow'))->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/commits/c1/replies?limit=5');
});

it('opens Show more at once from the thread kept last time, and refreshes it behind', function () {
    holdRefreshes();
    keep('log/following', pageJson([previewedJson([replyJson(['id' => 'r2', 'parent_id' => 'c1', 'body' => 'Second'])], more: true)]));
    keep('commits/c1/replies', pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'First kept']), replyJson(['id' => 'r2', 'parent_id' => 'c1', 'body' => 'Second'])]));
    serveApi(fn () => Http::response(pageJson([])));

    $screen = Native::visit('/log', platform: 'ios');
    $requests = count(Http::recorded());

    $screen->tap('more-replies-c1')->assertSee('First kept');

    expect(count(Http::recorded()))->toBe($requests)
        ->and(refreshesSent($screen))->toBeGreaterThan(1);
});

it('opens the commit with its replies showing instead when its thread is longer than a page', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([previewedJson([replyJson(['id' => 'r60', 'parent_id' => 'c1'])], more: true)])],
        'GET /api/v1/commits/c1/replies' => [pageJson(array_map(fn (int $n): array => replyJson(['id' => "r{$n}", 'parent_id' => 'c1']), range(1, 5)), 'page2')],
        'GET /api/v1/commits/c1' => [commitPageJson(previewedJson([], more: true))],
    ]);

    Native::visit('/log', platform: 'ios')
        ->tap('more-replies-c1')
        ->assertNavigatedTo('/log/commits/c1')
        ->follow()
        ->assertSet('repliesShown', true);
});

it('stars a reply in the thread in place', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([previewedJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'author' => grace(), 'stars_count' => 0])])])],
        'POST /api/v1/commits/r1/star' => [['data' => commitJson(['id' => 'r1', 'stars_count' => 1, 'starred' => true, 'author' => grace()])]],
    ]);

    $screen = Native::visit('/log')->tap('star-r1');

    expect($screen->instance()->commits[0]->replyPreview->replies[0]->starred)->toBeTrue()
        ->and($screen->instance()->commits[0]->replyPreview->replies[0]->starsCount)->toBe(1);
});

it('keeps the thread under a commit you star below a quote of it, and unstars it on the next tap', function () {
    $quoted = previewedJson([]);
    unset($quoted['reply_preview']);

    fakeApi([
        'GET /api/v1/log/following' => [pageJson([
            commitJson(['id' => 'q1', 'body' => 'Look at this', 'quoted_commit_id' => 'c1', 'quoted_commit' => $quoted]),
            previewedJson([replyJson(['id' => 'r9', 'parent_id' => 'c1', 'body' => 'The newest reply', 'author' => grace()])]),
        ])],
        'POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'stars_count' => 1, 'starred' => true])]],
        'DELETE /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'stars_count' => 0, 'starred' => false])]],
    ]);
    $screen = Native::visit('/log', platform: 'ios');

    $screen->tap('star-c1')->assertSee('The newest reply');
    $starred = $screen->instance()->commits[1]->starred;
    $screen->tap('star-c1');

    expect($starred)->toBeTrue()
        ->and($screen->instance()->commits[1]->starred)->toBeFalse()
        ->and(sentTo('DELETE', 'commits/c1/star'))->toHaveCount(1);
});

it('keeps the thread under a commit you star that is also the reply previewed under its parent', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([])],
        'GET /api/v1/log/latest' => [pageJson([
            previewedJson([replyJson(['id' => 'c1', 'parent_id' => 'p1', 'body' => 'Ship it?', 'replies_count' => 1])], overrides: ['id' => 'p1', 'body' => 'Should we ship?']),
            previewedJson([replyJson(['id' => 'r9', 'parent_id' => 'c1', 'body' => 'The newest reply', 'author' => grace()])], overrides: ['parent_id' => 'p1']),
        ], path: 'http://devnull.test/api/v1/log/latest')],
        'POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'stars_count' => 1, 'starred' => true])]],
    ]);
    $screen = tapLogTab(Native::visit('/log', platform: 'ios'), 'latest');

    $screen->call('toggleStar', 'c1');

    $screen->assertSee('The newest reply');
    expect($screen->instance()->commits[0]->replyPreview->replies[0]->starred)->toBeTrue()
        ->and($screen->instance()->commits[1]->starred)->toBeTrue();
});

it("runs the commit's rail on past its thread when the author's run carries on below", function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 1]);

    Http::fake(['devnull.test/*' => Http::response(pageJson([
        previewedJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'author' => grace()])], overrides: ['id' => 'c2', 'author' => $ada]),
        commitJson(['id' => 'c1', 'author' => $ada]),
    ]))]);

    $screen = Native::visit('/log', platform: 'ios');
    $lane = config('native-ui.theme.light.lane-1');
    $solidRail = fn (array $node): bool => ($node['layout']['width'] ?? null) === 2.0 && ($node['style']['bg_color'] ?? null) === $lane;

    expect(findNode(nodeByRef($screen, 'thread-reply-r1'), $solidRail))->not->toBeNull()
        ->and(findNode(nodeByRef($screen, 'commit-c2'), fn (array $node): bool => ($node['ref'] ?? null) === 'end-dot'))->toBeNull()
        ->and(railNode($screen, 'commit-c1'))->toBe('dot');
});

it('ends a commit alone in its run with a dot, and joins a run of one author', function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'lane' => 1]);

    Http::fake(['devnull.test/*' => Http::response(pageJson([
        commitJson(['id' => 'c3', 'author' => $ada]),
        commitJson(['id' => 'c2', 'author' => $ada]),
        commitJson(['id' => 'c1', 'author' => grace()]),
    ]))]);

    $screen = Native::visit('/log', platform: 'ios');
    $endDot = fn (string $ref): bool => findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['ref'] ?? null) === 'end-dot') !== null;

    expect(railNode($screen, 'commit-c3'))->toBe('avatar')
        ->and(railNode($screen, 'commit-c2'))->toBe('dot')
        ->and($endDot('commit-c3'))->toBeFalse()
        ->and($endDot('commit-c2'))->toBeFalse()
        ->and($endDot('commit-c1'))->toBeTrue();
});

it("closes a commit's row in the log with a hairline after its last previewed reply", function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([previewedJson([
        replyJson(['id' => 'r9', 'parent_id' => 'c1', 'body' => 'The newest reply', 'author' => grace()]),
    ])]))]);

    $screen = Native::visit('/log', platform: 'ios');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'thread-end-r9'))->not->toBeNull();
});
