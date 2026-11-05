<?php

namespace App\Devnull;

use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\PushNotifications;

/**
 * The prompt on the Notifications and Remotes tabs about push notifications: an offer to turn them on while this
 * phone has no token or hasn't been asked yet, or, once they were refused or turned off in the phone's Settings, a way there. Dismissing
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

        $permission = PushNotifications::checkPermission();

        if ($permission === 'denied') {
            return self::TurnedOff;
        }

        return ! self::allowed($permission) || PushNotifications::getToken() === null ? self::Offer : null;
    }

    /**
     * Whether the phone lets devnull show notifications. Android hands out a token before it has asked, so a token
     * alone doesn't mean they're on. Off a device the phone can't say, which counts as allowed.
     */
    public static function allowed(?string $permission): bool
    {
        return ! in_array($permission, ['denied', 'not_determined'], true);
    }

    public function dismiss(): void
    {
        Cache::forever(self::DismissedKey, true);
    }
}
