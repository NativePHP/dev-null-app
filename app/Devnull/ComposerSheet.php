<?php

namespace App\Devnull;

use Native\Mobile\UI\Caret;

/**
 * Where the composer sheet (partials/commit-sheet) floats its mention list: just under the line the caret is on, which
 * the field reports (`@caretChange`, see OpensCommitSheet::sheetCaretMoved()), down to the toolbar.
 *
 * The field fills the space between the author row and what's under it by itself (`h-full`), so nothing here sizes
 * it. The list's top is in the sheet's body area, whose top is the field slot's top: the slot's padding, then the
 * caret's line in the field's own points.
 */
final class ComposerSheet
{
    /** The field slot's padding above the field (pt-3). */
    public const int FieldTop = 12;

    /** The least the field shows, on a phone short enough that the rest leaves it little room. */
    public const int MinFieldLines = 2;

    /** The mention list's rows, and how many of them it always has room for. */
    public const int MentionRowHeight = 44;

    public const int MentionMinRows = 3;

    /** How far under the caret's line the mention list starts, and stops above the toolbar. */
    public const int MentionGap = 4;

    public const int MentionInset = 8;

    /** The list's own padding above and under its rows (py-1). */
    public const int MentionPadding = 8;

    /**
     * Before the field has said where its caret is (it says so as soon as it has focus, so only for a moment), the
     * list goes under about one line.
     */
    public const int FirstLineHeight = 24;

    /**
     * The caret as the sheet keeps it: the bottom of its line and the field's visible height, in points. A caret the
     * field has scrolled out of view is kept to its edges.
     *
     * @return array{bottom: float, field_height: float}
     */
    public static function caret(Caret $caret): array
    {
        $field = max(0.0, $caret->fieldHeight);
        $bottom = max(0.0, $caret->bottom());

        return ['bottom' => $field > 0 ? min($bottom, $field) : $bottom, 'field_height' => $field];
    }

    /**
     * Where the mention list's top goes in the sheet's body area: just under the caret's line, or higher when that
     * would leave it room for fewer than three people above the toolbar. The room is measured to the field's bottom,
     * since the field doesn't say how tall what's under it is, so with a quoted commit under it the list can go up a
     * little sooner than it has to.
     *
     * @param  array{bottom: float, field_height: float}|null  $caret
     */
    public static function mentionTop(?array $caret): int
    {
        if ($caret === null) {
            return self::FieldTop + self::FirstLineHeight + self::MentionGap;
        }

        $underCaret = self::FieldTop + (int) ceil($caret['bottom']) + self::MentionGap;

        if ($caret['field_height'] <= 0) {
            return $underCaret;
        }

        $lowest = self::FieldTop + (int) floor($caret['field_height'])
            - self::MentionInset - self::MentionMinRows * self::MentionRowHeight - self::MentionPadding;

        return max(0, min($underCaret, $lowest));
    }
}
