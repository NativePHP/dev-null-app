<?php

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\ErrorCode;
use App\NativeComponents\ThisDevice;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
});

afterEach(fn () => Platform::set(null));

/**
 * The text of each row of the profile table, label first.
 *
 * @return array<string, string>
 */
function profileFields(TestableComponent $screen): array
{
    $rows = findNodes(nodeByRef($screen, 'profile-fields'), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'field-'));

    return collect($rows)->mapWithKeys(function (array $row): array {
        [$label, $value] = array_map(fn (array $text): string => $text['props']['text'], findNodes($row, fn (array $node): bool => ($node['type'] ?? null) === 'text'));

        return [$label => $value];
    })->all();
}

/**
 * Whether a ref's text is drawn in muted mono, as the web draws null.
 */
function mutedMono(array $node): bool
{
    return ($node['props']['font_name'] ?? null) === 'mono'
        && strtolower((string) ($node['props']['color'] ?? '')) === strtolower((string) config('native-ui.theme.light.ink-muted'));
}

it('lays out the sections of the web\'s profile settings, in its order', function () {
    signIn()->respondTo('NativephpSocialAuth.AppleSignIn', ['pending' => true]);
    serveApi(['GET /api/v1/me' => [currentUserJson(extra: ['apple_linked' => false])]]);

    $screen = Native::visit('/profile/device', platform: 'ios')
        ->assertNavTitle('Account')
        ->assertTabActive('Profile')
        ->assertSee('Mirrored from GitHub when you sign in. To change any of it, change it on GitHub, then sync it here.')
        ->assertSee('Your lane on the graph: the rail and nodes beside your commits, the ring around your avatar, and the dot before your handle. Automatic picks one from your account id.')
        ->assertSee("Removes your /dev/null account, every commit and reply you've made, and everyone you follow. Replies others left under your commits go too. Replies under your replies stay, hanging off a [deleted] tombstone. Your GitHub account is not touched.")
        ->assertDontSee('Embed your activity graph')
        ->assertDontSee('Export your data');

    $headings = array_values(array_intersect(shownText($screen), ['Profile information', 'Color', 'Apple ID', 'This phone', 'Deletes the key from this phone and signs you out.', 'Delete account']));

    expect($headings)->toBe(['Profile information', 'Color', 'Apple ID', 'This phone', 'Deletes the key from this phone and signs you out.', 'Delete account'])
        ->and(nodeByRef($screen, 'delete-account')['props'])->toMatchArray(['label' => 'Delete account', 'variant' => 'destructive']);
});

it('shows your profile as devnull mirrors it from GitHub, ringed in your colour, with null for what GitHub leaves empty', function () {
    signIn();
    serveApi(['GET /api/v1/me' => [currentUserJson([
        'name' => 'Local Dev',
        'bio' => 'Ships things.',
        'company' => null,
        'location' => 'Lisbon',
        'website' => 'local.dev',
        'sponsors_url' => 'https://github.com/sponsors/local_dev',
        'lane' => 4,
        'preferred_lane' => 4,
    ])]]);

    $screen = Native::visit('/profile/device');

    expect(profileFields($screen))->toBe([
        'name' => 'Local Dev',
        'username' => '@local_dev',
        'bio' => 'Ships things.',
        'company' => 'null',
        'location' => 'Lisbon',
        'website' => 'local.dev',
        'sponsors' => 'github.com/sponsors/local_dev',
    ]);

    $identity = nodeByRef($screen, 'profile-identity');
    $ring = findNode($identity, fn (array $node): bool => ($node['type'] ?? null) === 'stack' && (float) ($node['layout']['width'] ?? 0) === 56.0);

    expect(strtolower($ring['style']['border_color']))->toBe(strtolower(config('native-ui.theme.light.lane-4')))
        ->and(treeShows($identity, '@local_dev'))->toBeTrue();
});

