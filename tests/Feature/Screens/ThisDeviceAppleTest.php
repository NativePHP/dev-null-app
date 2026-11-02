<?php

use App\Devnull\Account;
use App\Devnull\AppleSignIn;
use App\Devnull\Client;
use App\Devnull\ErrorCode;
use App\NativeComponents\ThisDevice;
use Codingwithrk\NativephpSocialAuth\Events\SignInFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Platform;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
});

afterEach(fn () => Platform::set(null));

/**
 * A signed-in iPhone whose Apple sheet opens.
 */
function signedInAppleBridge(): FakeBridge
{
    return signIn()->respondTo('NativephpSocialAuth.AppleSignIn', ['pending' => true]);
}

/**
 * `GET /me` saying whether an Apple ID is linked.
 *
 * @return array{0: array<string, mixed>}
 */
function meWithApple(bool $linked): array
{
    return [['data' => userWithCountsJson(), 'unread_notifications_count' => 0, 'apple_linked' => $linked]];
}

it('shows that no Apple ID is linked, with a line on what linking is for and Apple\'s button', function () {
    signedInAppleBridge();
    fakeApi(['GET /api/v1/me' => meWithApple(false)]);

    $screen = Native::visit('/profile/device', platform: 'ios')
        ->assertSee('apple id')
        ->assertSee('A quicker way back in on a new phone. The account is still your GitHub one.')
        ->assertSet('appleLinked', false)
        ->assertDontSee('Unlink Apple ID');

    $status = nodeByRef($screen, 'apple-status');

    expect($status['text'] ?? $status['props']['text'] ?? null)->toBe('not linked')
        ->and(nodeByRef($screen, 'link-apple')['props'])->toMatchArray(['label' => 'Sign in with Apple', 'variant' => 'accent', 'leading_icon' => 'apple.logo'])
        ->and(nodeByRef($screen, 'link-apple')['layout'])->toEqual(['width' => 'fill']);

    // You, and your settings for your timezone.
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'http://devnull.test/api/v1/me');
});

it('shows a linked Apple ID with a way to unlink it', function () {
    signedInAppleBridge();
    fakeApi(['GET /api/v1/me' => meWithApple(true)]);

    $screen = Native::visit('/profile/device', platform: 'ios')
        ->assertSet('appleLinked', true)
        ->assertSee('Unlink Apple ID')
        ->assertDontSee('Sign in with Apple');

    $status = nodeByRef($screen, 'apple-status');

    expect($status['text'] ?? $status['props']['text'] ?? null)->toBe('linked')
        ->and(nodeByRef($screen, 'unlink-apple')['props']['variant'])->toBe('secondary');
});

it("says it doesn't know, and offers nothing, when devnull can't be asked", function () {
    signedInAppleBridge();
    fakeApi(['GET /api/v1/me' => [['message' => 'GitHub is not answering right now.'], 503]]);

    // The rest of the screen shows you as this phone remembers you, so it only says quietly that it couldn't refresh.
    Native::visit('/profile/device', platform: 'ios')
        ->assertSet('appleLinked', null)
        ->assertSee("warning: couldn't refresh")
        ->assertSee('unknown')
        ->assertDontSee('Sign in with Apple')
        ->assertDontSee('Unlink Apple ID');
});

it('goes to onboarding when devnull no longer takes this key', function () {
    signedInAppleBridge();
    fakeApi(['GET /api/v1/me' => [['message' => 'Not approved.', 'code' => 'device_not_approved'], 401]]);

    Native::visit('/profile/device', platform: 'ios')
        ->assertReplacedWith('/onboarding', ['message' => ErrorCode::DeviceNotApproved->message()]);

    expect(app(Account::class)->user())->toBeNull();
});

it("links the Apple ID with Apple's token and the raw nonce it was asked for", function () {
    $bridge = signedInAppleBridge();
    fakeApi(['GET /api/v1/me' => meWithApple(false), 'PUT /api/v1/me/apple' => [['data' => userWithCountsJson(), 'apple_linked' => true]]]);
    $screen = Native::visit('/profile/device', platform: 'ios')->tap('link-apple');
    $nonce = heldAppleNonce();

    expect(appleSheetParams($bridge))->toMatchArray(['nonce' => $nonce, 'scopes' => []])
        ->and($nonce)->toMatch('/^[0-9a-f]{64}$/');

    appleAnswers($screen, $nonce)
        ->assertNoNavigation()
        ->assertSet('appleLinked', true)
        ->assertSet('error', null)
        ->assertSee('Unlink Apple ID');

    expect(heldAppleNonce())->toBeNull()
        ->and(json_encode(get_object_vars($screen->instance())))->not->toContain(AppleIdentityToken)->not->toContain($nonce)
        ->and(json_encode($screen->tree()))->not->toContain(AppleIdentityToken);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'http://devnull.test/api/v1/'.Client::ApplePath
        && $request->body() === json_encode(['identity_token' => AppleIdentityToken, 'nonce' => $nonce])
        && $request->hasHeader('X-SSH-Signature'));
});

