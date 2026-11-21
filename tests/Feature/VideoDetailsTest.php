<?php

use App\Devnull\Client;
use App\Devnull\Data\LinkPreview;
use App\Devnull\FetchVideoDetails;
use App\Devnull\VideoDetails;
use Illuminate\Support\Facades\Http;
use Native\Mobile\AsyncTask;

const VideoUrl = 'https://youtu.be/E9Zx6rx0bWk';

const VideoTitle = 'EXCLUSIVE LOOK: The next version of the /dev/null apps for #iOS and #Android';

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
});

/**
 * A link preview for the video, as devnull sends it, with these changes to its embed.
 *
 * @param  array<string, mixed>  $embed
 * @param  array<string, mixed>  $preview
 * @return array<string, mixed>
 */
function videoPreviewJson(array $embed = [], array $preview = []): array
{
    return [
        'url' => VideoUrl,
        'title' => '- YouTube',
        'embed' => [
            'provider' => 'YouTube',
            'kind' => 'video',
            'src' => 'https://www.youtube-nocookie.com/embed/E9Zx6rx0bWk?autoplay=1',
            'aspect_ratio' => '16 / 9',
            'poster' => 'https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg',
            ...$embed,
        ],
        ...$preview,
    ];
}

/**
 * @param  array<string, mixed>  $embed
 * @param  array<string, mixed>  $preview
 */
function videoPreview(array $embed = [], array $preview = []): LinkPreview
{
    return LinkPreview::fromArray(videoPreviewJson($embed, $preview));
}

function fakeOEmbed(int $status = 200): void
{
    Http::fake(['www.youtube.com/oembed*' => Http::response($status === 200 ? [
        'title' => VideoTitle,
        'author_name' => 'NativePHP',
        'thumbnail_url' => 'https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg',
        'author_url' => 'https://www.youtube.com/@NativePHPOfficial',
        'provider_name' => 'YouTube',
    ] : null, $status)]);
}

/**
 * The video links a dispatched FetchVideoDetails task was given, across every dispatch.
 *
 * @return list<string>
 */
function videosQueued(): array
{
    return collect(AsyncTask::fakeInstance()->dispatched ?? [])
        ->filter(fn (array $dispatch): bool => ($dispatch['work']['task'] ?? null) === FetchVideoDetails::class)
        ->flatMap(fn (array $dispatch): array => $dispatch['work']['args'][0])
        ->values()
        ->all();
}

it("finds the YouTube videos it hasn't looked up yet", function () {
    $json = pageJson([
        commitJson(['id' => 'c1', 'link_preview' => videoPreviewJson()]),
        commitJson(['id' => 'c2', 'link_preview' => videoPreviewJson(['title' => 'From devnull'], ['url' => 'https://youtu.be/other'])]),
        commitJson(['id' => 'c3', 'link_preview' => ['url' => 'https://nativephp.com', 'title' => 'NativePHP']]),
        commitJson(['id' => 'c4', 'link_preview' => videoPreviewJson(['provider' => 'Vimeo'], ['url' => 'https://vimeo.com/1'])]),
        commitJson(['id' => 'c5', 'quoted_commit' => commitJson(['id' => 'q1', 'link_preview' => videoPreviewJson()])]),
    ]);

    expect(VideoDetails::missingIn($json))->toBe([VideoUrl]);
});

it("looks up a video's title and channel", function () {
    fakeOEmbed();

    expect(FetchVideoDetails::save([VideoUrl]))->toBe(1)
        ->and(VideoDetails::card(videoPreview()))->toBe(['provider' => 'YouTube', 'title' => VideoTitle, 'author' => 'NativePHP', 'poster' => 'https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg'])
        ->and(VideoDetails::missingIn(pageJson([commitJson(['link_preview' => videoPreviewJson()])])))->toBe([]);

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://www.youtube.com/oembed')
        && $request['url'] === VideoUrl
        && $request['format'] === 'json');
});

