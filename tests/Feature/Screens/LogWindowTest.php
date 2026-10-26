<?php

use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();

    // A day marker and 300 commits, with a reply under c220, then the row loading the next page.
    fakeApi([
        'GET /api/v1/log/following' => [pageJson(array_map(fn (int $n): array => commitJson([
            'id' => "c{$n}",
            'body' => "Commit {$n}",
            ...($n === 220 ? ['replies_count' => 1, 'reply_preview' => ['data' => [replyJson(['id' => 'r220', 'parent_id' => 'c220'])], 'next_cursor' => null]] : []),
        ]), range(0, 299)), nextCursor: 'more')],
    ]);
});

/**
 * Each row of the log's list as its ref, or "placeholder".
 *
 * @return list<string|null>
 */
function windowRows(TestableComponent $screen): array
{
    return array_map(
        fn (array $row): ?string => $row['type'] === 'list_placeholder' ? 'placeholder' : $row['ref'] ?? null,
        showingLog($screen)['children'],
    );
}

/**
 * The node id of each row of the log's list.
 *
 * @return list<int>
 */
function windowRowIds(TestableComponent $screen): array
{
    return array_column(showingLog($screen)['children'], 'id');
}

it('draws the first rows and those near the top, and puts placeholders in the rest', function () {
    $rows = windowRows(Native::visit('/log'));

    // The first 151 rows of the timeline: the day marker and c0 to c149.
    expect($rows)->toHaveCount(303)
        ->and($rows[0])->toBe('day-Today')
        ->and($rows[1])->toBe('commit-c0')
        ->and($rows[150])->toBe('commit-c149')
        ->and(array_unique(array_slice($rows, 151, 151)))->toBe(['placeholder'])
        ->and($rows[302])->toBe('loading-more');
});

it('draws the rows around the ones on screen as you scroll, each keeping its place in the list', function () {
    $screen = Native::visit('/log');
    $ids = windowRowIds($screen);

    // On screen: c229 to c234, which come after c220 and its reply. Drawn: the first ten rows of the timeline, and
    // those from a hundred above c229 (c129) on.
    $rows = windowRows($screen->call('showRows', 231, 236));

    expect(windowRowIds($screen))->toBe($ids)
        ->and(array_slice($rows, 0, 10))->toBe(['day-Today', ...array_map(fn (int $n): string => "commit-c{$n}", range(0, 8))])
        ->and(array_unique(array_slice($rows, 10, 120)))->toBe(['placeholder'])
        ->and($rows[130])->toBe('commit-c129')
        ->and($rows[221])->toBe('commit-c220')
        ->and($rows[222])->toBe('thread-reply-r220')
        ->and($rows[301])->toBe('commit-c299');
});

it('draws more rows above the screen once you scroll back up and stop', function () {
    // Down to c229, then back up to c199: drawn from 150 above c199 (c49).
    $rows = windowRows(Native::visit('/log')->call('showRows', 231, 236)->call('showRows', 200, 205)->call('scrollEnded', 200, 205));

    expect($rows[49])->toBe('placeholder')
        ->and($rows[50])->toBe('commit-c49');
});

it('only asks the log you are on where it is scrolled to, and when it stops', function () {
    $screen = Native::visit('/log');

    expect(showingLog($screen)['props'])->toHaveKeys(['on_visible_range', 'on_scroll_end'])
        ->and(nodeByRef($screen, 'log-latest')['props'] ?? [])->not->toHaveKey('on_visible_range')->not->toHaveKey('on_scroll_end');
});

it('draws the top again when the log goes back to the top of a new list', function () {
    $screen = Native::visit('/log')->call('showRows', 231, 236)->call('show', 'following');
    $rows = windowRows($screen);

    expect($rows[21])->toBe('commit-c20')
        ->and($rows[200])->toBe('placeholder');
});
