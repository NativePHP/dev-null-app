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
    $bridge->respondTo('PushNotification.CheckPermission', ['status' => 'granted']);

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

/**
 * `GET /me/settings` as devnull answers it, with the push notifications given.
 *
 * @param  array<string, bool>  $push
 * @return array<string, mixed>
 */
function settingsWithPush(array $push = []): array
{
    return ['data' => [
        'timezone' => null,
        'show_local_time' => false,
        'push' => [...['follows' => true, 'replies' => true, 'mentions' => true, 'direct_remotes' => true, 'org_remotes' => true], ...$push],
    ]];
}

it('shows which push notifications you get, as devnull says', function () {
    serveApi(['GET /api/v1/me' => [currentUserJson()], 'GET /api/v1/me/settings' => [settingsWithPush(['mentions' => false])]]);
    signInWithPushOn();

    $screen = Native::visit('/profile/device')
        ->assertSee('Push notifications')
        ->assertSee('Follows')
        ->assertSee('Replies')
        ->assertSee('Mentions')
        ->assertSee('Remotes messages')
        ->assertSee('Org messages');

    expect(nodeByRef($screen, 'push-mentions-switch')['props']['value'] ?? null)->toBeFalse()
        ->and(nodeByRef($screen, 'push-follows-switch')['props']['value'] ?? null)->toBeTrue();
});

it('saves a push notification switch with all five', function () {
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/me/settings' => [settingsWithPush()],
        'PUT /api/v1/me/push-preferences' => [['data' => settingsWithPush(['org_remotes' => false])['data']['push']]],
    ]);
    signInWithPushOn();

    $screen = Native::visit('/profile/device')->toggle('push-org_remotes-switch', false);

    expect(sentTo('PUT', 'me/push-preferences'))->toBe(['{"follows":true,"replies":true,"mentions":true,"direct_remotes":true,"org_remotes":false}'])
        ->and(nodeByRef($screen, 'push-org_remotes-switch')['props']['value'] ?? null)->toBeFalse();
});

it('puts the switch back when devnull can\'t save it', function () {
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/me/settings' => [settingsWithPush()],
        'PUT /api/v1/me/push-preferences' => [['message' => 'Server Error'], 500],
    ]);
    signInWithPushOn();

    $screen = Native::visit('/profile/device')
        ->toggle('push-replies-switch', false)
        ->assertSee('Could not save your notification settings. Try again.');

    expect(nodeByRef($screen, 'push-replies-switch')['props']['value'] ?? null)->toBeTrue();
});

it('keeps the switches hidden until this phone gets notifications', function () {
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/me/settings' => [settingsWithPush()],
        'PUT /api/v1/me/push-token' => [null, 204],
    ]);
    $bridge = signIn()->respondTo('PushNotification.CheckPermission', ['status' => 'not_determined']);

    $screen = Native::visit('/profile/device')
        ->assertSee('Turn on notifications')
        ->assertDontSee('Org messages');

    $bridge->respondTo('PushNotification.CheckPermission', ['status' => 'granted']);

    $screen->emitNative(TokenGenerated::class, ['token' => 'fcm-new-token'])
        ->assertSee('Notifications are on')
        ->assertSee('Org messages');
});

it('hides the switches once notifications are turned off in the phone\'s Settings', function () {
    serveApi(['GET /api/v1/me' => [currentUserJson()], 'GET /api/v1/me/settings' => [settingsWithPush()]]);
    signIn()
        ->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token'])
        ->respondTo('PushNotification.CheckPermission', ['status' => 'denied']);

    Native::visit('/profile/device')
        ->assertSee('Turn on in Settings')
        ->assertDontSee('Org messages');
});

/**
 * Signs in on a phone that has a push token and notifications allowed.
 */
function signInWithPushOn(): void
{
    signIn()
        ->respondTo('PushNotification.GetToken', ['token' => 'fcm-phone-token'])
        ->respondTo('PushNotification.CheckPermission', ['status' => 'granted']);
}
