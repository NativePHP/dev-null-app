<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/draft-images'));
});

/**
 * A commit as devnull sends it, with $count images, the first described as the web's alt text.
 *
 * @return array<string, mixed>
 */
function imagedCommitJson(int $count = 1): array
{
    return commitJson(['id' => 'c1', 'images' => array_map(
        fn (int $index): array => [
            'id' => 'i'.($index + 1),
            'url' => 'https://img.test/'.($index + 1),
            'width' => 10,
            'height' => 10,
            'description' => $index === 0 ? 'A cat on a keyboard' : null,
        ],
        range(0, $count - 1),
    )]);
}

/**
 * The Following log showing one commit with $count images.
 */
function logWithImages(int $count = 1, string $platform = 'ios'): TestableComponent
{
    Http::fake(['devnull.test/*' => Http::response(pageJson([imagedCommitJson($count)]))]);

    return Native::visit('/log', platform: $platform);
}

/**
 * The images of the imaged commit, for faking another screen that shows it.
 *
 * @return list<array<string, mixed>>
 */
function imagedCommitImages(): array
{
    return imagedCommitJson()['images'];
}

it('opens a commit\'s image full-screen in the app, not the browser', function (string $platform) {
    $screen = logWithImages(platform: $platform);

    $screen->tap('image-i1');

    $viewer = nodeByRef($screen, 'image-viewer');
    $gestures = nodeByRef($screen, 'viewer-gestures');
    $image = nodeByRef($screen, 'viewed-image');
    $viewing = $screen->instance();

    expect($viewer['props'])->toMatchArray(['visible' => true, 'a11y_label' => 'Image viewer'])
        ->and($viewer['props']['on_dismiss'])->not->toBeEmpty()
        // The image whole, not cropped as it is in the row, read out by the description its author gave it.
        ->and($image['props'])->toMatchArray(['src' => 'https://img.test/1', 'fit' => 1, 'alt' => 'A cat on a keyboard'])
        // Pinch bounded one to four, and both pans, settled on the phone against the shared values this open made,
        // kept on screen by the image's own shape.
        ->and($gestures['props'])->toMatchArray(['pinch-min' => 1.0, 'pinch-max' => 4.0, 'bounded' => true, 'content-ratio' => 1.0])
        ->and($gestures['props']['pinch-id'])->toBe($viewing->viewerZoom->id)
        ->and($gestures['props']['pan-x-id'])->toBe($viewing->viewerPanX->id)
        ->and($gestures['props']['pan-y-id'])->toBe($viewing->viewerPanY->id)
        ->and($image['props']['scale_sv'])->toBe((string) $viewing->viewerZoom)
        ->and($image['props']['translate-x_sv'])->toBe((string) $viewing->viewerPanX)
        ->and($image['props']['translate-y_sv'])->toBe((string) $viewing->viewerPanY)
        // One image alone has no chevrons and no place among others.
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'viewer-count'))->toBeNull();

    $screen->assertNativeNotCalled('Browser.OpenInApp');
})->with(['ios', 'android']);

it('keeps an image of no known size on screen by the screen\'s own shape', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1', 'images' => [
        ['id' => 'i1', 'url' => 'https://img.test/1'],
    ]])]))]);

    $screen = Native::visit('/log')->tap('image-i1');

    expect(nodeByRef($screen, 'viewer-gestures')['props'])->toHaveKey('bounded')
        ->not->toHaveKey('content-ratio');
});

it('bounds a wide image by its real shape, not the one the feed caps it at', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1', 'images' => [
        ['id' => 'i1', 'url' => 'https://img.test/1', 'width' => 300, 'height' => 1200],
    ]])]))]);

    $screen = Native::visit('/log')->tap('image-i1');

    expect(nodeByRef($screen, 'viewer-gestures')['props']['content-ratio'])->toBe(0.25);
});

it('starts from the image the row tapped, with the chevrons under it for the commit\'s others', function () {
    $screen = logWithImages(3)->tap('image-i3');

    expect(nodeByRef($screen, 'viewed-image')['props'])->toMatchArray(['src' => 'https://img.test/3', 'alt' => 'Attached image 3 of 3'])
        ->and($screen->instance()->viewerIndex)->toBe(2)
        ->and(shownText($screen))->toContain('3 of 3')
        // The first image has nothing before it, the last nothing after it, so only the chevron that goes somewhere is drawn.
        ->and(nodeByRef($screen, 'viewed-previous')['props']['a11y_label'])->toBe('Previous image')
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'viewed-next'))->toBeNull();

    $screen->tap('viewed-previous')->tap('viewed-previous');

    expect(nodeByRef($screen, 'viewed-image')['props'])->toMatchArray(['src' => 'https://img.test/1', 'alt' => 'A cat on a keyboard, image 1 of 3'])
        ->and($screen->instance()->viewerIndex)->toBe(0)
        ->and(shownText($screen))->toContain('1 of 3')
        ->and(nodeByRef($screen, 'viewed-next')['props']['a11y_label'])->toBe('Next image')
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'viewed-previous'))->toBeNull();
});

