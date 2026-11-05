<?php

use App\Devnull\PushPrompt;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    serveApi(['GET /api/v1/me' => [currentUserJson()]]);
});

it('offers to turn on notifications, even after the tabs\' prompt is dismissed, and says once they\'re on', function () {
    $bridge = signIn()->respondTo('PushNotification.CheckPermission', ['status' => 'not_determined']);
    app(PushPrompt::class)->dismiss();

    $screen = Native::visit('/profile/device')
        ->assertSee('Turn on notifications')
        ->tap('enroll-push');

    $bridge->assertCalled('PushNotification.RequestPermission');

    $screen->emitNative(TokenGenerated::class, ['token' => 'fcm-secret-token'])
        ->assertSee('Notifications are on')
        ->assertDontSee('Turn on notifications')
        ->assertDontSee('fcm-secret-token');
});

it('says notifications are on when the phone already has a token', function () {
    signIn()
        ->respondTo('PushNotification.GetToken', ['token' => 'fcm-secret-token'])
        ->respondTo('PushNotification.CheckPermission', ['status' => 'granted']);

    Native::visit('/profile/device')
        ->assertSee('Notifications are on')
        ->assertDontSee('Turn on notifications')
        ->assertElement('button', fn (array $node): bool => ($node['props']['disabled'] ?? false) === true && str_contains(json_encode($node), 'Notifications are on'));
});

it('sends you to the phone\'s Settings once notifications were turned off there', function () {
    $bridge = signIn()
        ->respondTo('PushNotification.GetToken', ['token' => 'fcm-secret-token'])
        ->respondTo('PushNotification.CheckPermission', ['status' => 'denied']);

    Native::visit('/profile/device')
        ->assertSee('Turn on in Settings')
        ->assertSee('Notifications are off for devnull on this phone.')
        ->assertDontSee('Notifications are on')
        ->tap('open-push-settings');

    $bridge->assertCalled('System.OpenAppSettings');
    $bridge->assertNotCalled('PushNotification.RequestPermission');
});
