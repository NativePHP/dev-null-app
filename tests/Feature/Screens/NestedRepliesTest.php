<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A commit page for c2 whose replies chain five deep: r1 answers c2, r2 answers r1, and so on, each from someone
 * other than the one they answer, so none of them carries a thread on.
 */
function fakeDeepThread(): void
{
    $handles = ['grace', 'linus', 'alan', 'edsger', 'ken'];
    $reply = fn (int $depth): array => replyJson([
        'id' => "r{$depth}",
        'parent_id' => $depth === 1 ? 'c2' : 'r'.($depth - 1),
        'body' => "Reply at depth {$depth}",
        'replies_count' => $depth < 5 ? 1 : 0,
        'author' => userJson(['handle' => $handles[$depth - 1], 'name' => ucfirst($handles[$depth - 1]), 'lane' => $depth]),
    ]);

    Http::fake(function (Request $request) use ($reply) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/v1/commits/c2' => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'replies_count' => 1]))),
            $path === '/api/v1/commits/c2/related' => Http::response(['data' => []]),
            $path === '/api/v1/commits/c2/replies' => Http::response(pageJson([$reply(1)])),
            (bool) preg_match('#^/api/v1/commits/r(\d)/replies$#', $path, $match) => Http::response(pageJson((int) $match[1] < 5 ? [$reply((int) $match[1] + 1)] : [])),
            default => Http::response(['message' => 'No fake.'], 404),
        };
    });
}

it('opens replies of replies in place on a commit page, as deep as they go, without leaving the page', function () {
    fakeDeepThread();

    $screen = Native::visit('/log/commits/c2', ['replies' => true])->assertSee('Reply at depth 1');

    foreach (range(1, 4) as $depth) {
        $screen->tap("replies-r{$depth}")->assertSee('Reply at depth '.($depth + 1));
    }

    $screen->assertNoNavigation()->assertSee('Reply at depth 5');

    // Hiding a reply's replies takes the ones opened under them away too.
    $screen->tap('replies-r2')->assertDontSee('Reply at depth 3');
    finishRefreshes($screen)->assertDontSee('Reply at depth 5');

    // Tapping a reply itself still opens its page.
    $screen->tap('thread-reply-r2')->assertNavigatedTo('/log/commits/r2');
});

it('steps replies in up to the third level, and lines deeper ones up under it saying who they answer', function () {
    fakeDeepThread();

    $screen = Native::visit('/log/commits/c2', ['replies' => true]);

    foreach (range(1, 4) as $depth) {
        $screen->tap("replies-r{$depth}");
    }

    // The left padding of the row the reply's avatar sits in, past the commit's 64pt gutter.
    $indent = fn (string $id): float => (float) (findNode(nodeByRef($screen, "thread-reply-{$id}"), fn (array $node): bool => ($node['type'] ?? null) === 'row' && isset($node['layout']['padding']))['layout']['padding'][3] ?? 0);
    $elbow = fn (string $id): bool => findNode(nodeByRef($screen, "thread-reply-{$id}"), fn (array $node): bool => ($node['ref'] ?? null) === 'thread-elbow') !== null;

    expect([$indent('r1'), $indent('r2'), $indent('r3'), $indent('r4'), $indent('r5')])->toEqual([0, 32, 64, 64, 64])
        ->and([$elbow('r1'), $elbow('r2'), $elbow('r3'), $elbow('r4'), $elbow('r5')])->toBe([true, true, true, false, false])
        ->and(rendersRef($screen, 'replying-to-r3'))->toBeFalse();

    $screen->assertSee('replying to @alan')->assertSee('replying to @edsger');
});