it('prints null in muted mono for every field GitHub leaves empty, as the web does', function () {
    signIn();
    serveApi(['GET /api/v1/me' => [currentUserJson(['bio' => null, 'company' => null, 'location' => null, 'website' => null, 'sponsors_url' => null])]]);

    $screen = Native::visit('/profile/device');

    expect(array_slice(profileFields($screen), 2))->toBe(['bio' => 'null', 'company' => 'null', 'location' => 'null', 'website' => 'null', 'sponsors' => 'null']);

    foreach (['bio', 'company', 'location', 'website', 'sponsors'] as $field) {
        $value = findNodes(nodeByRef($screen, "field-{$field}"), fn (array $node): bool => ($node['type'] ?? null) === 'text')[1];

        expect(mutedMono($value))->toBeTrue("{$field} isn't in muted mono.");
    }

    $name = findNodes(nodeByRef($screen, 'field-name'), fn (array $node): bool => ($node['type'] ?? null) === 'text')[1];

    expect(mutedMono($name))->toBeFalse();
});

it('opens on you as devnull last said, with no request, then asks again behind it', function () {
    holdRefreshes();
    signIn();
    keep('me', currentUserJson(['bio' => 'Kept bio.']));
    serveApi(['GET /api/v1/me' => [currentUserJson(['bio' => 'Fresh bio.'])]]);

    $screen = Native::visit('/profile/device')->assertSee('Kept bio.');

    expect(count(Http::recorded()))->toBe(0)
        ->and(showsLoading($screen->tree()))->toBeFalse();

    finishRefreshes($screen)->assertSee('Fresh bio.')->assertDontSee('Kept bio.');
});

it('opens on you as this phone remembers you when devnull has said nothing yet', function () {
    holdRefreshes();
    signIn(['name' => 'Remembered Dev', 'bio' => null]);

    Native::visit('/profile/device')
        ->assertSee('Remembered Dev')
        ->assertSee('Last synced when you signed in')
        ->assertSee('Profile information');
});

it('says when your profile was last synced from GitHub', function (?string $syncedAt, string $shown) {
    $this->travelTo(now()->parse('2026-10-17T12:00:00Z'));
    signIn();
    serveApi(['GET /api/v1/me' => [currentUserJson(['github_synced_at' => $syncedAt])]]);

    $synced = nodeByRef(Native::visit('/profile/device'), 'synced-at');

    expect($synced['props']['text'])->toBe($shown);
})->with([
    'never since signing in' => [null, 'Last synced when you signed in'],
    'a moment ago' => ['2026-10-17T11:59:40Z', 'Last synced just now'],
    'minutes ago' => ['2026-10-17T11:55:00Z', 'Last synced 5 minutes ago'],
    'yesterday' => ['2026-10-16T09:00:00Z', 'Last synced yesterday'],
]);

it('opens GitHub\'s profile settings in the in-app browser to edit it there', function () {
    signIn();
    serveApi(['GET /api/v1/me' => [currentUserJson()]]);

    Native::visit('/profile/device')
        ->tap('edit-on-github')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === ThisDevice::GitHubSettingsUrl)
        ->assertNoNavigation();

    expect(ThisDevice::GitHubSettingsUrl)->toBe('https://github.com/settings/profile');
});