it('prefers the title and channel devnull sends', function () {
    Http::fake();

    VideoDetails::prefetch(pageJson([commitJson(['link_preview' => videoPreviewJson(['title' => 'From devnull', 'author' => 'Chan'])])]));

    expect(VideoDetails::card(videoPreview(['title' => 'From devnull', 'author' => 'Chan'])))
        ->toBe(['provider' => 'YouTube', 'title' => 'From devnull', 'author' => 'Chan', 'poster' => 'https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg'])
        ->and(videosQueued())->toBe([]);

    Http::assertNothingSent();
});

it('never shows the scraped "- YouTube" title', function () {
    expect(VideoDetails::card(videoPreview()))->toBe(['provider' => 'YouTube', 'title' => null, 'author' => null, 'poster' => 'https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg'])
        ->and(VideoDetails::card(videoPreview(preview: ['title' => 'Talk - YouTube']))['title'])->toBe('Talk')
        ->and(VideoDetails::card(videoPreview(preview: ['title' => null]))['title'])->toBeNull();
});

it('remembers a failed lookup for an hour', function () {
    $this->freezeTime();
    fakeOEmbed(404);
    $json = pageJson([commitJson(['link_preview' => videoPreviewJson()])]);

    expect(FetchVideoDetails::save([VideoUrl]))->toBe(0)
        ->and(VideoDetails::card(videoPreview())['title'])->toBeNull()
        ->and(VideoDetails::missingIn($json))->toBe([]);

    $this->travel(61)->minutes();

    expect(VideoDetails::missingIn($json))->toBe([VideoUrl]);
});

it("uses YouTube's thumbnail when devnull has no poster, as for a playlist", function () {
    Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'Omarchy', 'author_name' => 'nunomaduro', 'thumbnail_url' => 'https://i.ytimg.com/vi/mHrABR-IhxM/hqdefault.jpg'])]);
    $playlist = ['url' => 'https://www.youtube.com/playlist?list=PLbocgb58uTiU'];

    FetchVideoDetails::save([$playlist['url']]);

    expect(VideoDetails::card(videoPreview(['src' => 'https://www.youtube-nocookie.com/embed/videoseries?list=PLbocgb58uTiU', 'poster' => null], $playlist)))
        ->toBe(['provider' => 'YouTube', 'title' => 'Omarchy', 'author' => 'nunomaduro', 'poster' => 'https://i.ytimg.com/vi/mHrABR-IhxM/hqdefault.jpg'])
        ->and(VideoDetails::card(videoPreview(preview: $playlist))['poster'])->toBe('https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg');
});

it("prefers the preview's own image to YouTube's thumbnail when devnull has no poster", function () {
    Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'Programming', 'author_name' => 'VIPUL WALIA', 'thumbnail_url' => 'https://i.ytimg.com/vi/jxptIpCYAJA/hqdefault.jpg'])]);
    $playlist = ['url' => 'https://www.youtube.com/playlist?list=PL_FKmhByIzDYyl8nJGsrzzNczKfLjgmZy', 'image_url' => 'https://i.ytimg.com/vi/jxptIpCYAJA/hqdefault.jpg?sqp=card'];
    $preview = videoPreview(['src' => 'https://www.youtube-nocookie.com/embed/videoseries?list=PL_FKmhByIzDYyl8nJGsrzzNczKfLjgmZy', 'poster' => null], $playlist);

    expect(VideoDetails::card($preview)['poster'])->toBe('https://i.ytimg.com/vi/jxptIpCYAJA/hqdefault.jpg?sqp=card');

    FetchVideoDetails::save([$playlist['url']]);

    expect(VideoDetails::card($preview)['poster'])->toBe('https://i.ytimg.com/vi/jxptIpCYAJA/hqdefault.jpg?sqp=card');
});

it('ignores a thumbnail that isn\'t https', function () {
    Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'T', 'thumbnail_url' => 'http://i.ytimg.com/vi/x/hqdefault.jpg'])]);

    FetchVideoDetails::save([VideoUrl]);

    expect(VideoDetails::card(videoPreview(['poster' => null]))['poster'])->toBeNull();
});

it('prefetches videos with the page', function () {
    signIn();
    serveApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'link_preview' => videoPreviewJson()])])]]);

    app(Client::class)->log('following');

    expect(videosQueued())->toBe([VideoUrl]);
});
