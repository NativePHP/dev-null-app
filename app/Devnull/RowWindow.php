<?php

namespace App\Devnull;

use App\Devnull\Data\TimelineRow;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\UI\Elements\ListPlaceholder;

/**
 * Which rows of a long feed get drawn: the first few, and those near the rows
 * on screen, more of them the way you're scrolling, so a fast fling doesn't
 * reach the rest before the next frame does. Every other row goes as a
 * placeholder with the row's key, which the phone draws empty at the height it
 * last drew the row, so nothing on screen moves as rows come and go. Without
 * it every row stayed built, in PHP and on the phone, and a phone scrolled
 * 1,500 commits down the log ran out of memory.
 *
 * The list says which of its rows are on screen as you scroll, by position. A
 * commit coming in above moves every position, so the window holds on to the
 * timeline rows' keys instead, and finds them again in each frame.
 *
 * While the list is still moving, the rows drawn stay where they are until the
 * screen comes near the end of them (see nearsEdge()), since every frame that
 * changes costs the phone a pass over the list, and one landing mid-fling is
 * what makes it stutter. They're drawn around the screen again when a frame
 * goes anyway, and when the list comes to rest.
 */
final class RowWindow
{
    /**
     * The timeline rows at the top that are always drawn, which back to top,
     * the pill and a tap on the status bar land on.
     */
    public const int Top = 10;

    /**
     * The timeline rows drawn past the rows on screen, the way you're scrolling.
     */
    public const int Ahead = 150;

    /**
     * The timeline rows drawn past the rows on screen, the way you came from.
     */
    public const int Behind = 100;

    /**
     * How near the end of the rows drawn the screen comes, mid-scroll, before
     * they're drawn around it again. The list reports every 20 rows, and a
     * fling carries on past the report while its frame is on the way.
     */
    public const int Reserve = 50;

    /**
     * The keys of the first and last timeline rows on screen. Null is the top.
     */
    private ?string $firstShown = null;

    private ?string $lastShown = null;

    /**
     * Where those rows are in this frame's timeline.
     */
    private int $first = 0;

    private int $last = 0;

    /**
     * Whether you last scrolled up the feed, towards newer rows.
     */
    private bool $scrollingUp = false;

    /**
     * The keys of the first and last timeline rows on screen when the rows
     * drawn were last drawn around it, and the way you were scrolling then.
     */
    private ?string $drawnAroundFirst = null;

    private ?string $drawnAroundLast = null;

    private bool $drawnScrollingUp = false;

    /**
     * Where those rows are in this frame's timeline.
     */
    private int $aroundFirst = 0;

    private int $aroundLast = 0;

    private ?int $listKey = null;

    /**
     * This frame's timeline row keys.
     *
     * @var list<string>
     */
    private array $keys = [];

    /**
     * The timeline row each of this frame's list rows belongs to: a commit's
     * thread replies are rows of their own.
     *
     * @var list<int>
     */
    private array $listRows = [];

    /**
     * The placeholders drawn last frame, by key, kept so they go again without being built.
     *
     * @var array<string, CachedRow>
     */
    private array $placeholders = [];

    /**
     * @var array<string, true>
     */
    private array $drawnPlaceholders = [];

    /**
     * Starts a frame of a feed's rows. A new list key means a new list, which
     * starts at the top.
     *
     * @param  list<TimelineRow>  $rows
     */
    public function frame(array $rows, int $listKey): self
    {
        if ($listKey !== $this->listKey) {
            $this->listKey = $listKey;
            $this->firstShown = $this->lastShown = $this->drawnAroundFirst = $this->drawnAroundLast = null;
            $this->first = $this->last = $this->aroundFirst = $this->aroundLast = 0;
            $this->scrollingUp = $this->drawnScrollingUp = false;
        }

        $this->keys = array_map(self::key(...), $rows);
        $positions = array_flip($this->keys);
        $lastRow = max(0, count($rows) - 1);

        // A row that's gone since keeps its place.
        $this->first = min($this->firstShown === null ? 0 : $positions[$this->firstShown] ?? $this->first, $lastRow);
        $this->last = max($this->first, min($this->lastShown === null ? 0 : $positions[$this->lastShown] ?? $this->last, $lastRow));
        $this->aroundFirst = min($this->drawnAroundFirst === null ? 0 : $positions[$this->drawnAroundFirst] ?? $this->aroundFirst, $lastRow);
        $this->aroundLast = max($this->aroundFirst, min($this->drawnAroundLast === null ? 0 : $positions[$this->drawnAroundLast] ?? $this->aroundLast, $lastRow));

        $this->listRows = [];
        $this->placeholders = array_intersect_key($this->placeholders, $this->drawnPlaceholders);
        $this->drawnPlaceholders = [];

        return $this;
    }

