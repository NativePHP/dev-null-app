<?php

use App\Devnull\Data\User;
use App\Devnull\ErrorCode;
use App\Devnull\ShownTabs;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Ada, as devnull sends a user to a signed request.
 *
 * @return array<string, mixed>
 */
function safetyAdaJson(): array
{
    return userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1]);
}

/**
 * Ada's signed profile, which you follow.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function safetyProfileJson(array $extra = []): array
{
    return [
        'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1], commits: 1, followers: 5, following: 2),
        'is_following' => true,
        'follows_you' => false,
        'is_muting' => false,
        'activity' => ['scale' => 'week', 'buckets' => []],
        'streaks' => ['longest' => 0, 'current' => 0],
        'pinned_commit' => null,
        ...$extra,
    ];
}

/**
 * What devnull answers across a block you made: why, and who you blocked.
 *
 * @return array{0: array<string, mixed>, 1: int}
 */
function safetyBlockedAnswer(): array
{
    return [['message' => "You blocked Ada Lovelace. Neither of you can follow, reply to, or star the other, or see each other's commits.", 'code' => 'user_blocked', 'user' => safetyAdaJson()], 403];
}

/**
 * The items of the profile's safety menu, by id, or null when it has none.
 *
 * @return array<string, array<string, mixed>>|null
 */
function safetyMenu(TestableComponent $screen): ?array
{
    $menu = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action' && ($node['props']['id'] ?? null) === 'safety');

    if ($menu === null) {
        return null;
    }

    return collect($menu['children'] ?? [])->mapWithKeys(fn (array $item): array => [$item['props']['id'] => $item['props']])->all();
}

/**
 * Whether the app sent devnull a request.
 */
function safetySent(string $method, string $path): bool
{
    return collect(Http::recorded())->contains(fn (array $sent): bool => $sent[0]->method() === $method && $sent[0]->url() === 'http://devnull.test/api/v1/'.$path);
}

it("offers Mute and Block from a ⋯ in the nav bar of someone else's profile, as the web's menu does", function () {
    fakeApi(['GET /api/v1/users/ada' => [safetyProfileJson()], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    $screen = Native::visit('/log/users/ada', platform: 'ios');
    $more = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action' && ($node['props']['id'] ?? null) === 'safety');

    expect($more['props'])->toMatchArray(['icon' => 'custom:ellipsis', 'a11y_label' => 'More options for @ada'])
        ->and(safetyMenu($screen))->toBe([
            'mute' => ['id' => 'mute', 'icon' => 'custom:volume-x', 'label' => 'Mute @ada'],
            'block' => ['id' => 'block', 'icon' => 'custom:ban', 'label' => 'Block @ada', 'destructive' => true],
        ]);
});

it('offers Unmute on a profile you mute', function () {
    fakeApi(['GET /api/v1/users/ada' => [safetyProfileJson(['is_muting' => true])], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    expect(safetyMenu(Native::visit('/log/users/ada', platform: 'ios')))->toMatchArray([
        'unmute' => ['id' => 'unmute', 'icon' => 'custom:volume-2', 'label' => 'Unmute @ada'],
    ]);
});

it('has no safety menu on your own profile, on one that blocked you, or before devnull says whether you mute them', function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [safetyProfileJson(['data' => userWithCountsJson(['handle' => 'local_dev'])])],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
        'GET /api/v1/users/grace' => [['message' => "This profile isn't available.", 'code' => 'user_unavailable'], 403],
        'GET /api/v1/users/grace/commits' => [['message' => "This profile isn't available.", 'code' => 'user_unavailable'], 403],
    ]);

    expect(safetyMenu(Native::visit('/profile')))->toBeNull()
        ->and(safetyMenu(Native::visit('/log/users/grace')))->toBeNull();

    holdRefreshes();

    // Opened from a commit, the header only has what the commit knew of them.
    expect(safetyMenu(Native::visit('/log/users/ada', data: ['user' => User::fromArray(safetyAdaJson())])))->toBeNull();
});

it("mutes once you confirm, in the web's words, and Cancel leaves it as it was", function () {
    keep('users/ada', safetyProfileJson());
    serveApi([
        'GET /api/v1/users/ada' => [safetyProfileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
        'POST /api/v1/users/ada/mute' => [['data' => safetyAdaJson(), 'is_muting' => true]],
    ]);

    $screen = Native::visit('/log/users/ada', platform: 'ios')
        ->press('confirmMute')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Mute @ada?'
            && $params['message'] === "You won't see their commits or hear from them, and they won't know."
            && $params['buttons'] === [['label' => 'Cancel', 'style' => 'cancel'], ['label' => 'Mute', 'style' => 'default']])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'mute:ada'])
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'muted');

    expect(safetySent('POST', 'users/ada/mute'))->toBeFalse();

    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Mute', 'id' => 'mute:ada'])
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'muted')
        ->assertSee('Muted');

    expect(safetySent('POST', 'users/ada/mute'))->toBeTrue()
        ->and($screen->instance()->profile->isMuting)->toBeTrue()
        ->and(kept('users/ada')['is_muting'])->toBeTrue()
        ->and(array_keys(safetyMenu($screen)))->toBe(['unmute', 'block']);
});

