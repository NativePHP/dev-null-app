<?php

namespace Devnull\Photos\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool save(string $path)
 *
 * @see \Devnull\Photos\Photos
 */
class Photos extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Devnull\Photos\Photos::class;
    }
}
