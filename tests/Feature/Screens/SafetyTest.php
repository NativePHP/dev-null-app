<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Someone you mute or block, as devnull lists them.
 *
 * @return array<string, mixed>
 */
function listedJson(string $id, string $username, string $name): array
{
    return userJson(['id' => $id, 'handle' => $username, 'name' => $name, 'lane' => 2]);
}

/**
 * A page of the people you mute or block.
 *
 * @param  list<array<string, mixed>>  $people
 * @return array<string, mixed>
 */
function peopleJson(array $people, ?string $cursor = null, string $path = 'me/muted'): array
{
    return pageJson($people, $cursor, path: "http://devnull.test/api/v1/{$path}");
}

function safetyRequests(string $method, string $path): int
{
    return count(Http::recorded(fn (Request $request): bool => $request->method() === $method && parse_url($request->url(), PHP_URL_PATH) === "/api/v1/{$path}"));
}

it("lists the people you mute and block in the web's words, beneath the Profile tab", function () {
    fakeApi([
        'GET /api/v1/me/muted' => [peopleJson([listedJson('u2', 'ada', 'Ada Lovelace'), listedJson('u3', 'grace', 'Grace Hopper')])],
        'GET /api/v1/me/blocked' => [peopleJson([listedJson('u4', 'mallory', 'Mallory')], path: 'me/blocked')],
    ]);

    $screen = Native::visit('/profile/safety', platform: 'ios')
        ->assertNavTitle('Muted and blocked')
        ->assertTabActive('Profile')
        ->assertSee('Muted')
        ->assertSee("Their commits and replies stay out of your logs, threads, search, and suggestions, and they can't notify you. They aren't told, and you can still visit their profile.")
        ->assertSee('Blocked')
        ->assertSee("Neither of you can follow, reply to, or star the other, or see each other's commits. Unblocking doesn't bring back follows.")
        ->assertSee('Ada Lovelace')->assertSee('@ada')
        ->assertSee('Grace Hopper')->assertSee('@grace')
        ->assertSee('Mallory')->assertSee('@mallory')
        ->assertDontSee("You haven't");

    expect(childRefs($screen, 'muted-people'))->toContain('muted-ada', 'muted-grace')
        ->and(childRefs($screen, 'blocked-people'))->toContain('blocked-mallory')
        ->and(nodeByRef($screen, 'unmute-ada')['props']['label'] ?? null)->toBe('Unmute')
        ->and(nodeByRef($screen, 'unblock-mallory')['props']['label'] ?? null)->toBe('Unblock')
        ->and(showsLoading($screen->tree()))->toBeFalse();
});

it("says so in the web's words when you mute or block no one", function () {
    fakeApi(['GET /api/v1/me/muted' => [peopleJson([])], 'GET /api/v1/me/blocked' => [peopleJson([], path: 'me/blocked')]]);

    $screen = Native::visit('/profile/safety')
        ->assertSee("You haven't muted anyone.")
        ->assertSee("You haven't blocked anyone.");

    nodeByRef($screen, 'muted-empty');
    nodeByRef($screen, 'blocked-empty');
});

it('shows skeleton rows until devnull answers, with nothing kept', function () {
    holdRefreshes();
    serveApi([
        'GET /api/v1/me/muted' => [peopleJson([listedJson('u2', 'ada', 'Ada Lovelace')])],
        'GET /api/v1/me/blocked' => [peopleJson([], path: 'me/blocked')],
    ]);

    $screen = Native::visit('/profile/safety')->assertDontSee('Ada Lovelace')->assertDontSee("You haven't");

    nodeByRef($screen, 'loading-muted-1');
    nodeByRef($screen, 'loading-blocked-1');

    finishRefreshes($screen)
        ->assertSee('Ada Lovelace')
        ->assertSee("You haven't blocked anyone.");

    expect(showsLoading($screen->tree()))->toBeFalse()
        ->and(kept('me/muted')['data'][0]['handle'])->toBe('ada');
});

