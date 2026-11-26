<?php

use App\Devnull\Pop;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

function grace(): array
{
    return userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace', 'lane' => 4]);
}

function threadAda(): array
{
    return userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 1]);
}

/**
 * A commit by Ada in a list, with $replies replies, and the newest one devnull previews under it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function threadedJson(int $replies = 1, array $overrides = []): array
{
    return commitJson([
        'id' => 'c1',
        'body' => 'Ship it?',
        'replies_count' => $replies,
        'author' => threadAda(),
        'reply_preview' => ['data' => [replyJson(['id' => 'r9', 'parent_id' => 'c1', 'body' => 'The newest reply', 'author' => grace()])], 'next_cursor' => null],
        ...$overrides,
    ]);
}

/**
 * $count replies to $parentId, r1 to rN unless $prefix says otherwise.
 *
 * @return list<array<string, mixed>>
 */
function repliesJson(string $parentId, int $count, string $prefix = 'r', int $from = 1, array $overrides = []): array
{
    return array_map(fn (int $n): array => replyJson(['id' => "{$prefix}{$n}", 'parent_id' => $parentId, 'body' => "Reply {$prefix}{$n}", 'author' => grace(), ...$overrides]), range($from, $from + $count - 1));
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

function refNode(array $node, string $ref): ?array
{
    return findNode($node, fn (array $child): bool => ($child['ref'] ?? null) === $ref);
}

it('keeps replies hidden under a commit, with the replies button on its own line under the actions', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([threadedJson(3)]))]);

    $screen = Native::visit('/log', platform: 'ios')
        ->assertSee('Ship it?')
        ->assertDontSee('The newest reply')
        ->assertSee('3 replies');

    expect(refNode(nodeByRef($screen, 'actions-c1'), 'replies-c1'))->toBeNull()
        ->and(refNode(nodeByRef($screen, 'commit-c1'), 'replies-c1'))->not->toBeNull()
        ->and(findNode($screen->tree(), fn (array $node): bool => str_starts_with($node['ref'] ?? '', 'thread-reply-')))->toBeNull();
});

it('opens the replies under a commit from its replies button, on a dashed elbow in its lane', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([threadedJson(2)])],
        'GET /api/v1/commits/c1/replies' => [pageJson(repliesJson('c1', 2))],
    ]);

    $screen = Native::visit('/log', platform: 'ios')
        ->tap('replies-c1')
        ->assertNoNavigation()
        ->assertSee('Reply r1')
        ->assertSee('Reply r2')
        ->assertSee('Hide replies');

    $reply = nodeByRef($screen, 'thread-reply-r1');
    $elbow = refNode($reply, 'thread-elbow');

    expect($elbow)->not->toBeNull()
        ->and(dashColours($elbow))->toBe([config('native-ui.theme.light.lane-1')])
        ->and($elbow['props'])->toMatchArray(['border_l' => 1.0, 'border_b' => 1.0, 'border_t' => 0.0, 'border_r' => 0.0, 'radius_bl' => 12.0])
        // Level with the middle of the avatar, which sits 3pt down, under the commit row's 7pt of bottom padding.
        ->and($elbow['layout'])->toMatchArray(['width' => 31.5, 'height' => 15.5, 'position' => [0.0, 0.0, 0.0, 31.5]])
        // A reply's own actions: the star and Reply, but no Quote.
        ->and(refNode($reply, 'reply-to-r1'))->not->toBeNull()
        ->and(refNode($reply, 'quote-r1'))->toBeNull()
        // The second hangs on a short link in the lane of the reply above it.
        ->and(dashColours(nodeByRef($screen, 'thread-reply-r2')))->toContain(config('native-ui.theme.light.lane-4'))
        ->and(refNode(nodeByRef($screen, 'thread-reply-r2'), 'thread-elbow'))->toBeNull()
        // The last closes the commit's row with its hairline.
        ->and(refNode($screen->tree(), 'thread-end-r2'))->not->toBeNull();

    $screen->tap('thread-reply-r1')->assertNavigatedTo('/log/commits/r1');
});

