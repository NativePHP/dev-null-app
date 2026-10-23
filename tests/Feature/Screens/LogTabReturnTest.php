<?php

use App\NativeComponents\Log;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

/**
 * A child Log reselect pops to the existing feed; URL REPLACE would mount
 * another feed. These tests dispatch the published reselect callback and
 * pin PHP screen/list/window preservation. Native pixel-offset restoration
 * remains an Android device check.
 */
beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Dispatch the active tab's reselect PRESS, not its inactive-tab URL press.
 */
function returnToExistingLog(TestableComponent $screen): TestableComponent
{
    $tab = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_nav_item'
        && ($node['props']['label'] ?? null) === 'Log');
    $callback = 'returnToLog';
    $callbackId = Closure::bind(fn (): ?int => $this->callbacks()->lookup($callback), $screen, TestableComponent::class)();

    expect($tab['props']['on_reselect'] ?? null)->toBeInt()->toBe($callbackId)
        ->and($tab['props']['reselect_scroll_target'] ?? null)->toBeNull();

    return $screen->fireEvent($callback, TestableComponent::EVENT_PRESS)
        ->assertPoppedTo('/log')
        ->followNavigation();
}

/**
 * Each row of the log that's showing, as its ref, or "placeholder" for one
 * the phone holds a place for off screen (as the window suite reads them).
 *
 * @return list<string|null>
 */
function tabReturnRows(TestableComponent $screen): array
{
    return array_map(
        fn (array $row): ?string => $row['type'] === 'list_placeholder' ? 'placeholder' : $row['ref'] ?? null,
        showingLog($screen)['children'],
    );
}

/**
 * A log of 300 commits, c0 to c299, one page, as both Following and Latest
 * answer it — enough log under your finger that where you were scrolled is
 * a fact worth returning to.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function tabReturnFeeds(): array
{
    $page = fn (): array => pageJson(array_map(fn (int $n): array => commitJson([
        'id' => "c{$n}",
        'body' => "Commit {$n}",
    ]), range(0, 299)), 'more');

    return [
        'GET /api/v1/log/following' => [$page()],
        'GET /api/v1/log/latest' => [$page()],
    ];
}

it('returns from a commit under the Log tab to the log it left: the same one, on the feed you chose, where you had scrolled', function () {
    holdRefreshes();
    fakeApi([
        ...tabReturnFeeds(),
        'GET /api/v1/commits/c150' => [commitPageJson(commitJson(['id' => 'c150', 'body' => 'Commit 150, opened']))],
        'GET /api/v1/commits/c150/replies' => [pageJson([], path: 'http://devnull.test/api/v1/commits/c150/replies')],
    ]);

    $log = finishRefreshes(Native::visit('/log', platform: 'android'));
    $log = finishRefreshes(tapLogTab($log, 'latest'));
    // A tap on the pager tab you're on takes that log back to its top, so the
    // list has a key of its own to come back to.
    tapLogTab($log, 'latest');
    // Deep in the log, a few hundred commits down.
    $log->call('showRows', 231, 236);

    $instance = spl_object_id($log->instance());
    $listKey = $log->get('listKey');
    $rows = tabReturnRows($log);

    // A commit under the Log tab, as opening or replying to one takes you
    // there. The Log tab stays the active one.
    $commit = $log->tap('commit-c150')->assertNavigatedTo('/log/commits/c150')->follow();

    finishRefreshes($commit)->assertTabActive('Log');

    $returned = returnToExistingLog($commit);
    expect(spl_object_id($returned->instance()))->toBe($instance, 'The Log tab landed on a newly mounted log instead of the one that was already under the child.')
        ->and($returned->get('log'))->toBe('latest')
        ->and($returned->get('listKey'))->toBe($listKey)
        ->and(tabReturnRows($returned))->toBe($rows)
        ->and($returned->instance()->refreshFeedbackOut())->toBeFalse();

    $returned->assertSee('Commit 150');
});

it('returns from the reply a post focuses, under its commit, to the same log', function () {
    holdRefreshes();
    keep('log/following', pageJson(array_map(fn (int $n): array => commitJson(['id' => "c{$n}", 'body' => "Commit {$n}"]), range(0, 9)), 'more'));
    keep('commits/c2', commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit'])));
    keep('commits/c2/replies', pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply'])], 'page2'));
    fakeApi([
        'GET /api/v1/log/following' => [pageJson(array_map(fn (int $n): array => commitJson(['id' => "c{$n}", 'body' => "Commit {$n}"]), range(0, 9)), 'more')],
        'POST /api/v1/commits' => [['data' => replyJson(['id' => 'r9', 'parent_id' => 'c2', 'status' => 'pending', 'body' => 'A fresh reply'])], 201],
    ]);

    $log = finishRefreshes(Native::visit('/log', platform: 'android'));

    $log->call('showRows', 5, 8);
    $instance = spl_object_id($log->instance());
    $listKey = $log->get('listKey');
    $rows = tabReturnRows($log);

    // Open a commit, reply to it: the view focuses the reply, two screens
    // down under the Log tab.
    $reply = $log->tap('commit-c2')->assertNavigatedTo('/log/commits/c2')->follow();
    $reply = finishRefreshes($reply)
        ->input('reply-input', 'A fresh reply')
        ->tap('send-reply')
        ->assertNavigatedTo('/log/commits/r9')
        ->follow()
        ->assertSet('id', 'r9')
        ->assertSee('A fresh reply');

    $reply->assertTabActive('Log');

    $returned = returnToExistingLog($reply);

    expect(spl_object_id($returned->instance()))->toBe($instance, 'The Log tab landed on a newly mounted log instead of the one that was already under the child.')
        ->and($returned->get('log'))->toBe('following')
        ->and($returned->get('listKey'))->toBe($listKey)
        ->and(tabReturnRows($returned))->toBe($rows)
        ->and($returned->instance()->refreshFeedbackOut())->toBeFalse();

    $returned->assertSee('Commit 0');
});
