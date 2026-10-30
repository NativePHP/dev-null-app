<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\ImageCache;
use App\NativeComponents\Concerns\TalksToDevnull;
use Carbon\CarbonImmutable;
use Devnull\Calls\Events\Connected;
use Devnull\Calls\Events\DescriptionReady;
use Devnull\Calls\Events\Ended;
use Devnull\Calls\Facades\Calls;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Device;

/**
 * A one-to-one call, as the web's: calling someone from your thread with them, or answering them when they call you
 * (see WatchesForCalls), whether they're on the web or in the app.
 *
 * The two ends swap an offer and an answer through devnull, then talk directly through the relay, encrypted from one
 * to the other (see the Calls plugin), so neither devnull nor the relay can hear a call. Calling rings them for 45
 * seconds; once they pick up, the call has 20 seconds to connect. Once it does, it shows the web's short code, made
 * from both ends' keys: if you both read out the same one, nobody is listening in between.
 *
 * It's the microphone only for now: a partner on the web can still turn their camera on, which this end doesn't
 * show yet. The call lasts as long as this screen: leaving it hangs up.
 */
class Call extends NativeComponent
{
    use TalksToDevnull;

    public const string Calling = 'calling';

    public const string Ringing = 'ringing';

    public const string Connecting = 'connecting';

    public const string Connected = 'connected';

    public const string Ended = 'ended';

    /**
     * How long a call rings, as devnull keeps it.
     */
    public const int RingSeconds = 45;

    /**
     * How long a picked-up call has to connect before it gives up, as the web's does.
     */
    public const int ConnectSeconds = 20;

    /**
     * How long the screen says how the call ended before closing, as long as the web's says it.
     */
    public const int EndedSeconds = 4;

    public string $username = '';

    public string $name = '';

    public ?string $avatarUrl = null;

    public string $status = self::Calling;

    /**
     * The offer of a call ringing for you, empty when you're the one calling.
     */
    public string $offer = '';

    public bool $isRingingSilently = false;

    /**
     * What this phone and the other end handed each other, for the code.
     */
    public string $localDescription = '';

    public string $remoteDescription = '';

    /**
     * When it started ringing, or connected.
     */
    public float $since = 0;

    /**
     * When ringing or connecting gives up, or the ended screen closes.
     */
    public float $deadline = 0;

    public bool $isMuted = false;

    public bool $isSpeakerOn = false;

    public string $code = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->username = (string) $this->param('username', '');
        $this->name = (string) $this->data('name', $this->username);
        $this->avatarUrl = $this->data('avatar_url');
        $this->offer = (string) $this->data('offer', '');
        $this->since = self::now();

        if ($this->offer !== '') {
            $this->status = self::Ringing;
            $this->isRingingSilently = (bool) $this->data('is_muted', false);
            $this->deadline = $this->since + self::RingSeconds;
            self::markSeen($this->offer);

            return;
        }

        $relays = $this->attempt(fn (Client $devnull): array => $devnull->callIceServers($this->username));

        if ($relays === null) {
            $this->finish($this->error ?? "Could not call @{$this->username}.");

            return;
        }

