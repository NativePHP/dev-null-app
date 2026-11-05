<?php

use App\Devnull\PushPrompt;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    serveApi(['GET /api/v1/me' => [currentUserJson()]]);
});

it('always offers to turn on notifications, even after the tabs\' prompt is dismissed', function (array $token) {
    $bridge = signIn()->respondTo('PushNotification.GetToken', $token);
    app(PushPrompt::class)->dismiss();

    Native::visit('/profile/device')
        ->assertSee('Turn on notifications')
        ->assertDontSee('token received')
        ->tap('enroll-push');

    $bridge->assertCalled('PushNotification.RequestPermission');
})->with([
    'no token' => [['token' => '']],
    'a token' => [['token' => 'fcm-secret-token']],
]);

it('points to the phone\'s Settings once notifications were refused', function () {
    signIn()->respondTo('PushNotification.CheckPermission', ['status' => 'denied']);

    Native::visit('/profile/device')
        ->assertSee('Turn on notifications')
        ->assertSee("Notifications are off for devnull. Turn them on in your phone's Settings.");
});
