<?php

use App\Devnull\Account;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    app(Account::class)->rememberProfile(['data' => userWithCountsJson(['name' => 'Local Dev', 'bio' => 'Ships things.'], commits: 12, followers: 3)]);
});

it("shows your own profile from the copy kept since you signed in when devnull can't be reached", function () {
    fakeOffline();

    Native::visit('/profile')
        ->assertSee('Local Dev')
        ->assertSee('Ships things.')
        ->assertSee('3 followers');
});

it('shows it in place of a kept answer with nobody in it, and keeps it when devnull answers with a maintenance page', function () {
    keep('users/local_dev', []);
    serveApi(fn () => Http::response('<html>Down for maintenance</html>'));

    Native::visit('/profile')->assertSee('Local Dev')->assertSee('3 followers');

    expect(app(Account::class)->profile()['data']['name'] ?? null)->toBe('Local Dev');
});

it('keeps your profile current whenever it loads', function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [['data' => userWithCountsJson(['name' => 'Local Dev', 'bio' => 'Ships more things.'], commits: 13)]],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
    ]);

    Native::visit('/profile')->assertSee('Ships more things.');

    expect(app(Account::class)->profile()['data']['bio'] ?? null)->toBe('Ships more things.');
});

it("doesn't keep someone else's profile as yours", function () {
    fakeApi([
        'GET /api/v1/users/ada' => [['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']), 'is_following' => false]],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    Native::visit('/log/users/ada')->assertSee('Ada');

    expect(app(Account::class)->profile()['data']['handle'] ?? null)->toBe('local_dev');
});

it('forgets your kept profile when you sign out', function () {
    app(Account::class)->forget();

    expect(app(Account::class)->profile())->toBeNull();
});
