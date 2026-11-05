<?php

namespace App\Devnull;

use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\PushNotifications;

/**
 * The prompt on the Notifications and Remotes tabs about push notifications: an offer to turn them on while this
 * phone has no token, or, once they were refused or turned off in the phone's Settings, a way there. Dismissing
 * it hides it for good on this phone. The Account screen's button stays either way.
 */
final class PushPrompt
{
    /**
     * This phone has no token yet, and a tap asks for one.
     */
    public const string Offer = 'offer';

    /**
     * The phone has notifications turned off for devnull, and only its Settings can turn them back on.
     */
    public const string TurnedOff = 'turned-off';

    private const string DismissedKey = 'devnull.push-prompt.dismissed';

    /**
     * Which prompt to show, or null for none.
     */
    public function current(): ?string
    {
        if (Cache::get(self::DismissedKey) === true) {
            return null;
        }

        if (PushNotifications::checkPermission() === 'denied') {
            return self::TurnedOff;
        }

        return PushNotifications::getToken() === null ? self::Offer : null;
    }

    public function dismiss(): void
    {
        Cache::forever(self::DismissedKey, true);
    }
}
