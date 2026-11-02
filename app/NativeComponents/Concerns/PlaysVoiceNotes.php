<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Data\MessageVoice;
use App\Devnull\DownloadVoiceNote;
use App\Devnull\VoiceNotes;
use App\Devnull\VoicePlayback;
use Carbon\CarbonImmutable;
use Native\Mobile\Attributes\On;
use NativePHP\MediaPlayer\Events\PlaybackEnded;
use NativePHP\MediaPlayer\Events\PlaybackError;
use NativePHP\MediaPlayer\Facades\MediaPlayer;
use Throwable;

/**
 * Voice notes played as the web's player plays them: play and pause, a tap
 * on the waveform to play from there, and the speed, 1×, 1.5× or 2×, which
 * each note keeps while you're on the thread. Only one plays at a time, and
 * another note starting leaves the last one paused where it was. Leaving the
 * thread stops it.
 *
 * Nothing is downloaded until play is pressed, as on the web, and then from
 * the URL the thread was signed for, onto the phone, which neither phone's
 * player can do itself (see DownloadVoiceNote). A recording of yours plays
 * from the phone. One that can't be fetched or played shows the web's retry.
 *
 * The thread finds a note by its key through voiceNoteFor(), and draws them
 * all from voicePlayback().
 */
trait PlaysVoiceNotes
{
    /**
     * The voice note in the player, by its key (see VoicePlayback), and what it's doing.
     */
    public ?string $playingVoice = null;

    public string $playback = VoicePlayback::Idle;

    /**
     * Where the note in the player started from, in milliseconds, and when, while it plays; where it is while paused.
     */
    public int $playbackFromMs = 0;

    public float $playbackStartedAt = 0;

    /**
     * The file the player has, to tell its events from another's.
     */
    public ?string $playbackSource = null;

    /**
     * Where each voice note you paused or left for another stopped, in milliseconds.
     *
     * @var array<string, int>
     */
    public array $voicePositions = [];

    /**
     * How fast each voice note plays, for those you changed from 1×.
     *
     * @var array<string, float>
     */
    public array $voiceSpeeds = [];

    /**
     * The voice note with this key, in the thread or the composer.
     */
    abstract protected function voiceNoteFor(string $key): ?MessageVoice;

    /**
     * Play, pause, or after it failed, retry.
     */
    public function toggleVoiceNote(string $key): void
    {
        if ($this->playingVoice === $key && $this->playback === VoicePlayback::Playing) {
            $this->pauseVoiceNote();

            return;
        }

        if ($this->playingVoice === $key && $this->playback === VoicePlayback::Loading) {
            return;
        }

        $this->playVoiceNote($key, $this->playingVoice === $key ? $this->playbackFromMs : ($this->voicePositions[$key] ?? 0));
    }

    /**
     * A tap on one of the waveform's bars plays the note from there.
     */
    public function seekVoiceNote(string $key, int $bar): void
    {
        $voice = $this->voiceNoteFor($key);

        if ($voice !== null) {
            $this->playVoiceNote($key, intdiv(max(0, min(VoiceNotes::Bars - 1, $bar)) * $voice->durationMs, VoiceNotes::Bars));
        }
    }

    /**
     * The speed button: the next speed, back to 1× after 2×. A note playing carries on from where it is at the new
     * speed.
     */
    public function changeVoiceSpeed(string $key): void
    {
        $voice = $this->voiceNoteFor($key);
        $playing = $voice !== null && $this->playingVoice === $key && $this->playback === VoicePlayback::Playing;
        $position = $playing ? $this->voicePlayback()->positionOf($key, $voice) : 0;
        $speed = VoiceNotes::nextSpeed($this->voiceSpeeds[$key] ?? 1.0);

        if ($speed === 1.0) {
            unset($this->voiceSpeeds[$key]);
        } else {
            $this->voiceSpeeds[$key] = $speed;
        }

        if ($this->playingVoice === $key) {
            MediaPlayer::setRate($speed);
        }

        if ($playing) {
            $this->playbackFromMs = $position;
            $this->playbackStartedAt = (float) CarbonImmutable::now()->format('U.u');
        }
    }

