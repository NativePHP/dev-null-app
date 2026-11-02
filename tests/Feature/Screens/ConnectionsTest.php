<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Someone in a list of followers or following, as the API sends them to a signed request.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function connectionJson(string $username, array $overrides = []): array
{
    return userJson(['id' => "id-{$username}", 'handle' => $username, 'name' => ucfirst($username), 'bio' => null, 'lane' => 2, 'is_followed' => false, 'follows_you' => false, ...$overrides]);
}

/**
 * A page of `GET /users/{username}/followers` or `/following`.
 *
 * @param  list<array<string, mixed>>  $people
 * @return array<string, mixed>
 */
function connectionsJson(array $people, ?string $nextCursor = null, string $path = 'users/ada/followers'): array
{
    return pageJson($people, $nextCursor, "http://devnull.test/api/v1/{$path}", perPage: 30);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function profileWithCounts(array $extra = []): array
{
    return [
        'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1], commits: 1, followers: 5, following: 2),
        'is_following' => false,
        'activity' => null,
        'streaks' => ['longest' => 0, 'current' => 0],
        'pinned_commit' => null,
        ...$extra,
    ];
}

it('opens the followers and the following from the counts on a profile', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileWithCounts()],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    Native::visit('/log/users/ada')->tap('count-followers')->assertNavigatedTo('/log/users/ada/followers');
    Native::visit('/log/users/ada')->tap('count-following')->assertNavigatedTo('/log/users/ada/following');
});

it('opens your own lists from the counts on the Profile tab', function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [profileWithCounts(['data' => userWithCountsJson(followers: 1, following: 1)])],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
        'GET /api/v1/me' => [currentUserJson()],
    ]);

    Native::visit('/profile')->tap('count-following')->assertNavigatedTo('/profile/users/local_dev/following');
});

it('lists them as the web does, with their lane, bio, whether they follow you and a Follow button', function () {
    fakeApi([
        'GET /api/v1/users/ada/followers' => [connectionsJson([
            connectionJson('grace', ['bio' => 'Found the first bug.', 'lane' => 4, 'is_followed' => true, 'follows_you' => true]),
            connectionJson('linus'),
            connectionJson('local_dev', ['id' => '0199e5c4-0000-7000-8000-000000000001', 'name' => 'Local Dev']),
        ])],
    ]);

    $screen = Native::visit('/log/users/ada/followers')
        ->assertNavTitle("@ada's followers")
        ->assertSee('Grace')
        ->assertSee('@grace')
        ->assertSee('Found the first bug.')
        ->assertSee('Linus');

    expect(treeShows(nodeByRef($screen, 'person-grace'), 'Following'))->toBeTrue()
        ->and(treeShows(nodeByRef($screen, 'person-linus'), 'Follow'))->toBeTrue()
        ->and(treeShows(nodeByRef($screen, 'person-linus'), 'Following'))->toBeFalse()
        ->and(treeShows(nodeByRef($screen, 'person-grace'), 'Follows you'))->toBeTrue()
        ->and(treeShows(nodeByRef($screen, 'person-linus'), 'Follows you'))->toBeFalse()
        ->and(findNode(nodeByRef($screen, 'person-grace'), fn (array $node): bool => ($node['layout']['width'] ?? null) === 48.0
            && ($node['style']['bg_color'] ?? null) === config('native-ui.theme.light.lane-4')))->not->toBeNull();

    // Your own row has no button, as on the web.
    $screen->assertMissingElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'follow-local_dev');
});

it('titles the people someone follows as theirs', function () {
    fakeApi([
        'GET /api/v1/users/ada/following' => [connectionsJson([connectionJson('grace')], path: 'users/ada/following')],
    ]);

    Native::visit('/log/users/ada/following')->assertNavTitle("@ada's following")->assertSee('Grace');
});

it('leaves out Follows you on your own followers, where everyone does', function () {
    fakeApi([
        'GET /api/v1/users/local_dev/followers' => [connectionsJson([connectionJson('grace', ['follows_you' => true])], path: 'users/local_dev/followers')],
    ]);

    $screen = Native::visit('/profile/users/local_dev/followers')->assertSee('Grace');

    expect(treeShows(nodeByRef($screen, 'person-grace'), 'Follows you'))->toBeFalse();
});

it('speaks git when there is nobody, as the web words it', function (string $type, string $command, string $message) {
    fakeApi([
        "GET /api/v1/users/ada/{$type}" => [connectionsJson([], path: "users/ada/{$type}")],
    ]);

    Native::visit("/log/users/ada/{$type}")->assertSee($command)->assertSee($message);
})->with([
    'followers' => ['followers', 'fatal: no followers yet', 'Nobody is following @ada yet.'],
    'following' => ['following', 'fatal: no following yet', "@ada isn't following anyone yet."],
]);

it('opens their profile from a row', function () {
    fakeApi([
        'GET /api/v1/users/ada/followers' => [connectionsJson([connectionJson('grace')])],
    ]);

    Native::visit('/log/users/ada/followers')->tap('person-grace')->assertNavigatedTo('/log/users/grace');
});

it('follows and unfollows straight away', function () {
    fakeApi([
        'GET /api/v1/users/ada/followers' => [connectionsJson([connectionJson('grace')])],
        'POST /api/v1/users/grace/follow' => [null, 204],
        'DELETE /api/v1/users/grace/follow' => [null, 204],
    ]);

    $screen = Native::visit('/log/users/ada/followers')->tap('follow-grace');

    expect(treeShows(nodeByRef($screen, 'person-grace'), 'Following'))->toBeTrue();

    $screen->tap('follow-grace');

    expect(treeShows(nodeByRef($screen, 'person-grace'), 'Following'))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://devnull.test/api/v1/users/grace/follow');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/users/grace/follow');
});