it('shows the lists as they were last time at once, then takes what devnull says behind them', function () {
    holdRefreshes();
    keep('me/muted', peopleJson([listedJson('u2', 'ada', 'Ada Lovelace')]));
    keep('me/blocked', peopleJson([listedJson('u4', 'mallory', 'Mallory')], path: 'me/blocked'));
    serveApi([
        'GET /api/v1/me/muted' => [peopleJson([listedJson('u3', 'grace', 'Grace Hopper')])],
        'GET /api/v1/me/blocked' => [peopleJson([], path: 'me/blocked')],
    ]);

    $screen = Native::visit('/profile/safety')->assertSee('Ada Lovelace')->assertSee('Mallory');

    expect(showsLoading($screen->tree()))->toBeFalse()
        ->and(refreshesSent($screen))->toBe(2);

    finishRefreshes($screen)
        ->assertSee('Grace Hopper')
        ->assertDontSee('Ada Lovelace')
        ->assertDontSee('Mallory')
        ->assertSee("You haven't blocked anyone.");
});

it('says quietly that it could not refresh, and keeps showing the kept lists', function () {
    keep('me/muted', peopleJson([listedJson('u2', 'ada', 'Ada Lovelace')]));
    keep('me/blocked', peopleJson([], path: 'me/blocked'));
    fakeOffline();

    Native::visit('/profile/safety')
        ->assertSee('Ada Lovelace')
        ->assertSee("warning: couldn't refresh")
        ->assertDontSee('could not connect');
});

it("prints the error when there's nothing kept to show", function () {
    fakeOffline();

    $screen = Native::visit('/profile/safety')->assertSee('could not connect to the server');

    expect(showsLoading($screen->tree()))->toBeFalse();
});

it('unmutes straight away, as the web does, and they leave the list and their kept profile', function () {
    keep('users/ada', ['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada']), 'is_muting' => true]);
    serveApi([
        'GET /api/v1/me/muted' => [peopleJson([listedJson('u2', 'ada', 'Ada Lovelace'), listedJson('u3', 'grace', 'Grace Hopper')])],
        'GET /api/v1/me/blocked' => [peopleJson([], path: 'me/blocked')],
        'DELETE /api/v1/users/ada/mute' => [['data' => listedJson('u2', 'ada', 'Ada Lovelace'), 'is_muting' => false]],
    ]);

    Native::visit('/profile/safety')
        ->tap('unmute-ada')
        ->assertNativeNotCalled('Dialog.Alert')
        ->assertDontSee('Ada Lovelace')
        ->assertSee('Grace Hopper');

    expect(safetyRequests('DELETE', 'users/ada/mute'))->toBe(1)
        ->and(array_column(kept('me/muted')['data'], 'handle'))->toBe(['grace'])
        ->and(kept('users/ada')['is_muting'])->toBeFalse();
});

it('unblocks straight away, and they leave the list', function () {
    serveApi([
        'GET /api/v1/me/muted' => [peopleJson([])],
        'GET /api/v1/me/blocked' => [peopleJson([listedJson('u4', 'mallory', 'Mallory')], path: 'me/blocked')],
        'DELETE /api/v1/users/mallory/block' => [['data' => listedJson('u4', 'mallory', 'Mallory'), 'is_blocking' => false]],
    ]);

    Native::visit('/profile/safety')
        ->tap('unblock-mallory')
        ->assertDontSee('Mallory')
        ->assertSee("You haven't blocked anyone.");

    expect(safetyRequests('DELETE', 'users/mallory/block'))->toBe(1)
        ->and(kept('me/blocked')['data'])->toBe([]);
});

