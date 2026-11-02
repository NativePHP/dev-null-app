<?php

namespace App\Devnull\Data;

use App\Devnull\VoiceNotes;

/**
 * A voice note in a Remotes message, as devnull sends it to someone in the
 * conversation: how long it plays, the waveform the sender's phone or browser
 * drew of it, and a URL signed for you that serves it (MP4 holding Opus, or
 * WAV). One you recorded on this phone, waiting to be pushed or on its way,
 * has no id yet, and its URL is the WAV in the app's storage (see VoiceNotes).
 */
final readonly class MessageVoice
{
    /**
     * @param  list<int>  $waveform  VoiceNotes::Bars levels, 0 to 100
     */
    public function __construct(public string $id, public int $durationMs, public array $waveform, public string $url) {}

    /**
     * A voice note as devnull lists it in a message, or null when it isn't one.
     */
    public static function fromArray(mixed $voice): ?self
    {
        if (! is_array($voice) || ! is_string($voice['url'] ?? null) || ! is_int($voice['duration_ms'] ?? null)) {
            return null;
        }

        return new self((string) ($voice['id'] ?? ''), $voice['duration_ms'], VoiceNotes::bars($voice['waveform'] ?? []), $voice['url']);
    }

    /**
     * A recording on this phone, as the outbox keeps it, or null when its file has gone.
     */
    public static function fromOutbox(mixed $voice): ?self
    {
        if (! is_array($voice) || ! is_string($voice['path'] ?? null) || ! is_file($voice['path'])) {
            return null;
        }

        return new self('', (int) ($voice['duration_ms'] ?? 0), VoiceNotes::bars($voice['waveform'] ?? []), $voice['path']);
    }

    /**
     * @return array{path: string, duration_ms: int, waveform: list<int>}
     */
    public function toOutbox(): array
    {
        return ['path' => $this->url, 'duration_ms' => $this->durationMs, 'waveform' => $this->waveform];
    }

    /**
     * Whether it's a file on this phone rather than one devnull serves.
     */
    public function isOnPhone(): bool
    {
        return str_starts_with($this->url, '/');
    }
}
