<?php

use Native\Mobile\Testing\Native;

it('sends a new device to the welcome screen', function () {
    fakeKeychain();

    Native::visit('/')->assertReplacedWith('/welcome');
});

it('sends a verified device straight to the log', function () {
    signIn();

    Native::visit('/')->assertReplacedWith('/log');
});
