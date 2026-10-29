<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Data\User;
use Native\Mobile\Facades\Browser;

/**
 * The web's live stream sheet (see partials/live-stream-sheet), which a tap on
 * a live user's avatar opens on a phone, as the web does on touch: the
 * stream's thumbnail and Watch now, then who it is with Open profile, which
 * their own profile leaves out.
 */
trait OpensLiveStream
{
    /**
     * The user whose stream the sheet shows, in the API's shape, from the tap
     * until iOS says the sheet has gone.
     *
     * @var array<string, mixed>|null
     */
    public ?array $liveStreamer = null;

    /**
     * Whether the sheet offers their profile, which it doesn't on it.
     */
    public bool $liveStreamWithProfile = true;

    public function openLiveStream(string $username, bool $withProfile = true): void
    {
        $streamer = $this->streamer($username);

        if ($streamer === null || ! $streamer->isLive()) {
            $this->openProfile($username);

            return;
        }

        $this->liveStreamer = $streamer->toArray();
        $this->liveStreamWithProfile = $withProfile;
    }

    /**
     * Watch now, in the system browser, so the Twitch or YouTube app takes it when it's there.
     */
    public function watchLiveStream(): void
    {
        $url = $this->liveStreamUser()?->live?->url;

        if ($url !== null) {
            Browser::open($url);
        }
    }

    public function openLiveStreamerProfile(): void
    {
        $username = $this->liveStreamUser()?->username;

        $this->liveStreamClosed();

        if ($username !== null) {
            $this->openProfile($username);
        }
    }

    /**
     * iOS says the sheet has gone.
     */
    public function liveStreamClosed(): void
    {
        $this->liveStreamer = null;
        $this->liveStreamWithProfile = true;
    }

    public function liveStreamUser(): ?User
    {
        return $this->liveStreamer === null ? null : User::fromArray($this->liveStreamer);
    }

    /**
     * The user a row on this screen shows by that username.
     */
    protected function streamer(string $username): ?User
    {
        return $this->shownAuthor($username);
    }
}