    #[On(PlaybackEnded::class)]
    public function voiceNotePlayed(string $source = ''): void
    {
        if ($this->playingVoice === null || $source !== $this->playbackSource) {
            return;
        }

        unset($this->voicePositions[$this->playingVoice]);
        $this->forgetPlayback();
    }

    #[On(PlaybackError::class)]
    public function voiceNoteBroke(string $source = '', string $message = ''): void
    {
        if ($this->playingVoice !== null && ($source === $this->playbackSource || $this->playback === VoicePlayback::Loading)) {
            $this->playback = VoicePlayback::Failed;
        }
    }

    /**
     * Where the thread's voice notes are, for drawing them.
     */
    protected function voicePlayback(): VoicePlayback
    {
        return new VoicePlayback(
            key: $this->playingVoice,
            state: $this->playback,
            fromMs: $this->playbackFromMs,
            startedAt: $this->playbackStartedAt,
            positions: $this->voicePositions,
            speeds: $this->voiceSpeeds,
            now: (float) CarbonImmutable::now()->format('U.u'),
        );
    }

    /**
     * Stops whatever plays, as leaving the thread does, or pushing the recording in the composer.
     */
    protected function stopVoiceNotes(): void
    {
        if ($this->playingVoice !== null) {
            MediaPlayer::stop();
        }

        $this->forgetPlayback();
    }

    private function playVoiceNote(string $key, int $fromMs): void
    {
        $voice = $this->voiceNoteFor($key);

        if ($voice === null) {
            return;
        }

        $this->leaveNotePlaying($key);
        $this->playingVoice = $key;
        $this->playbackFromMs = $fromMs >= $voice->durationMs ? 0 : $fromMs;

        $file = app(VoiceNotes::class)->playable($voice);

        if ($file !== null) {
            $this->startPlayer($file);

            return;
        }

        if ($voice->isOnPhone()) {
            $this->playback = VoicePlayback::Failed;

            return;
        }

        $this->playback = VoicePlayback::Loading;

        DownloadVoiceNote::dispatch($voice->url, VoiceNotes::cacheName($voice))
            ->timeout(DownloadVoiceNote::Timeout + 5)
            ->finished(fn (mixed $path) => $this->voiceNoteDownloaded($key, is_string($path) ? $path : null))
            ->failed(fn (Throwable $exception) => $this->voiceNoteDownloaded($key, null));
    }

    /**
     * The download is done: it plays, unless you've moved on to another note since.
     */
    private function voiceNoteDownloaded(string $key, ?string $file): void
    {
        if ($this->playingVoice !== $key || $this->playback !== VoicePlayback::Loading) {
            return;
        }

        $file === null || ! is_file($file)
            ? $this->playback = VoicePlayback::Failed
            : $this->startPlayer($file);
    }

    private function startPlayer(string $file): void
    {
        $key = (string) $this->playingVoice;

        $started = MediaPlayer::play($file, ['rate' => $this->voiceSpeeds[$key] ?? 1.0, 'position' => $this->playbackFromMs / 1000]);

        $this->playbackSource = $file;
        $this->playback = $started ? VoicePlayback::Playing : VoicePlayback::Failed;
        $this->playbackStartedAt = (float) CarbonImmutable::now()->format('U.u');
    }

    private function pauseVoiceNote(): void
    {
        $key = (string) $this->playingVoice;
        $voice = $this->voiceNoteFor($key);

        MediaPlayer::pause();

        $this->playbackFromMs = $voice === null ? 0 : $this->voicePlayback()->positionOf($key, $voice);
        $this->voicePositions[$key] = $this->playbackFromMs;
        $this->playback = VoicePlayback::Paused;
    }

    /**
     * Another note is about to play: the one in the player keeps where it was, paused, as the web's do.
     */
    private function leaveNotePlaying(string $key): void
    {
        if ($this->playingVoice === null || $this->playingVoice === $key) {
            return;
        }

        if ($this->playback === VoicePlayback::Playing) {
            $this->pauseVoiceNote();
        }

        $this->playback = VoicePlayback::Idle;
    }

    private function forgetPlayback(): void
    {
        $this->playingVoice = null;
        $this->playback = VoicePlayback::Idle;
        $this->playbackFromMs = 0;
        $this->playbackStartedAt = 0;
        $this->playbackSource = null;
    }
}