it('syncs from GitHub with a spinner until devnull answers, then shows the fresh profile and remembers it', function () {
    $this->travelTo(now()->parse('2026-10-17T12:00:00Z'));
    $bridge = holdRefreshes();
    signIn();
    keep('me', currentUserJson(['name' => 'Old Name', 'bio' => 'Old bio.'], ['apple_linked' => true]));
    serveApi([
        'GET /api/v1/me' => [currentUserJson(['name' => 'Old Name', 'bio' => 'Old bio.'], ['apple_linked' => true])],
        'PUT /api/v1/me/github' => [currentUserJson(['name' => 'Fresh Name', 'bio' => 'Fresh bio.', 'github_synced_at' => '2026-10-17T12:00:00Z'], ['unread_notifications_count' => 4])],
    ]);

    $screen = Native::visit('/profile/device')->tap('sync-github')->tap('sync-github');

    // Signed on the UI thread and sent in the background, once, while the button spins.
    expect(nodeByRef($screen, 'sync-github')['props'])->toMatchArray(['label' => 'Sync from GitHub', 'loading' => true, 'disabled' => true])
        ->and(refreshesSent($screen))->toBe(2)
        ->and(count(Http::recorded()))->toBe(0);

    $screen->assertSet('syncing', true)->assertSee('Old Name');

    finishRefreshes($screen)
        ->assertSet('syncing', false)
        ->assertSet('syncError', null)
        ->assertSee('Fresh Name')
        ->assertSee('Fresh bio.')
        ->assertSee('Last synced just now')
        ->assertDontSee('Old Name');

    expect(nodeByRef($screen, 'sync-github')['props'])->not->toHaveKey('loading')
        ->and(app(Account::class)->user()?->name)->toBe('Fresh Name')
        ->and(app(Account::class)->unreadNotifications())->toBe(4)
        ->and(kept('me')['data']['name'])->toBe('Fresh Name')
        ->and(kept('me')['apple_linked'])->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'http://devnull.test/api/v1/'.Client::GitHubPath
        && $request->body() === ''
        && $request->hasHeader('X-SSH-Signature'));
    expect(collect(Http::recorded())->filter(fn (array $sent): bool => $sent[0]->method() === 'PUT'))->toHaveCount(1);
    $bridge->assertNotCalled('ToastKit.Show');
});

it('gives your profile on the Profile tab the synced name at once', function () {
    signIn();
    keep('users/local_dev', ['data' => userWithCountsJson(['name' => 'Old Name']), 'is_following' => false]);
    serveApi([
        'GET /api/v1/me' => [currentUserJson(['name' => 'Old Name'])],
        'PUT /api/v1/me/github' => [currentUserJson(['name' => 'Fresh Name'])],
    ]);

    Native::visit('/profile/device')->tap('sync-github');

    holdRefreshes();

    Native::visit('/profile')->assertSee('Fresh Name')->assertDontSee('Old Name');
});

it('says in the section why it could not sync, and keeps what it showed', function (int $status, array $body, string $message) {
    $bridge = signIn();
    serveApi([
        'GET /api/v1/me' => [currentUserJson(['name' => 'Kept Name'])],
        'PUT /api/v1/me/github' => [$body, $status],
    ]);

    $screen = Native::visit('/profile/device')
        ->tap('sync-github')
        ->assertSet('syncing', false)
        ->assertSet('syncError', $message)
        ->assertSet('error', null)
        ->assertSee('Kept Name')
        ->assertNoNavigation();

    expect(nodeByRef($screen, 'sync-error')['props']['text'])->toBe($message)
        ->and(nodeByRef($screen, 'sync-github')['props'])->not->toHaveKey('disabled')
        ->and(app(Account::class)->user()?->name)->toBe('Kept Name');

    $bridge->assertNotCalled('ToastKit.Show');
})->with([
    'GitHub could not be reached' => [503, ['message' => 'Whatever devnull words it as.', 'code' => ErrorCode::GitHubSyncFailed->value], 'Could not sync from GitHub. Try again in a minute.'],
    'synced a moment ago' => [429, ['message' => 'You just synced. Try again in a minute.', 'code' => 'rate_limited'], 'You just synced. Try again in a minute.'],
]);

it('says so when devnull cannot be reached to sync', function () {
    signIn();
    keep('me', currentUserJson());
    fakeOffline();

    Native::visit('/profile/device')
        ->tap('sync-github')
        ->assertSet('syncError', "fatal: unable to access 'http://devnull.test': could not connect to the server");
});

it('signs out when devnull no longer takes this phone\'s key for a sync', function () {
    signIn();
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'PUT /api/v1/me/github' => [['message' => 'Not approved.', 'code' => 'device_not_approved'], 401],
    ]);

    Native::visit('/profile/device')
        ->tap('sync-github')
        ->assertReplacedWith('/onboarding', ['message' => ErrorCode::DeviceNotApproved->message()]);

    expect(app(Account::class)->user())->toBeNull();
});