it('puts the follow back when the server refuses', function () {
    fakeApi([
        'GET /api/v1/users/ada/followers' => [connectionsJson([connectionJson('grace')])],
        'POST /api/v1/users/grace/follow' => [['message' => 'Too Many Attempts.'], 429],
    ]);

    $screen = Native::visit('/log/users/ada/followers')->tap('follow-grace')->assertSee('Too Many Attempts.');

    expect($screen->instance()->people[0]->isFollowed)->toBeFalse();
});

it('unfollows from your own following, keeping them in the list so it can be undone, and counts one fewer', function () {
    keep('users/local_dev', ['data' => userWithCountsJson(following: 2), 'is_following' => false]);
    fakeApi([
        'GET /api/v1/users/local_dev/following' => [connectionsJson([connectionJson('grace', ['is_followed' => true])], path: 'users/local_dev/following')],
        'DELETE /api/v1/users/grace/follow' => [null, 204],
    ]);

    $screen = Native::visit('/profile/users/local_dev/following')->tap('follow-grace')->assertSee('Grace');

    expect(treeShows(nodeByRef($screen, 'person-grace'), 'Following'))->toBeFalse()
        ->and(kept('users/local_dev')['data']['following_count'])->toBe(1)
        ->and(kept('users/local_dev/following')['data'][0]['is_followed'])->toBeFalse();
});

it('has the profile count the follow when you go back to it', function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [profileWithCounts(['data' => userWithCountsJson(followers: 1, following: 2)])],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/users/local_dev/followers' => [connectionsJson([connectionJson('grace', ['follows_you' => true])], path: 'users/local_dev/followers')],
        'POST /api/v1/users/grace/follow' => [null, 204],
    ]);

    $profile = Native::visit('/profile')->assertSee('2');
    $list = $profile->tap('count-followers')->follow()->tap('follow-grace');

    holdRefreshes();
    $list->goBack();

    expect($profile->instance()->profile->followingCount)->toBe(3);
});

it('loads more people by cursor', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), 'cursor=older')
        ? Http::response(connectionsJson([connectionJson('linus')]))
        : Http::response(connectionsJson([connectionJson('grace')], 'older')));

    $screen = Native::visit('/log/users/ada/followers')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-more')
        ->assertDontSee('Linus')
        ->call('loadMore')
        ->assertSee('Grace')
        ->assertSee('Linus')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-more');

    expect($screen->get('nextCursor'))->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/users/ada/followers?cursor=older');
});

it('opens on the people kept last time, then lays the fresh page over them', function () {
    holdRefreshes();
    keep('users/ada/followers', connectionsJson([connectionJson('grace'), connectionJson('linus')]));
    serveApi([
        'GET /api/v1/users/ada/followers' => [connectionsJson([connectionJson('margaret'), connectionJson('grace')])],
    ]);

    $screen = Native::visit('/log/users/ada/followers')->assertSee('Grace')->assertSee('Linus');

    expect(showsLoading($screen->tree()))->toBeFalse()
        ->and(count(Http::recorded()))->toBe(0);

    finishRefreshes($screen)->assertSee('Margaret')->assertSee('Grace')->assertDontSee('Linus');

    expect(array_map(fn ($person): string => $person->user->username, $screen->instance()->people))->toBe(['margaret', 'grace']);
});

it('keeps the pages loaded further down when the first page comes in fresh', function () {
    holdRefreshes();
    keep('users/ada/followers', connectionsJson([connectionJson('grace'), connectionJson('linus')], 'p2'));
    serveApi(fn (Request $request) => str_contains($request->url(), 'cursor=p2')
        ? Http::response(connectionsJson([connectionJson('margaret')]))
        : Http::response(connectionsJson([connectionJson('alan'), connectionJson('grace'), connectionJson('linus')], 'p2')));

    $screen = Native::visit('/log/users/ada/followers')->call('loadMore');

    finishRefreshes($screen);

    expect(array_map(fn ($person): string => $person->user->username, $screen->instance()->people))->toBe(['alan', 'grace', 'linus', 'margaret'])
        ->and($screen->get('nextCursor'))->toBeNull();
});

it('shows skeleton rows the first time only, while nothing is known', function () {
    holdRefreshes();
    serveApi([
        'GET /api/v1/users/ada/followers' => [connectionsJson([connectionJson('grace')])],
    ]);

    $screen = Native::visit('/log/users/ada/followers')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-1');

    finishRefreshes($screen)->assertSee('Grace');

    expect(showsLoading($screen->tree()))->toBeFalse();
});

it('asks again when pulled', function () {
    fakeApi([
        'GET /api/v1/users/ada/followers' => [connectionsJson([connectionJson('grace')])],
    ]);

    $screen = Native::visit('/log/users/ada/followers');
    $sent = count(Http::recorded());

    $screen->call('load');

    expect(count(Http::recorded()))->toBe($sent + 1);
});

it('says so across a block, as a profile does', function (string $code, string $says) {
    fakeApi([
        'GET /api/v1/users/ada/followers' => [['message' => 'Nope.', 'code' => $code], 403],
    ]);

    Native::visit('/log/users/ada/followers')->assertSee($says)->assertDontSee('Nope.');
})->with([
    'blocked by you' => ['user_blocked', 'fatal: @ada is blocked'],
    'blocked by them' => ['user_unavailable', "This profile isn't available."],
]);
