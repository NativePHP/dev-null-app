<?php

use App\Devnull\Client;
use App\Devnull\DownloadImages;
use App\Devnull\FetchInBackground;
use App\Devnull\ImageCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\AsyncTask;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test', 'devnull.image_cache_hours' => 24]);
    app()->useStoragePath(sys_get_temp_dir().'/devnull-image-cache-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists(ImageCache::directory());
});

afterEach(function () {
    File::deleteDirectory(storage_path());
    AsyncTask::clearFake();
});

/**
 * Puts an image on the device for a URL, downloaded this many hours ago.
 */
function cachedImage(string $url, int $hoursAgo = 0): string
{
    $path = ImageCache::path($url);
    File::put($path, 'image bytes');
    touch($path, time() - $hoursAgo * 3600);

    return $path;
}

/**
 * The app starting again: what each image is drawn from is settled afresh.
 */
function newLaunch(): void
{
    app()->forgetInstance(ImageCache::class);
}

/**
 * The URLs a dispatched DownloadImages task was given, across every dispatch.
 *
 * @return list<string>
 */
function imagesQueued(): array
{
    return collect(AsyncTask::fakeInstance()->dispatched ?? [])
        ->filter(fn (array $dispatch): bool => ($dispatch['work']['task'] ?? null) === DownloadImages::class)
        ->flatMap(fn (array $dispatch): array => $dispatch['work']['args'][0])
        ->values()
        ->all();
}

it('finds the avatars, commit images and link preview images in an answer, however deep', function () {
    $json = pageJson([
        commitJson([
            'id' => 'c1',
            'author' => userJson(['avatar_url' => 'https://avatars.githubusercontent.com/u/2?v=4']),
            'images' => [['id' => 'i1', 'url' => 'https://dev-null.sh/commit-images/i1', 'width' => 10, 'height' => 10]],
            'link_preview' => ['url' => 'https://example.com', 'image_url' => 'https://example.com/card.png'],
            'quoted_commit' => commitJson(['id' => 'q1', 'author' => userJson(['avatar_url' => 'https://avatars.githubusercontent.com/u/3?v=4'])]),
        ]),
        commitJson(['id' => 'c2', 'author' => userJson(['avatar_url' => null])]),
    ]);

    expect(ImageCache::urlsIn($json))->toContain(
        'https://avatars.githubusercontent.com/u/2?v=4',
        'https://dev-null.sh/commit-images/i1',
        'https://example.com/card.png',
        'https://avatars.githubusercontent.com/u/3?v=4',
    )->not->toContain('https://example.com');
});

it('draws an image from the device while it is younger than a day, and from its URL otherwise', function () {
    $url = 'https://avatars.githubusercontent.com/u/2?v=4';

    expect(ImageCache::src($url))->toBe($url)
        ->and(ImageCache::src(null))->toBeNull()
        ->and(ImageCache::src('/already/on/the/device.png'))->toBe('/already/on/the/device.png');

    newLaunch();
    $path = cachedImage($url, hoursAgo: 23);

    expect(ImageCache::src($url))->toBe($path);

    newLaunch();
    touch($path, time() - 25 * 3600);

    expect(ImageCache::src($url))->toBe($url);

    // With the cache off, every image comes from its URL.
    newLaunch();
    touch($path);
    config(['devnull.image_cache_hours' => 0]);

    expect(ImageCache::src($url))->toBe($url);
});

it('draws each image the way it was first drawn for as long as the app runs', function () {
    $downloaded = 'https://avatars.githubusercontent.com/u/2?v=4';
    $kept = 'https://avatars.githubusercontent.com/u/3?v=4';
    $path = cachedImage($kept, hoursAgo: 23);

    expect(ImageCache::src($downloaded))->toBe($downloaded)
        ->and(ImageCache::src($kept))->toBe($path);

    // One lands, the other ages past a day: both draw as they did, so no row showing them changes.
    cachedImage($downloaded);
    touch($path, time() - 25 * 3600);

    expect(ImageCache::src($downloaded))->toBe($downloaded)
        ->and(ImageCache::src($kept))->toBe($path);

    newLaunch();

    expect(ImageCache::src($downloaded))->toBe(ImageCache::path($downloaded))
        ->and(ImageCache::src($kept))->toBe($kept);
});

