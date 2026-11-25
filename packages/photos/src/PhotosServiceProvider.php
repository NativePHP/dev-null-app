<?php

namespace Devnull\Photos;

use Illuminate\Support\ServiceProvider;

class PhotosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Photos::class);
    }
}
