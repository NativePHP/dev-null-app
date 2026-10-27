<?php

use Native\Mobile\Plugins\PluginRegistry;

it('builds the iOS app with its data out of backups and locked away while the phone is locked', function () {
    $registry = app(PluginRegistry::class);
    $plugin = $registry->find('devnull/ios-data-protection');

    expect($plugin)->not->toBeNull()
        ->and($registry->isRegistered('devnull/ios-data-protection'))->toBeTrue()
        ->and($plugin->getIosEntitlements())->toBe(['com.apple.developer.default-data-protection' => 'NSFileProtectionCompleteUnlessOpen'])
        ->and($plugin->getIosInitFunction())->toBe('protectAppData')
        ->and(file_get_contents($plugin->path.'/resources/ios/DataProtection.swift'))->toContain('func protectAppData()');
});
