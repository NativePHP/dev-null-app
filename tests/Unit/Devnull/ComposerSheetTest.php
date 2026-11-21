<?php

use App\Devnull\ComposerSheet;

it('gives the field the lines left once everything over and under it has its height', function () {
    $empty = ComposerSheet::fieldLines([], []);

    expect($empty)->toBe(intdiv(ComposerSheet::BodyHeight - ComposerSheet::TopPadding - ComposerSheet::BottomPadding, ComposerSheet::FieldLineHeight))
        // A quoted card under the field takes its height and a gap off the field.
        ->and(ComposerSheet::fieldLines([], [96]))->toBe(intdiv(ComposerSheet::BodyHeight - ComposerSheet::TopPadding - ComposerSheet::BottomPadding - 96 - ComposerSheet::Gap, ComposerSheet::FieldLineHeight))
        // Nothing there is nothing taken.
        ->and(ComposerSheet::fieldLines([0], [0, 0]))->toBe($empty)
        // However much is around it, the field keeps two lines.
        ->and(ComposerSheet::fieldLines([200], [300]))->toBe(ComposerSheet::MinFieldLines);
});

it('finds the line the caret is on from the text before it, wrapped a word at a time', function () {
    expect(ComposerSheet::caretLine('see @a', 6, 10))->toBe(0)
        ->and(ComposerSheet::caretLine("one\ntwo\n@a", 10, 10))->toBe(2)
        // A blank line still counts.
        ->and(ComposerSheet::caretLine("one\n\n@a", 7, 10))->toBe(2)
        // Wrapped at about 40 characters on iOS, and fewer on Android's bigger text.
        ->and(ComposerSheet::caretLine(str_repeat('word ', 9).'@a', 47, 10))->toBe(1)
        ->and(ComposerSheet::caretLine(str_repeat('word ', 7).'@a', 37, 10, ios: true))->toBe(0)
        ->and(ComposerSheet::caretLine(str_repeat('word ', 7).'@a', 37, 10, ios: false))->toBe(1)
        // Only the text before the caret moves it.
        ->and(ComposerSheet::caretLine("@a\nmore\nlines", 2, 10))->toBe(0);
});

it('keeps the caret on the last line the field shows once the field has scrolled', function () {
    expect(ComposerSheet::caretLine(str_repeat("line\n", 20).'@a', 102, 6))->toBe(5);
});

it('starts the mention list under the caret line, or higher when that would leave it room for fewer than three', function () {
    $underFirstLine = ComposerSheet::TopPadding + ComposerSheet::FieldLineHeight + ComposerSheet::MentionGap;
    $lowest = 300 - ComposerSheet::MentionInset - ComposerSheet::MentionMinRows * ComposerSheet::MentionRowHeight - 8;

    expect(ComposerSheet::mentionTop(0, 300))->toBe($underFirstLine)
        ->and(ComposerSheet::mentionTop(9, 300))->toBe($lowest)
        ->and(ComposerSheet::mentionTop(0, 100))->toBe(0);
});
