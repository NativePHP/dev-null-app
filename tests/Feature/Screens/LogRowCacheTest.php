<?php

use App\Devnull\Account;
use App\Devnull\Data\Pasteis;
use App\Devnull\Data\StashedDraft;
use App\Devnull\ImageCache;
use App\Devnull\Stash;
use App\NativeComponents\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test', 'devnull.image_cache_hours' => 24]);
    app()->useStoragePath(sys_get_temp_dir().'/devnull-row-cache-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists(ImageCache::directory());
    signIn();

    $grace = userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace']);
    $long = str_repeat('A commit long enough to fold. ', 12).'The very end.';

    fakeApi([
        'GET /api/v1/log/following' => [pageJson([
            commitJson(['id' => 'c3', 'body' => $long, 'preview_segments' => [['type' => 'text', 'text' => substr($long, 0, 270).'…']]]),
            commitJson(['id' => 'c2', 'replies_count' => 1, 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'avatar_url' => 'https://avatars.githubusercontent.com/u/2?v=4'])]),
            commitJson(['id' => 'c1', 'body' => 'The oldest commit', 'author' => $grace]),
        ])],
        'GET /api/v1/commits/c2/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'A reply in place'])])],
        'POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'body' => 'The oldest commit', 'author' => $grace, 'stars_count' => 1, 'starred' => true])]],
    ]);
});

afterEach(function () {
    File::deleteDirectory(storage_path());
});

/**
 * Draws the log again with every row built afresh, and expects the frame it drew
 * from the rows it kept, which has to differ from the frame before the change.
 *
 * @param  array<string, mixed>  $before
 */
function expectKeptRowsToMatchFreshOnes(TestableComponent $screen, array $before): void
{
    $kept = $screen->tree();
    expect($kept)->not->toBe($before);

    Closure::bind(fn () => $this->rowCaches = [], $screen->instance(), Log::class)();

    expect($screen->call('isRanked')->tree())->toBe($kept);
}

it('draws a kept row as building it again would, whatever changed', function () {
    $screen = Native::visit('/log')->assertSee('5m');
    $changes = [
        'a star' => fn () => $screen->tap('star-c1'),
        'the time' => function () use ($screen) {
            $this->travel(2)->minutes();
            $screen->call('isRanked')->assertSee('7m');
        },
        'a commit unfolded' => fn () => $screen->tap('show-more-c3'),
        'a thread opened' => fn () => $screen->tap('replies-c2')->assertSee('A reply in place'),
        'a draft stashed' => function () use ($screen) {
            app(Stash::class)->put(app(Account::class)->user()?->id, 'reply-c1', new StashedDraft('Half a reply'));
            $screen->call('isRanked');
        },
        'pastéis mode' => function () use ($screen) {
            app(Account::class)->rememberPasteis(new Pasteis);
            $screen->call('isRanked');
        },
    ];

    foreach ($changes as $change) {
        $before = $screen->tree();
        $change();
        expectKeptRowsToMatchFreshOnes($screen, $before);
    }
});

it('leaves a row as it was drawn when one of its images downloads', function () {
    $built = [];
    View::composer('native.partials.commit-row', function ($view) use (&$built) {
        $built[] = $view->getData()['commit']->id;
    });

    $screen = Native::visit('/log');
    $before = $screen->tree();
    $built = [];

    File::put(ImageCache::path('https://avatars.githubusercontent.com/u/2?v=4'), 'png bytes');

    expect($screen->call('isRanked')->tree())->toBe($before)
        ->and($built)->toBe([]);
});

it('only builds the rows that changed since the last frame', function () {
    $built = [];
    View::composer(['native.partials.commit-row', 'native.partials.thread-reply'], function ($view) use (&$built) {
        $built[] = ($view->getData()['reply'] ?? $view->getData()['commit'])->id;
    });

    $screen = Native::visit('/log')->tap('replies-c2');
    $built = [];

    $screen->call('isRanked');
    expect($built)->toBe([]);

    $screen->tap('star-c1');
    expect($built)->toBe(['c1']);
});
