<?php

use Native\Mobile\Testing\Native;

it('says what devnull is without touching the Keychain', function () {
    $bridge = Native::fakeBridge();

    Native::visit('/welcome')
        ->assertSee('/dev/null')
        ->assertSee('A social network for developers.')
        ->assertSee('Sign in with GitHub')
        ->assertAccessible();

    $bridge->assertNotCalled('SecureStorage.Get');
    $bridge->assertNotCalled('SecureStorage.Set');
});

it('goes on to onboarding to sign in with GitHub', function () {
    Native::visit('/welcome')
        ->tap('sign-in')
        ->assertNavigatedTo('/onboarding');
});
