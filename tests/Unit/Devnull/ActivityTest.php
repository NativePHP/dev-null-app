<?php

use App\Devnull\Data\Activity;
use App\Devnull\Data\ActivityCell;
use Carbon\CarbonImmutable;

/**
 * @param  list<int>  $counts
 * @return array{scale: string, buckets: list<array{date: string, count: int}>}
 */
function activityJson(string $scale, CarbonImmutable $from, string $step, array $counts): array
{
    return ['scale' => $scale, 'buckets' => array_map(fn (int $index, int $count): array => [
        'date' => $scale === 'day' ? $from->add($index, $step)->toIso8601ZuluString() : $from->add($index, $step)->toDateString(),
        'count' => $count,
    ], array_keys($counts), $counts)];
}

it('sums a year into weeks, labelled where each month starts', function () {
    // The server's year: 52 weeks back from Thursday 15 October 2026, starting on a Sunday, 369 days.
    $from = CarbonImmutable::parse('2025-10-12');
    $counts = array_fill(0, 369, 0);
    $counts[0] = 2;
    $counts[6] = 1;
    $counts[368] = 4;

    $activity = Activity::fromArray(activityJson('year', $from, 'days', $counts));
    $cells = $activity->cells();

    expect($cells)->toHaveCount(53)
        ->and($cells[0]->count)->toBe(3)
        ->and($cells[52]->count)->toBe(4)
        ->and($cells[52]->level)->toBe(4)
        ->and($cells[0]->level)->toBe(3)
        ->and($activity->total())->toBe(7)
        ->and($activity->window())->toBe('in the last year')
        ->and($cells[3]->label)->toBe('Nov')
        // October 2026 starts too near the end for its label to fit.
        ->and(array_values(array_filter(array_map(fn (ActivityCell $cell): ?string => $cell->label, $cells))))
        ->toBe(['Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep']);
});

it('leaves the first week unlabelled when the next month starts right after it', function () {
    $cells = Activity::fromArray(activityJson('year', CarbonImmutable::parse('2025-10-26'), 'days', array_fill(0, 369, 0)))->cells();

    expect($cells[0]->label)->toBeNull()
        ->and($cells[1]->label)->toBe('Nov');
});

it('labels a month on Mondays and an hour every six hours', function () {
    $month = Activity::fromArray(activityJson('month', CarbonImmutable::parse('2026-09-16'), 'days', array_fill(0, 30, 0)));
    $day = Activity::fromArray(activityJson('day', CarbonImmutable::parse('2026-10-14T13:00:00Z'), 'hours', array_fill(0, 24, 0)));

    expect(array_column($month->labels(), 'label'))->toBe([null, 'Sep 21', 'Sep 28', 'Oct 5', 'Oct 12'])
        ->and(array_column($month->labels(), 'span'))->toBe([5, 7, 7, 7, 4])
        ->and(array_column($day->labels(), 'label'))->toBe([null, '18:00', '00:00', '06:00'])
        ->and(array_sum(array_column($day->labels(), 'span')))->toBe(24);
});

it('tints busier periods more strongly, as the web does', function () {
    $activity = Activity::fromArray(activityJson('week', CarbonImmutable::parse('2026-10-09'), 'days', [0, 1, 2, 3, 4, 8, 0]));

    expect(array_map(fn (ActivityCell $cell): int => $cell->level, $activity->cells()))->toBe([0, 1, 1, 2, 2, 4, 0])
        ->and(array_map(fn (ActivityCell $cell): float => $cell->opacity(), $activity->cells()))->toBe([0.0, 0.32, 0.32, 0.56, 0.56, 1.0, 0.0]);
});

it('reads the old list of activity, or nothing, as no activity', function (mixed $json) {
    $activity = Activity::fromArray($json);

    expect($activity->buckets)->toBe([])
        ->and($activity->total())->toBe(0)
        ->and($activity->cells())->toBe([]);
})->with([
    [[]],
    [[['date' => '2026-10-15', 'count' => 1]]],
    [null],
    [['scale' => 'decade', 'buckets' => []]],
]);

it('says no commits yet for an empty week but counts an empty year', function () {
    expect(Activity::fromArray(activityJson('week', CarbonImmutable::parse('2026-10-09'), 'days', array_fill(0, 7, 0)))->isEmpty())->toBeTrue()
        ->and(Activity::fromArray(activityJson('year', CarbonImmutable::parse('2025-10-12'), 'days', array_fill(0, 7, 0)))->isEmpty())->toBeFalse();
});

it('draws a scale it does not know as a year rather than dropping the commits', function () {
    $activity = Activity::fromArray(activityJson('quarter', CarbonImmutable::parse('2026-08-23'), 'days', [1, ...array_fill(0, 89, 0), 2]));

    expect($activity->scale)->toBe('year')
        ->and($activity->cells())->toHaveCount(13)
        ->and($activity->total())->toBe(3);
});