it('moves between the commit\'s images with the chevrons, each image starting at rest', function () {
    $screen = logWithImages(3)->tap('image-i1');

    $atRest = nodeByRef($screen, 'viewer-gestures')['props'];

    $screen->tap('viewed-next');

    $image = nodeByRef($screen, 'viewed-image');
    $moved = nodeByRef($screen, 'viewer-gestures')['props'];

    expect($image['props']['src'])->toBe('https://img.test/2')
        ->and($screen->instance()->viewerIndex)->toBe(1)
        // Fresh zoom and pans, so what the last image's pinch or drag left behind can't carry over to it.
        ->and($moved['pinch-id'])->not->toBe($atRest['pinch-id'])
        ->and($moved['pan-x-id'])->not->toBe($atRest['pan-x-id'])
        ->and($moved['pan-y-id'])->not->toBe($atRest['pan-y-id'])
        ->and($image['props']['scale'])->toBe(1.0);

    // Past the last image, the chevron is gone and the call goes nowhere.
    $screen->tap('viewed-next')->call('viewNextImage');

    expect($screen->instance()->viewerIndex)->toBe(2)
        ->and(nodeByRef($screen, 'viewed-image')['props']['src'])->toBe('https://img.test/3');
});

it('opens nothing for an image the screen isn\'t showing, a place with no image, or no web address', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([
        imagedCommitJson(1),
        commitJson(['id' => 'c2', 'images' => [['id' => 'not-web', 'url' => 'file:///etc/passwd']]]),
    ]))]);

    $screen = Native::visit('/log');

    $screen->call('openImage', 'nope')
        ->call('openImage', 'c1', 3)
        ->call('openImage', 'c1', -1)
        ->tap('image-not-web')
        ->assertSet('viewerOpen', false)
        ->assertSet('viewerImages', [])
        ->assertNativeNotCalled('Browser.OpenInApp');
});

it('closes on the phone\'s close or back and forgets everything, so the next image opens as this one did', function (string $platform) {
    $screen = logWithImages(2, platform: $platform);

    $screen->tap('image-i2');
    $firstPinch = nodeByRef($screen, 'viewer-gestures')['props']['pinch-id'];

    $screen->dismissSheet('image-viewer');

    $viewing = $screen->instance();

    expect($viewing->viewerOpen)->toBeFalse()
        ->and($viewing->viewerImages)->toBe([])
        ->and($viewing->viewerIndex)->toBe(0)
        ->and($viewing->viewerZoom)->toBeNull()
        ->and($viewing->viewerPanX)->toBeNull()
        ->and($viewing->viewerPanY)->toBeNull()
        // The modal itself stays in the tree, hidden, so the phone can animate it away and say when it's gone.
        ->and(nodeByRef($screen, 'image-viewer')['props']['visible'])->toBeFalse()
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'viewed-image'))->toBeNull();

    $screen->tap('image-i1');

    expect(nodeByRef($screen, 'viewed-image')['props']['src'])->toBe('https://img.test/1')
        // A fresh pinch, seeded at one again, not the one the closed image was using.
        ->and(nodeByRef($screen, 'viewer-gestures')['props']['pinch-id'])->not->toBe($firstPinch);
})->with(['ios', 'android']);

