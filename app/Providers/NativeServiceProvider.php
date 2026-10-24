<?php

namespace App\Providers;

use Codingwithrk\NativephpSocialAuth\NativephpSocialAuthServiceProvider;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Providers\BrowserServiceProvider;
use Native\Mobile\Providers\CameraServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;
use Native\Mobile\Providers\ShareServiceProvider;
use Native\Mobile\UI\NativeUIServiceProvider;
use S2BR\MobileSplashscreen\MobileSplashscreenServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * The social auth plugin brings the Sign in with Apple entitlement, so it
     * only goes in when that's turned on (see config/devnull.php).
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            NativeUIServiceProvider::class,
            BrowserServiceProvider::class,
            CameraServiceProvider::class,
            SecureStorageServiceProvider::class,
            ShareServiceProvider::class,
            MobileSplashscreenServiceProvider::class,
            ...(config('devnull.apple_sign_in') ? [NativephpSocialAuthServiceProvider::class] : []),
        ];
    }
}
