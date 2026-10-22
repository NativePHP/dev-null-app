<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use App\Devnull\Data\TimelineRow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Lays commits out on the graph rail the way the web does since it put author
 * avatars on the rail.
 *
 * A run of commits by one author shows their avatar once, on the first commit,
 * and a lane dot on the rest. A day marker or the last-visit marker between two
 * commits starts a new run.
 *
 * In a feed only a run is joined up, as the web has drawn it since "Improve
 * commit rail": a commit on its own draws its tail down to a dot, and the
 * next author starts afresh. A commit page keeps one rail from the oldest
 * ancestor down, since each commit there replies to the one above.
 */
final class Timeline
{
    public const string JustNow = 'Just now';

    public const string TopCommits = 'Top commits';

    /**
     * A feed, like the web's CommitFeed: a day marker wherever the day changes and
     * the last-visit marker above the first commit you had already seen.
     *
     * Given $whoToFollowAfter, who to follow sits under that many commits, or under the last when there are fewer.
     *
     * @param  list<Commit>  $commits
     * @return list<TimelineRow>
     */
    public static function feed(array $commits, ?string $firstSeenId = null, ?CarbonInterface $now = null, ?int $whoToFollowAfter = null): array
    {
        $now ??= CarbonImmutable::now();
        $rows = [];
        $previous = null;
        $previousDay = null;

        foreach ($commits as $commit) {
            $isFirstSeen = $firstSeenId !== null && $commit->id === $firstSeenId;
            // Each commit's day is worked out once: a log scrolled a long way down has thousands of them.
            $day = $commit->createdAt === null ? null : Display::dayKey($commit->createdAt, $now);
            $startsDay = $previous === null || ($day !== null && $previousDay !== null && $day !== $previousDay);

            if ($isFirstSeen) {
                $rows[] = TimelineRow::lastVisit(null);
            }

            if ($startsDay && $commit->createdAt !== null) {
                $rows[] = TimelineRow::day(Display::dayLabel($commit->createdAt, $now), null);
            }

            $rows[] = self::run($commit, ! $startsDay && ! $isFirstSeen && self::continuesRun($previous, $commit));
            $previous = $commit;
            $previousDay = $day;
        }

        return self::joinRuns(self::withWhoToFollow($rows, $whoToFollowAfter));
    }

    /**
     * A ranked log, like the web's Trending: no day markers and no last-visit
     * marker, since it isn't newest first. Commits newer than the ranking, put
     * on top by the new commits pill, sit above it under "Just now", and the
     * ranking follows under "Top commits". A run of one author's commits still
     * shows their avatar once.
     *
     * @param  list<Commit>  $latest  newest first
     * @param  list<Commit>  $ranked
     * @return list<TimelineRow>
     */
    public static function ranked(array $latest, array $ranked, ?int $whoToFollowAfter = null): array
    {
        $rows = [];
        $previous = null;

        if ($latest !== []) {
            $rows[] = TimelineRow::day(self::JustNow, null);

            foreach ($latest as $commit) {
                $rows[] = self::run($commit, self::continuesRun($previous, $commit));
                $previous = $commit;
            }

            if ($ranked !== []) {
                $rows[] = TimelineRow::day(self::TopCommits, null);
                $previous = null;
            }
        }

        foreach ($ranked as $commit) {
            $rows[] = self::run($commit, self::continuesRun($previous, $commit));
            $previous = $commit;
        }

        return self::joinRuns(self::withWhoToFollow($rows, $whoToFollowAfter));
    }

    /**
     * Puts who to follow under the commit $after commits down, or under the
     * last when there are fewer, as the web's log does on smaller screens. The
     * rail stops above it, so the commit below starts a run of its own, as it
     * does under a day marker.
     *
     * @param  list<TimelineRow>  $rows
     * @return list<TimelineRow>
     */
    private static function withWhoToFollow(array $rows, ?int $after): array
    {
        $commits = array_keys(array_filter($rows, fn (TimelineRow $row): bool => $row->isCommit()));

        if ($after === null || $commits === []) {
            return $rows;
        }

        $at = $commits[min(max(1, $after), count($commits)) - 1] + 1;
        $below = $rows[$at] ?? null;

        if ($below?->isCommit() === true) {
            $rows[$at] = $below->startingRun();
        }

        array_splice($rows, $at, 0, [TimelineRow::whoToFollow()]);

        return $rows;
    }

    /**
     * A commit in a feed: the segment above it only when it carries on its author's run.
     */
    private static function run(Commit $commit, bool $continuesRun): TimelineRow
    {
        return TimelineRow::commit($commit, $continuesRun ? $commit->lane() : null, $continuesRun);
    }

    /**
     * Marks each commit whose run goes on in the row below it.
     *
     * @param  list<TimelineRow>  $rows
     * @return list<TimelineRow>
     */
    private static function joinRuns(array $rows): array
    {
        foreach ($rows as $index => $row) {
            if ($row->isCommit() && ($rows[$index + 1] ?? null)?->continuesRun === true) {
                $rows[$index] = $row->continuingBelow();
            }
        }

        return $rows;
    }

    /**
     * A commit page's ancestors and the commit itself, on one rail with no day
     * markers, so someone replying to themselves shows their avatar once.
     * When the ancestors wait behind a row of their own, $laneAbove runs the
     * last one's lane into the first row, which shows its avatar all the same.
     *
     * @param  list<Commit>  $commits
     * @return list<TimelineRow>
     */
    public static function thread(array $commits, int|string|null $laneAbove = null): array
    {
        $rows = [];
        $previous = null;

        foreach ($commits as $commit) {
            $rows[] = TimelineRow::commit($commit, $previous === null ? $laneAbove : $previous->lane(), self::continuesRun($previous, $commit));
            $previous = $commit;
        }

        return $rows;
    }

    /**
     * Whether a commit carries on the run of the one above it: the same author, and
     * neither of them deleted.
     */
    public static function continuesRun(?Commit $previous, Commit $commit): bool
    {
        return $previous !== null
            && ! $previous->isDeleted()
            && ! $commit->isDeleted()
            && $previous->author->id === $commit->author->id;
    }
}
