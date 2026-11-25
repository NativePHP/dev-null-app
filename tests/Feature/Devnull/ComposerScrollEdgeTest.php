<?php

use App\Devnull\Display;
use Native\Mobile\System;
use Tests\Support\ScrollEdge;

it('stands the composer bars on the scroll edge effect from iOS 26, and keeps the fade before it and on Android', function (string $platform, string $version, bool $edge) {
    ScrollEdge::onIos($version, function () use ($platform, $edge): void {
        System::rememberPlatform($platform);

        try {
            expect(Display::hasScrollEdge())->toBe($edge)
                ->and(Display::fadeRowInBar())->toBe($platform === 'ios' && ! $edge)
                ->and(Display::composerFadeHeight())->toBe($edge ? 0 : Display::ComposerFadeHeight)
                ->and(Display::composerSurface() === '')->toBe($edge)
                ->and(Display::composerInGlassBox())->toBe($edge && Display::ComposerInGlassBox)
                ->and(Display::composerBoxBarHeight())->toBe($edge && Display::ComposerInGlassBox ? Display::ComposerGlassBoxBarHeight : Display::ComposerBoxBarHeight)
                ->and(Display::composerBox() === '')->toBe(! ($edge && Display::ComposerInGlassBox));
        } finally {
            System::rememberPlatform(null);
        }
    });
})->with([
    'iOS 18' => ['ios', '18.5', false],
    'iOS 26' => ['ios', '26.0', true],
    'iOS 26.1' => ['ios', '26.1', true],
    'iOS 27' => ['ios', '27.0.1', true],
    'Android 16' => ['android', '16', false],
    'Android 26' => ['android', '26', false],
]);

it('asks iOS for the composer edge blur on the bar, over glass for older iOS, and Android for neither', function () {
    System::rememberPlatform('ios');

    try {
        expect(Display::composerBarGlass())->toBe('glass/'.Display::ComposerGlassOpacity)
            ->and(Display::composerEdgeBlur())->toBe(Display::ComposerEdgeBlur);
    } finally {
        System::rememberPlatform(null);
    }

    System::rememberPlatform('android');

    try {
        expect(Display::composerBarGlass())->toBe('')
            ->and(Display::composerEdgeBlur())->toBe('');
    } finally {
        System::rememberPlatform(null);
    }
});

it('asks for an edge blur mobile-air takes', function () {
    expect(Display::ComposerEdgeBlur)->toBeIn(['soft', 'hard']);
});

it('keeps the glass box as tall as the bar it replaces, plus its own padding', function () {
    // The field's padding over it grows from 4 to 8, and the box adds 2 under the row of tools.
    expect(Display::ComposerGlassBoxBarHeight)->toBe(Display::ComposerBoxBarHeight + 4 + 2);
});
