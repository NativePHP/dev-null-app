<?php

use App\Devnull\Data\Embed;
use App\Devnull\Data\LinkPreview;

/**
 * The embed devnull sends for https://youtu.be/E9Zx6rx0bWk.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function youTubeEmbedJson(array $overrides = []): array
{
    return [
        'provider' => 'YouTube',
        'kind' => 'video',
        'src' => 'https://www.youtube-nocookie.com/embed/E9Zx6rx0bWk?autoplay=1',
        'aspect_ratio' => '16 / 9',
        'height' => null,
        'poster' => 'https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg',
        ...$overrides,
    ];
}

it("reads devnull's embed", function () {
    $embed = Embed::fromArray(youTubeEmbedJson());

    expect($embed)->not->toBeNull()
        ->and($embed->provider)->toBe('YouTube')
        ->and($embed->kind)->toBe('video')
        ->and($embed->src)->toBe('https://www.youtube-nocookie.com/embed/E9Zx6rx0bWk?autoplay=1')
        ->and($embed->aspectRatio)->toEqualWithDelta(16 / 9, 0.0001)
        ->and($embed->poster)->toBe('https://i.ytimg.com/vi/E9Zx6rx0bWk/hqdefault.jpg')
        ->and($embed->height)->toBeNull()
        ->and($embed->title)->toBeNull()
        ->and($embed->author)->toBeNull()
        ->and($embed->isYouTube())->toBeTrue();
});

it('reads the aspect ratio', function (mixed $ratio, float $expected) {
    expect(Embed::fromArray(youTubeEmbedJson(['aspect_ratio' => $ratio]))->aspectRatio)->toEqualWithDelta($expected, 0.0001);
})->with([
    'vertical' => ['9 / 16', 9 / 16],
    'no spaces' => ['4/3', 4 / 3],
    'empty' => ['', 16 / 9],
    'not a ratio' => ['abc', 16 / 9],
    'zero height' => ['16 / 0', 16 / 9],
    'missing' => [null, 16 / 9],
]);

it('takes a title and channel when devnull sends them', function () {
    $embed = Embed::fromArray(youTubeEmbedJson(['title' => 'T', 'author' => 'NativePHP']));
    $blank = Embed::fromArray(youTubeEmbedJson(['title' => ' ', 'author' => '']));

    expect($embed->title)->toBe('T')
        ->and($embed->author)->toBe('NativePHP')
        ->and($blank->title)->toBeNull()
        ->and($blank->author)->toBeNull();
});

it('has no embed without an https src', function (mixed $src) {
    expect(Embed::fromArray(youTubeEmbedJson(['src' => $src])))->toBeNull();
})->with([
    'missing' => [null],
    'empty' => [''],
    'not https' => ['http://www.youtube-nocookie.com/embed/E9Zx6rx0bWk'],
]);

it("knows other providers aren't YouTube", function () {
    expect(Embed::fromArray(youTubeEmbedJson(['provider' => 'youtube']))->isYouTube())->toBeTrue()
        ->and(Embed::fromArray(youTubeEmbedJson(['provider' => 'Vimeo']))->isYouTube())->toBeFalse();
});

it("reads a link preview's embed", function () {
    $preview = ['url' => 'https://youtu.be/E9Zx6rx0bWk', 'title' => '- YouTube'];

    expect(LinkPreview::fromArray([...$preview, 'embed' => youTubeEmbedJson()])->embed)->toBeInstanceOf(Embed::class)
        ->and(LinkPreview::fromArray($preview)->embed)->toBeNull()
        ->and(LinkPreview::fromArray([...$preview, 'embed' => 'nope'])->embed)->toBeNull();
});
