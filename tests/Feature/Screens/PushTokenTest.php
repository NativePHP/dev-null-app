<?php

use App\NativeComponents\Notifications;
use App\NativeComponents\Remotes;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    $this->travelTo('2026-10-19 15:30:00');
});

/**
 * devnull for these tests: empty tabs, you, and the push token endpoint answering $tokenStatus.
 */
function servePushToken(int $tokenStatus = 204): void
{
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/remotes' => [remotesInboxJson([])],
        'GET /api/v1/notifications' => [['data' => [], 'meta' => ['next_cursor' => null]]],
        'PUT /api/v1/me/push-token' => [$tokenStatus === 204 ? null : ['message' => 'Server Error'], $tokenStatus],
        'DELETE /api/v1/me/push-token' => [null, 204],
    ]);
}

it('sends devnull a new token as soon as it arrives, with the platform', function (string $screen) {
    servePushToken();
    signIn();

    Native::test($screen)->emitNative(TokenGenerated::class, ['token' => 'fcm-new-token']);

    expect(sentTo('PUT', 'me/push-token'))->toBe(['{"token":"fcm-new-token","platform":"android"}']);
})->with([Notifications::class, Remotes::class]);

it('sends the phone\'s token once when a screen opens, and not again while devnull has it', function () {
    servePushToken();
    signIn()->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token']);

    Native::test(Notifications::class);
    Native::test(Remotes::class);
    Native::visit('/profile/device');

    expect(sentTo('PUT', 'me/push-token'))->toHaveCount(1);
});

it('sends the token again next time when devnull didn\'t take it', function () {
    servePushToken(500);
    signIn()->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token']);

    Native::test(Notifications::class);
    Native::test(Notifications::class);

    expect(sentTo('PUT', 'me/push-token'))->toHaveCount(2);
});

it('sends the token again for someone else signing in on the phone', function () {
    servePushToken();
    signIn()->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token']);
    Native::test(Notifications::class);

    signIn(['id' => 'someone-else']);
    Native::fakeBridge()->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token']);
    Native::test(Notifications::class);

    expect(sentTo('PUT', 'me/push-token'))->toHaveCount(2);
});

it('sends nothing while the phone has no token', function () {
    servePushToken();
    signIn();

    Native::test(Notifications::class);
    Native::visit('/profile/device');

    expect(sentTo('PUT', 'me/push-token'))->toBe([]);
});

it('asks devnull to forget the token before signing out, and sends it afresh after', function () {
    servePushToken();
    signIn()->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token']);

    Native::visit('/profile/device')
        ->emitNative(ButtonPressed::class, ['label' => 'Sign out', 'id' => 'sign-out']);

    expect(sentTo('DELETE', 'me/push-token'))->toHaveCount(1);

    signIn()->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token']);
    Native::test(Notifications::class);

    expect(sentTo('PUT', 'me/push-token'))->toHaveCount(2);
});

it('signs out without asking devnull anything when the phone has no token', function () {
    servePushToken();
    signIn();

    Native::visit('/profile/device')
        ->emitNative(ButtonPressed::class, ['label' => 'Sign out', 'id' => 'sign-out']);

    expect(sentTo('DELETE', 'me/push-token'))->toBe([]);
});

it('keeps the token back until the phone lets devnull show notifications', function () {
    servePushToken();
    signIn()
        ->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token'])
        ->respondTo('PushNotification.CheckPermission', ['status' => 'not_determined']);

    Native::test(Notifications::class);
    Native::visit('/profile/device')->assertSee('Turn on notifications')->assertDontSee('Notifications are on');

    expect(sentTo('PUT', 'me/push-token'))->toBe([]);
});
