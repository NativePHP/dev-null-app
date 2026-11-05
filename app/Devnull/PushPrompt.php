<?php

namespace App\Devnull;

use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\PushNotifications;

/**
 * The prompt on the Notifications and Remotes tabs that offers push notifications. It shows while this phone has no
 * push token, until you turn them on, refuse them when the phone asks, or dismiss it, which hides it for good on
 * this phone. The Account screen's button stays either way.
 */
final class PushPrompt
{
    private const string DismissedKey = 'devnull.push-prompt.dismissed';

    public function shows(): bool
    {
        if (Cache::get(self::DismissedKey) === true) {
            return false;
        }

        return PushNotifications::getToken() === null && PushNotifications::checkPermission() !== 'denied';
    }

    public function dismiss(): void
    {
        Cache::forever(self::DismissedKey, true);
    }
}
