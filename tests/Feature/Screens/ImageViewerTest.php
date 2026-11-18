<?php

use App\Devnull\ImageCache;
use App\Devnull\ImageViewer;
use Devnull\Photos\Events\Saved;
use Devnull\Photos\Events\SaveFailed;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    $this->bridge = signIn();
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
    $gestures = nodeByRef($screen, 'viewer-gestures-0');
    $image = nodeByRef($screen, 'viewed-image-0');
    $viewing = $screen->instance();

    expect($viewer['props'])->toMatchArray(['visible' => true, 'a11y_label' => 'Image viewer'])
        ->and($viewer['props']['on_dismiss'])->not->toBeEmpty()
        // The image whole, not cropped as it is in the row, read out by the description its author gave it.
        ->and($image['props'])->toMatchArray(['src' => 'https://img.test/1', 'fit' => 1, 'alt' => 'A cat on a keyboard'])
        // Pinch bounded one to four, settled on the phone against the shared values this open made, kept on screen
        // by the image's own shape. At its whole self it doesn't pan, so the pager and the drag to close get the drag.
        ->and($gestures['props'])->toMatchArray(['pinch-min' => 1.0, 'pinch-max' => 4.0, 'bounded' => true, 'content-ratio' => 1.0])
        ->and($gestures['props']['pinch-id'])->toBe($viewing->viewerZooms[0]->id)
        ->and($gestures['props'])->not->toHaveKey('pan-x-id')
        ->and($gestures['props'])->not->toHaveKey('pan-y-id')
        ->and($image['props']['scale_sv'])->toBe((string) $viewing->viewerZooms[0])
        ->and($image['props']['translate-x_sv'])->toBe((string) $viewing->viewerPansX[0])
        ->and($image['props']['translate-y_sv'])->toBe((string) $viewing->viewerPansY[0])
        // One image alone has no dashes to say its place.
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'viewer-dots'))->toBeNull();

    $screen->assertNativeNotCalled('Browser.OpenInApp');
})->with(['ios', 'android']);

it('floats a sheet\'s round close over the picture on iOS, with no band for the modal\'s own', function () {
    $screen = logWithImages(platform: 'ios')->tap('image-i1');

    expect(nodeByRef($screen, 'image-viewer')['props']['dismissible'])->toBeFalse()
        ->and(nodeByRef($screen, 'viewer-close')['props']['a11y_label'])->toBe('Close');

    $screen->tap('viewer-close')->assertSet('viewerOpen', false);
});

it('keeps the modal\'s own close row on Android, so Android\'s back still closes it', function () {
    $screen = logWithImages(platform: 'android')->tap('image-i1');

    expect(nodeByRef($screen, 'image-viewer')['props']['dismissible'])->toBeTrue()
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'viewer-close'))->toBeNull()
        ->and(nodeByRef($screen, 'viewer-save')['props']['a11y_label'])->toBe('Save to Photos');
});

it('keeps an image of no known size on screen by the screen\'s own shape', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1', 'images' => [
        ['id' => 'i1', 'url' => 'https://img.test/1'],
    ]])]))]);

    $screen = Native::visit('/log')->tap('image-i1');

    expect(nodeByRef($screen, 'viewer-gestures-0')['props'])->toHaveKey('bounded')
        ->not->toHaveKey('content-ratio');
});

it('bounds a wide image by its real shape, not the one the feed caps it at', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1', 'images' => [
        ['id' => 'i1', 'url' => 'https://img.test/1', 'width' => 300, 'height' => 1200],
    ]])]))]);

    $screen = Native::visit('/log')->tap('image-i1');

    expect(nodeByRef($screen, 'viewer-gestures-0')['props']['content-ratio'])->toBe(0.25);
});

