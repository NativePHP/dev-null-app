<?php

namespace App\Providers;

use App\Devnull\AppleSignIn;
use App\Devnull\Client;
use App\Devnull\DeviceKey;
use App\Devnull\ImageCache;
use App\Devnull\KeyStore;
use App\Devnull\KeyStoreChoice;
use App\Devnull\ResponseCache;
use App\Devnull\SignIn;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Client::class, fn (Application $app): Client => new Client(
            $app->make(DeviceKey::class),
            (string) config('devnull.url'),
            $app->make(ResponseCache::class),
        ));

        $this->app->bind(KeyStore::class, fn (Application $app): KeyStore => $app->make(KeyStoreChoice::here()->store()));

        $this->app->singleton(DeviceKey::class);
        $this->app->singleton(SignIn::class);
        $this->app->singleton(AppleSignIn::class);
        $this->app->singleton(ImageCache::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
