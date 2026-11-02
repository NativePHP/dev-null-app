<?php

namespace App\Devnull;

use App\Devnull\Data\MessageVoice;

/**
 * Where the thread's voice notes are, for drawing them: the one in the player
 * and what it's doing, where each paused one stopped, and how fast each plays.
 * Voice notes are keyed by their message's client id, so one you pushed keeps
 * its place once devnull has it, or "draft" for the one in the composer.
 *
 * While a note plays, the thread renders four times a second for its clock
 * and its waveform, where the web paints both every frame. Holding each bar
 * back natively until playback reaches it (animate-delay) would need fewer
 * renders, but iOS drew the delayed bars played at once.
 */
final readonly class VoicePlayback
{
    public const string Idle = 'idle';

    public const string Loading = 'loading';

    public const string Playing = 'playing';

    public const string Paused = 'paused';

    public const string Failed = 'failed';

    /**
     * How often the thread renders while a note plays, in milliseconds.
     */
    public const int Interval = 250;

    /**
     * The waveform's height, and the shortest a bar gets, as a share of it, as the web draws them.
     */
    public const int Height = 28;

    public const int ShortestBar = 10;

    /**
     * @param  array<string, int>  $positions  where each paused voice note stopped, in milliseconds
     * @param  array<string, float>  $speeds  how fast each plays, for those not at 1×
     */
    public function __construct(
        public ?string $key = null,
        public string $state = self::Idle,
        public int $fromMs = 0,
        public float $startedAt = 0,
        public array $positions = [],
        public array $speeds = [],
        public float $now = 0,
    ) {}

    public function stateOf(string $key): string
    {
        return $key === $this->key ? $this->state : self::Idle;
    }

    public function isPlaying(string $key): bool
    {
        return $this->stateOf($key) === self::Playing;
    }

    public function speedOf(string $key): float
    {
        return $this->speeds[$key] ?? 1.0;
    }

    /**
     * Where a voice note is, in milliseconds: moving on from where it started while it plays.
     */
    public function positionOf(string $key, MessageVoice $voice): int
    {
        $position = $this->isPlaying($key)
            ? $this->fromMs + (int) round(($this->now - $this->startedAt) * 1000 * $this->speedOf($key))
            : ($key === $this->key ? $this->fromMs : ($this->positions[$key] ?? 0));

        return max(0, min($voice->durationMs, $position));
    }

    /**
     * The web's clock: where it is, or how long it is before it has played, in the time it takes at its speed, so
     * 0:14 at 2× reads 0:07.
     */
    public function clock(string $key, MessageVoice $voice): string
    {
        $position = $this->positionOf($key, $voice);

        return VoiceNotes::clock(($position > 0 ? $position : $voice->durationMs) / $this->speedOf($key));
    }

    /**
     * Each bar of the waveform: its height in points, and whether it's drawn played, which it is once playback reaches
     * its middle.
     *
     * @return list<array{height: int, played: bool}>
     */
    public function bars(string $key, MessageVoice $voice): array
    {
        $position = $this->positionOf($key, $voice);
        $bars = [];

        foreach ($voice->waveform as $bar => $level) {
            $bars[] = [
                'height' => (int) max(3, round(self::Height * max(self::ShortestBar, $level) / 100)),
                'played' => $position >= (int) round(($bar + 0.5) * $voice->durationMs / VoiceNotes::Bars),
            ];
        }

        return $bars;
    }

    public function speedLabel(string $key): string
    {
        return VoiceNotes::speedLabel($this->speedOf($key));
    }
}
