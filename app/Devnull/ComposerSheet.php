<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use App\Devnull\Data\PollDraft;

/**
 * How the composer sheet (partials/commit-sheet) shares its height out, in points. The field takes what the rest
 * leaves between the author row and the toolbar, and the mention list floats under the line the caret is on.
 *
 * Neither platform tells PHP how tall the sheet is, or where the caret is drawn, and mobile-ui's multiline field is as
 * tall as its lines (min-lines to max-lines), not as tall as the space it's given. So this works both out from what
 * the app knows: the sheet's height on a 6.1" iPhone with the keyboard up, how tall each thing under the field is
 * drawn, and how the draft wraps at the field's width. The field's line height is set (FieldLineHeight), so a line
 * is the same height on both platforms.
 */
final class ComposerSheet
{
    /**
     * The sheet between its bar's hairline and the toolbar's hairline, keyboard up, on a 6.1" iPhone. Bigger phones
     * leave a little room under the field; nothing smaller is supported for composing.
     */
    public const int BodyHeight = 340;

    /** The padding above the first thing in the sheet and under the last. */
    public const int TopPadding = 12;

    public const int BottomPadding = 8;

    /** The gap between the things stacked above and under the field. */
    public const int Gap = 12;

    public const int FieldLineHeight = 24;

    public const int MinFieldLines = 2;

    /**
     * About how many characters of the field's text fit on a line across the sheet: 18pt on iOS, 20sp on Android.
     */
    public const int IosCharactersPerLine = 40;

    public const int AndroidCharactersPerLine = 36;

    /** The Post as row's avatar, with its name and handle beside it. */
    public const int PostAsHeight = 36;

    public const int IssueBadgeHeight = 32;

    /** A line of small text: an error, or the link-length note. */
    public const int NoteLineHeight = 20;

    public const int NoteCharactersPerLine = 48;

    /** The photo strip's 80pt squares, and the description under them while it's open. */
    public const int PhotosHeight = 80;

    public const int PhotoDescriptionHeight = 150;

    /** The schedule box: "Submit at", the picker, the timezone and its lines, then Scheduled commits →. */
    public const int ScheduleHeight = 170;

    public const int IssueCommandRowHeight = 44;

    public const int IssueHintHeight = 32;

    /** A quoted commit's card: its margin, padding and header, then up to six lines of its body. */
    public const int CardChromeHeight = 44;

    public const int CardLineHeight = 24;

    public const int CardCharactersPerLine = 42;

    public const int CardMaxLines = 6;

    public const int CardPollOptionHeight = 36;

    /** The mention list's rows, and how many of them it always has room for. */
    public const int MentionRowHeight = 44;

    public const int MentionMinRows = 3;

    /** How far under the caret's line the mention list starts, and stops above the toolbar. */
    public const int MentionGap = 4;

    public const int MentionInset = 8;

    /**
     * How many lines the field shows, after everything else in the sheet has its height: $above are the heights of
     * what's stacked over the field, $below of what's under it.
     *
     * @param  list<int>  $above
     * @param  list<int>  $below
     */
    public static function fieldLines(array $above, array $below): int
    {
        $parts = [...array_filter($above), ...array_filter($below)];
        $free = self::BodyHeight - self::TopPadding - self::BottomPadding - array_sum($parts) - count($parts) * self::Gap;

        return max(self::MinFieldLines, intdiv($free, self::FieldLineHeight));
    }

    /**
     * The height of the space under the stack above the field: the field and what's under it, where the mention list
     * floats.
     *
     * @param  list<int>  $above
     */
    public static function bodyHeight(array $above): int
    {
        $above = array_filter($above);

        return self::BodyHeight - array_sum($above) - count($above) * self::Gap;
    }

    /**
     * Which of the field's shown lines the caret is on, from the top, counting from 0: the text before it, wrapped a
     * word at a time at the field's width. Past the last line shown, the field has scrolled to keep the caret in
     * view, so it's on the last.
     */
    public static function caretLine(string $text, int $caret, int $shownLines, bool $ios = true): int
    {
        $perLine = $ios ? self::IosCharactersPerLine : self::AndroidCharactersPerLine;
        $lines = 0;

        foreach (explode("\n", mb_substr($text, 0, max(0, $caret))) as $paragraph) {
            $lines += self::wrappedLines($paragraph, $perLine);
        }

        return min(max(0, $lines - 1), max(0, $shownLines - 1));
    }

    /**
     * Where the mention list's top goes in the space under the stack: just under the caret's line, or higher when
     * that would leave it room for fewer than three people.
     */
    public static function mentionTop(int $caretLine, int $bodyHeight): int
    {
        $underCaret = self::TopPadding + ($caretLine + 1) * self::FieldLineHeight + self::MentionGap;
        $lowest = $bodyHeight - self::MentionInset - self::MentionMinRows * self::MentionRowHeight - 8;

        return max(0, min($underCaret, $lowest));
    }

    /**
     * A commit drawn as a static card (partials/quoted-commit): its chrome, its body clamped to six lines, its poll.
     */
    public static function cardHeight(Commit $commit): int
    {
        $lines = 0;

        foreach (explode("\n", $commit->plainText()) as $paragraph) {
            $lines += self::wrappedLines($paragraph, self::CardCharactersPerLine);
        }

        return self::CardChromeHeight
            + min(self::CardMaxLines, max(1, $lines)) * self::CardLineHeight
            + ($commit->poll === null ? 0 : 4 + count($commit->poll->options) * self::CardPollOptionHeight);
    }

    /**
     * The poll editor (partials/poll-editor): its padding, a field per option, Add option until there are four, then
     * Poll length and Remove poll under a hairline.
     *
     * @param  list<string>  $options
     */
    public static function pollEditorHeight(array $options): int
    {
        $count = count($options);

        return 24 + $count * 64 + ($count < PollDraft::MaxOptions ? 44 : 0) + 9 + 56;
    }

    /**
     * A note in small text, such as an error, at its wrapped height.
     */
    public static function noteHeight(string $note): int
    {
        return self::wrappedLines($note, self::NoteCharactersPerLine) * self::NoteLineHeight;
    }

    /**
     * How many lines a paragraph wraps to, a word at a time, at about $perLine characters. A word longer than a line
     * breaks, as the fields and Text break it.
     */
    private static function wrappedLines(string $paragraph, int $perLine): int
    {
        return $paragraph === '' ? 1 : substr_count(wordwrap($paragraph, $perLine, "\n", true), "\n") + 1;
    }
}