it('frames a reply avatar in its own lane without shifting the thread', function (string $platform) {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([threadedJson()])],
        'GET /api/v1/commits/c1/replies' => [pageJson(repliesJson('c1', 1))],
    ]);

    $screen = Native::visit('/log', platform: $platform)->tap('replies-c1');
    $ring = findNode(nodeByRef($screen, 'thread-reply-r1'), fn (array $node): bool => ($node['style']['border_color'] ?? null) === config('native-ui.theme.light.lane-4')
        && ($node['layout']['width'] ?? null) === 24.0
        && ($node['layout']['height'] ?? null) === 24.0);

    expect($ring)->not->toBeNull()
        ->and($ring['style']['border_radius'])->toBeGreaterThanOrEqual(12.0)
        ->and($ring['style'])->toMatchArray(['border_width' => 1.0, 'bg_color' => config('native-ui.theme.light.surface')])
        ->and($ring['children'])->toHaveCount(1)
        ->and($ring['children'][0]['layout'])->toMatchArray(['width' => 20.0, 'height' => 20.0]);
})->with(['ios', 'android']);

it('shows ten replies at a time when devnull sends more, and asks for the next page once they all show', function () {
    Http::fake(fn (Request $request) => Http::response(match (true) {
        str_contains($request->url(), '/log/following') => pageJson([threadedJson(25)]),
        // devnull sends 50 a page whatever limit asks for.
        str_contains($request->url(), 'cursor=page2') => pageJson(repliesJson('c1', 12, from: 14), path: 'http://devnull.test/api/v1/commits/c1/replies'),
        default => pageJson(repliesJson('c1', 13), 'page2', 'http://devnull.test/api/v1/commits/c1/replies'),
    }));

    $screen = Native::visit('/log', platform: 'ios')
        ->tap('replies-c1')
        ->assertSee('Reply r10')
        ->assertDontSee('Reply r11')
        ->assertSee('Show more');

    $screen->tap('more-replies-c1')->assertSee('Reply r13')->assertDontSee('Reply r14')->assertNoNavigation();
    expect(sentTo('GET', 'commits/c1/replies'))->toHaveCount(1);

    $screen->tap('more-replies-c1')->assertSee('Reply r23')->assertDontSee('Reply r24');
    $screen->tap('more-replies-c1')->assertSee('Reply r25')->assertDontSee('Show more');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/commits/c1/replies?limit=10');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/commits/c1/replies?cursor=page2&limit=10');
});

it("opens a reply's own replies in place, one step in, hanging off the line from its avatar", function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([threadedJson(2)])],
        'GET /api/v1/commits/c1/replies' => [pageJson([
            replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'First', 'replies_count' => 12, 'author' => grace()]),
            replyJson(['id' => 'r2', 'parent_id' => 'c1', 'body' => 'Second', 'author' => replierJson()]),
        ])],
        'GET /api/v1/commits/r1/replies' => [pageJson(repliesJson('r1', 12, 'n', overrides: ['author' => threadAda(), 'replies_count' => 2]))],
    ]);

    $screen = Native::visit('/log', platform: 'ios')->tap('replies-c1');

    // Its replies button is under its actions too.
    expect(refNode(nodeByRef($screen, 'actions-r1'), 'replies-r1'))->toBeNull()
        ->and(refNode(nodeByRef($screen, 'thread-reply-r1'), 'replies-r1'))->not->toBeNull();

    $screen->tap('replies-r1')
        ->assertNoNavigation()
        ->assertSee('Reply n10')
        ->assertDontSee('Reply n11');

    // In order: r1, its replies, its Show more, then r2.
    $refs = array_values(array_filter(array_map(fn (array $node): ?string => $node['ref'] ?? null, findNodes($screen->tree(), fn (array $node): bool => preg_match('/^(thread-reply-|more-replies-)/', $node['ref'] ?? '') === 1))));
    expect($refs)->toBe(['thread-reply-r1', ...array_map(fn (int $n): string => "thread-reply-n{$n}", range(1, 10)), 'more-replies-r1', 'thread-reply-r2']);

    $nested = nodeByRef($screen, 'thread-reply-n1');
    $elbow = refNode($nested, 'thread-elbow');
    $spine = fn (array $node): ?array => findNode($node, fn (array $line): bool => ($line['props']['border_style'] ?? null) === 1 && ($line['layout']['position'][3] ?? null) === 75.5 && ! isset($line['layout']['height']));

    // Its elbow leaves the line down from r1's avatar, in r1's lane, and that line runs on past it to r2.
    expect($elbow['layout']['position'][3] ?? null)->toBe(75.5)
        ->and(dashColours($elbow))->toBe([config('native-ui.theme.light.lane-4')])
        ->and($spine($nested))->not->toBeNull();

    // One step in, a reply's replies open its page instead.
    $screen->tap('replies-n1')->assertNavigatedTo('/log/commits/n1');
    expect($screen->navigationIntent()->data['replies'])->toBeTrue();
});

