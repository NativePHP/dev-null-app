<?php

use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    serveApi(['GET /api/v1/me' => [currentUserJson()]]);
});

it('turns on notifications and says a token arrived without showing it', function () {
    $bridge = signIn()->respondTo('PushNotification.CheckPermission', ['status' => 'not_determined']);

    $screen = Native::visit('/profile/device')
        ->assertSee('no token')
        ->tap('enroll-push');

    $bridge->assertCalled('PushNotification.RequestPermission');

    $bridge->respondTo('PushNotification.CheckPermission', ['status' => 'granted']);

    $screen->emitNative(TokenGenerated::class, ['token' => 'fcm-secret-token'])
        ->assertSee('token received')
        ->assertDontSee('fcm-secret-token')
        ->assertDontSee('Turn on notifications');
});

it('says a token arrived when the phone already has one', function () {
    signIn()
        ->respondTo('PushNotification.GetToken', ['token' => 'fcm-secret-token'])
        ->respondTo('PushNotification.CheckPermission', ['status' => 'granted']);

    Native::visit('/profile/device')
        ->assertSee('token received')
        ->assertDontSee('fcm-secret-token')
        ->assertDontSee('Turn on notifications');
});

it('points to the phone\'s Settings once notifications were refused', function () {
    signIn()->respondTo('PushNotification.CheckPermission', ['status' => 'denied']);

    Native::visit('/profile/device')
        ->assertSee('turned off')
        ->assertSee("Notifications are off for devnull. Turn them on in your phone's Settings.")
        ->assertDontSee('Turn on notifications');
});
