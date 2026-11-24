<?php

use App\NativeComponents\CommitDetail;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A commit page for c2, under c1 and c0, with a first page of ten replies and more after them. r1 has a reply of
 * its own, n1. Everything is kept from before, so the page shows it at once and holds its refreshes, the reply
 * you post among them, until the test lets them go.
 *
 * @param  array<string, mixed>  $commit
 */
function fakeLongThread(array $commit = [], array $posted = []): void
{
    holdRefreshes();

    $grace = userJson(['handle' => 'grace', 'name' => 'Grace']);
    $c0 = commitJson(['id' => 'c0', 'body' => 'The root commit', 'author' => userJson(['handle' => 'ada', 'name' => 'Ada'])]);
    $c1 = commitJson(['id' => 'c1', 'parent_id' => 'c0', 'body' => 'The parent commit', 'author' => userJson(['handle' => 'linus', 'name' => 'Linus'])]);
    $c2 = commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'The focused commit', 'replies_count' => 11, 'author' => $grace, ...$commit]);
    $replies = array_map(fn (int $n): array => replyJson(['id' => "r{$n}", 'parent_id' => 'c2', 'body' => "Reply {$n}", 'replies_count' => $n === 1 ? 1 : 0]), range(1, 10));
    $repliesPage = pageJson($replies, 'page2', 'http://devnull.test/api/v1/commits/c2/replies');
    $nested = pageJson([replyJson(['id' => 'n1', 'parent_id' => 'r1', 'body' => 'A reply to the first reply', 'author' => userJson(['handle' => 'alan', 'name' => 'Alan'])])]);

    keep('commits/c2', commitPageJson($c2, [$c0, $c1]));
    keep('commits/c2/replies', $repliesPage);
    keep('commits/r1/replies', $nested);

    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson($c2, [$c0, $c1])],
        'GET /api/v1/commits/c2/replies' => [$repliesPage],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
        'GET /api/v1/commits/r1/replies' => [$nested],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'r99', 'parent_id' => 'c2', 'body' => 'My fresh reply', ...$posted])], 201],
    ]);
}

/**
 * Where the scroll view is asked to go: the ref of the child at auto-scroll-to, and the request it's under, which
 * the scroll view takes as its auto-scroll-token where mobile-ui has it.
 *
 * @return array{0: string|null, 1: int}
 */
function scrolledTo(TestableComponent $screen): array
{
    $index = nodeByRef($screen, 'thread-scroll')['props']['auto_scroll_to'] ?? -1;

    return [childRefs($screen, 'thread-scroll')[$index] ?? null, $screen->instance()->scrollRequest];
}

function stagedReplyId(TestableComponent $screen): string
{
    /** @var CommitDetail $page */
    $page = $screen->instance();

    return collect($page->replies)->first(fn ($reply): bool => $reply->isStaged())->id;
}

it('scrolls to a reply sent from the bar, under the commits above and past ten replies', function () {
    fakeLongThread();

    $screen = Native::visit('/log/commits/c2')->assertSet('scrollRequest', 0);

    $screen->input('reply-input', 'My fresh reply')->tap('send-reply')->assertSet('repliesShown', true);

    $staged = stagedReplyId($screen);
    $children = childRefs($screen, 'thread-scroll');

    expect($children)->toBe([
        'earlier-commits', 'commit-c1', 'focused-commit',
        ...array_map(fn (int $n): string => "thread-reply-r{$n}", range(1, 10)),
        "thread-reply-{$staged}", null, 'reply-bar-clearance',
    ])
        ->and($screen->instance()->scrollTarget)->toBe(array_search("thread-reply-{$staged}", $children, true))
        ->and(scrolledTo($screen))->toBe(["thread-reply-{$staged}", 1]);

    // devnull's reply takes the staged one's place, on the same row, and the page doesn't scroll again.
    finishRefreshes($screen)->assertDontSee('staged');

    expect(scrolledTo($screen))->toBe(['thread-reply-r99', 1]);
});

it('scrolls to a reply sent from the sheet, past replies opened in place under the ones before it', function () {
    fakeLongThread();

    $screen = Native::visit('/log/commits/c2')
        ->tap('earlier-commits')
        ->tap('replies-c2')
        ->tap('replies-r1');

    finishRefreshes($screen)
        ->assertSee('A reply to the first reply')
        ->assertSet('scrollRequest', 0);

    $screen->call('replyInSheet', 'c2')->input('sheet-body', 'My fresh reply')->tap('sheet-commit');

    $staged = stagedReplyId($screen);
    $children = childRefs($screen, 'thread-scroll');

    expect(array_slice($children, 0, 5))->toBe(['commit-c0', 'commit-c1', 'focused-commit', 'thread-reply-r1', 'thread-reply-n1'])
        ->and(scrolledTo($screen))->toBe(["thread-reply-{$staged}", 1])
        ->and($screen->instance()->scrollTarget)->toBe(14);

    finishRefreshes($screen);

    expect(scrolledTo($screen))->toBe(['thread-reply-r99', 1]);
});

it('follows a reply to your own commit when devnull has it carry your thread on', function () {
    // The commit is yours, and so is the reply, so once devnull has it, it's the next part of your thread, below the
    // commit's replies, rather than one of them.
    fakeLongThread(commit: ['author' => userJson()]);

    $screen = Native::visit('/log/commits/c2');
    $screen->input('reply-input', 'My fresh reply')->tap('send-reply');

    expect(scrolledTo($screen))->toBe(['thread-reply-'.stagedReplyId($screen), 1]);

    finishRefreshes($screen);

    // It moved a row down, past "Show more" under the replies, so the page goes after it.
    expect(scrolledTo($screen))->toBe(['commit-r99', 2]);
});