it("closes a commit's thread, and the replies opened inside it, from Hide replies", function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([threadedJson(1)])],
        'GET /api/v1/commits/c1/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'replies_count' => 1, 'author' => grace()])])],
        'GET /api/v1/commits/r1/replies' => [pageJson(repliesJson('r1', 1, 'n', overrides: ['author' => threadAda()]))],
    ]);

    $screen = Native::visit('/log', platform: 'ios')->tap('replies-c1')->tap('replies-r1')->assertSee('Reply n1');

    $screen->tap('replies-c1')->assertDontSee('Reply n1')->assertSee('1 reply');

    expect($screen->instance()->threads)->toBe([])
        ->and($screen->instance()->fadedRows)->toBe([]);
});

it('fades a thread in as it opens and out as it closes', function () {
    holdRefreshes();
    keep('log/following', pageJson([threadedJson(1)]));
    keep('commits/c1/replies', pageJson(repliesJson('c1', 1)));
    serveApi(fn () => Http::response(pageJson([])));

    $screen = Native::visit('/log', platform: 'ios');
    $content = fn (): array => nodeByRef($screen, 'thread-reply-r1')['children'][0]['children'][1];

    // Drawn see-through and nudged up first, with the animation it eases in by.
    $screen->tap('replies-c1');
    expect($content()['style']['opacity'] ?? null)->toBe(0.0)
        ->and($content()['props']['translate-y'] ?? null)->toBe(-8.0)
        ->and($content()['props']['animate-duration'] ?? null)->toBe(220.0);

    finishBeats($screen);
    expect($content()['style']['opacity'] ?? 1.0)->toBe(1.0)
        ->and($content()['props']['translate-y'] ?? null)->toBe(0.0);

    // Closing fades it out, and it goes once that's done.
    $screen->tap('replies-c1')->assertSee('1 reply');
    expect($content()['style']['opacity'] ?? null)->toBe(0.0);

    finishBeats($screen);
    expect(refNode($screen->tree(), 'thread-reply-r1'))->toBeNull();
});

it('keeps a thread under a commit you star, and stars a reply in it in place', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([threadedJson(1)])],
        'GET /api/v1/commits/c1/replies' => [pageJson(repliesJson('c1', 1))],
        'POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'stars_count' => 1, 'starred' => true])]],
        'POST /api/v1/commits/r1/star' => [['data' => commitJson(['id' => 'r1', 'stars_count' => 1, 'starred' => true, 'author' => grace()])]],
    ]);

    $screen = Native::visit('/log', platform: 'ios')->tap('replies-c1')->tap('star-c1')->tap('star-r1');

    $screen->assertSee('Reply r1');
    expect($screen->instance()->commits[0]->starred)->toBeTrue()
        ->and($screen->instance()->threads['c1'][0]->starred)->toBeTrue();
});

it('pops a star you give, as the web does, and leaves it as it was', function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'author' => threadAda(), 'starred' => false])]));
    serveApi(fn () => Http::response(['data' => commitJson(['id' => 'c1', 'starred' => true, 'stars_count' => 1, 'author' => threadAda()])]));

    $screen = Native::visit('/log', platform: 'ios');
    $star = fn (): array => findNode(nodeByRef($screen, 'star-c1'), fn (array $node): bool => ($node['type'] ?? null) === 'icon');

    expect($star()['props'])->not->toHaveKey('scale');

    $screen->tap('star-c1');
    expect($screen->instance()->popping)->toBe(['star-c1' => Pop::Ready])
        ->and($star()['props'])->toMatchArray(['scale' => 1.0, 'animate-duration' => 150.0]);

    finishBeats($screen, 1);
    expect($star()['props'])->toMatchArray(['scale' => 1.35, 'rotate' => -12.0]);

    finishBeats($screen, 1);
    expect($star()['props'])->toMatchArray(['scale' => 1.0, 'rotate' => 0.0, 'animate-easing' => 'spring']);

    finishBeats($screen);
    expect($screen->instance()->popping)->toBe([])
        ->and($star()['props'])->not->toHaveKey('scale');
});

it("doesn't pop a star you take back, as the web doesn't", function () {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'author' => threadAda(), 'starred' => true, 'stars_count' => 1])]));
    serveApi(fn () => Http::response(['data' => commitJson(['id' => 'c1', 'starred' => false, 'stars_count' => 0, 'author' => threadAda()])]));

    $screen = Native::visit('/log', platform: 'ios')->tap('star-c1');

    expect($screen->instance()->popping)->toBe([]);
});

