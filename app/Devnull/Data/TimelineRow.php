<?php

namespace App\Devnull\Data;

/**
 * One row on the graph rail: a commit, a day marker, the last-visit marker or who to follow.
 *
 * $previousLane is the lane of the segment running into the row from above,
 * or null when nothing runs into it. In a feed a commit carries on its
 * author's run ($continuesRun) when the one above is theirs, and the run goes
 * on below it ($continuesNext) when the one below is too.
 */
final readonly class TimelineRow
{
    private function __construct(
        public string $kind,
        public int|string|null $previousLane,
        public ?Commit $commit = null,
        public bool $continuesRun = false,
        public string $label = '',
        public bool $continuesNext = false,
    ) {}

    /**
     * A commit, drawn with its author's avatar on the rail, or with a lane dot when
     * it carries on a run by the same author.
     */
    public static function commit(Commit $commit, int|string|null $previousLane, bool $continuesRun = false): self
    {
        return new self('commit', $previousLane, $commit, $continuesRun);
    }

    /**
     * This commit row, with its author's run going on below it.
     */
    public function continuingBelow(): self
    {
        return new self($this->kind, $this->previousLane, $this->commit, $this->continuesRun, $this->label, continuesNext: true);
    }

    /**
     * Whether a feed draws this commit's tail down to a dot of its own: one with no run above or below it.
     */
    public function endsWithDot(): bool
    {
        return $this->isCommit() && ! $this->continuesRun && ! $this->continuesNext;
    }

    public static function day(string $label, int|string|null $previousLane): self
    {
        return new self('day', $previousLane, label: $label);
    }

    public static function lastVisit(int|string|null $previousLane): self
    {
        return new self('last-visit', $previousLane);
    }

    /**
     * Who to follow, between two commits, with no rail running through it.
     */
    public static function whoToFollow(): self
    {
        return new self('who-to-follow', null);
    }

    /**
     * This commit row starting a run of its own, as the first under a row that breaks the rail.
     */
    public function startingRun(): self
    {
        return new self($this->kind, null, $this->commit, label: $this->label);
    }

    public function isCommit(): bool
    {
        return $this->kind === 'commit';
    }

    public function isDayMarker(): bool
    {
        return $this->kind === 'day';
    }

    public function isLastVisitMarker(): bool
    {
        return $this->kind === 'last-visit';
    }

    public function isWhoToFollow(): bool
    {
        return $this->kind === 'who-to-follow';
    }
}