it('downloads in the background only the images that are not on the device yet, once each', function () {
    AsyncTask::fake();
    cachedImage('https://avatars.githubusercontent.com/u/2?v=4');

    ImageCache::prefetch(pageJson([
        commitJson(['id' => 'c1', 'author' => userJson(['avatar_url' => 'https://avatars.githubusercontent.com/u/2?v=4'])]),
        commitJson(['id' => 'c2', 'author' => userJson(['avatar_url' => 'https://avatars.githubusercontent.com/u/3?v=4'])]),
        commitJson(['id' => 'c3', 'author' => userJson(['avatar_url' => 'https://avatars.githubusercontent.com/u/3?v=4'])]),
    ]));

    expect(imagesQueued())->toBe(['https://avatars.githubusercontent.com/u/3?v=4']);

    // Nothing to fetch, and nothing at all with the cache off.
    AsyncTask::fake();
    ImageCache::prefetch(pageJson([commitJson(['id' => 'c1', 'author' => userJson(['avatar_url' => 'https://avatars.githubusercontent.com/u/2?v=4'])])]));
    config(['devnull.image_cache_hours' => 0]);
    ImageCache::prefetch(pageJson([commitJson(['id' => 'c2', 'author' => userJson(['avatar_url' => 'https://avatars.githubusercontent.com/u/9?v=4'])])]));

    AsyncTask::fakeInstance()->assertNotDispatched();
});

it('keeps only what downloads as an image, and clears out images older than a day when asked to', function () {
    $stale = cachedImage('https://avatars.githubusercontent.com/u/8?v=4', hoursAgo: 30);
    File::put(ImageCache::path('https://avatars.githubusercontent.com/u/9?v=4').'.part', 'half');

    Http::fake([
        'avatars.githubusercontent.com/u/2*' => Http::response('png bytes', 200, ['Content-Type' => 'image/png']),
        'dev-null.sh/commit-images/i1' => Http::response('webp bytes', 200, ['Content-Type' => 'image/webp']),
        'example.com/not-an-image' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
        'example.com/missing.png' => Http::response('', 404),
        'example.com/down.png' => fn () => throw new ConnectionException('Could not resolve host'),
    ]);

    $saved = (new DownloadImages)->handle([
        'https://avatars.githubusercontent.com/u/2?v=4',
        'https://dev-null.sh/commit-images/i1',
        'https://example.com/not-an-image',
        'https://example.com/missing.png',
        'https://example.com/down.png',
    ], ImageCache::takePrune());

    expect($saved)->toBe(2)
        ->and(File::get(ImageCache::path('https://avatars.githubusercontent.com/u/2?v=4')))->toBe('png bytes')
        ->and(File::get(ImageCache::path('https://dev-null.sh/commit-images/i1')))->toBe('webp bytes')
        ->and(File::exists(ImageCache::path('https://example.com/not-an-image')))->toBeFalse()
        ->and(File::exists(ImageCache::path('https://example.com/missing.png')))->toBeFalse()
        ->and(File::exists($stale))->toBeFalse()
        ->and(File::files(ImageCache::directory()))->toHaveCount(2);
});

it('draws the Log\'s avatars from the device from the launch after a refresh brought them down', function () {
    signIn();
    holdRefreshes();
    $avatar = 'https://avatars.githubusercontent.com/u/2?v=4';
    // Before serveApi(), whose fake answers every other URL.
    Http::fake(['avatars.githubusercontent.com/*' => Http::response('png bytes', 200, ['Content-Type' => 'image/png'])]);
    serveApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'avatar_url' => $avatar])])])]]);

    $imageSources = fn ($screen): array => array_map(
        fn (array $node): string => $node['props']['src'] ?? '',
        findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'image'),
    );

    $screen = finishRefreshes(Native::visit('/log'));

    expect(File::exists(ImageCache::path($avatar)))->toBeTrue()
        ->and($imageSources($screen))->toContain($avatar);

    // Coming back to the Log the same launch, it's drawn as it was.
    expect($imageSources(Native::visit('/log')))->toContain($avatar);

    newLaunch();

    expect($imageSources(Native::visit('/log')))->toContain(ImageCache::path($avatar))
        ->not->toContain($avatar);
});