    /**
     * Puts a timeline row in the list as its next $listRows rows (a commit's
     * thread replies are rows of their own), and says whether it's drawn or
     * goes as placeholders.
     */
    public function place(int $index, int $listRows = 1): bool
    {
        array_push($this->listRows, ...array_fill(0, $listRows, $index));

        return $index < self::Top || ($index >= $this->drawnFrom() && $index <= $this->drawnTo());
    }

    /**
     * Draws a placeholder for the row with this key.
     */
    public function placeholder(string $key): void
    {
        $this->drawnPlaceholders[$key] = true;

        NativeElementCollector::attachElement($this->placeholders[$key] ??= new CachedRow(ListPlaceholder::make()->key($key)));
    }

    /**
     * The rows on screen, by their positions in the list as last drawn. The
     * rows drawn stay where they are until drawAroundScreen().
     */
    public function show(int $first, int $last): void
    {
        if ($this->listRows === []) {
            return;
        }

        $shownFirst = $this->timelineRow($first);
        $this->scrollingUp = $shownFirst === $this->first ? $this->scrollingUp : $shownFirst < $this->first;
        $this->first = $shownFirst;
        $this->last = max($this->first, $this->timelineRow($last));
        $this->firstShown = $this->keys[$this->first] ?? null;
        $this->lastShown = $this->keys[$this->last] ?? null;
    }

    /**
     * Draws the rows around the ones on screen, from the next frame on.
     */
    public function drawAroundScreen(): void
    {
        $this->drawnAroundFirst = $this->firstShown;
        $this->drawnAroundLast = $this->lastShown;
        $this->aroundFirst = $this->first;
        $this->aroundLast = $this->last;
        $this->drawnScrollingUp = $this->scrollingUp;
    }

    /**
     * Whether the screen has come within Reserve rows of either end of the
     * rows drawn, short of the top rows and the end of the feed, which are
     * there however far it goes.
     */
    public function nearsEdge(): bool
    {
        return ($this->last > $this->drawnTo() - self::Reserve && $this->drawnTo() < count($this->keys) - 1)
            || ($this->first < $this->drawnFrom() + self::Reserve && $this->drawnFrom() > self::Top);
    }

    /**
     * How many timeline rows sit below the last one on screen, so a screen can
     * tell how near the end you are before the list says you reached it. A
     * commit and its thread replies count as one.
     */
    public function rowsBelowScreen(): int
    {
        return max(0, count($this->keys) - 1 - $this->last);
    }

    /**
     * The first and last timeline rows drawn past the top ones.
     */
    private function drawnFrom(): int
    {
        return $this->aroundFirst - ($this->drawnScrollingUp ? self::Ahead : self::Behind);
    }

    private function drawnTo(): int
    {
        return $this->aroundLast + ($this->drawnScrollingUp ? self::Behind : self::Ahead);
    }

    /**
     * The timeline row a list row belongs to. Rows after the feed, such as the
     * one loading the next page, count as its last.
     */
    private function timelineRow(int $listRow): int
    {
        return $this->listRows[min(max(0, $listRow), count($this->listRows) - 1)];
    }

    private static function key(TimelineRow $row): string
    {
        return match (true) {
            $row->isDayMarker() => 'day-'.$row->label,
            $row->isLastVisitMarker() => 'last-visit',
            default => 'commit-'.$row->commit?->id,
        };
    }
}
