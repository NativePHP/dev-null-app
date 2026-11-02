<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Data\MessageVoice;
use App\Devnull\Exceptions\InvalidVoiceNote;
use App\Devnull\VoiceNotes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Microphone\MicrophoneCancelled;
use Native\Mobile\Events\Microphone\MicrophoneRecorded;
use Native\Mobile\Facades\Microphone;

/**
 * A voice note recorded in the composer, as the web's microphone button
 * records one: up to a minute, taking the field's place while it's recorded,
 * saying it's opening the microphone until it really records (the first time,
 * while the phone asks whether devnull may use it), then with the
 * microphone's level and a clock counting to the limit, then the
 * recording itself, to listen to before it's pushed. Push sends it at any
 * point, stopping the recording first, and the trash throws it away. The
 * microphone is only open while it records, and stops by itself at a minute.
 *
 * The phone records it as 16 kHz WAV (see VoiceNotes). Each recording has
 * its own id, so a recording that only arrives after it was thrown away, or
 * after the composer went, is deleted instead of shown.
 *
 * The thread says what happens to a recording through takesVoiceNotes() and
 * pushVoiceNote().
 */
trait RecordsVoiceNotes
{
    public const string Recording = 'recording';

    public const string Stopping = 'stopping';

    public const string Recorded = 'recorded';

    /**
     * How often the meter takes the microphone's level while recording, in milliseconds.
     */
    public const int MeterInterval = 200;

    /**
     * Where the voice note in the composer is: recording, stopping (waiting for the microphone to hand it over),
     * recorded (waiting to be pushed), or null when there's none.
     */
    public ?string $recorder = null;

    public ?string $recordingId = null;

    public float $recordingStartedAt = 0;

    /**
     * The microphone has really started recording, which the clock counts from.
     */
    public bool $recordingStarted = false;

    /**
     * Push was pressed while it recorded, so it goes as soon as the microphone hands it over.
     */
    public bool $pushesWhenRecorded = false;

    public ?MessageVoice $recorded = null;

    /**
     * The meter's bars, oldest first, from 0 to 100, as the web's draws the level coming in.
     *
     * @var list<int>
     */
    public array $recordingLevels = [];

    /**
     * Whether the composer can take a voice note right now: the thread takes messages, and there's no text or
     * photos on the draft, since voice notes go on their own.
     */
    abstract protected function takesVoiceNotes(): bool;

    /**
     * A recorded voice note to push, with whatever it replies to.
     */
    abstract protected function pushVoiceNote(MessageVoice $voice): void;

    public function recordVoiceNote(): void
    {
        if ($this->recorder !== null || ! $this->takesVoiceNotes()) {
            return;
        }

        $this->recordingId = (string) Str::uuid();

        $started = Microphone::record()
            ->id($this->recordingId)
            ->wav(VoiceNotes::SampleRate)
            ->maxDuration(VoiceNotes::MaxMilliseconds / 1000)
            ->start();

        if (! $started) {
            $this->recordingId = null;
            $this->voiceNoteFailed(VoiceNotes::CouldNotOpen);

            return;
        }

        $this->recorder = self::Recording;
        $this->recordingStarted = Microphone::getStatus() === 'recording';
        $this->recordingStartedAt = (float) CarbonImmutable::now()->format('U.u');
        $this->recordingLevels = array_fill(0, VoiceNotes::Bars, 0);
        $this->pushesWhenRecorded = false;
    }

    /**
     * Stop: the recording, to listen to before it's pushed.
     */
    public function stopVoiceNote(): void
    {
        if ($this->recorder !== self::Recording) {
            return;
        }

        $this->recorder = self::Stopping;
        Microphone::stop();
    }

    /**
     * Push: a recording goes as soon as the microphone hands it over, and a recorded one at once.
     */
    public function pushRecording(): void
    {
        if ($this->recorder === self::Recorded && $this->recorded !== null) {
            $voice = $this->recorded;
            $this->forgetRecording();
            $this->pushVoiceNote($voice);

            return;
        }

        if ($this->recorder === self::Recording || $this->recorder === self::Stopping) {
            $this->pushesWhenRecorded = true;
            $this->stopVoiceNote();
        }
    }

    /**
     * The trash: the recording goes, from the composer and off the phone.
     */
    public function discardVoiceNote(): void
    {
        if ($this->recorder === self::Recording) {
            Microphone::stop();
        }

        app(VoiceNotes::class)->discard($this->recorded);
        $this->forgetRecording();
    }

    /**
     * The microphone handed over a recording, or reached its minute and stopped by itself.
     */
    #[On(MicrophoneRecorded::class)]
    public function voiceNoteRecorded(string $path = '', string $mimeType = '', ?string $id = null): void
    {
        if ($id === null || $id !== $this->recordingId || $this->recorder === null || $this->recorder === self::Recorded) {
            @unlink($path);

            return;
        }

        try {
            $voice = app(VoiceNotes::class)->keep($path);
        } catch (InvalidVoiceNote $exception) {
            $this->forgetRecording();
            $this->voiceNoteFailed($exception->getMessage());

            return;
        }

        if ($this->pushesWhenRecorded) {
            $this->forgetRecording();
            $this->pushVoiceNote($voice);

            return;
        }

        $this->recorder = self::Recorded;
        $this->recorded = $voice;
    }

    /**
     * The microphone couldn't be used: not allowed, or it wouldn't start.
     */
    #[On(MicrophoneCancelled::class)]
    public function voiceNoteCancelled(?string $id = null, string $reason = ''): void
    {
        if ($id === null || $id !== $this->recordingId) {
            return;
        }

        $this->forgetRecording();
        $this->voiceNoteFailed($reason === 'permission_denied' ? VoiceNotes::Denied : VoiceNotes::CouldNotOpen);
    }

    /**
     * How long it has been recording, up to the limit, in milliseconds.
     */
    protected function recordingElapsed(): int
    {
        return $this->recorder === self::Recording && $this->recordingStarted
            ? (int) min(VoiceNotes::MaxMilliseconds, round(((float) CarbonImmutable::now()->format('U.u') - $this->recordingStartedAt) * 1000))
            : 0;
    }

    /**
     * Takes the microphone's level since the last time for the meter's newest bar, while it records. Until the
     * microphone really records, the clock waits. The thread renders every MeterInterval for it.
     */
    protected function sampleRecording(): void
    {
        if ($this->recorder !== self::Recording) {
            return;
        }

        if (! $this->recordingStarted) {
            $this->recordingStarted = Microphone::getStatus() === 'recording';
            $this->recordingStartedAt = (float) CarbonImmutable::now()->format('U.u');

            return;
        }

        $level = (int) round(sqrt(Microphone::level()) * 100);
        $this->recordingLevels = [...array_slice($this->recordingLevels, 1 - VoiceNotes::Bars), $level];
    }

    /**
     * Leaving the thread lets go of the microphone and throws the recording away, as the web's composer going does.
     */
    protected function letGoOfRecording(): void
    {
        if ($this->recorder !== null) {
            $this->discardVoiceNote();
        }
    }

    /**
     * Why a voice note couldn't be recorded, in the web's words. The thread says it with a toast.
     */
    abstract protected function voiceNoteFailed(string $error): void;

    private function forgetRecording(): void
    {
        $this->recorder = null;
        $this->recordingId = null;
        $this->recordingStartedAt = 0;
        $this->recordingStarted = false;
        $this->recorded = null;
        $this->pushesWhenRecorded = false;
        $this->recordingLevels = [];
    }
}