it('goes nowhere when the next or previous image is at no web address, so the viewer never shows one', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1', 'images' => [
        ['id' => 'i1', 'url' => 'https://img.test/1', 'width' => 10, 'height' => 10],
        ['id' => 'i2', 'url' => 'file:///etc/passwd', 'width' => 10, 'height' => 10],
        ['id' => 'i3', 'url' => 'https://img.test/3', 'width' => 10, 'height' => 10],
    ]])]))]);

    $screen = Native::visit('/log');

    // From the first, next is the unsafe image: the chevron goes nowhere.
    $screen->tap('image-i1')->tap('viewed-next');

    expect($screen->instance()->viewerIndex)->toBe(0)
        ->and(nodeByRef($screen, 'viewed-image')['props']['src'])->toBe('https://img.test/1');

    // From the last, previous is the unsafe image: the chevron goes nowhere either.
    $screen->tap('image-i3')->tap('viewed-previous');

    expect($screen->instance()->viewerIndex)->toBe(2)
        ->and(nodeByRef($screen, 'viewed-image')['props']['src'])->toBe('https://img.test/3');

    // Nothing the viewer shows is at any but a web address.
    $shown = findNodes(nodeByRef($screen, 'image-viewer'), fn (array $node): bool => ($node['type'] ?? null) === 'image');

    expect($shown)->not->toBeEmpty()
        ->and(array_map(fn (array $node): string => $node['props']['src'] ?? '', $shown))->each->toStartWith('https://');
});

it('keeps the browser for a commit\'s link preview, as before', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'body' => 'Read https://nativephp.com/docs',
        'link_preview' => ['url' => 'https://nativephp.com/docs', 'title' => 'Docs', 'ready' => true],
    ])]))]);

    Native::visit('/log')->tap('link-preview-c1')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://nativephp.com/docs')
        ->assertSet('viewerOpen', false);
});

it('hangs the viewer over every screen that shows commits', function (string $path, array $api) {
    fakeApi($api);

    $screen = $path === '/profile/starred'
        ? finishRefreshes(Native::visit($path, platform: 'ios'))
        : Native::visit($path, platform: 'ios');

    $screen->tap('image-i1');

    expect(nodeByRef($screen, 'image-viewer')['props']['visible'])->toBeTrue()
        ->and(nodeByRef($screen, 'viewed-image')['props']['src'])->toBe('https://img.test/1');

    $screen->assertNativeNotCalled('Browser.OpenInApp');
})->with([
    'a commit\'s page' => ['/log/commits/c1', [
        'GET /api/v1/commits/c1' => [commitPageJson(commitJson(['id' => 'c1', 'images' => imagedCommitImages()]))],
        'GET /api/v1/commits/c1/replies' => [pageJson([])],
    ]],
    'a profile' => ['/log/users/ada', [
        'GET /api/v1/users/ada' => [[
            'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada'], commits: 1),
            'is_following' => false,
            'activity' => ['scale' => 'week', 'buckets' => []],
            'streaks' => ['longest' => 0, 'current' => 0],
            'pinned_commit' => null,
        ]],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'handle' => 'ada']), 'images' => imagedCommitImages()])])],
    ]],
    'the starred page' => ['/profile/starred', [
        'GET /api/v1/starred' => [pageJson([commitJson(['id' => 'c1', 'starred' => true, 'images' => imagedCommitImages()])], path: 'http://devnull.test/api/v1/starred')],
    ]],
]);

it('hangs over the sheet, and leaves a photo being written in the sheet alone, as before', function () {
    serveApi([
        'GET /api/v1/log/following' => [pageJson([imagedCommitJson(1)])],
        'POST /api/v1/commits/preview' => [['data' => ['body' => 'Look at this']]],
    ]);

    $screen = Native::visit('/log', platform: 'ios');

    // The composer in the sheet, previewing the photo just picked.
    $screen->press('composeInSheet')
        ->emitNative(MediaSelected::class, ['success' => true, 'files' => [['path' => testPhoto()]]])
        ->tap('sheet-preview');

    // The photo being written is the draft's, not a shown commit's, so tapping it opens nothing — in the browser it
    // opened nothing either.
    $screen->tap('image-draft-0')
        ->assertSet('viewerOpen', false)
        ->assertSet('sheetOpen', true)
        ->assertNativeNotCalled('Browser.OpenInApp');

    // The viewer hangs beside the sheet in the tree, after it, with the sheet still up underneath — the order the
    // platforms are given to draw them in. That it truly covers the sheet on a device is left to verify there; this
    // is what the app can promise from here.
    $screen->call('openImage', 'c1', 0);

    $refs = [];
    $collect = function (array $node) use (&$collect, &$refs): void {
        if (isset($node['ref'])) {
            $refs[] = $node['ref'];
        }

        foreach ($node['children'] ?? [] as $child) {
            $collect($child);
        }
    };
    $collect($screen->tree());

    expect(array_search('image-viewer', $refs, true))->toBeGreaterThan(array_search('sheet', $refs, true))
        ->and(nodeByRef($screen, 'image-viewer')['props']['visible'])->toBeTrue()
        ->and($screen->instance()->sheetOpen)->toBeTrue();
});
