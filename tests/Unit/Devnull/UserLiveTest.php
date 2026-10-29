<?php

use App\Devnull\Data\LivePlatform;
use App\Devnull\Data\User;
use Carbon\CarbonImmutable;

beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28T17:00:00Z')));

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  array<string, mixed>|null  $live
 */
function streamer(?array $live): User
{
    return User::fromArray(['id' => 'u1', 'handle' => 'alice', 'live' => $live]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{platform: string, url: string, thumbnail_url: string, ends_at: string}
 */
function stream(array $overrides = []): array
{
    return [
        'platform' => 'twitch',
        'url' => 'https://www.twitch.tv/alice',
        'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_alice-1280x720.jpg',
        'ends_at' => '2026-10-28T18:00:00Z',
        ...$overrides,
    ];
}

it('reads the stream a user is live on', function () {
    $live = streamer(stream())->live;

    expect($live->platform)->toBe(LivePlatform::Twitch)
        ->and($live->url)->toBe('https://www.twitch.tv/alice')
        ->and($live->thumbnailUrl)->toBe('https://static-cdn.jtvnw.net/previews-ttv/live_user_alice-1280x720.jpg')
        ->and($live->endsAt->toIso8601ZuluString())->toBe('2026-10-28T18:00:00Z');
});

it('is live until the stream ends', function (string $endsAt, bool $isLive) {
    expect(streamer(stream(['ends_at' => $endsAt]))->isLive())->toBe($isLive);
})->with([
    'ends later' => ['2026-10-28T18:00:00Z', true],
    'ended' => ['2026-10-28T16:59:59Z', false],
]);

it('is not live without a stream devnull can show', function (mixed $live) {
    $user = User::fromArray(['id' => 'u1', 'handle' => 'alice', 'live' => $live]);

    expect($user->live)->toBeNull()
        ->and($user->isLive())->toBeFalse();
})->with([
    'none' => [null],
    'not an object' => ['twitch'],
    'no url' => [stream(['url' => null])],
    'no end' => [stream(['ends_at' => null])],
]);

it('names where the stream is as the web does, without www', function (string $url, string $host) {
    expect(streamer(stream(['url' => $url]))->live->host())->toBe($host);
})->with([
    'twitch' => ['https://www.twitch.tv/alice', 'twitch.tv'],
    'youtube' => ['https://youtube.com/watch?v=abc', 'youtube.com'],
    'not a url' => ['twitch', 'twitch'],
]);

it('does not know a platform devnull adds later', function () {
    $live = streamer(stream(['platform' => 'kick']))->live;

    expect($live->platform)->toBeNull()
        ->and($live->url)->toBe('https://www.twitch.tv/alice');
});

it('keeps the stream when the user is kept and read back', function () {
    $user = streamer(stream(['platform' => 'youtube']));

    expect($user->toArray()['live'])->toBe(stream(['platform' => 'youtube']))
        ->and(User::fromArray($user->toArray()))->toEqual($user)
        ->and(streamer(null)->toArray()['live'])->toBeNull();
});
