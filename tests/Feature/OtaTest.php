<?php

use App\Providers\NativeServiceProvider;
use Nativephp\MobileOta\OtaServiceProvider;

it('registers the OTA plugin and updates silently when no mode is set', function () {
    expect((new NativeServiceProvider(app()))->plugins())->toContain(OtaServiceProvider::class)
        ->and(config('nativephp-ota.mode'))->toBe('silent');
});
