<?php

use App\Devnull\VideoDetails;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A log with one commit linking to the video, whose preview devnull gave a
 * player with these changes.
 *
 * @param  array<string, mixed>  $embed
 */
function logWithVideo(array $embed = []): TestableComponent
{
    Http::fake(['www.youtube.com/oembed*' => Http::response(null, 404), 'devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'body' => 'Watch https://www.youtube.com/watch?v=E9Zx6rx0bWk',
        'link_preview' => [
            'url' => 'https://youtu.be/E9Zx6rx0bWk',
            'title' => '- YouTube',
            'description' => 'Enjoy the videos and music you love.',
            'image_url' => null,
            'site_name' => null,
            'embed' => [
                'provider' => 'YouTube',
                'kind' => 'video',
                'src' => 'https://www.youtube-nocookie.com/embed/E9Zx6rx0bWk?autoplay=1',
                'aspect_ratio' => '16 / 9',
                'height' => null,
                'poster' => 'https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg',
                ...$embed,
            ],
        ],
    ])]))]);

    return Native::visit('/log');
}

/**
 * @param  array<string, mixed>  $node
 * @return list<array<string, mixed>>
 */
function imagesIn(array $node): array
{
    return findNodes($node, fn (array $child): bool => $child['type'] === 'image');
}

it("draws a video's poster with a play button, and its title and channel under it", function () {
    VideoDetails::remember('https://youtu.be/E9Zx6rx0bWk', 'EXCLUSIVE LOOK: The next version of the /dev/null apps for #iOS and #Android', 'NativePHP');

    $screen = logWithVideo()
        ->assertSee('YouTube')
        ->assertSee('EXCLUSIVE LOOK: The next version of the /dev/null apps for #iOS and #Android')
        ->assertSee('NativePHP')
        ->assertDontSee('- YouTube')
        ->assertDontSee('Enjoy the videos and music you love.');

    $play = nodeByRef($screen, 'embed-play-c1');

    expect($play['props']['a11y_label'])->toBe('Play EXCLUSIVE LOOK: The next version of the /dev/null apps for #iOS and #Android from YouTube')
        ->and(array_column(array_column(imagesIn($play), 'props'), 'src'))->toBe(['https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg']);
});

it("shows a video's title and channel once the lookup lands, on a row the screen already drew", function () {
    holdRefreshes();
    Http::fake([
        'www.youtube.com/oembed*' => Http::response(['title' => 'Landed Title', 'author_name' => 'NativePHP']),
        'devnull.test/*' => Http::response(pageJson([commitJson([
            'id' => 'c1',
            'created_at' => now()->subDays(3)->toIso8601ZuluString(),
            'link_preview' => [
                'url' => 'https://youtu.be/E9Zx6rx0bWk',
                'title' => '- YouTube',
                'embed' => ['provider' => 'YouTube', 'kind' => 'video', 'src' => 'https://www.youtube-nocookie.com/embed/E9Zx6rx0bWk?autoplay=1'],
            ],
        ])])),
    ]);

    finishRefreshes(Native::visit('/log'))
        ->assertSee('Landed Title')
        ->assertSee('NativePHP');
});

it('names just the provider until it knows the title', function () {
    $screen = logWithVideo()->assertDontSee('- YouTube');

    expect(nodeByRef($screen, 'embed-play-c1')['props']['a11y_label'])->toBe('Play video from YouTube');
});

it('plays the video in the in-app browser', function () {
    logWithVideo()
        ->tap('embed-play-c1')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://youtu.be/E9Zx6rx0bWk')
        ->assertNoNavigation();
});

it("opens the video's link from the row under it", function () {
    logWithVideo()
        ->tap('link-preview-c1')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://youtu.be/E9Zx6rx0bWk');
});

it("draws YouTube's thumbnail for a playlist devnull has no poster for", function () {
    VideoDetails::remember('https://youtu.be/E9Zx6rx0bWk', 'Omarchy', 'nunomaduro', 'https://i.ytimg.com/vi/mHrABR-IhxM/hqdefault.jpg');

    $screen = logWithVideo(['src' => 'https://www.youtube-nocookie.com/embed/videoseries?list=PLbocgb58uTiU', 'poster' => null]);

    expect(array_column(array_column(imagesIn(nodeByRef($screen, 'embed-play-c1')), 'props'), 'src'))->toBe(['https://i.ytimg.com/vi/mHrABR-IhxM/hqdefault.jpg']);
});

it("draws a black box with the play button when there's no poster", function () {
    $screen = logWithVideo(['poster' => null]);

    expect(imagesIn(nodeByRef($screen, 'embed-play-c1')))->toBe([]);
});

