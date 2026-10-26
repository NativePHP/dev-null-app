<?php

use App\Devnull\Client;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A part of a series as the API sends it: a reply to the part before it, with
 * its place in the series.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function seriesPartJson(int $position, int $size = 3, array $overrides = []): array
{
    return commitJson([
        'id' => 'p'.$position,
        'body' => "Part {$position} of the thread",
        'parent_id' => $position === 1 ? null : 'p'.($position - 1),
        'series_id' => 's1',
        'series_position' => $position,
        'series_size' => $size,
        ...$overrides,
    ]);
}

/**
 * The refs of the rows on a commit page's rail, top to bottom.
 *
 * @return list<string>
 */
function threadRows(TestableComponent $screen): array
{
    $rows = findNodes($screen->tree(), fn (array $node): bool => preg_match('/^(commit-|focused-commit$|tombstone-|thread-reply-)/', (string) ($node['ref'] ?? '')) === 1);

    return array_map(fn (array $node): string => $node['ref'], $rows);
}

/**
 * The text of a series position pill.
 */
function seriesPosition(TestableComponent $screen, string $id): string
{
    $text = findNode(nodeByRef($screen, 'series-position-'.$id), fn (array $node): bool => ($node['type'] ?? null) === 'text');

    return $text['props']['text'] ?? '';
}

/**
 * Whether a row's rail runs on below its node, into the row underneath.
 */
function railRunsOn(TestableComponent $screen, string $ref): bool
{
    return findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['layout']['position_type'] ?? null) === 1
        && ($node['layout']['width'] ?? null) == 2
        && ($node['layout']['position'][0] ?? null) == 32
        && ! isset($node['layout']['height'])) !== null;
}

it('reads a commit page with the earlier and later parts of its series', function () {
    Http::fake(['devnull.test/*' => Http::response(commitPageJson(seriesPartJson(2), [seriesPartJson(1)], [seriesPartJson(3)]))]);

    $thread = app(Client::class)->commit('p2');

    expect($thread->commit->seriesId)->toBe('s1')
        ->and($thread->commit->seriesPosition)->toBe(2)
        ->and($thread->commit->seriesSize)->toBe(3)
        ->and($thread->commit->laterSeriesParts())->toBe(1)
        ->and(array_map(fn ($part) => $part->id, $thread->ancestors))->toBe(['p1'])
        ->and(array_map(fn ($part) => $part->id, $thread->series))->toBe(['p3']);
});

it('marks the first part in the log and links to the rest of the series', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([seriesPartJson(1), commitJson(['id' => 'c1', 'body' => 'Not a series'])])],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    $screen = Native::visit('/log')
        ->assertSee('Part 1 of the thread')
        ->assertSee('2 more commits in this series');

    expect(seriesPosition($screen, 'p1'))->toBe('1/3')
        ->and(nodeByRef($screen, 'series-position-p1')['props']['a11y_label'] ?? null)->toBe('Commit 1 of 3 in a series');

    $screen->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'series-position-c1')
        ->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'series-continues-c1')
        ->tap('series-continues-p1')
        ->assertNavigatedTo('/log/commits/p1');
});

it('says one more commit when one part is left', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([seriesPartJson(1, 2)])],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
    ]);

    Native::visit('/log')->assertSee('1 more commit in this series');
});

it('lays the series out in order on a part\'s page, the part in focus', function () {
    fakeApi([
        'GET /api/v1/commits/p2' => [commitPageJson(seriesPartJson(2), [seriesPartJson(1)], [seriesPartJson(3)])],
        'GET /api/v1/commits/p2/replies' => [pageJson([])],
    ]);

    $screen = Native::visit('/log/commits/p2')
        // The part before it shows above, as the commit it replies to, and the later one follows.
        ->assertDontSee('earlier commit')
        ->assertSee('Part 1 of the thread')
        ->assertSee('Part 2 of the thread')
        ->assertSee('Part 3 of the thread')
        // The whole series is on the page, so nothing points to more of it.
        ->assertDontSee('more commits in this series')
        ->assertDontSee('more commit in this series')
        // Nobody has replied to this part, and the series goes on below it.
        ->assertDontSee('No replies yet.');

    expect(threadRows($screen))->toBe(['commit-p1', 'focused-commit', 'commit-p3'])
        ->and(seriesPosition($screen, 'p1'))->toBe('1/3')
        ->and(seriesPosition($screen, 'p2'))->toBe('2/3')
        ->and(seriesPosition($screen, 'p3'))->toBe('3/3')
        // One rail from the first part to the last, which ends it.
        ->and(railRunsOn($screen, 'commit-p1'))->toBeTrue()
        ->and(railRunsOn($screen, 'focused-commit'))->toBeTrue()
        ->and(railRunsOn($screen, 'commit-p3'))->toBeFalse();

    $screen->tap('commit-p3')->assertNavigatedTo('/log/commits/p3');
});

it('puts a part\'s replies under it, above the later parts, as on the web', function () {
    fakeApi([
        'GET /api/v1/commits/p1' => [commitPageJson(seriesPartJson(1, overrides: ['replies_count' => 2]), [], [seriesPartJson(2), seriesPartJson(3)])],
        'GET /api/v1/commits/p1/replies' => [pageJson([
            replyJson(['id' => 'r1', 'parent_id' => 'p1', 'body' => 'Reads well', 'author' => userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada'])]),
            replyJson(['id' => 'r2', 'parent_id' => 'p1', 'body' => 'Agreed', 'author' => userJson(['id' => 'u3', 'username' => 'grace', 'name' => 'Grace'])]),
        ], path: 'http://devnull.test/api/v1/commits/p1/replies', perPage: 50)],
    ]);

    $screen = Native::visit('/log/commits/p1')
        ->assertDontSee('Reads well')
        ->assertSee('2 replies');

    expect(threadRows($screen))->toBe(['focused-commit', 'commit-p2', 'commit-p3']);

    $screen->tap('replies-p1')
        ->assertSee('Reads well')
        ->assertSee('Agreed')
        ->assertDontSee('2 replies');

    expect(threadRows($screen))->toBe(['focused-commit', 'thread-reply-r1', 'thread-reply-r2', 'commit-p2', 'commit-p3']);
});

it('keeps replies at the foot of the last part\'s page', function () {
    fakeApi([
        'GET /api/v1/commits/p3' => [commitPageJson(seriesPartJson(3), [seriesPartJson(1), seriesPartJson(2)])],
        'GET /api/v1/commits/p3/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'p3', 'body' => 'The end'])])],
    ]);

    $screen = unfolded(Native::visit('/log/commits/p3'))
        ->assertSee('The end');

    expect(threadRows($screen))->toBe(['commit-p1', 'commit-p2', 'focused-commit', 'thread-reply-r1'])
        ->and(railRunsOn($screen, 'focused-commit'))->toBeFalse();
});

it('keeps a deleted part in its place in the series', function () {
    fakeApi([
        'GET /api/v1/commits/p1' => [commitPageJson(seriesPartJson(1), [], [
            tombstoneJson(['id' => 'p2', 'parent_id' => 'p1', 'replies_count' => 0, 'series_position' => 2, 'series_size' => 3]),
            seriesPartJson(3),
        ])],
        'GET /api/v1/commits/p1/replies' => [pageJson([])],
    ]);

    $screen = Native::visit('/log/commits/p1')->assertSee('This commit was deleted.');

    expect(threadRows($screen))->toBe(['focused-commit', 'tombstone-p2', 'commit-p3'])
        ->and(seriesPosition($screen, 'p2'))->toBe('2/3');
});