it('downloads the images in an answer the app waited for, too', function () {
    signIn();
    AsyncTask::fake();
    serveApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => userJson(['avatar_url' => 'https://avatars.githubusercontent.com/u/4?v=4'])])])]]);

    app(Client::class)->log('following');

    expect(imagesQueued())->toBe(['https://avatars.githubusercontent.com/u/4?v=4']);
});

it('clears out the image folder once a launch, in the first task that downloads images', function () {
    $this->freezeTime();
    AsyncTask::fake();
    $answer = fn (int $user): array => pageJson([commitJson(['author' => userJson(['avatar_url' => "https://avatars.githubusercontent.com/u/{$user}?v=4"])])]);
    $prunes = fn (): array => array_map(fn (array $dispatch): mixed => $dispatch['work']['args'][1], AsyncTask::fakeInstance()->dispatched);

    ImageCache::prefetch($answer(2));
    ImageCache::prefetch($answer(3));

    expect($prunes())->toBe([now()->getTimestamp() - 24 * 3600, null]);

    newLaunch();
    AsyncTask::fake();
    ImageCache::prefetch($answer(4));

    expect($prunes())->toBe([now()->getTimestamp() - 24 * 3600]);

    // Never with the cache off.
    newLaunch();
    config(['devnull.image_cache_hours' => 0]);

    expect(ImageCache::takePrune())->toBeNull();
});

it('clears out only the images that were too old when the app started, and half-written ones', function () {
    $before = ImageCache::takePrune();
    $stale = cachedImage('https://avatars.githubusercontent.com/u/8?v=4', hoursAgo: 30);
    $fresh = cachedImage('https://avatars.githubusercontent.com/u/2?v=4', hoursAgo: 23);
    $half = ImageCache::path('https://avatars.githubusercontent.com/u/9?v=4').'.part';
    File::put($half, 'half');

    DownloadImages::prune($before);

    expect(File::exists($stale))->toBeFalse()
        ->and(File::exists($half))->toBeFalse()
        ->and(File::exists($fresh))->toBeTrue();

    // Nothing to clear out on a device that has never kept an image.
    File::deleteDirectory(ImageCache::directory());
    DownloadImages::prune($before);

    expect(File::isDirectory(ImageCache::directory()))->toBeFalse();
});

it('brings an answer\'s images down with it when asked, before it comes back', function () {
    Http::fake([
        'avatars.githubusercontent.com/*' => Http::response('png bytes', 200, ['Content-Type' => 'image/png']),
        'devnull.test/api/v1/log/following*' => Http::response(pageJson([commitJson(['author' => userJson(['avatar_url' => 'https://avatars.githubusercontent.com/u/2?v=4'])])])),
        'devnull.test/api/v1/broken' => Http::response(['https://avatars.githubusercontent.com/u/3?v=4']),
    ]);

    (new FetchInBackground)->handle('http://devnull.test/api/v1/log/following', []);

    expect(File::exists(ImageCache::path('https://avatars.githubusercontent.com/u/2?v=4')))->toBeFalse();

    $result = (new FetchInBackground)->handle('http://devnull.test/api/v1/log/following?cursor=page2', [], 'GET', '', true);

    expect($result['status'])->toBe(200)
        ->and($result['from_devnull'])->toBeTrue()
        ->and(File::get(ImageCache::path('https://avatars.githubusercontent.com/u/2?v=4')))->toBe('png bytes');

    // Only devnull's own answers.
    (new FetchInBackground)->handle('http://devnull.test/api/v1/broken', [], 'GET', '', true);

    expect(File::exists(ImageCache::path('https://avatars.githubusercontent.com/u/3?v=4')))->toBeFalse();
});

it('gives images it waits on only the time that is left, and starts none once it is up', function () {
    Http::fake(['avatars.githubusercontent.com/*' => Http::response('png bytes', 200, ['Content-Type' => 'image/png'])]);

    expect(DownloadImages::save(['https://avatars.githubusercontent.com/u/2?v=4'], until: microtime(true) - 1))->toBe(0);

    Http::assertNothingSent();

    expect(DownloadImages::save(['https://avatars.githubusercontent.com/u/2?v=4'], until: microtime(true) + FetchInBackground::ImagesWithin))->toBe(1);
});