it('pages through the commit\'s images from the one the row tapped, with dashes for its place', function () {
    $screen = logWithImages(3)->tap('image-i3');

    $pager = nodeByRef($screen, 'viewer-pager');

    expect($pager['props'])->toMatchArray(['horizontal' => true, 'page' => 2, 'count' => 3])
        ->and($pager['props']['on_page_change'])->not->toBeEmpty()
        ->and(nodeByRef($screen, 'viewed-image-2')['props'])->toMatchArray(['src' => 'https://img.test/3', 'alt' => 'Attached image 3 of 3'])
        ->and(nodeByRef($screen, 'viewed-image-0')['props']['alt'])->toBe('A cat on a keyboard, image 1 of 3')
        ->and($screen->instance()->viewerIndex)->toBe(2)
        ->and(nodeByRef($screen, 'viewer-dots')['props']['a11y_label'])->toBe('Image 3 of 3')
        // The bright dash sits over the third of three, and slides rather than jumps.
        ->and(nodeByRef($screen, 'viewer-dot')['props'])->toMatchArray(['translate-x' => 22.0, 'animate-duration' => 250.0])
        // No chevrons any more: a swipe pages.
        ->and(findNode($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['viewed-next', 'viewed-previous'], true)))->toBeNull();

    $screen->changeTab('viewerPaged', 0);

    expect($screen->instance()->viewerIndex)->toBe(0)
        ->and(nodeByRef($screen, 'viewer-dots')['props']['a11y_label'])->toBe('Image 1 of 3')
        ->and(nodeByRef($screen, 'viewer-dot')['props']['translate-x'])->toBe(-22.0);
});

it('puts an image back at rest once the pager leaves it, as Photos does', function () {
    $screen = logWithImages(3)->tap('image-i1');

    $first = nodeByRef($screen, 'viewer-gestures-0')['props']['pinch-id'];
    $second = nodeByRef($screen, 'viewer-gestures-1')['props']['pinch-id'];

    $screen->pinch('viewer-gestures-0', 2.5);

    expect($screen->instance()->viewerZoomed)->toBeTrue();

    $screen->changeTab('viewerPaged', 1);

    expect($screen->instance()->viewerZoomed)->toBeFalse()
        // Fresh zoom for the one left, so it's whole again when the pager comes back to it; the one arrived at keeps its own.
        ->and(nodeByRef($screen, 'viewer-gestures-0')['props']['pinch-id'])->not->toBe($first)
        ->and(nodeByRef($screen, 'viewer-gestures-1')['props']['pinch-id'])->toBe($second)
        ->and(nodeByRef($screen, 'viewed-image-0')['props']['scale'])->toBe(1.0);
});

it('pans a zoomed image instead of paging or closing, until a pinch brings it back to its whole self', function () {
    $screen = logWithImages(2)->tap('image-i1');

    expect(nodeByRef($screen, 'viewer-dismiss')['props'])->toHaveKey('pan-y-id')
        ->toHaveKey('pan-y-snap');

    $screen->pinch('viewer-gestures-0', 3.0);

    $viewing = $screen->instance();
    $gestures = nodeByRef($screen, 'viewer-gestures-0')['props'];

    expect($gestures['pan-x-id'])->toBe($viewing->viewerPansX[0]->id)
        ->and($gestures['pan-y-id'])->toBe($viewing->viewerPansY[0]->id)
        // The page that isn't showing doesn't pan, and the drag to close lets go of the drag.
        ->and(nodeByRef($screen, 'viewer-gestures-1')['props'])->not->toHaveKey('pan-x-id')
        ->and(nodeByRef($screen, 'viewer-dismiss')['props'])->not->toHaveKey('pan-y-id')
        ->not->toHaveKey('on_drag_end');

    $screen->pinch('viewer-gestures-0', 1.0);

    expect($screen->instance()->viewerZoomed)->toBeFalse()
        ->and(nodeByRef($screen, 'viewer-gestures-0')['props'])->not->toHaveKey('pan-x-id')
        ->and(nodeByRef($screen, 'viewer-dismiss')['props'])->toHaveKey('pan-y-id');
});

