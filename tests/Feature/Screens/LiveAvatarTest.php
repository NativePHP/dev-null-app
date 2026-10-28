<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A stream Ada is on, ending at $endsAt.
 *
 * @return array{platform: string, url: string, thumbnail_url: string, ends_at: string}
 */
function adaStream(string $endsAt): array
{
    return [
        'platform' => 'twitch',
        'url' => 'https://www.twitch.tv/ada',
        'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_ada-1280x720.jpg',
        'ends_at' => $endsAt,
    ];
}

/**
 * A log with one commit of Ada's, who is on $live. The commit is two days old, so its time reads the same for hours.
 *
 * @param  array<string, mixed>|null  $live
 */
function logByStreamer(?array $live): TestableComponent
{
    serveApi(fn (Request $request) => Http::response(pageJson([
        commitJson(['id' => 'c1', 'created_at' => now()->subDays(2)->toIso8601ZuluString(), 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 2, 'live' => $live])]),
    ])));

    return Native::visit('/log', platform: 'ios');
}

/**
 * The colour of the ring around the avatar on a commit's rail.
 */
function railRingColour(TestableComponent $screen, string $ref): string
{
    $ring = findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['type'] ?? null) === 'stack' && isset($node['style']['border_color']) && strtolower($node['style']['border_color']) !== '#00000000');

    return strtolower($ring['style']['border_color']);
}

it('rings a live author in red and marks them live, as the web does', function () {
    $screen = logByStreamer(adaStream(now()->addHour()->toIso8601ZuluString()));

    $badge = findNode(nodeByRef($screen, 'commit-c1'), fn (array $node): bool => ($node['ref'] ?? null) === 'live-badge');

    expect($badge)->not->toBeNull()
        ->and(treeShows($badge, 'LIVE'))->toBeTrue()
        ->and(strtolower($badge['style']['bg_color']))->toBe(strtolower(config('native-ui.theme.light.live')))
        ->and(railRingColour($screen, 'commit-c1'))->toBe(strtolower(config('native-ui.theme.light.live')));
});

it("pulses the badge's dot, as the web's animate-pulse does", function () {
    $badge = findNode(nodeByRef(logByStreamer(adaStream(now()->addHour()->toIso8601ZuluString())), 'commit-c1'), fn (array $node): bool => ($node['ref'] ?? null) === 'live-badge');
    $dot = findNode($badge, fn (array $node): bool => ($node['ref'] ?? null) === 'live-dot');

    expect($dot['props'])->toMatchArray(['animate-duration' => 1000.0, 'animate-loop' => true])
        ->and($dot['style']['opacity'])->toBe(0.5);
});

it('takes the badge off a row the log kept once the stream ends', function () {
    $screen = logByStreamer(adaStream(now()->addMinute()->toIso8601ZuluString()));
    $badge = fn (): ?array => findNode(nodeByRef($screen, 'commit-c1'), fn (array $node): bool => ($node['ref'] ?? null) === 'live-badge');

    expect($badge())->not->toBeNull();

    $this->travel(2)->minutes();
    $screen->call('isRanked');

    expect($badge())->toBeNull()
        ->and(railRingColour($screen, 'commit-c1'))->toBe(strtolower(config('native-ui.theme.light.lane-2')));
});

it('keeps the lane ring once the stream has ended', function (?array $live) {
    $screen = logByStreamer($live);

    expect(findNode(nodeByRef($screen, 'commit-c1'), fn (array $node): bool => ($node['ref'] ?? null) === 'live-badge'))->toBeNull()
        ->and(railRingColour($screen, 'commit-c1'))->toBe(strtolower(config('native-ui.theme.light.lane-2')));
})->with('streams that are not on');

/**
 * Ada's profile, while she is on $live.
 *
 * @param  array<string, mixed>|null  $live
 */