it('unmutes once you confirm', function () {
    serveApi([
        'GET /api/v1/users/ada' => [safetyProfileJson(['is_muting' => true])],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
        'DELETE /api/v1/users/ada/mute' => [['data' => safetyAdaJson(), 'is_muting' => false]],
    ]);

    $screen = Native::visit('/log/users/ada')
        ->assertSee('Muted')
        ->press('confirmMute')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Unmute @ada?')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Unmute', 'id' => 'unmute:ada'])
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'muted');

    expect(safetySent('DELETE', 'users/ada/mute'))->toBeTrue()
        ->and(array_keys(safetyMenu($screen)))->toBe(['mute', 'block']);
});

it('puts the mute back, and says why, when devnull says no', function () {
    serveApi([
        'GET /api/v1/users/ada' => [safetyProfileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
        'POST /api/v1/users/ada/mute' => [['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429],
    ]);

    $screen = Native::visit('/log/users/ada')
        ->call('mute')
        ->assertSee('Too Many Attempts.')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'muted');

    expect($screen->instance()->profile->isMuting)->toBeFalse()
        ->and(array_keys(safetyMenu($screen)))->toBe(['mute', 'block']);
});

it("blocks once you confirm with the web's Block dialog, and shows the blocked page", function () {
    keep('users/ada', safetyProfileJson());
    serveApi([
        'GET /api/v1/users/ada' => [safetyProfileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Notes on the engine', 'author' => safetyAdaJson()])])],
        'POST /api/v1/users/ada/block' => [['data' => safetyAdaJson(), 'is_blocking' => true]],
    ]);

    $screen = Native::visit('/log/users/ada', platform: 'ios')
        ->assertSee('Notes on the engine')
        ->press('confirmBlock')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Block @ada?'
            && $params['message'] === "You'll stop following each other. Neither of you will be able to follow, reply to, or star the other, or see each other's commits. They won't be told, but they'll see your profile as unavailable. You can unblock them any time from your settings."
            && $params['buttons'] === [['label' => 'Cancel', 'style' => 'cancel'], ['label' => 'Block', 'style' => 'destructive']])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'block:ada'])
        ->assertSee('Notes on the engine');

    expect(safetySent('POST', 'users/ada/block'))->toBeFalse();

    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Block', 'id' => 'block:ada'])
        ->assertSet('unavailable', ErrorCode::UserBlocked->value)
        ->assertSee('fatal: @ada is blocked')
        ->assertSee("You blocked Ada Lovelace. Neither of you can follow, reply to, or star the other, or see each other's commits.")
        ->assertSee('Unblock @ada')
        ->assertDontSee('Notes on the engine');

    expect(safetySent('POST', 'users/ada/block'))->toBeTrue()
        ->and(kept('users/ada'))->toBeNull()
        ->and(kept('users/ada/commits'))->toBeNull()
        // A blocked profile offers only Unblock, as the web's blocked page does.
        ->and(safetyMenu($screen))->toBe([
            'unblock' => ['id' => 'unblock', 'icon' => 'custom:ban', 'label' => 'Unblock @ada'],
        ]);
});

