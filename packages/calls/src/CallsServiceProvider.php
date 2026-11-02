<?php

namespace Devnull\Calls;

use Illuminate\Support\ServiceProvider;

class CallsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Calls::class);
    }
}
