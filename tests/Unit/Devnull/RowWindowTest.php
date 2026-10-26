<?php

use App\Devnull\Data\Commit;
use App\Devnull\Data\TimelineRow;
use App\Devnull\RowWindow;

/**
 * A timeline of commits with ids from "{$prefix}0" on, newest first.
 *
 * @return list<TimelineRow>
 */
function windowTimeline(int $count, string $prefix = 'c'): array
{
    return array_map(fn (int $n): TimelineRow => TimelineRow::commit(Commit::fromArray([
        'id' => $prefix.$n,
        'body' => "Commit {$n}",
        'created_at' => '2026-10-15T12:00:00Z',
        'author' => ['id' => 'ada', 'username' => 'ada', 'name' => 'Ada', 'lane' => 1],
    ]), null), range(0, $count - 1));
}

/**
 * Starts a frame and lays every row out in the list, one list row each.
 *
 * @param  list<TimelineRow>  $rows
 * @return list<bool>
 */
function windowFrame(RowWindow $window, array $rows, int $listKey = 0): array
{
    $window->frame($rows, $listKey);

    return array_map(fn (int $index): bool => $window->place($index), array_keys($rows));
}

it('stays on the rows that were on screen when rows come in above them', function () {
    $window = new RowWindow;
    windowFrame($window, windowTimeline(300));
    $window->show(200, 205);
    $window->drawAroundScreen();

    $drawn = windowFrame($window, [...windowTimeline(5, 'new'), ...windowTimeline(300)]);

    // c100 is now row 105, a hundred above c200 at 205.
    expect($drawn[104])->toBeFalse()
        ->and($drawn[105])->toBeTrue()
        ->and($drawn[304])->toBeTrue()
        ->and($drawn[4])->toBeTrue();
});

it('keeps its place when a row that was on screen goes', function () {
    $window = new RowWindow;
    $rows = windowTimeline(300);
    windowFrame($window, $rows);
    $window->show(200, 205);
    $window->drawAroundScreen();

    unset($rows[200]);
    $drawn = windowFrame($window, array_values($rows));

    expect($drawn[99])->toBeFalse()
        ->and($drawn[100])->toBeTrue();
});

it('counts a commit and its replies as one row of the timeline', function () {
    $window = new RowWindow;
    $window->frame(windowTimeline(300), 0);

    foreach (range(0, 299) as $index) {
        $window->place($index, $index === 0 ? 51 : 1);
    }

    // List rows 0 to 50 are c0 and its replies, so list row 250 is c200.
    $window->show(250, 255);
    $window->drawAroundScreen();
    $drawn = windowFrame($window, windowTimeline(300));

    expect($drawn[99])->toBeFalse()
        ->and($drawn[100])->toBeTrue();
});

it('draws more rows the way you are scrolling', function () {
    $window = new RowWindow;
    windowFrame($window, windowTimeline(500));
    $window->show(250, 255);
    $window->drawAroundScreen();

    // Scrolling down: a hundred rows above the screen.
    $down = windowFrame($window, windowTimeline(500));
    $window->show(220, 225);
    $window->drawAroundScreen();

    // Scrolling up: a hundred and fifty.
    $up = windowFrame($window, windowTimeline(500));

    expect($down[149])->toBeFalse()
        ->and($down[150])->toBeTrue()
        ->and($down[405])->toBeTrue()
        ->and($down[406])->toBeFalse()
        ->and($up[69])->toBeFalse()
        ->and($up[70])->toBeTrue()
        ->and($up[325])->toBeTrue()
        ->and($up[326])->toBeFalse();
});

it('leaves the rows drawn where they are as the screen moves, until it nears either end of them', function () {
    $window = new RowWindow;
    windowFrame($window, windowTimeline(500));
    $window->show(200, 205);
    $window->drawAroundScreen();

    // Drawn from 100 above c200 (c100) to 150 below c205 (c355), so the screen may come within 50 of either.
    $window->show(300, 305);
    $drawn = windowFrame($window, windowTimeline(500));

    expect($window->nearsEdge())->toBeFalse()
        ->and($drawn[99])->toBeFalse()
        ->and($drawn[100])->toBeTrue()
        ->and($drawn[355])->toBeTrue()
        ->and($drawn[356])->toBeFalse();

    $window->show(301, 306);

    expect($window->nearsEdge())->toBeTrue();

    $window->show(150, 155);

    expect($window->nearsEdge())->toBeFalse();

    $window->show(149, 154);

    expect($window->nearsEdge())->toBeTrue();
});

it('never nears an end of the rows drawn that is the end of the feed or the top rows', function () {
    $window = new RowWindow;
    windowFrame($window, windowTimeline(200));
    $window->show(60, 65);
    $window->drawAroundScreen();
    windowFrame($window, windowTimeline(200));

    // Drawn from the top to 150 below c65, past the end of the feed at c199.
    $window->show(190, 199);

    expect($window->nearsEdge())->toBeFalse();

    $window->show(0, 5);

    expect($window->nearsEdge())->toBeFalse();
});

it('counts the timeline rows below the last one on screen, a commit and its replies as one', function () {
    $window = new RowWindow;
    $window->frame(windowTimeline(100), 0);

    foreach (range(0, 99) as $index) {
        $window->place($index, $index === 90 ? 6 : 1);
    }

    // Until the list says otherwise, the top is on screen.
    expect($window->rowsBelowScreen())->toBe(99);

    $window->show(10, 15);

    expect($window->rowsBelowScreen())->toBe(84);

    // List rows 90 to 95 are c90 and its replies, so list row 100 is c95.
    $window->show(95, 100);

    expect($window->rowsBelowScreen())->toBe(4);

    // The row loading the next page counts as the last.
    $window->show(100, 110);

    expect($window->rowsBelowScreen())->toBe(0);
});