it("puts the profile back as it was when devnull won't block", function () {
    serveApi([
        'GET /api/v1/users/ada' => [safetyProfileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Notes on the engine', 'author' => safetyAdaJson()])])],
        'POST /api/v1/users/ada/block' => [['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429],
    ]);

    $screen = Native::visit('/log/users/ada')
        ->call('block')
        ->assertSet('unavailable', null)
        ->assertSee('Too Many Attempts.')
        ->assertSee('Notes on the engine')
        ->assertDontSee('is blocked');

    expect(array_keys(safetyMenu($screen)))->toBe(['mute', 'block']);
});

it('shows who you blocked as devnull sends them, with nothing kept', function () {
    fakeApi(['GET /api/v1/users/ada' => safetyBlockedAnswer(), 'GET /api/v1/users/ada/commits' => safetyBlockedAnswer()]);

    $screen = Native::visit('/log/users/ada')
        ->assertSee('fatal: @ada is blocked')
        ->assertSee("You blocked Ada Lovelace. Neither of you can follow, reply to, or star the other, or see each other's commits.")
        ->assertElement('image', fn (array $node): bool => ($node['props']['src'] ?? $node['props']['url'] ?? null) === safetyAdaJson()['avatar_url']);

    expect(array_keys(safetyMenu($screen)))->toBe(['unblock']);
});

it("unblocks from the menu once you confirm, in the web's words, and shows the profile again without the follows", function () {
    serveApi([
        'GET /api/v1/users/ada' => safetyBlockedAnswer(),
        'GET /api/v1/users/ada/commits' => safetyBlockedAnswer(),
        'DELETE /api/v1/users/ada/block' => [['data' => safetyAdaJson(), 'is_blocking' => false]],
    ]);

    $screen = Native::visit('/log/users/ada')
        ->press('confirmBlock')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Unblock @ada?'
            && $params['message'] === "Unblocking doesn't bring back follows."
            && $params['buttons'] === [['label' => 'Cancel', 'style' => 'cancel'], ['label' => 'Unblock', 'style' => 'default']])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'unblock:ada'])
        ->assertSee('fatal: @ada is blocked');

    expect(safetySent('DELETE', 'users/ada/block'))->toBeFalse();

    serveApi([
        'GET /api/v1/users/ada' => [safetyProfileJson(['is_following' => false])],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Notes on the engine', 'author' => safetyAdaJson()])])],
        'DELETE /api/v1/users/ada/block' => [['data' => safetyAdaJson(), 'is_blocking' => false]],
    ]);

    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Unblock', 'id' => 'unblock:ada'])
        ->assertSet('unavailable', null)
        ->assertDontSee('is blocked')
        ->assertSee('Ada Lovelace')
        ->assertSee('Follow')
        ->assertDontSee('Unfollow')
        ->assertSee('Notes on the engine');

    expect(safetySent('DELETE', 'users/ada/block'))->toBeTrue()
        ->and(array_keys(safetyMenu($screen)))->toBe(['mute', 'block']);
});

it("unblocks straight away from the blocked page's own button, as the web's does, and stays blocked when devnull says no", function () {
    serveApi([
        'GET /api/v1/users/ada' => safetyBlockedAnswer(),
        'GET /api/v1/users/ada/commits' => safetyBlockedAnswer(),
        'DELETE /api/v1/users/ada/block' => [['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429],
    ]);

    $screen = Native::visit('/log/users/ada')
        ->tap('unblock')
        ->assertNativeNotCalled('Dialog.Alert')
        ->assertSet('unavailable', ErrorCode::UserBlocked->value)
        ->assertSee('Too Many Attempts.')
        ->assertSee('fatal: @ada is blocked');

    expect(safetySent('DELETE', 'users/ada/block'))->toBeTrue();
});

it('opens the people you mute and block beneath the tab, from a profile you blocked', function () {
    fakeApi(['GET /api/v1/users/ada' => safetyBlockedAnswer(), 'GET /api/v1/users/ada/commits' => safetyBlockedAnswer()]);

    Native::visit('/log/users/ada')
        ->tap('muted-and-blocked')
        ->assertNavigatedTo('/log/safety')
        ->assertNativeNotCalled('Browser.OpenInApp');
});

it('leaves an alert another profile asked alone', function () {
    serveApi([
        'GET /api/v1/users/ada' => [safetyProfileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
        'POST /api/v1/users/ada/mute' => [['data' => safetyAdaJson(), 'is_muting' => true]],
        'POST /api/v1/users/ada/block' => [['data' => safetyAdaJson(), 'is_blocking' => true]],
    ]);

    Native::visit('/log/users/ada')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Mute', 'id' => 'mute:grace'])
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Block', 'id' => 'block:grace'])
        ->assertSet('unavailable', null);

    expect(safetySent('POST', 'users/ada/mute'))->toBeFalse()
        ->and(safetySent('POST', 'users/ada/block'))->toBeFalse();
});

it('takes the commits of someone you mute out of the log as soon as you come back to it, Trending too', function () {
    holdRefreshes();
    app(ShownTabs::class)->remember(userJson()['id'], 'log', 'trending');
    keep('log/trending', [
        ...pageJson([commitJson(['id' => 't2', 'body' => 'Ada on Trending', 'author' => safetyAdaJson()]), commitJson(['id' => 't1', 'body' => 'Someone else on Trending'])], path: 'http://devnull.test/api/v1/log/trending'),
        'log' => 'trending',
        'is_ranked' => true,
        'newest_commit_id' => 't2',
        'suggestions' => [],
    ]);
    keep('users/ada', safetyProfileJson());
    serveApi(['POST /api/v1/users/ada/mute' => [['data' => safetyAdaJson(), 'is_muting' => true]]]);

    $log = Native::visit('/log')->assertSee('Ada on Trending');

    $profile = $log->call('openProfile', 'ada')->follow()
        ->press('confirmMute')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Mute', 'id' => 'mute:ada']);

    $profile->goBack()
        ->assertDontSee('Ada on Trending')
        ->assertSee('Someone else on Trending');
});
