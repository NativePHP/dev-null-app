<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Tests\Support\LayoutAnimation;

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

    // Tapping a reply itself moves the page to it.
    $screen->tap('thread-reply-r2')->assertNoNavigation()->assertSet('id', 'r2');
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

it('moves the page to a reply in its tree rather than pushing another, keeping what was open', function () {
    fakeDeepThread();

    $screen = Native::visit('/log/commits/c2', ['replies' => true]);

    foreach (range(1, 3) as $depth) {
        $screen->tap("replies-r{$depth}");
    }

    $screen->tap('thread-reply-r3')
        ->assertNoNavigation()
        ->assertSet('id', 'r3')
        ->assertSet('scrollTarget', 3);

    // The reply is the commit the page is on, under the commits above it, with its replies showing at once.
    expect(childRefs($screen, 'thread-scroll'))->toBe(['commit-c2', 'commit-r1', 'commit-r2', 'focused-commit', 'thread-reply-r4', 'reply-bar-clearance'])
        ->and(nodeByRef($screen, 'thread-scroll')['props']['auto_scroll_to'])->toBe(3);

    // Back up at the top, the reply the page was on is still open under its parents.
    $screen->tap('commit-c2')
        ->assertNoNavigation()
        ->assertSet('id', 'c2')
        ->assertSet('scrollTarget', 0)
        ->assertSee('Reply at depth 4');

    expect(childRefs($screen, 'thread-scroll'))->toBe(['focused-commit', 'thread-reply-r1', 'thread-reply-r2', 'thread-reply-r3', 'thread-reply-r4', 'reply-bar-clearance']);
});

it('answers a reply in the tree from the bar, on the same page', function () {
    fakeDeepThread();

    Native::visit('/log/commits/c2', ['replies' => true])
        ->call('replyTo', 'r1')
        ->assertNoNavigation()
        ->assertSet('id', 'r1')
        ->assertSet('focusReply', true);
});

it('still pushes a page for a commit outside the tree', function () {
    fakeDeepThread();

    Native::visit('/log/commits/c2', ['replies' => true])
        ->call('openCommit', 'q9')
        ->assertNavigatedTo('/log/commits/q9');
});

it('opens at the top with no scroll asked for until the page moves', function () {
    fakeDeepThread();

    $screen = Native::visit('/log/commits/c2', ['replies' => true]);

    expect(nodeByRef($screen, 'thread-scroll')['props'] ?? [])->not->toHaveKey('auto_scroll_to');
});

it('keeps the commit row and the scroll view the same nodes when the page moves', function () {
    fakeDeepThread();

    $screen = Native::visit('/log/commits/c2', ['replies' => true]);
    $screen->tap('replies-r1');
    $screen->tap('thread-reply-r2');
    $scroller = nodeByRef($screen, 'thread-scroll')['id'];
    $ancestor = nodeByRef($screen, 'commit-c2')['id'];

    // The commit the page moves to keeps the node it had above the focus, so its row slides into place. The scroll
    // view keeps its node too: a new one would make every row in it new.
    $screen->tap('commit-c2')->assertSet('id', 'c2');

    expect(nodeByRef($screen, 'focused-commit')['id'])->toBe($ancestor)
        ->and(nodeByRef($screen, 'thread-scroll')['id'])->toBe($scroller);
});

it('animates the move and asks again for a row it went to before', function () {
    LayoutAnimation::skipUnlessSupported();
    fakeDeepThread();

    $screen = Native::visit('/log/commits/c2', ['replies' => true]);
    $props = fn (): array => nodeByRef($screen, 'thread-scroll')['props'];

    expect($props())->toHaveKey('animate_layout', true)
        ->and($props())->toHaveKey('auto_scroll_animated', true)
        ->and($props())->toHaveKey('auto_scroll_token', '0');

    $screen->tap('replies-r1');
    $screen->tap('thread-reply-r1')->assertSet('scrollTarget', 1);
    $screen->tap('commit-c2')->assertSet('scrollTarget', 0);
    $first = $props()['auto_scroll_token'];
    $screen->tap('thread-reply-r1');
    $screen->tap('commit-c2')->assertSet('scrollTarget', 0);

    // The same row as two moves ago, under a new token, so the scroll view goes back to it.
    expect($props()['auto_scroll_to'])->toBe(0)
        ->and($props()['auto_scroll_token'])->not->toBe($first);
});

/**
 * A commit page for c2, which answers c1. c1 has another reply, x1, and x1 has one of its own, x2. c2 has one reply, r1.
 */
