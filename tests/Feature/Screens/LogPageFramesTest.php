<?php

use App\Devnull\ImageCache;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskRunner;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    // Every commit was made at 11:55, as devnull keeps it, however late a page is asked for.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    config(['devnull.url' => 'http://devnull.test', 'devnull.image_cache_hours' => 24]);
    app()->useStoragePath(sys_get_temp_dir().'/devnull-log-frames-'.bin2hex(random_bytes(4)));
    signIn();
    holdRefreshes();

    // Before serveApi(), whose fake answers every other URL.
    Http::fake([
        'avatars.githubusercontent.com/*' => Http::response('png bytes', 200, ['Content-Type' => 'image/png']),
        'dev-null.sh/commit-images/*' => Http::response('webp bytes', 200, ['Content-Type' => 'image/webp']),
    ]);

    // Five pages of 20 commits, each by its own author, with an image on the first commit of each.
    serveApi(function (Request $request) {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1/'));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) substr($query['cursor'] ?? 'page1', 4);

        return match ($path) {
            'me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
            'log/following' => Http::response(pageJson(logPageCommits($page), $page < 5 ? 'page'.($page + 1) : null)),
            default => Http::response(['message' => "No fake for {$path}."], 404),
        };
    });
});

afterEach(function () {
    File::deleteDirectory(storage_path());
});

/**
 * A page of the log, as devnull sends it.
 *
 * @return list<array<string, mixed>>
 */
function logPageCommits(int $page): array
{
    return array_map(fn (int $n): array => commitJson([
        'id' => "p{$page}-{$n}",
        'created_at' => '2026-10-21T11:55:00Z',
        'author' => userJson(['id' => "u{$page}-{$n}", 'username' => "author{$page}x{$n}", 'avatar_url' => "https://avatars.githubusercontent.com/u/{$page}0{$n}?v=4"]),
        'images' => $n === 1 ? [['id' => "i{$page}", 'url' => "https://dev-null.sh/commit-images/i{$page}", 'width' => 10, 'height' => 10]] : [],
    ]), range(1, 20));
}

/**
 * Runs the background tasks held so far, and any they lead to, as
 * finishRefreshes() does, and names each one as it lands.
 *
 * @return list<string>
 */
function landBackgroundTasks(TestableComponent $screen): array
{
    $landed = [];

    do {
        $ran = false;

        foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
            $payload = AsyncTaskTransport::readPayload($call['params']['id']);

            if ($payload === null) {
                continue;
            }

            $ran = true;
            $work = unserialize($payload);
            $landed[] = class_basename($work['task']);
            $screen->emitNative(AsyncTaskFinished::class, ['id' => $call['params']['id'], 'result' => AsyncTaskRunner::normalizeResult(AsyncTaskRunner::invoke($work))]);
        }
    } while ($ran);

    return $landed;
}

/**
 * The sources of every image the log you're on draws.
 *
 * @return list<string>
 */
function drawnImageSources(TestableComponent $screen): array
{
    return array_map(
        fn (array $node): string => $node['props']['src'] ?? '',
        findNodes(showingLog($screen), fn (array $node): bool => ($node['type'] ?? null) === 'image'),
    );
}

it('renders twice a page as you scroll, and the page after it landing leaves the frame as it was', function () {
    $screen = Native::visit('/log');

    // The first page's images download in a task of their own, and its next page's come with it.
    expect(landBackgroundTasks($screen))->toContain('DownloadImages', 'FetchInBackground');

    $pages = [];

    foreach ([2, 3, 4] as $page) {
        $frames = $screen->renderCount();

        // Near the end: the list's report of the rows on screen puts the held page in, and asks for the next.
        $screen->call('showRows', 10_000, 10_005);
        $drawn = $screen->tree();

        $pages[$page] = [
            'landed' => landBackgroundTasks($screen),
            'frames' => $screen->renderCount() - $frames,
            'changed' => $screen->tree() !== $drawn,
        ];
    }

    expect($pages)->each->toBe(['landed' => ['FetchInBackground'], 'frames' => 2, 'changed' => false])
        ->and($screen->instance()->commits)->toHaveCount(80);
});

it('draws the rows of a page fetched ahead from the device from the start', function () {
    $screen = Native::visit('/log');
    landBackgroundTasks($screen);

    $sources = drawnImageSources($screen->call('showRows', 10_000, 10_005));

    expect($sources)
        ->toContain(ImageCache::path('https://avatars.githubusercontent.com/u/201?v=4'))
        ->toContain(ImageCache::path('https://dev-null.sh/commit-images/i2'))
        ->not->toContain('https://avatars.githubusercontent.com/u/201?v=4')
        // The first page was drawn before its images came, and stays as it was drawn.
        ->toContain('https://avatars.githubusercontent.com/u/101?v=4');
});

it('draws the frame a page lands in at the time of the frame before it, so a minute ticking over in between changes nothing', function () {
    $screen = Native::visit('/log');
    landBackgroundTasks($screen);

    $screen->call('showRows', 10_000, 10_005);
    $drawn = $screen->tree();

    expect(treeShows(showingLog($screen), '5m'))->toBeTrue();

    $this->travel(61)->seconds();

    expect(landBackgroundTasks($screen))->toBe(['FetchInBackground'])
        ->and($screen->tree())->toBe($drawn);

    // The next report of the rows on screen draws at the time it comes.
    expect(treeShows(showingLog($screen->call('showRows', 10_000, 10_005)), '6m'))->toBeTrue();
});

it('keeps the frame as it was while the list moves, times and all, until it comes to rest', function () {
    $screen = Native::visit('/log');
    landBackgroundTasks($screen);

    // Near the end, the held page goes in, and the one after it is on its way.
    $screen->call('showRows', 10_000, 10_005);
    $drawn = $screen->tree();

    $this->travel(61)->seconds();

    // Back up the list, with no page here and the rows drawn reaching well past the screen, then the poll.
    $screen->call('showRows', 0, 5)->firePoll('checkForNewCommits');
    $unchanged = $screen->tree() === $drawn;

    expect($unchanged)->toBeTrue()
        ->and(treeShows(showingLog($screen->call('scrollEnded', 0, 5)), '6m'))->toBeTrue();
});

it('still brings the times up to date on the poll, with nothing new to show', function () {
    $screen = Native::visit('/log');
    landBackgroundTasks($screen);

    $this->travel(61)->seconds();
    $screen->firePoll('checkForNewCommits');

    expect(treeShows(showingLog($screen), '6m'))->toBeTrue()
        ->and(treeShows(showingLog($screen), '5m'))->toBeFalse();

    // What the poll asked lands with nothing new, and the frame stays as the poll drew it.
    $drawn = $screen->tree();
    $this->travel(61)->seconds();
    landBackgroundTasks($screen);

    expect($screen->tree())->toBe($drawn);
});