it('takes a double tap\'s zoom as it takes a pinch\'s, to twice and back to its whole self', function () {
    $screen = logWithImages(2)->tap('image-i1');

    // A double tap zooms to half the most an image zooms, and says so through the pinch's end.
    expect(ImageViewer::DoubleTapZoom)->toBe(ImageViewer::MaxZoom / 2);

    $screen->pinch('viewer-gestures-0', ImageViewer::DoubleTapZoom);

    expect($screen->instance()->viewerZoomed)->toBeTrue()
        ->and(nodeByRef($screen, 'viewer-gestures-0')['props']['pan-x-id'])->toBe($screen->instance()->viewerPansX[0]->id)
        ->and(nodeByRef($screen, 'viewer-dismiss')['props'])->not->toHaveKey('pan-y-id');

    $screen->pinch('viewer-gestures-0', 1.0);

    expect($screen->instance()->viewerZoomed)->toBeFalse()
        ->and(nodeByRef($screen, 'viewer-gestures-0')['props'])->not->toHaveKey('pan-x-id')
        ->and(nodeByRef($screen, 'viewer-dismiss')['props'])->toHaveKey('pan-y-id');
});

it('grows out of the image the row was tapped in, and shrinks back into whichever image it has paged to', function () {
    $screen = logWithImages(3);

    // Each of the row's images has a ref of its own, beside the pressable's, for the viewer to grow out of.
    expect(nodeByRef($screen, 'thumb-i1')['props'])->toMatchArray(['src' => 'https://img.test/1', 'fit' => 2])
        ->and(nodeByRef($screen, 'thumb-i3')['props']['src'])->toBe('https://img.test/3');

    $screen->tap('image-i2');

    $viewing = $screen->instance();

    expect($viewing->viewerSources)->toBe(['thumb-i1', 'thumb-i2', 'thumb-i3'])
        ->and($viewing->viewerSources[$viewing->viewerIndex])->toBe('thumb-i2');

    $screen->changeTab('viewerPaged', 2);

    expect($screen->instance()->viewerSources[$screen->instance()->viewerIndex])->toBe('thumb-i3');

    $screen->dismissSheet('image-viewer');

    expect($screen->instance()->viewerSources)->toBe([]);
});

it('closes when the image is dragged far enough up or down, and springs back from a short drag', function (float $settled, bool $closes) {
    $screen = logWithImages(2)->tap('image-i1');

    $dismiss = nodeByRef($screen, 'viewer-dismiss')['props'];

    expect($dismiss['pan-y-id'])->toBe($screen->instance()->viewerDrag->id)
        ->and($dismiss['pan-y-snap'])->toBe('-240,0,240')
        // The black and the buttons fade with the drag either way.
        ->and(nodeByRef($screen, 'viewer-backdrop')['props']['opacity_sv'])->toContain('interp:0,240:1,0');

    $screen->dragEnd('viewer-dismiss', 0, $settled);

    expect($screen->instance()->viewerOpen)->toBe(! $closes);
})->with([
    'let go short' => [0.0, false],
    'dragged down' => [240.0, true],
    'flicked up' => [-240.0, true],
]);

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
    $firstPinch = nodeByRef($screen, 'viewer-gestures-1')['props']['pinch-id'];
    $firstDrag = $screen->instance()->viewerDrag->id;

    $screen->dismissSheet('image-viewer');

    $viewing = $screen->instance();

    expect($viewing->viewerOpen)->toBeFalse()
        ->and($viewing->viewerImages)->toBe([])
        ->and($viewing->viewerIndex)->toBe(0)
        ->and($viewing->viewerZooms)->toBe([])
        ->and($viewing->viewerPansX)->toBe([])
        ->and($viewing->viewerPansY)->toBe([])
        ->and($viewing->viewerDrag)->toBeNull()
        // The modal itself stays in the tree, hidden, so the phone can animate it away and say when it's gone.
        ->and(nodeByRef($screen, 'image-viewer')['props']['visible'])->toBeFalse()
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'viewer-pager'))->toBeNull();

    $screen->tap('image-i2');

    // A fresh pinch and drag, seeded at rest again, not the ones the closed viewer was using.
    expect(nodeByRef($screen, 'viewer-gestures-1')['props']['pinch-id'])->not->toBe($firstPinch)
        ->and($screen->instance()->viewerDrag->id)->not->toBe($firstDrag);
})->with(['ios', 'android']);