it('keeps the compact row for players other than YouTube', function (string $provider, string $kind) {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'link_preview' => [
            'url' => 'https://example.com/thing',
            'title' => 'Chill video game focus soundtracks',
            'description' => 'Playlist · Pauline Vos · 819 items · 5 saves',
            'image_url' => 'https://example.com/art.jpg',
            'site_name' => $provider,
            'embed' => ['provider' => $provider, 'kind' => $kind, 'src' => 'https://example.com/embed/thing'],
        ],
    ])]))]);

    $screen = Native::visit('/log')
        ->assertSee('Chill video game focus soundtracks')
        ->assertSee('Playlist · Pauline Vos · 819 items · 5 saves');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'embed-play-c1'))->toBeNull()
        ->and(nodeByRef($screen, 'link-preview-c1'))->not->toBeEmpty();
})->with([
    'Spotify' => ['Spotify', 'audio'],
    'Vimeo' => ['Vimeo', 'video'],
    'CodePen' => ['CodePen', 'code'],
]);

it('keeps the compact row for links without a player', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'link_preview' => ['url' => 'https://nativephp.com', 'title' => 'NativePHP', 'description' => null, 'image_url' => null, 'site_name' => 'NativePHP'],
    ])]))]);

    $screen = Native::visit('/log')->assertSee('NativePHP');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'embed-play-c1'))->toBeNull()
        ->and(nodeByRef($screen, 'link-preview-c1'))->not->toBeEmpty();
});

it("shows a video as a compact link row with no picture under the commit's own images", function () {
    Http::fake(['www.youtube.com/oembed*' => Http::response(null, 404), 'devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'body' => 'Watch https://www.youtube.com/watch?v=E9Zx6rx0bWk',
        'images' => [['id' => 7, 'width' => 400, 'height' => 300, 'description' => null, 'url' => 'https://img.test/7']],
        'link_preview' => [
            'url' => 'https://youtu.be/E9Zx6rx0bWk',
            'title' => 'The episode',
            'description' => null,
            'image_url' => 'https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg',
            'site_name' => 'YouTube',
            'embed' => [
                'provider' => 'YouTube',
                'kind' => 'video',
                'src' => 'https://www.youtube-nocookie.com/embed/E9Zx6rx0bWk?autoplay=1',
                'aspect_ratio' => '16 / 9',
                'height' => null,
                'poster' => 'https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg',
            ],
        ],
    ])]))]);

    $screen = Native::visit('/log')->assertSee('The episode');

    $screen->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'embed-play-c1');

    expect(imagesIn(nodeByRef($screen, 'link-preview-c1')))->toBe([])
        ->and(array_column(array_column(imagesIn(nodeByRef($screen, 'image-7')), 'props'), 'src'))->toHaveCount(1);
});

it("leaves a link preview's picture out under the commit's own images", function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'body' => 'Read https://example.com/post',
        'images' => [['id' => 7, 'width' => 400, 'height' => 300, 'description' => null, 'url' => 'https://img.test/7']],
        'link_preview' => ['url' => 'https://example.com/post', 'title' => 'The post', 'description' => null, 'image_url' => 'https://example.com/og.png', 'site_name' => null],
    ])]))]);

    $screen = Native::visit('/log')->assertSee('The post');

    expect(imagesIn(nodeByRef($screen, 'link-preview-c1')))->toBe([]);
});

it("names the video and its channel in the compact row under the commit's own images", function () {
    VideoDetails::remember('https://youtu.be/bod7JDMeF-0', 'Side Projects Got Me Hired', 'Shruti Balasa');

    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'body' => 'Watch https://youtu.be/bod7JDMeF-0',
        'images' => [['id' => 7, 'width' => 400, 'height' => 300, 'description' => null, 'url' => 'https://img.test/7']],
        'link_preview' => [
            'url' => 'https://youtu.be/bod7JDMeF-0',
            'title' => '- YouTube',
            'description' => 'Enjoy the videos and music you love, upload original content.',
            'image_url' => null,
            'site_name' => 'YouTube',
            'embed' => [
                'provider' => 'YouTube',
                'kind' => 'video',
                'src' => 'https://www.youtube-nocookie.com/embed/bod7JDMeF-0?autoplay=1',
                'aspect_ratio' => '16 / 9',
                'height' => null,
                'poster' => null,
            ],
        ],
    ])]))]);

    Native::visit('/log')
        ->assertSee('Side Projects Got Me Hired')
        ->assertSee('Shruti Balasa')
        ->assertDontSee('- YouTube')
        ->assertDontSee('Enjoy the videos and music you love');
});
