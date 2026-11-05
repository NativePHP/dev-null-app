<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\PushPrompt;
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;

/**
 * The push notifications prompt at the top of a tab (see App\Devnull\PushPrompt and the push-prompt partial). The
 * screen asks which to show when it mounts and when you come back to it, and sends devnull the phone's token then
 * too (see SendsPushToken).
 */
trait OffersPush
{
    use SendsPushToken;

    /**
     * PushPrompt::Offer, PushPrompt::TurnedOff, or null for none.
     */
    public ?string $pushPrompt = null;

    protected function checkPushPrompt(): void
    {
        $this->pushPrompt = app(PushPrompt::class)->current();
        $this->sendPushToken();
    }

    /**
     * Asks the phone for notifications, prompting the first time. The prompt goes at once: the token, or a refusal,
     * decides what shows next time.
     */
    public function enrollPush(): void
    {
        $this->pushPrompt = null;

        PushNotifications::enroll();
    }

    /**
     * devnull's page in the phone's Settings, the only place notifications come back on once they were turned off.
     */
    public function openPushSettings(): void
    {
        System::appSettings();
    }

    public function dismissPushPrompt(): void
    {
        $this->pushPrompt = null;

        app(PushPrompt::class)->dismiss();
    }

    #[On(TokenGenerated::class)]
    public function pushTokenReceived(string $token): void
    {
        $this->pushPrompt = null;
        $this->sendPushToken($token);
    }
}
