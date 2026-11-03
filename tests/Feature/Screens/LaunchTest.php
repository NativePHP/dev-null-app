<?php

use App\Devnull\Account;
use App\Devnull\Data\User;
use App\NativeComponents\AcceptTerms;
use App\NativeComponents\Launch;
use Native\Mobile\Testing\Native;

it('sends a new device to onboarding', function () {
    fakeKeychain();

    Native::visit('/')->assertReplacedWith('/onboarding');
});

it('sends a verified device straight to the log', function () {
    signIn();

    Native::visit('/')->assertReplacedWith('/log');
});

it('sends a verified device to accept the terms first when devnull last said they are owed', function () {
    signIn();
    app(Account::class)->rememberTermsToAccept('2026-11-01T14:35:00Z');

    Native::visit('/')->assertReplacedWith(AcceptTerms::Path);
});

it('stays signed in across a rebuild, which keeps the key and the stored sign-in', function () {
    $bridge = signIn();

    Native::visit('/')->assertReplacedWith('/log');
    Native::visit('/')->assertReplacedWith('/log');

    expect(app(Account::class)->user()?->username)->toBe('local_dev');
    $bridge->assertNotCalled('SecureStorage.Set');
});

it('signs out a verified device whose key has gone, says why, and makes no key itself', function () {
    $bridge = fakeKeychain();
    app(Account::class)->remember(User::fromArray(userJson()));

    $screen = Native::visit('/')->assertReplacedWith('/onboarding');

    expect($screen->navigationIntent()->data)->toBe(['message' => Launch::KeyGone])
        ->and(app(Account::class)->user())->toBeNull();
    $bridge->assertNotCalled('SecureStorage.Set');
});

it('keeps a verified device signed in when the Keychain cannot be read just now', function () {
    Native::fakeBridge()->respondTo('SecureStorage.Get', ['status' => 'unavailable', 'code' => 'UNAVAILABLE', 'message' => 'Locked.']);
    app(Account::class)->remember(User::fromArray(userJson()));

    Native::visit('/')->assertReplacedWith('/log');

    expect(app(Account::class)->user())->not->toBeNull();
});
