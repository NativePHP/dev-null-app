<?php

namespace Devnull\Calls\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool start(array $iceServers, bool $isRelayOnly, ?string $offer = null)
 * @method static bool answer(string $sdp)
 * @method static bool setMuted(bool $isMuted)
 * @method static bool setSpeaker(bool $isOn)
 * @method static bool end()
 *
 * @see \Devnull\Calls\Calls
 */
class Calls extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Devnull\Calls\Calls::class;
    }
}