it("says by devnull's code why the Apple ID can't be linked", function (int $status, ErrorCode $code) {
    signedInAppleBridge();
    $message = $code->message();
    fakeApi(['GET /api/v1/me' => meWithApple(false), 'PUT /api/v1/me/apple' => [['message' => 'Whatever /dev/null words it as.', 'code' => $code->value], $status]]);
    $screen = Native::visit('/profile/device', platform: 'ios')->tap('link-apple');

    appleAnswers($screen, heldAppleNonce())
        ->assertNoNavigation()
        ->assertSet('error', $message)
        ->assertSee($message)
        ->assertSet('appleLinked', false)
        ->assertSee('Sign in with Apple');
})->with([
    'linked to another account' => [409, ErrorCode::AppleLinkedElsewhere],
    'a token devnull cannot verify' => [422, ErrorCode::InvalidAppleToken],
]);

it("returns quietly when Apple's sheet is closed, and says so when it fails", function () {
    signedInAppleBridge();
    fakeApi(['GET /api/v1/me' => meWithApple(false)]);
    $screen = Native::visit('/profile/device', platform: 'ios')->tap('link-apple');

    $screen->emitNative(SignInFailed::class, ['provider' => 'apple', 'error' => 'Sign in with Apple was cancelled.', 'errorCode' => AppleSignIn::Cancelled])
        ->assertSet('error', null)
        ->assertSet('appleLinked', false);

    expect(heldAppleNonce())->toBeNull();

    $screen->tap('link-apple')
        ->emitNative(SignInFailed::class, ['provider' => 'apple', 'error' => 'Something broke.', 'errorCode' => 'SIGN_IN_FAILED'])
        ->assertSee('fatal: Sign in with Apple failed. Something broke.');

    // Only you and your settings, when the screen opened.
    Http::assertSentCount(2);
});

it('rejects an answer it never asked for', function () {
    signedInAppleBridge();
    fakeApi(['GET /api/v1/me' => meWithApple(false)]);

    appleAnswers(Native::visit('/profile/device', platform: 'ios'), str_repeat('ab', 32))
        ->assertSet('error', AppleSignIn::NotStartedHere)
        ->assertSet('appleLinked', false);

    // Only you and your settings, when the screen opened.
    Http::assertSentCount(2);
});

it('unlinks the Apple ID only after a confirm', function () {
    signedInAppleBridge();
    fakeApi(['GET /api/v1/me' => meWithApple(true), 'DELETE /api/v1/me/apple' => [['data' => userWithCountsJson(), 'apple_linked' => false]]]);

    $screen = Native::visit('/profile/device', platform: 'ios')
        ->tap('unlink-apple')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Unlink your Apple ID?')
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => ThisDevice::UnlinkAppleAlert])
        ->assertSet('appleLinked', true);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Unlink', 'id' => ThisDevice::UnlinkAppleAlert])
        ->assertNoNavigation()
        ->assertSet('appleLinked', false)
        ->assertSee('Sign in with Apple');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://devnull.test/api/v1/me/apple'
        && $request->body() === ''
        && $request->hasHeader('X-SSH-Signature'));

    // Unlinking is not forgetting: the key and the sign-in stay.
    expect(app(Account::class)->user()?->username)->toBe('local_dev');
});

it('has no Apple section on Android, and never asks devnull about it', function () {
    $bridge = signedInAppleBridge();
    fakeApi(['GET /api/v1/me' => [currentUserJson()]]);

    Native::visit('/profile/device', platform: 'android')
        ->assertDontSee('Apple ID')
        ->assertDontSee('apple id')
        ->assertDontSee('Sign in with Apple')
        ->assertDontSee('A quicker way back in')
        ->call('linkApple')
        ->call('unlinkApple')
        ->assertSet('appleLinked', null)
        ->assertSee('Sign out');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), Client::ApplePath));
    $bridge->assertNotCalled('NativephpSocialAuth.AppleSignIn');
});