function fakeAncestorThread(): void
{
    $person = fn (string $handle, int $lane): array => userJson(['handle' => $handle, 'name' => ucfirst($handle), 'lane' => $lane]);
    $c1 = commitJson(['id' => 'c1', 'body' => 'The commit above', 'replies_count' => 2, 'author' => $person('ada', 1)]);
    $c2 = commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'The focused commit', 'replies_count' => 1, 'author' => $person('grace', 2)]);
    $x1 = replyJson(['id' => 'x1', 'parent_id' => 'c1', 'body' => 'Another reply above', 'replies_count' => 1, 'author' => $person('linus', 4)]);
    $x2 = replyJson(['id' => 'x2', 'parent_id' => 'x1', 'body' => 'A reply to the other reply', 'author' => $person('alan', 5)]);
    $r1 = replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'A reply below', 'author' => $person('ken', 6)]);

    Http::fake(function (Request $request) use ($c1, $c2, $x1, $x2, $r1) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/api/v1/commits/c2' => Http::response(commitPageJson($c2, [$c1])),
            '/api/v1/commits/c1' => Http::response(commitPageJson($c1)),
            '/api/v1/commits/r1' => Http::response(commitPageJson($r1, [$c1, $c2])),
            '/api/v1/commits/c1/related', '/api/v1/commits/c2/related', '/api/v1/commits/r1/related' => Http::response(['data' => []]),
            '/api/v1/commits/c1/replies' => Http::response(pageJson([$c2, $x1])),
            '/api/v1/commits/c2/replies' => Http::response(pageJson([$r1])),
            '/api/v1/commits/x1/replies' => Http::response(pageJson([$x2])),
            '/api/v1/commits/r1/replies', '/api/v1/commits/x2/replies' => Http::response(pageJson([])),
            default => Http::response(['message' => 'No fake.'], 404),
        };
    });
}

it('opens the replies of a commit above the commit in place, leaving out the one on the way down', function () {
    fakeAncestorThread();

    $screen = Native::visit('/log/commits/c2', ['replies' => true]);

    // c1 has two replies, and one of them is the commit the page is on, so its button counts the other.
    expect(nodeByRef($screen, 'replies-c1')['props']['a11y_label'] ?? null)->toBe('1 reply');

    $screen->tap('replies-c1')->assertNoNavigation()->assertSee('Another reply above');

    // The other reply opens under c1, and the commit the page is on shows once, as the commit.
    expect(childRefs($screen, 'thread-scroll'))->toBe(['commit-c1', 'thread-reply-x1', 'focused-commit', 'thread-reply-r1', 'reply-bar-clearance']);

    // Its replies open in place too, at any depth.
    $screen->tap('replies-x1')->assertNoNavigation()->assertSee('A reply to the other reply');

    $screen->tap('replies-c1');
    finishRefreshes($screen)->assertDontSee('Another reply above')->assertDontSee('A reply to the other reply');

    expect(childRefs($screen, 'thread-scroll'))->toBe(['commit-c1', 'focused-commit', 'thread-reply-r1', 'reply-bar-clearance']);
});

it('folds the replies of the commits above when the page moves down to a reply', function () {
    fakeAncestorThread();

    $screen = Native::visit('/log/commits/c2', ['replies' => true]);
    $screen->tap('replies-c1')->assertSee('Another reply above');
    $screen->tap('thread-reply-r1')->assertSet('id', 'r1')->assertSet('scrollTarget', 2)->assertDontSee('Another reply above');

    // c1 is back to its button, still leaving out c2 on the way down. r1 has no replies, so "No replies yet" follows it.
    expect(childRefs($screen, 'thread-scroll'))->toBe(['commit-c1', 'commit-c2', 'focused-commit', null, 'reply-bar-clearance'])
        ->and(nodeByRef($screen, 'replies-c1')['props']['a11y_label'] ?? null)->toBe('1 reply')
        ->and(rendersRef($screen, 'replies-c2'))->toBeFalse();

    // Opening them again later doesn't scroll the page.
    $screen->tap('replies-c1')->assertSee('Another reply above')->assertSet('scrollTarget', 2);
});

it('folds the replies of the commit the page was on when it moves down to one of them, and opens them again moving back up', function () {
    fakeAncestorThread();

    $screen = Native::visit('/log/commits/c1', ['replies' => true])->assertSee('Another reply above');
    $screen->tap('thread-reply-c2')->assertNoNavigation()->assertSet('id', 'c2')->assertDontSee('Another reply above');

    expect(childRefs($screen, 'thread-scroll'))->toBe(['commit-c1', 'focused-commit', 'thread-reply-r1', 'reply-bar-clearance'])
        ->and(nodeByRef($screen, 'replies-c1')['props']['a11y_label'] ?? null)->toBe('1 reply');

    // Back up on c1, its replies show as the commit's do, with c2's still open under it.
    $screen->tap('commit-c1')->assertSet('id', 'c1')->assertSee('Another reply above')->assertSee('A reply below');
});