it('puts them back, and says why, when devnull says no', function (string $route, string $button, string $name) {
    serveApi([
        'GET /api/v1/me/muted' => [peopleJson([listedJson('u2', 'ada', 'Ada Lovelace')])],
        'GET /api/v1/me/blocked' => [peopleJson([listedJson('u4', 'mallory', 'Mallory')], path: 'me/blocked')],
        $route => [['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429],
    ]);

    Native::visit('/profile/safety')
        ->tap($button)
        ->assertSee('Too Many Attempts.')
        ->assertSee($name);
})->with([
    'unmute' => ['DELETE /api/v1/users/ada/mute', 'unmute-ada', 'Ada Lovelace'],
    'unblock' => ['DELETE /api/v1/users/mallory/block', 'unblock-mallory', 'Mallory'],
]);

it('ignores Unmute for someone it no longer lists', function () {
    fakeApi(['GET /api/v1/me/muted' => [peopleJson([])], 'GET /api/v1/me/blocked' => [peopleJson([], path: 'me/blocked')]]);

    Native::visit('/profile/safety')->call('unmute', 'ada');

    expect(safetyRequests('DELETE', 'users/ada/mute'))->toBe(0);
});

it('shows more of a list under the people showing', function () {
    serveApi(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match (true) {
            $path === '/api/v1/me/muted' && ($query['cursor'] ?? null) === 'page2' => Http::response(peopleJson([listedJson('u3', 'grace', 'Grace Hopper')])),
            $path === '/api/v1/me/muted' => Http::response(peopleJson([listedJson('u2', 'ada', 'Ada Lovelace')], 'page2')),
            default => Http::response(peopleJson([], path: 'me/blocked')),
        };
    });

    $screen = Native::visit('/profile/safety')
        ->assertSee('Ada Lovelace')
        ->assertDontSee('Grace Hopper')
        ->assertMissingElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'blocked-more')
        ->tap('muted-more')
        ->assertSee('Ada Lovelace')
        ->assertSee('Grace Hopper')
        ->assertMissingElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'muted-more');

    expect(childRefs($screen, 'muted-people'))->toContain('muted-ada', 'muted-grace')
        ->and(kept('me/muted')['data'])->toHaveCount(1);

    // Nothing more to show.
    $screen->call('loadMore', 'muted');

    expect(safetyRequests('GET', 'me/muted'))->toBe(2);
});

it('opens their profile beneath the tab it was opened in', function () {
    fakeApi([
        'GET /api/v1/me/muted' => [peopleJson([listedJson('u2', 'ada', 'Ada Lovelace')])],
        'GET /api/v1/me/blocked' => [peopleJson([], path: 'me/blocked')],
    ]);

    Native::visit('/profile/safety')->tap('Ada Lovelace')->assertNavigatedTo('/profile/users/ada');
    Native::visit('/log/safety')->tap('Ada Lovelace')->assertNavigatedTo('/log/users/ada');
});

it('comes back from a profile showing the mute made there', function () {
    holdRefreshes();
    keep('me/muted', peopleJson([]));
    keep('me/blocked', peopleJson([listedJson('u2', 'ada', 'Ada Lovelace')], path: 'me/blocked'));
    keep('users/grace', [
        'data' => userWithCountsJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace Hopper']),
        'is_following' => false,
        'is_muting' => false,
    ]);
    serveApi(['POST /api/v1/users/grace/mute' => [['data' => listedJson('u3', 'grace', 'Grace Hopper'), 'is_muting' => true]]]);

    $safety = Native::visit('/profile/safety')->assertSee("You haven't muted anyone.");

    $profile = Native::visit('/profile/users/grace')
        ->press('confirmMute')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Mute', 'id' => 'mute:grace']);

    expect($profile->instance()->profile->isMuting)->toBeTrue();

    $safety->call('onResume')->assertSee('Grace Hopper')->assertDontSee("You haven't muted anyone.");
});

it('leads from your account to the people you mute and block', function () {
    serveApi(['GET /api/v1/me' => [currentUserJson()]]);

    Native::visit('/profile/device')
        ->assertSee('Safety')
        ->assertSee('Everyone you mute or block, and a way to undo it.')
        ->tap('muted-and-blocked')
        ->assertNavigatedTo('/profile/safety');
});
