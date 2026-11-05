<?php

use App\NativeComponents\Notifications;
use App\NativeComponents\Remotes;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    $this->travelTo('2026-10-19 15:30:00');
    fakeApi([
        'GET /api/v1/remotes' => [remotesInboxJson([])],
        'GET /api/v1/notifications' => [['data' => [], 'meta' => ['next_cursor' => null]]],
    ]);
});

it('offers notifications at the top of the tab while the phone has no token, and a tap turns them on', function (string $screen) {
    $bridge = signIn()->respondTo('PushNotification.CheckPermission', ['status' => 'not_determined']);

    $tab = Native::test($screen)
        ->assertSee('Turn on notifications')
        ->tap('enroll-push');

    $bridge->assertCalled('PushNotification.RequestPermission');
    $tab->assertDontSee('Turn on notifications');
})->with([Notifications::class, Remotes::class]);

it('takes the prompt away once a token arrives', function (string $screen) {
    signIn();

    Native::test($screen)
        ->assertSee('Turn on notifications')
        ->emitNative(TokenGenerated::class, ['token' => 'fcm-secret-token'])
        ->assertDontSee('Turn on notifications')
        ->assertDontSee('fcm-secret-token');
})->with([Notifications::class, Remotes::class]);

it('never offers notifications again once the prompt is dismissed, on either tab', function () {
    $bridge = signIn();

    Native::test(Notifications::class)
        ->tap('dismiss-push-prompt')
        ->assertDontSee('Turn on notifications');

    Native::test(Notifications::class)->assertDontSee('Turn on notifications');
    Native::test(Remotes::class)->assertDontSee('Turn on notifications');

    $bridge->assertNotCalled('PushNotification.RequestPermission');
});

it('offers nothing when the phone has a token', function () {
    signIn()->respondTo('PushNotification.GetToken', ['token' => 'fcm-secret-token']);

    Native::test(Notifications::class)->assertDontSee('Turn on notifications');
    Native::test(Remotes::class)->assertDontSee('Turn on notifications');
});

it('says notifications are off, and a tap opens the phone\'s Settings, once they were turned off', function (string $screen) {
    $bridge = signIn()
        ->respondTo('PushNotification.GetToken', ['token' => 'fcm-secret-token'])
        ->respondTo('PushNotification.CheckPermission', ['status' => 'denied']);

    Native::test($screen)
        ->assertSee('Notifications are off')
        ->assertDontSee('Turn on notifications')
        ->tap('open-push-settings');

    $bridge->assertCalled('System.OpenAppSettings');
    $bridge->assertNotCalled('PushNotification.RequestPermission');
})->with([Notifications::class, Remotes::class]);

it('stays dismissed once notifications are turned off', function () {
    $bridge = signIn()->respondTo('PushNotification.CheckPermission', ['status' => 'denied']);

    Native::test(Remotes::class)->tap('dismiss-push-prompt')->assertDontSee('Notifications are off');

    Native::test(Notifications::class)->assertDontSee('Notifications are off');
});

it('still offers notifications when the phone has a token but hasn\'t been asked, as on Android', function (string $screen) {
    signIn()
        ->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token'])
        ->respondTo('PushNotification.CheckPermission', ['status' => 'not_determined']);

    Native::test($screen)->assertSee('Turn on notifications');
})->with([Notifications::class, Remotes::class]);
