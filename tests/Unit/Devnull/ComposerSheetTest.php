<?php

use App\Devnull\ComposerSheet;
use Native\Mobile\UI\Caret;
use Tests\Support\TextFieldFill;

beforeEach(fn () => TextFieldFill::skipUnlessSupported());

it('starts the mention list just under the line the field says the caret is on', function () {
    $caret = ComposerSheet::caret(new Caret(y: 48, height: 24, line: 2, fieldHeight: 300));

    expect(ComposerSheet::mentionTop($caret))->toBe(ComposerSheet::FieldTop + 72 + ComposerSheet::MentionGap);
});

it('moves the list up when the caret is too near the field\'s bottom to leave room for three people', function () {
    $lowest = ComposerSheet::FieldTop + 300 - ComposerSheet::MentionInset - ComposerSheet::MentionMinRows * ComposerSheet::MentionRowHeight - ComposerSheet::MentionPadding;

    expect(ComposerSheet::mentionTop(ComposerSheet::caret(new Caret(y: 264, height: 24, fieldHeight: 300))))->toBe($lowest)
        // A field too short for three people puts the list at the top of it.
        ->and(ComposerSheet::mentionTop(ComposerSheet::caret(new Caret(y: 24, height: 24, fieldHeight: 80))))->toBe(0);
});

it('keeps a caret the field has scrolled out of view to the field\'s edges', function () {
    expect(ComposerSheet::caret(new Caret(y: -60, height: 24, fieldHeight: 300)))->toBe(['bottom' => 0.0, 'field_height' => 300.0])
        ->and(ComposerSheet::caret(new Caret(y: 400, height: 24, fieldHeight: 300)))->toBe(['bottom' => 300.0, 'field_height' => 300.0]);
});

it('puts the list under about one line before the field has said where its caret is', function () {
    expect(ComposerSheet::mentionTop(null))->toBe(ComposerSheet::FieldTop + ComposerSheet::FirstLineHeight + ComposerSheet::MentionGap);
});
