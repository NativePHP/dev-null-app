<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\User;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\PushPrompt;
use App\Devnull\PushToken;
use Closure;
use Native\Mobile\Facades\PushNotifications;

/**
 * Sends this phone's push token to devnull, which keeps it on the device the request is signed with. A screen sends
 * the phone's token when it opens, and a new one as soon as it arrives, once the phone lets devnull show
 * notifications. One that didn't land goes again the next
 * time, and one devnull already has isn't sent twice (see PushToken).
 */
trait SendsPushToken
{
    abstract protected function me(): ?User;

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  Closure(array<string, mixed>): void  $done
     * @param  Closure(string, ?ApiException): void  $failed
     */
    abstract protected function writeInBackground(string $method, string $path, ?array $payload, Closure $done, Closure $failed): void;

    protected function sendPushToken(?string $token = null): void
    {
        $token ??= PushNotifications::getToken();
        $userId = $this->me()?->id;

        if ($token === null || $userId === null || ! PushPrompt::allowed(PushNotifications::checkPermission()) || ! app(PushToken::class)->needsSending($userId, $token)) {
            return;
        }

        $this->writeInBackground(
            'PUT',
            Client::PushTokenPath,
            ['token' => $token, 'platform' => PushToken::platform()],
            fn () => app(PushToken::class)->sent($userId, $token),
            fn () => null,
        );
    }

    /**
     * Asks devnull to forget this phone's token before the key that signs for it goes. devnull keeps the device
     * approved after a sign-out, so it would go on sending to it otherwise.
     */
    protected function dropPushToken(): void
    {
        app(PushToken::class)->forget();

        if (PushNotifications::getToken() === null) {
            return;
        }

        $this->writeInBackground('DELETE', Client::PushTokenPath, null, fn () => null, fn () => null);
    }
}
