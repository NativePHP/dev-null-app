<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\PushPrompt;
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Facades\PushNotifications;

/**
 * The prompt to turn on push notifications, at the top of a tab (see App\Devnull\PushPrompt and the push-prompt
 * partial). The screen asks whether to show it when it mounts and when you come back to it.
 */
trait OffersPush
{
    public bool $offersPush = false;

    protected function checkPushPrompt(): void
    {
        $this->offersPush = app(PushPrompt::class)->shows();
    }

    /**
     * Asks the phone for notifications, prompting the first time. The prompt goes at once: the token, or a refusal,
     * keeps it away.
     */
    public function enrollPush(): void
    {
        $this->offersPush = false;

        PushNotifications::enroll();
    }

    public function dismissPushPrompt(): void
    {
        $this->offersPush = false;

        app(PushPrompt::class)->dismiss();
    }

    #[On(TokenGenerated::class)]
    public function pushTokenReceived(): void
    {
        $this->offersPush = false;
    }
}