it("runs the commit's rail on past its thread when the author's run carries on below", function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([threadedJson(1, ['id' => 'c2']), commitJson(['id' => 'c1', 'author' => threadAda()])])],
        'GET /api/v1/commits/c2/replies' => [pageJson(repliesJson('c2', 1))],
    ]);

    $screen = Native::visit('/log', platform: 'ios')->tap('replies-c2');
    $lane = config('native-ui.theme.light.lane-1');
    $solidRail = fn (array $node): bool => ($node['layout']['width'] ?? null) === 2.0 && ($node['style']['bg_color'] ?? null) === $lane;

    expect(findNode(nodeByRef($screen, 'thread-reply-r1'), $solidRail))->not->toBeNull()
        ->and(refNode(nodeByRef($screen, 'commit-c2'), 'end-dot'))->toBeNull()
        ->and(railNode($screen, 'commit-c1'))->toBe('dot');
});

it('ends a commit alone in its run with a dot inside its action row, level with the actions', function (string $platform) {
    Http::fake(['devnull.test/*' => Http::response(pageJson([
        commitJson(['id' => 'c3', 'author' => threadAda()]),
        commitJson(['id' => 'c2', 'author' => threadAda()]),
        commitJson(['id' => 'c1', 'author' => grace(), 'replies_count' => 4]),
        commitJson(['id' => 'c0', 'author' => threadAda(), 'body' => str_repeat('A long body that wraps. ', 20)]),
    ]))]);

    $screen = Native::visit('/log', platform: $platform);

    expect(railNode($screen, 'commit-c3'))->toBe('avatar')
        ->and(railNode($screen, 'commit-c2'))->toBe('dot')
        ->and(refNode(nodeByRef($screen, 'commit-c3'), 'end-dot'))->toBeNull()
        ->and(refNode(nodeByRef($screen, 'commit-c2'), 'end-dot'))->toBeNull();

    foreach (['c1', 'c0'] as $id) {
        $dot = refNode(nodeByRef($screen, "actions-{$id}"), 'end-dot');

        // Centred in the 36pt row, at the rail's x: the row starts 56pt in, the dot's middle is at 32.
        expect($dot)->not->toBeNull()
            ->and($dot['layout'])->toMatchArray(['width' => 18.0, 'height' => 18.0])
            ->and($dot['layout']['position'][0])->toBe(9.0)
            ->and($dot['layout']['position'][3])->toBe(-33.0)
            ->and(nodeByRef($screen, "actions-{$id}")['layout']['height'])->toBe(36.0);
    }
})->with(['ios', 'android']);

it('stops the rail at the end dot, past the replies line, and carries it on in dashes once the replies open', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => grace(), 'replies_count' => 1])])],
        'GET /api/v1/commits/c1/replies' => [pageJson(repliesJson('c1', 1, overrides: ['author' => threadAda()]))],
    ]);

    $screen = Native::visit('/log', platform: 'ios');
    $lane = config('native-ui.theme.light.lane-4');
    $tail = fn (): ?array => findNode(nodeByRef($screen, 'commit-c1'), fn (array $node): bool => ($node['layout']['width'] ?? null) === 2.0 && ($node['style']['bg_color'] ?? null) === $lane);

    // Closed: 7pt padding with the hairline, the replies line (4pt gap and 36pt) and half the action row (18pt) put
    // the dot's middle 65pt up, and the rail stops 2pt into its ring, 9pt above that.
    expect($tail()['layout']['position'][2])->toBe(72.0);

    $screen->tap('replies-c1');

    // Open: the same 7pt padding without the hairline, so the dot stays put, and the dashes run on from the dot's foot
    // to the row's, where the first reply's elbow takes over.
    $dashes = findNode(nodeByRef($screen, 'commit-c1'), fn (array $node): bool => ($node['props']['border_style'] ?? null) === 1 && ($node['layout']['position'][2] ?? null) === 0.0);

    expect($tail()['layout']['position'][2])->toBe(72.0)
        ->and($dashes['layout']['height'] ?? null)->toBe(56.0)
        ->and(refNode(nodeByRef($screen, 'thread-reply-r1'), 'thread-elbow')['layout']['position'][0])->toBe(0.0);
});