it('never shows or pages to an image at no web address', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1', 'images' => [
        ['id' => 'i1', 'url' => 'https://img.test/1', 'width' => 10, 'height' => 10],
        ['id' => 'i2', 'url' => 'file:///etc/passwd', 'width' => 10, 'height' => 10],
        ['id' => 'i3', 'url' => 'https://img.test/3', 'width' => 10, 'height' => 10],
    ]])]))]);

    $screen = Native::visit('/log')->tap('image-i1');

    // Its page is empty, and settling on it leaves the viewer on the image it was showing.
    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'viewed-image-1'))->toBeNull();

    $screen->changeTab('viewerPaged', 1);

    expect($screen->instance()->viewerIndex)->toBe(0);

    $shown = findNodes(nodeByRef($screen, 'image-viewer'), fn (array $node): bool => ($node['type'] ?? null) === 'image');

    expect($shown)->toHaveCount(2)
        ->and(array_map(fn (array $node): string => $node['props']['src'] ?? '', $shown))->each->toStartWith('https://');
});

it('saves the showing image to the phone\'s photos from the copy the app keeps, and says so', function () {
    $screen = logWithImages(2)->tap('image-i1');
    $screen->changeTab('viewerPaged', 1);

    $kept = ImageCache::path('https://img.test/2');
    File::ensureDirectoryExists(dirname($kept));
    File::put($kept, 'jpeg bytes');

    try {
        $this->bridge->respondTo('Photos.Save', ['success' => true]);

        $screen->tap('viewer-save')
            ->assertNativeCalled('Photos.Save', fn (array $params): bool => $params['path'] === $kept)
            ->assertSet('savingViewedImage', true);

        // A second tap while it's saving does nothing: the button is a spinner, not a button.
        expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'viewer-save' && isset($node['props']['on_press'])))->toBeNull();

        $screen->emitNative(Saved::class)
            ->assertSet('savingViewedImage', false)
            ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Saved to Photos');
    } finally {
        File::delete($kept);
    }
});

it('downloads an image the app hasn\'t kept before saving it', function () {
    Http::fake([
        'devnull.test/*' => Http::response(pageJson([imagedCommitJson(1)])),
        'img.test/*' => Http::response('png bytes', 200, ['Content-Type' => 'image/png']),
    ]);
    $this->bridge->respondTo('Photos.Save', ['success' => true]);

    $kept = ImageCache::path('https://img.test/1');
    File::delete($kept);

    try {
        Native::visit('/log')->tap('image-i1')->tap('viewer-save')
            ->assertNativeCalled('Photos.Save', fn (array $params): bool => $params['path'] === $kept);

        expect(File::get($kept))->toBe('png bytes');
    } finally {
        File::delete($kept);
    }
});

it('says why an image couldn\'t be saved', function (string $reason, string $message) {
    $this->bridge->respondTo('Photos.Save', ['success' => true]);

    $kept = ImageCache::path('https://img.test/1');
    File::ensureDirectoryExists(dirname($kept));
    File::put($kept, 'jpeg bytes');

    try {
        logWithImages()->tap('image-i1')->tap('viewer-save')
            ->emitNative(SaveFailed::class, ['reason' => $reason])
            ->assertSet('savingViewedImage', false)
            ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === $message);
    } finally {
        File::delete($kept);
    }
})->with([
    'not allowed' => ['denied', 'devnull isn\'t allowed to add to your photos. You can allow it in Settings.'],
    'anything else' => ['error', 'Could not save the image. Try again.'],
]);

it('says it couldn\'t save when the image can\'t be fetched', function () {
    Http::fake([
        'devnull.test/*' => Http::response(pageJson([imagedCommitJson(1)])),
        'img.test/*' => Http::response('nope', 404),
    ]);
    File::delete(ImageCache::path('https://img.test/1'));

    Native::visit('/log')->tap('image-i1')->tap('viewer-save')
        ->assertNativeNotCalled('Photos.Save')
        ->assertSet('savingViewedImage', false)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Could not save the image. Try again.');
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
        ->and(nodeByRef($screen, 'viewed-image-0')['props']['src'])->toBe('https://img.test/1');

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
