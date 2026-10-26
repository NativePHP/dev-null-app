<?php

use App\Devnull\Data\Commit;
use App\Devnull\Data\TimelineRow;
use App\Devnull\LastVisit;
use App\Devnull\Timeline;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $overrides
 */
function timelineCommit(string $id, string $userId, string $createdAt, array $overrides = []): Commit
{
    return Commit::fromArray([
        'id' => $id,
        'body' => "Commit {$id}",
        'created_at' => $createdAt,
        'author' => ['id' => $userId, 'handle' => $userId, 'name' => ucfirst($userId), 'lane' => $userId === 'ada' ? 1 : 2],
        ...$overrides,
    ]);
}

/**
 * Each row as a short string: "day:Today", "visit", or "c1:avatar" / "c1:dot".
 *
 * @param  list<TimelineRow>  $rows
 * @return list<string>
 */
function describeRows(array $rows): array
{
    return array_map(fn (TimelineRow $row): string => match (true) {
        $row->isDayMarker() => "day:{$row->label}",
        $row->isLastVisitMarker() => 'visit',
        default => $row->commit->id.':'.($row->continuesRun ? 'dot' : 'avatar'),
    }, $rows);
}

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-10-15T12:00:00Z');
});

it('gives the first commit of a run the avatar and the rest a dot', function () {
    $rows = Timeline::feed([
        timelineCommit('c4', 'ada', '2026-10-15T11:00:00Z'),
        timelineCommit('c3', 'ada', '2026-10-15T10:00:00Z'),
        timelineCommit('c2', 'grace', '2026-10-15T09:00:00Z'),
        timelineCommit('c1', 'ada', '2026-10-15T08:00:00Z'),
    ], now: $this->now);

    expect(describeRows($rows))->toBe(['day:Today', 'c4:avatar', 'c3:dot', 'c2:avatar', 'c1:avatar']);
});

it('starts a new run at a day marker', function () {
    $rows = Timeline::feed([
        timelineCommit('c3', 'ada', '2026-10-15T01:00:00Z'),
        timelineCommit('c2', 'ada', '2026-10-14T23:00:00Z'),
        timelineCommit('c1', 'ada', '2026-10-14T22:00:00Z'),
    ], now: $this->now);

    expect(describeRows($rows))->toBe(['day:Today', 'c3:avatar', 'day:Yesterday', 'c2:avatar', 'c1:dot']);
});

it('starts a new run at the last-visit marker, which goes above the day marker', function () {
    $rows = Timeline::feed([
        timelineCommit('c3', 'ada', '2026-10-15T11:00:00Z'),
        timelineCommit('c2', 'ada', '2026-10-15T10:00:00Z'),
        timelineCommit('c1', 'ada', '2026-10-14T10:00:00Z'),
    ], firstSeenId: 'c2', now: $this->now);

    expect(describeRows($rows))->toBe(['day:Today', 'c3:avatar', 'visit', 'c2:avatar', 'day:Yesterday', 'c1:avatar']);

    $rows = Timeline::feed([
        timelineCommit('c2', 'ada', '2026-10-15T10:00:00Z'),
        timelineCommit('c1', 'ada', '2026-10-14T10:00:00Z'),
    ], firstSeenId: 'c1', now: $this->now);

    expect(describeRows($rows))->toBe(['day:Today', 'c2:avatar', 'visit', 'day:Yesterday', 'c1:avatar']);
});

