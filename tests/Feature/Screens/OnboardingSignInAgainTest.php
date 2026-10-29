<?php

use App\Devnull\Account;
use App\Devnull\DeviceKey;
use App\Devnull\ErrorCode;
use App\NativeComponents\Launch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    $this->freezeTime();
});

it('signs straight back in when the stored sign-in is gone but the key is still approved', function () {
    $bridge = fakeKeychain(testSeed());
    fakeApi(['GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 4]]]);

    $screen = Native::visit('/')->assertReplacedWith('/onboarding');
    $screen = Native::visit('/onboarding')->assertSet('fingerprint', testKey()->fingerprint());

    Http::assertNothingSent();

    $this->travel(1)->seconds();
    $screen->firePoll('tick')->assertReplacedWith('/log');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/me'
        && $request->hasHeader('X-SSH-Signature'));

    expect(app(Account::class)->user()?->username)->toBe('local_dev')
        ->and(app(Account::class)->unreadNotifications())->toBe(4)
        ->and(app(DeviceKey::class)->current()->openSsh())->toBe(testKey()->openSsh());

    $bridge->assertNotCalled('SecureStorage.Set');
});

it('asks only once, and says nothing, when devnull does not know the key', function (int $status) {
    fakeKeychain(testSeed());
    fakeApi(['GET /api/v1/me' => [['message' => 'Unauthenticated.'], $status]]);

    $screen = Native::visit('/onboarding');

    foreach (range(1, 3) as $second) {
        $this->travel(1)->seconds();
        $screen->firePoll('tick');
    }

    $screen->assertNoNavigation()
        ->assertSet('error', null)
        ->assertSee('Sign in on dev-null.sh');

    Http::assertSentCount(1);
    expect(app(Account::class)->user())->toBeNull();
})->with([401, 500]);

it('does not ask for a key it has only just made', function () {
    fakeKeychain();
    fakeApi([]);

    $screen = Native::visit('/onboarding');
    $this->travel(1)->seconds();
    $screen->firePoll('tick');

    Http::assertNothingSent();
});

it('does not ask when another screen sent the phone here', function (array $data) {
    fakeKeychain(testSeed());
    fakeApi([]);

    $screen = Native::visit('/onboarding', $data);
    $this->travel(1)->seconds();
    $screen->firePoll('tick');

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/me'));
})->with([
    'signed out with a reason' => [['message' => Launch::KeyGone]],
    'to approve the phone' => [['message' => ErrorCode::DeviceNotApproved->message()]],
]);
