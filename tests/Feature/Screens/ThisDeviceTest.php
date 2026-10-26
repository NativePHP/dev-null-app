<?php

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\DeviceKey;
use App\Devnull\Display;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    serveApi(['GET /api/v1/me' => [currentUserJson()], 'DELETE /api/v1/me' => [null, 204]]);
});

/**
 * Whether the app asked devnull to delete the account.
 */
function deleteSent(): bool
{
    return collect(Http::recorded())->contains(fn (array $sent): bool => $sent[0]->method() === 'DELETE');
}

it('shows your account with this phone\'s fingerprint, pushed onto the Profile tab', function () {
    signIn();

    Native::visit('/profile/device')
        ->assertNavTitle('Account')
        ->assertTabActive('Profile')
        ->assertSee('SHA256:lbmsoA0yIEcEiVDRnMWuzm+nV+3ZEEpVIURqFoeSspg')
        ->assertSee('Your devices on dev-null.sh')
        ->assertSee('Sign out')
        ->assertSee('Delete account')
        ->assertDontSee('ssh-ed25519')
        ->assertDontSee(Display::maskedKey(testKey()->openSsh()))
        ->assertDontSee('Share public key')
        ->assertDontSee('Keychain')
        ->assertDontSee('Forget');
});

it('shows where the app talks to only when it is not dev-null.sh', function (string $url, ?string $shown) {
    config(['devnull.url' => $url]);
    signIn();

    $screen = Native::visit('/profile/device');
    $remote = findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'remote');

    if ($shown === null) {
        expect($remote)->toBeNull();
        $screen->assertDontSee('remote')->assertDontSee('https://');
    } else {
        expect($remote)->not->toBeNull();
        $screen->assertSee('remote')->assertSee($shown);
    }
})->with([
    'production' => [Client::ProductionUrl, null],
    'production with a trailing slash' => [Client::ProductionUrl.'/', null],
    'a local copy' => ['http://devnull.test', 'http://devnull.test'],
]);

it('opens your devices on dev-null.sh', function () {
    signIn();

    Native::visit('/profile/device')
        ->tap('devices')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/settings/devices');
});

it('signs out only after a confirm', function () {
    signIn();

    $screen = Native::visit('/profile/device')
        ->tap('sign-out')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Sign out?'
            && array_column($params['buttons'], 'label') === ['Cancel', 'Sign out'])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'sign-out'])
        ->assertNoNavigation()
        ->assertNativeNotCalled('SecureStorage.Delete');

    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Sign out', 'id' => 'sign-out'])
        ->assertNativeCalled('SecureStorage.Delete', fn (array $params): bool => $params['key'] === DeviceKey::StorageKey)
        ->assertReplacedWith('/onboarding');

    expect(app(Account::class)->user())->toBeNull();

    // A new key is made next time, so the old one on GitHub no longer works here.
    expect(app(DeviceKey::class)->current()->openSsh())->not->toBe(testKey()->openSsh());
});

it('says what goes before deleting your account, and Cancel keeps it', function () {
    signIn();

    Native::visit('/profile/device')
        ->tap('delete-account')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Delete your account?'
            && $params['message'] === 'Your profile, commits, replies, stars and follows are removed from /dev/null. There is no undo.'
            && array_column($params['buttons'], 'label') === ['Cancel', 'Delete']
            && array_column($params['buttons'], 'style') === ['cancel', 'destructive'])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'delete-account'])
        ->assertNoNavigation()
        ->assertNativeNotCalled('SecureStorage.Delete');

    expect(deleteSent())->toBeFalse()
        ->and(app(Account::class)->user())->not->toBeNull();
});

it('deletes your account on devnull, then signs out to onboarding', function () {
    signIn();

    Native::visit('/profile/device')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-account'])
        ->assertNativeCalled('SecureStorage.Delete', fn (array $params): bool => $params['key'] === DeviceKey::StorageKey)
        ->assertNativeNotCalled('ToastKit.Show')
        ->assertReplacedWith('/onboarding');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://devnull.test/api/v1/me'
        && $request->body() === ''
        && $request->hasHeader('X-SSH-Signature'));

    expect(app(Account::class)->user())->toBeNull()
        ->and(app(DeviceKey::class)->current()->openSsh())->not->toBe(testKey()->openSsh());
});

it('keeps you signed in when devnull cannot delete your account', function (Closure $fake, string $message) {
    signIn();
    $fake();

    Native::visit('/profile/device')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-account'])
        ->assertSet('error', $message)
        ->assertSee($message)
        ->assertNoNavigation()
        ->assertNativeNotCalled('SecureStorage.Delete');

    expect(app(Account::class)->user())->not->toBeNull()
        ->and(app(DeviceKey::class)->current()->openSsh())->toBe(testKey()->openSsh());
})->with([
    'a server error' => [fn () => serveApi(['GET /api/v1/me' => [currentUserJson()], 'DELETE /api/v1/me' => [['message' => 'Server Error'], 500]]), 'Server Error'],
    'offline' => [fn () => fakeOffline(), "fatal: unable to access 'http://devnull.test': could not connect to the server"],
]);