        $this->deadline = $this->since + self::RingSeconds;
        Calls::start($relays['ice_servers'], $relays['is_relay_only']);
    }

    public function navTitle(): string
    {
        return $this->name;
    }

    /**
     * Picks up. Only now does this phone's own address go to the other end, through the relay.
     */
    public function accept(): void
    {
        if ($this->status !== self::Ringing) {
            return;
        }

        $relays = $this->attempt(fn (Client $devnull): array => $devnull->callIceServers($this->username));

        if ($relays === null) {
            $this->forget(failed: true);
            $this->finish($this->error ?? 'Could not answer the call.');

            return;
        }

        $this->status = self::Connecting;
        $this->remoteDescription = $this->offer;
        $this->deadline = self::now() + self::ConnectSeconds;
        Calls::start($relays['ice_servers'], $relays['is_relay_only'], $this->offer);
    }

    public function decline(): void
    {
        if ($this->status === self::Ringing) {
            $this->forget();
        }

        $this->back();
    }

    /**
     * Ends the call for both: cancelling it while it rings, or hanging up.
     */
    public function hangUp(): void
    {
        if ($this->status === self::Ended) {
            $this->back();

            return;
        }

        if ($this->status === self::Ringing) {
            $this->decline();

            return;
        }

        if ($this->status === self::Calling) {
            $this->forget();
        }

        Calls::end();
        $this->finish('Call ended.');
    }

    public function toggleMute(): void
    {
        $this->isMuted = ! $this->isMuted;
        Calls::setMuted($this->isMuted);
    }

    public function toggleSpeaker(): void
    {
        $this->isSpeakerOn = ! $this->isSpeakerOn;
        Calls::setSpeaker($this->isSpeakerOn);
    }

    /**
     * Android's back hangs up, as leaving the web's page asks before it ends the call.
     */
    public function onBackPressed(): void
    {
        $this->hangUp();
    }

    /**
     * This phone's offer or answer, with every way to reach it, for devnull to pass on.
     */
    #[On(DescriptionReady::class)]
    public function described(string $sdp = ''): void
    {
        $this->localDescription = $sdp;

        if ($this->status === self::Calling) {
            $rang = $this->attempt(function (Client $devnull) use ($sdp): bool {
                $devnull->ring($this->username, $sdp);

                return true;
            });

            if ($rang === null) {
                Calls::end();
                $this->finish($this->error ?? "Could not call @{$this->username}.");
            }

            return;
        }

        if ($this->status === self::Connecting) {
            $answered = $this->attempt(function (Client $devnull) use ($sdp): bool {
                $devnull->answerCall($this->username, $sdp);

                return true;
            });

            if ($answered === null) {
                Calls::end();
                $this->forget(failed: true);
                $this->finish($this->error ?? 'Could not answer the call.');
            }
        }
    }

    #[On(Connected::class)]
    public function connected(): void
    {
        if ($this->status !== self::Connecting) {
            return;
        }

        $this->status = self::Connected;
        $this->since = self::now();
        $this->code = self::verificationCode($this->localDescription, $this->remoteDescription);
    }

    /**
     * The call ended without this phone hanging up: the other end did, or it couldn't connect or dropped.
     */
    #[On(Ended::class)]
    public function ended(string $reason = ''): void
    {
        if ($this->status === self::Ended) {
            return;
        }

        if ($this->status === self::Calling) {
            $this->forget();
        }

        // Picking up went wrong before the answer got to them, so they hear it failed rather than declined.
        if ($this->status === self::Connecting && $this->localDescription === '') {
            $this->forget(failed: true);
        }

        $this->finish(match (true) {
            $reason === 'hung_up' => 'Call ended.',
            $reason === 'microphone' => 'Allow the microphone to call.',
            $this->status === self::Connected => 'Call ended.',
            default => "Couldn't connect the call.",
        });
    }

    /**
     * Checks on the call as the web does: for their answer while it rings for them, for it still ringing while it
     * rings for you, and for giving up when nothing comes.
     */
    #[Poll(1500)]
    public function check(): void
    {
        $now = self::now();

        match ($this->status) {
            self::Calling => $this->checkForAnswer($now),
            self::Ringing => $this->checkStillRinging($now),
            self::Connecting => $now > $this->deadline ? $this->giveUp("Couldn't connect the call.") : null,
            self::Ended => $now > $this->deadline ? $this->back() : null,
            default => null,
        };
    }

    /**
     * Eight hex digits from both ends' key fingerprints, read like a short hash, exactly as the web makes them:
     * `3f9a c217`.
     */
    public static function verificationCode(string $localDescription, string $remoteDescription): string
    {
        $fingerprints = collect([$localDescription, $remoteDescription])
            ->map(fn (string $sdp): string => preg_match('/a=fingerprint:(.+)/', $sdp, $match) === 1 ? trim($match[1]) : '')
            ->sort(strcmp(...))
            ->implode('|');
        $hex = substr(hash('sha256', $fingerprints), 0, 8);

        return substr($hex, 0, 4).' '.substr($hex, 4);
    }

    /**
     * Whether this offer already rang here, so a call you declined or left doesn't ring again while devnull still
     * has it.
     */
    public static function hasSeen(string $offer): bool
    {
        return Cache::get('calls.seen') === hash('sha256', $offer);
    }

    public function render(): View
    {
        return view('native.call', [
            'avatar' => ImageCache::src($this->avatarUrl),
            'line' => $this->line(),
        ]);
    }

    private function checkForAnswer(float $now): void
    {
        $call = $this->attempt(fn (Client $devnull): ?array => $devnull->outgoingCall($this->username));

        // Try again on the next check.
        if ($this->error !== null) {
            return;
        }

        if (($call['answer'] ?? null) !== null) {
            $this->status = self::Connecting;
            $this->remoteDescription = $call['answer'];
            $this->deadline = $now + self::ConnectSeconds;
            Calls::answer($call['answer']);
            $this->forget();

            return;
        }

        if ($call === null || $now > $this->deadline) {
            $this->forget();
            $this->giveUp('No answer.');
        }
    }

    private function checkStillRinging(float $now): void
    {
        $call = $this->attempt(fn (Client $devnull) => $devnull->incomingCall());

        if ($this->error !== null) {
            return;
        }

        if ($call?->offer !== $this->offer || $now > $this->deadline) {
            $this->finish("Missed call from @{$this->username}.");

            return;
        }

        if (! $this->isRingingSilently) {
            Device::vibrate();
        }
    }

    private function giveUp(string $reason): void
    {
        Calls::end();
        $this->finish($reason);
    }

    private function finish(string $reason): void
    {
        $this->status = self::Ended;
        $this->reason = $reason;
        $this->deadline = self::now() + self::EndedSeconds;
    }

    /**
     * Forgets the call on devnull, which logs one that was never picked up in your thread: cancelled, declined, or
     * failed when this phone tried to answer and couldn't.
     */
    private function forget(bool $failed = false): void
    {
        $this->attempt(function (Client $devnull) use ($failed): bool {
            $devnull->endCall($this->username, $failed);

            return true;
        });
    }

    /**
     * What the screen says under their name, in the web's words.
     */
    private function line(): string
    {
        $elapsed = self::elapsed(self::now() - $this->since);

        return match ($this->status) {
            self::Ringing => 'is calling',
            self::Calling => "Ringing… {$elapsed}",
            self::Connecting => 'Connecting…',
            self::Connected => $elapsed,
            default => $this->reason,
        };
    }

    private static function elapsed(float $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private static function markSeen(string $offer): void
    {
        Cache::put('calls.seen', hash('sha256', $offer), self::RingSeconds + 15);
    }

    private static function now(): float
    {
        return (float) CarbonImmutable::now()->format('U.u');
    }
}
