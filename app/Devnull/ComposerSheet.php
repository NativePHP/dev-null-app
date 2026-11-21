<?php

namespace App\Devnull;

use Native\Mobile\UI\Caret;

/**
 * Where the composer sheet (partials/commit-sheet) puts its mention list, and how tall its field is meanwhile.
 *
 * The field fills the space between the author row and what's under it by itself (`h-full`). The list floats just
 * under the line the caret is on, which the field reports (`@caretChange`, see OpensCommitSheet::sheetCaretMoved()),
 * as tall as its rows, up to six of them. When there isn't room for that under the caret, the field gets shorter
 * instead, just tall enough to end on the caret's line (it scrolls its text to keep the caret in view), and the list
 * goes in the room that frees, under the field. Once the list is gone, the field fills again.
 *
 * Everything is in points from the top of the sheet's body area, whose top is the field slot's top: the slot's
 * padding, then the field.
 */
final class ComposerSheet
{
    /** The field slot's padding above the field (pt-3). */
    public const int FieldTop = 12;

    /** The least the field shows, on a phone short enough that the rest leaves it little room. */
    public const int MinFieldLines = 2;

    /**
     * The mention list's rows, and how many show before the rest scroll inside it. The same as
     * Display::MentionRowHeight and Display::MentionRowsShown, which cap the lists by the other fields.
     */
    public const int MentionRowHeight = 44;

    public const int MentionRowsShown = 6;

    /** The list's own padding above and under its rows (py-1), and its heading's height (one line of text-xs, py-1). */
    public const int MentionPadding = 8;

    public const int MentionHeadingHeight = 24;

    /** How far under the caret's line (or the field) the mention list starts, and stops above the toolbar. */
    public const int MentionGap = 4;

    public const int MentionInset = 8;

    /**
     * Before the field has said where its caret is (it says so as soon as it has focus, so only for a moment), the
     * list goes under about one line, and a line is taken to be this tall.
     */
    public const int FirstLineHeight = 24;

    /**
     * The caret as the sheet keeps it, as the field shows it at its full height: the bottom of its line, the line's
     * height, and the field's visible height, in points. A caret the field has scrolled out of view is kept to its
     * edges.
     *
     * @return array{bottom: float, line_height: float, field_height: float}
     */
    public static function caret(Caret $caret): array
    {
        $field = max(0.0, $caret->fieldHeight);
        $bottom = max(0.0, $caret->bottom());

        return [
            'bottom' => $field > 0 ? min($bottom, $field) : $bottom,
            'line_height' => max(0.0, $caret->height),
            'field_height' => $field,
        ];
    }

    /**
     * The caret to keep after the field reports a new one. While the field is shorter for the list, it reports
     * itself at that height, scrolled to the caret, which says nothing about where the caret is at its full height,
     * so the sheet keeps what it had. Any other report is the field at its full height.
     *
     * @param  array{bottom: float, line_height: float, field_height: float}|null  $kept
     * @param  int|null  $shortened  how tall the sheet has made the field, or null when it fills
     * @return array{bottom: float, line_height: float, field_height: float}
     */
    public static function reported(Caret $caret, ?array $kept, ?int $shortened): array
    {
        if ($kept !== null && $shortened !== null && abs($caret->fieldHeight - $shortened) <= 1) {
            return $kept;
        }

        return self::caret($caret);
    }

    /** The mention list's height with all its rows showing, up to six: its padding, its heading, then its rows. */
    public static function mentionHeight(int $rows, bool $heading = false): int
    {
        return self::MentionPadding
            + ($heading ? self::MentionHeadingHeight : 0)
            + min(max($rows, 1), self::MentionRowsShown) * self::MentionRowHeight;
    }

    /**
     * Where the mention list goes, and how tall the field is while it shows.
     *
     * - `field`: the field's height, or null when it fills as usual.
     * - `top`: the list's top.
     * - `height`: the list's height, or null when it runs down to the toolbar.
     *
     * With room for the whole list (up to six rows) between the caret's line and the field's bottom, it floats there
     * over the field and nothing moves. Without it, the field ends on the caret's line, shortened by as much as the
     * list needs but never under two lines, and the list starts under it and runs to the toolbar, over anything
     * under the field (which comes up with the field's bottom). So the list never covers the caret's line.
     *
     * The room is measured to the field's bottom, since nothing reports how tall what's under it is.
     *
     * @param  array{bottom: float, line_height: float, field_height: float}|null  $caret
     * @return array{field: int|null, top: int, height: int|null}
     */
    public static function mentionLayout(?array $caret, int $rows, bool $heading = false): array
    {
        if ($caret === null || $caret['field_height'] <= 0) {
            $line = $caret === null ? self::FirstLineHeight : (int) ceil($caret['bottom']);

            return ['field' => null, 'top' => self::FieldTop + $line + self::MentionGap, 'height' => null];
        }

        $list = self::mentionHeight($rows, $heading);
        $field = (int) floor($caret['field_height']);
        $bottom = (int) ceil($caret['bottom']);
        $room = $field - $bottom - self::MentionGap - self::MentionInset;

        if ($room >= $list) {
            return ['field' => null, 'top' => self::FieldTop + $bottom + self::MentionGap, 'height' => $list];
        }

        $line = $caret['line_height'] > 0 ? (int) ceil($caret['line_height']) : self::FirstLineHeight;
        $shortened = max(self::MinFieldLines * $line, $field - self::MentionGap - self::MentionInset - $list);

        if ($shortened >= $bottom) {
            // The caret is in the field's first lines already: shortening it frees nothing, so the list takes what
            // room there is under the caret, at least a row.
            return [
                'field' => null,
                'top' => self::FieldTop + $bottom + self::MentionGap,
                'height' => max($room, self::MentionPadding + self::MentionRowHeight),
            ];
        }

        return ['field' => $shortened, 'top' => self::FieldTop + $shortened + self::MentionGap, 'height' => null];
    }

    /** Whether the sheet's mention list has the "People you follow" heading: a bare "@" in a new commit. */
    public static function mentionHeading(?array $mention, string $view): bool
    {
        return ($mention['query'] ?? null) === '' && $view === 'compose';
    }
}
