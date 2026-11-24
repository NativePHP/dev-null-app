<?php

use App\Devnull\ComposerSheet;
use Native\Mobile\UI\Caret;

/** A field 360pt tall, as on a phone with the keyboard up, with its caret's line ending at $bottom. */
function composerCaret(float $bottom, float $fieldHeight = 360): array
{
    return ComposerSheet::caret(new Caret(y: $bottom - 24, height: 24, fieldHeight: $fieldHeight));
}

it('floats the list just under the caret, as tall as its rows, when they fit above the field\'s bottom', function () {
    $layout = ComposerSheet::mentionLayout(composerCaret(72), rows: 3);

    expect($layout)->toBe([
        'field' => null,
        'top' => ComposerSheet::FieldTop + 72 + ComposerSheet::MentionGap,
        'height' => ComposerSheet::MentionPadding + 3 * ComposerSheet::MentionRowHeight,
    ]);
});

it('holds the list to six rows, plus its heading, and scrolls the rest', function () {
    expect(ComposerSheet::mentionHeight(10))->toBe(ComposerSheet::MentionPadding + 6 * ComposerSheet::MentionRowHeight)
        ->and(ComposerSheet::mentionHeight(2, heading: true))->toBe(ComposerSheet::MentionPadding + ComposerSheet::MentionHeadingHeight + 2 * ComposerSheet::MentionRowHeight);
});

it('shortens the field to end on the caret\'s line when the list would not fit under it, and puts the list under the field', function () {
    // The caret low in the field, as in build 42: six people need 272pt, and there's 360 - 300 under the caret.
    $layout = ComposerSheet::mentionLayout(composerCaret(300), rows: 8);
    $list = ComposerSheet::mentionHeight(8);
    $field = 360 - ComposerSheet::MentionGap - ComposerSheet::MentionInset - $list;

    expect($layout)->toBe(['field' => $field, 'top' => ComposerSheet::FieldTop + $field + ComposerSheet::MentionGap, 'height' => null])
        // The list starts under the caret's line, never over it: the field ends on that line.
        ->and($layout['top'])->toBeGreaterThan(ComposerSheet::FieldTop + $layout['field'])
        // And the list has its six rows from there to the toolbar.
        ->and(ComposerSheet::FieldTop + 360 - ComposerSheet::MentionInset - $layout['top'])->toBe($list);
});

it('shortens the field only as far as the list needs', function () {
    $layout = ComposerSheet::mentionLayout(composerCaret(300), rows: 2);

    expect($layout['field'])->toBe(360 - ComposerSheet::MentionGap - ComposerSheet::MentionInset - ComposerSheet::mentionHeight(2))
        ->and($layout['field'])->toBeLessThan(300);
});

it('keeps two lines of field on a short phone, and the list scrolls in what\'s left', function () {
    $layout = ComposerSheet::mentionLayout(composerCaret(140, fieldHeight: 160), rows: 6);

    expect($layout)->toBe(['field' => ComposerSheet::MinFieldLines * 24, 'top' => ComposerSheet::FieldTop + 48 + ComposerSheet::MentionGap, 'height' => null]);
});

it('leaves the field alone when the caret is already in its first lines', function () {
    $layout = ComposerSheet::mentionLayout(composerCaret(24, fieldHeight: 160), rows: 6);

    expect($layout['field'])->toBeNull()
        ->and($layout['top'])->toBe(ComposerSheet::FieldTop + 24 + ComposerSheet::MentionGap)
        ->and($layout['height'])->toBe(160 - 24 - ComposerSheet::MentionGap - ComposerSheet::MentionInset);
});

it('keeps where the caret is at the field\'s full height while the field is shortened', function () {
    $kept = composerCaret(300);
    $shortened = ComposerSheet::mentionLayout($kept, rows: 8)['field'];

    // The shortened field reports itself scrolled to the caret: that's not where the caret is at full height.
    expect(ComposerSheet::reported(new Caret(y: $shortened - 24, height: 24, fieldHeight: $shortened), $kept, $shortened))->toBe($kept)
        // A report at another height is the field at its full height again.
        ->and(ComposerSheet::reported(new Caret(y: 48, height: 24, fieldHeight: 360), $kept, $shortened))->toBe(composerCaret(72))
        ->and(ComposerSheet::reported(new Caret(y: 48, height: 24, fieldHeight: 360), $kept, null))->toBe(composerCaret(72));
});

it('keeps a caret the field has scrolled out of view to the field\'s edges', function () {
    expect(ComposerSheet::caret(new Caret(y: -60, height: 24, fieldHeight: 300)))->toBe(['bottom' => 0.0, 'line_height' => 24.0, 'field_height' => 300.0])
        ->and(ComposerSheet::caret(new Caret(y: 400, height: 24, fieldHeight: 300)))->toBe(['bottom' => 300.0, 'line_height' => 24.0, 'field_height' => 300.0]);
});

it('puts the list under about one line, down to the toolbar, before the field has said where its caret is', function () {
    expect(ComposerSheet::mentionLayout(null, rows: 3))->toBe([
        'field' => null,
        'top' => ComposerSheet::FieldTop + ComposerSheet::FirstLineHeight + ComposerSheet::MentionGap,
        'height' => null,
    ]);
});