it('joins only a run, as the web has since it improved the rail: anything else starts afresh', function () {
    $rows = Timeline::feed([
        timelineCommit('c4', 'ada', '2026-10-15T11:00:00Z'),
        timelineCommit('c3', 'ada', '2026-10-15T10:00:00Z'),
        timelineCommit('c2', 'grace', '2026-10-15T09:00:00Z'),
        timelineCommit('c1', 'ada', '2026-10-14T11:00:00Z'),
    ], now: $this->now);

    $joins = array_map(fn (TimelineRow $row): array => [
        $row->isCommit() ? $row->commit->id : $row->label,
        $row->previousLane,
        $row->continuesNext,
        $row->endsWithDot(),
    ], $rows);

    expect($joins)->toBe([
        ['Today', null, false, false],
        // Ada's run: the avatar runs on into the dot below, which ends it.
        ['c4', null, true, false],
        ['c3', 1, false, false],
        // Alone in their run, so each ends in a dot of its own.
        ['c2', null, false, true],
        ['Yesterday', null, false, false],
        ['c1', null, false, true],
    ]);
});

it('lays Trending out with what came in above the ranking, and joins runs within each', function () {
    $rows = Timeline::ranked(
        [timelineCommit('n2', 'grace', '2026-10-15T11:00:00Z'), timelineCommit('n1', 'grace', '2026-10-15T10:00:00Z')],
        [timelineCommit('c2', 'grace', '2026-10-13T11:00:00Z'), timelineCommit('c1', 'ada', '2026-10-15T09:00:00Z')],
    );

    expect(describeRows($rows))->toBe(['day:Just now', 'n2:avatar', 'n1:dot', 'day:Top commits', 'c2:avatar', 'c1:avatar'])
        ->and($rows[1]->continuesNext)->toBeTrue()
        ->and($rows[4]->endsWithDot())->toBeTrue();
});

it('never runs on from or into a deleted commit', function () {
    $tombstone = Commit::fromArray(['id' => 't1', 'deleted_at' => '2026-10-15T09:30:00Z', 'replies_count' => 1]);

    expect(describeRows(Timeline::thread([
        timelineCommit('c1', 'ada', '2026-10-15T09:00:00Z'),
        $tombstone,
        timelineCommit('c2', 'ada', '2026-10-15T10:00:00Z'),
    ])))->toBe(['c1:avatar', 't1:avatar', 'c2:avatar']);
});

it('groups someone replying to themselves on a commit page, without day markers', function () {
    $rows = Timeline::thread([
        timelineCommit('c1', 'ada', '2026-10-13T09:00:00Z'),
        timelineCommit('c2', 'ada', '2026-10-15T10:00:00Z'),
        timelineCommit('c3', 'grace', '2026-10-15T11:00:00Z'),
        timelineCommit('c4', 'ada', '2026-10-15T11:30:00Z'),
    ]);

    expect(describeRows($rows))->toBe(['c1:avatar', 'c2:dot', 'c3:avatar', 'c4:avatar'])
        ->and($rows[0]->previousLane)->toBeNull()
        ->and($rows[1]->previousLane)->toBe(1);
});

it('finds the first commit you had seen, only when someone else committed since', function () {
    $commits = [
        timelineCommit('c3', 'grace', '2026-10-15T11:00:00Z'),
        timelineCommit('c2', 'ada', '2026-10-15T10:00:00Z'),
        timelineCommit('c1', 'grace', '2026-10-15T09:00:00Z'),
    ];

    expect(LastVisit::firstSeenId($commits, '2026-10-15T10:00:00+00:00', 'ada'))->toBe('c2')
        ->and(LastVisit::firstSeenId($commits, '2026-10-15T09:30:00+00:00', 'ada'))->toBe('c1')
        // Nothing new.
        ->and(LastVisit::firstSeenId($commits, '2026-10-15T11:00:00+00:00', 'ada'))->toBeNull()
        // Only your own commit is new.
        ->and(LastVisit::firstSeenId($commits, '2026-10-15T10:00:00+00:00', 'grace'))->toBeNull()
        // Everything is new, so there's nothing to mark.
        ->and(LastVisit::firstSeenId($commits, '2026-10-15T08:00:00+00:00', 'ada'))->toBeNull()
        // A first visit.
        ->and(LastVisit::firstSeenId($commits, null, 'ada'))->toBeNull();
});
