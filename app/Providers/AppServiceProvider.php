<?php

namespace App\Providers;

use App\Devnull\Client;
use App\Devnull\DeviceKey;
use App\Devnull\GitHub;
use App\Devnull\KeyStore;
use App\Devnull\KeyStores\EncryptedFile;
use App\Devnull\KeyStores\Keychain;
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
        ));

        $this->app->bind(GitHub::class, fn (): GitHub => new GitHub((string) config('devnull.github_client_id')));

        $this->app->bind(KeyStore::class, fn (Application $app): KeyStore => config('devnull.key_store') === 'file'
            ? $app->make(EncryptedFile::class)
            : $app->make(Keychain::class));
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