function profileOfStreamer(?array $live): TestableComponent
{
    fakeApi([
        'GET /api/v1/users/ada' => [[
            'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 2, 'live' => $live]),
            'is_following' => false,
            'activity' => ['scale' => 'week', 'buckets' => []],
            'streaks' => ['longest' => 0, 'current' => 0],
            'pinned_commit' => null,
        ]],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    return Native::visit('/log/users/ada', platform: 'ios');
}

/**
 * The colour of the ring the profile's avatar sits in.
 */
function profileRingColour(TestableComponent $screen): string
{
    $ring = findNode(nodeByRef($screen, 'profile-header'), fn (array $node): bool => ($node['type'] ?? null) === 'stack' && ($node['layout']['width'] ?? null) === 92.0);

    return strtolower($ring['style']['bg_color']);
}

it("rings a live user's profile avatar in red and marks them live", function () {
    $screen = profileOfStreamer(adaStream(now()->addHour()->toIso8601ZuluString()));

    expect(findNode(nodeByRef($screen, 'profile-header'), fn (array $node): bool => ($node['ref'] ?? null) === 'live-badge'))->not->toBeNull()
        ->and(profileRingColour($screen))->toBe(strtolower(config('native-ui.theme.light.live')));
});

it('keeps the lane ring on a profile once the stream has ended', function (?array $live) {
    $screen = profileOfStreamer($live);

    expect(findNode(nodeByRef($screen, 'profile-header'), fn (array $node): bool => ($node['ref'] ?? null) === 'live-badge'))->toBeNull()
        ->and(profileRingColour($screen))->toBe(strtolower(config('native-ui.theme.light.lane-2')));
})->with('streams that are not on');

it("opens the web's stream sheet from a live author's avatar on the log", function () {
    $screen = logByStreamer(adaStream(now()->addHour()->toIso8601ZuluString()));

    expect(nodeByRef($screen, 'live-stream-sheet')['props']['visible'])->toBeFalse();

    $screen = $screen->press("openLiveStream('ada')");

    expect(nodeByRef($screen, 'live-stream-sheet')['props']['visible'])->toBeTrue()
        ->and(nodeByRef($screen, 'live-stream-thumbnail')['props']['src'])->toBe('https://static-cdn.jtvnw.net/previews-ttv/live_user_ada-1280x720.jpg');

    $screen->assertSee('Ada is live')
        ->assertSee('twitch.tv')
        ->assertSee('Watch now')
        ->assertSee('@ada')
        ->assertSee('Open profile');
});

it('opens the stream in the system browser, so Twitch or YouTube can take it', function () {
    logByStreamer(adaStream(now()->addHour()->toIso8601ZuluString()))
        ->press("openLiveStream('ada')")
        ->tap('watch-live-stream')
        ->assertNativeCalled('Browser.Open', fn (array $params): bool => $params['url'] === 'https://www.twitch.tv/ada');
});

it("opens the streamer's profile from the sheet", function () {
    logByStreamer(adaStream(now()->addHour()->toIso8601ZuluString()))
        ->press("openLiveStream('ada')")
        ->tap('live-stream-profile')
        ->assertSet('liveStreamer', null)
        ->assertNavigatedTo('/log/users/ada');
});

it("opens a stopped streamer's profile from their avatar, as before", function (?array $live) {
    logByStreamer($live)->press("openProfile('ada')")->assertNavigatedTo('/log/users/ada');
})->with('streams that are not on');

it("opens the stream sheet from a live user's profile avatar, without the profile link", function () {
    $screen = profileOfStreamer(adaStream(now()->addHour()->toIso8601ZuluString()))->tap('profile-live-stream');

    expect(nodeByRef($screen, 'live-stream-sheet')['props']['visible'])->toBeTrue();

    $screen->assertSee('Ada is live')->assertDontSee('Open profile');
});

it('forgets the stream once the sheet has gone', function () {
    logByStreamer(adaStream(now()->addHour()->toIso8601ZuluString()))
        ->press("openLiveStream('ada')")
        ->call('liveStreamClosed')
        ->assertSet('liveStreamer', null);
});

dataset('streams that are not on', [
    'not live' => [null],
    'ended' => [fn (): array => adaStream(now()->subMinute()->toIso8601ZuluString())],
]);