it('opens on a reply with the replies of the commits above it folded', function () {
    fakeAncestorThread();

    $screen = Native::visit('/log/commits/r1')->call('showEarlier')->assertDontSee('Another reply above');

    expect(nodeByRef($screen, 'replies-c1')['props']['a11y_label'] ?? null)->toBe('1 reply')
        ->and(rendersRef($screen, 'replies-c2'))->toBeFalse()
        ->and($screen->get('threads'))->toBe([]);
});

it('keeps the replies of the commits on the line open when the replies of one above them close', function () {
    fakeDeepThread();

    $screen = Native::visit('/log/commits/c2', ['replies' => true]);
    $screen->tap('replies-r1');
    $screen->tap('replies-r2');
    $screen->tap('thread-reply-r3')->assertSet('id', 'r3');

    // The commits above r3 fold their replies. Each has one, the one on the way down, so none of them has a button.
    expect($screen->get('threads'))->toBe([])
        ->and(rendersRef($screen, 'replies-c2'))->toBeFalse()
        ->and(rendersRef($screen, 'replies-r1'))->toBeFalse();

    // Opened again, closing c2's replies leaves r1's be, since r1 draws a row of its own.
    $screen->call('toggleReplies', 'c2');
    $screen->call('toggleReplies', 'r1');
    expect($screen->get('threads'))->toHaveKeys(['c2', 'r1']);

    $screen->call('toggleReplies', 'c2');
    finishRefreshes($screen);

    expect($screen->get('threads'))->toHaveKey('r1')->not->toHaveKey('c2');
});

/**
 * c3 answers c2, which answers c1, shaped like devnull's API: the ancestors on c3's page come without their counts,
 * since CommitAncestorsQuery doesn't load them. Only a commit's own page says how many replies it has. c2 has two
 * besides c3, and c1 has one besides c2. Neither page was opened before.
 */
function fakeUncountedAncestors(): void
{
    $person = fn (string $handle, int $lane): array => userJson(['handle' => $handle, 'name' => ucfirst($handle), 'lane' => $lane]);
    $uncounted = fn (array $commit): array => array_diff_key($commit, array_flip(['replies_count', 'stars_count']));
    $c1 = commitJson(['id' => 'c1', 'body' => 'The first commit', 'replies_count' => 2, 'author' => $person('ada', 1)]);
    $c2 = commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'The commit above', 'replies_count' => 3, 'author' => $person('grace', 2)]);
    $c3 = commitJson(['id' => 'c3', 'parent_id' => 'c2', 'body' => 'The focused commit', 'author' => $person('ken', 3)]);
    $y1 = replyJson(['id' => 'y1', 'parent_id' => 'c1', 'body' => 'Another reply to the first', 'author' => $person('linus', 4)]);
    $x1 = replyJson(['id' => 'x1', 'parent_id' => 'c2', 'body' => 'Another reply above', 'author' => $person('alan', 5)]);
    $x2 = replyJson(['id' => 'x2', 'parent_id' => 'c2', 'body' => 'One more reply above', 'author' => $person('edsger', 6)]);

    Http::fake(function (Request $request) use ($uncounted, $c1, $c2, $c3, $x1, $x2, $y1) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/v1/commits/c3' => Http::response(commitPageJson($c3, [$uncounted($c1), $uncounted($c2)])),
            '/api/v1/commits/c2' => Http::response(commitPageJson($c2, [$uncounted($c1)])),
            '/api/v1/commits/c1' => Http::response(commitPageJson($c1)),
            '/api/v1/commits/c1/related', '/api/v1/commits/c2/related', '/api/v1/commits/c3/related' => Http::response(['data' => []]),
            '/api/v1/commits/c1/replies' => Http::response(pageJson([$c2, $y1])),
            '/api/v1/commits/c2/replies' => Http::response(pageJson([$c3, $x1, $x2])),
            '/api/v1/commits/c3/replies', '/api/v1/commits/x1/replies', '/api/v1/commits/x2/replies', '/api/v1/commits/y1/replies' => Http::response(pageJson([])),
            default => Http::response(['message' => 'No fake.'], 404),
        };
    });
}

it('shows the folded replies of a commit above that was never opened, from that commit\'s own page', function () {
    fakeUncountedAncestors();

    $screen = finishRefreshes(Native::visit('/log/commits/c3'));

    // c2's page says it has three replies, and c3 is one of them, on the way down.
    expect(nodeByRef($screen, 'replies-c2')['props']['a11y_label'] ?? null)->toBe('2 replies')
        ->and($screen->get('threads'))->toBe([]);

    $screen->tap('replies-c2')->assertNoNavigation()->assertSee('Another reply above')->assertSee('One more reply above');

    // The earlier commits get theirs once they show.
    expect(rendersRef($screen, 'replies-c1'))->toBeFalse();

    finishRefreshes($screen->call('showEarlier'));

    expect(nodeByRef($screen, 'replies-c1')['props']['a11y_label'] ?? null)->toBe('1 reply');
});