it("keeps a commit's row the same height with its replies shut, opening, open and closing, so the list never moves it", function (string $platform) {
    holdRefreshes();
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'author' => grace(), 'replies_count' => 2])]));
    keep('commits/c1/replies', pageJson(repliesJson('c1', 2, overrides: ['author' => threadAda()])));
    serveApi(fn () => Http::response(pageJson([])));

    $screen = Native::visit('/log', platform: $platform);
    $content = fn (): array => nodeByRef($screen, 'commit-c1')['children'][0];
    $heights = fn (): array => [
        $content()['layout'],
        nodeByRef($screen, 'actions-c1')['layout'],
        nodeByRef($screen, 'replies-c1')['layout'],
    ];
    $hairline = fn (): ?float => $content()['props']['border_b'] ?? null;

    $shut = $heights();

    // 12pt above, and under the replies line the 6pt the action row leaves and the hairline's 1pt.
    expect($shut[0]['padding'])->toEqual([12, 16, 7, 0])
        ->and($hairline())->toBe(1.0);

    $screen->tap('replies-c1');
    expect($heights())->toBe($shut);

    finishBeats($screen);
    expect($heights())->toBe($shut)
        ->and($hairline())->toBeNull()
        // The first reply takes the 7pt off its 10pt top, so the thread sits where it did; the next keeps its 10pt.
        ->and(nodeByRef($screen, 'thread-reply-r1')['children'][0]['children'][1]['children'][0]['layout']['padding'][0])->toEqual(3)
        ->and(nodeByRef($screen, 'thread-reply-r2')['children'][0]['children'][1]['children'][0]['layout']['padding'][0])->toEqual(10);

    $screen->tap('replies-c1')->assertSee('2 replies');
    expect($heights())->toBe($shut);

    finishBeats($screen);
    expect(refNode($screen->tree(), 'thread-reply-r1'))->toBeNull()
        ->and($heights())->toBe($shut)
        ->and($hairline())->toBe(1.0);
})->with(['ios', 'android']);

it('drops a reply that went from an open thread when the log refreshes, and keeps the pages loaded after the first', function () {
    $gone = false;

    Http::fake(function (Request $request) use (&$gone) {
        return Http::response(match (true) {
            str_contains($request->url(), '/log/following') => pageJson([threadedJson(12)]),
            str_contains($request->url(), 'cursor=page2') => pageJson(repliesJson('c1', 2, from: 11), path: 'http://devnull.test/api/v1/commits/c1/replies'),
            default => pageJson(array_values(array_filter(repliesJson('c1', 10), fn (array $reply): bool => ! ($gone && $reply['id'] === 'r3'))), 'page2', 'http://devnull.test/api/v1/commits/c1/replies'),
        });
    });

    $screen = Native::visit('/log', platform: 'ios')->tap('replies-c1')->tap('more-replies-c1')->assertSee('Reply r12');

    $gone = true;
    $screen->call('load');

    expect(array_map(fn ($reply): string => $reply->id, $screen->instance()->threads['c1']))->toBe(['r1', 'r2', ...array_map(fn (int $n): string => "r{$n}", range(4, 12))]);
});

it('opens a thread at once from the replies kept last time, and refreshes it behind', function () {
    holdRefreshes();
    keep('log/following', pageJson([threadedJson(2)]));
    keep('commits/c1/replies', pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'First kept']), replyJson(['id' => 'r2', 'parent_id' => 'c1'])]));
    serveApi(fn () => Http::response(pageJson([])));

    $screen = Native::visit('/log', platform: 'ios');
    $requests = count(Http::recorded());

    $screen->tap('replies-c1')->assertSee('First kept');

    expect(count(Http::recorded()))->toBe($requests)
        ->and(refreshesSent($screen))->toBeGreaterThan(1);
});

/**
 * Runs the Beats a screen is waiting on, $times rounds of them or until none are left.
 */
function finishBeats(TestableComponent $screen, int $times = PHP_INT_MAX): TestableComponent
{
    $done = [];

    for ($round = 0; $round < $times; $round++) {
        $beats = array_filter($screen->bridge()->callsTo('AsyncTask.Dispatch'), function (array $call) use (&$done): bool {
            $payload = AsyncTaskTransport::readPayload($call['params']['id']);

            return ! isset($done[$call['params']['id']]) && $payload !== null && str_contains($payload, 'App\\Devnull\\Beat');
        });

        if ($beats === []) {
            break;
        }

        foreach ($beats as $call) {
            $done[$call['params']['id']] = true;
            $screen->emitNative(AsyncTaskFinished::class, ['id' => $call['params']['id'], 'result' => true]);
        }
    }

    return $screen;
}
