<?php

namespace App\Devnull;

use App\Devnull\Data\MessageVoice;
use App\Devnull\Exceptions\InvalidVoiceNote;
use App\Models\PendingWrite;
use Illuminate\Support\Facades\File;

/**
 * Voice notes for Remotes, as the web records and plays them: up to a minute
 * from the microphone, pushed on their own, played back at 1×, 1.5× or 2×.
 *
 * The phone records 16 kHz mono 16-bit WAV, one of the two kinds devnull
 * takes (the web's other is Opus in WebM, which neither phone records). A
 * minute of it is about 1.9 MB, under devnull's 1,950 KB. devnull never
 * decodes a recording, so the waveform is drawn here from its samples, as the
 * web draws one for a WAV: the loudest sample of each of 48 slices.
 *
 * A recording is moved out of the microphone's temporary folder into the
 * app's storage, where it stays as long as the message holding it is in the
 * outbox, as draft photos do (see DraftImages). Voice notes others sent come
 * from devnull's signed URLs, and are downloaded before they play, since
 * neither phone's player streams from a server that doesn't take ranges; the
 * copies are kept a week. Signing out deletes both (see Account::forget()).
 */
final class VoiceNotes
{
    public const int Bars = 48;

    public const int SampleRate = 16_000;

    public const int MinMilliseconds = 500;

    /**
     * The web's limit, which the microphone stops at by itself. devnull takes a second more, for a recorder to stop.
     */
    public const int MaxMilliseconds = 60_000;

    private const int AcceptedMilliseconds = 61_000;

    /**
     * The speeds a voice note plays at, as the web's speed button steps through them.
     *
     * @var list<float>
     */
    public const array Speeds = [1.0, 1.5, 2.0];

    public const string TooShort = 'fatal: voice note shorter than half a second';

    public const string Unreadable = 'fatal: could not read the recording';

    public const string NotOne = 'Voice notes must be Opus WebM or 16 kHz mono WAV recordings between half a second and a minute long.';

    public const string Denied = 'fatal: microphone access denied';

    public const string CouldNotOpen = 'fatal: could not open the microphone';

    /**
     * How long a recording nothing holds any more is kept, and a downloaded voice note.
     */
    private const int KeptForSeconds = 86_400;

    private const int CachedForSeconds = 7 * 86_400;

    /**
     * Moves a recording the microphone made into the app's storage, as a voice
     * note to push, with how long it plays and its waveform.
     *
     * @throws InvalidVoiceNote
     */
    public function keep(string $path): MessageVoice
    {
        $wav = is_file($path) ? (string) file_get_contents($path) : '';
        $samples = self::samples($wav);

        try {
            if ($samples === null) {
                throw new InvalidVoiceNote(self::Unreadable);
            }

            $milliseconds = intdiv(strlen($samples) * 1000, self::SampleRate * 2);

            if ($milliseconds < self::MinMilliseconds) {
                throw new InvalidVoiceNote(self::TooShort);
            }

            if ($milliseconds > self::AcceptedMilliseconds) {
                throw new InvalidVoiceNote(self::NotOne);
            }
        } catch (InvalidVoiceNote $exception) {
            @unlink($path);

            throw $exception;
        }

        $this->prune();

        $kept = $this->directory().'/'.bin2hex(random_bytes(16)).'.wav';

        if (! @rename($path, $kept) && ! (@copy($path, $kept) && @unlink($path))) {
            throw new InvalidVoiceNote(self::Unreadable);
        }

        return new MessageVoice('', $milliseconds, self::waveformOf($samples), $kept);
    }

    /**
     * Deletes a recording that was pushed, or that you discarded. Voice notes devnull serves are left alone.
     */
    public function discard(?MessageVoice $voice): void
    {
        if ($voice !== null && str_starts_with($voice->url, $this->directory().'/')) {
            @unlink($voice->url);
        }
    }

    /**
     * Where a voice note can be played from on this phone: the recording itself, or the copy downloaded of one
     * devnull serves, or null until it's downloaded.
     */
    public function playable(MessageVoice $voice): ?string
    {
        if ($voice->isOnPhone()) {
            return is_file($voice->url) ? $voice->url : null;
        }

        return array_find(glob(self::cacheDirectory().'/'.self::cacheName($voice).'.*') ?: [], fn (string $file): bool => ! str_ends_with($file, '.part'));
    }

    /**
     * The downloaded copies of voice notes, by their id. Each is named for what devnull serves it as, which is how
     * the players tell MP4 from WAV.
     */
    public static function cacheDirectory(): string
    {
        return storage_path('app/voice-notes-cache');
    }

    public static function cacheName(MessageVoice $voice): string
    {
        return preg_replace('/[^A-Za-z0-9-]/', '', $voice->id) ?: md5($voice->url);
    }

    /**
     * Deletes downloaded voice notes past a week, and any half-written ones.
     */
    public static function pruneCache(): void
    {
        foreach (glob(self::cacheDirectory().'/*') ?: [] as $file) {
            if (str_ends_with($file, '.part') || filemtime($file) < time() - self::CachedForSeconds) {
                @unlink($file);
            }
        }
    }

    /**
     * Everything signing out leaves behind: recordings and downloaded voice notes.
     */
    public static function clear(): void
    {
        File::deleteDirectory(storage_path('app/voice-notes'));
        File::deleteDirectory(self::cacheDirectory());
    }

    /**
     * A waveform as devnull lists it, or as the outbox keeps it: Bars levels from 0 to 100, flat where it's short.
     *
     * @return list<int>
     */
    public static function bars(mixed $levels): array
    {
        $levels = is_array($levels) ? array_values($levels) : [];

        return array_map(fn (int $bar): int => max(0, min(100, (int) ($levels[$bar] ?? 0))), range(0, self::Bars - 1));
    }

    /**
     * The web's waveform of a WAV: the loudest sample of each slice, its square root, from 0 to 100.
     *
     * @return list<int>
     */
    public static function waveformOf(string $samples): array
    {
        $count = intdiv(strlen($samples), 2);
        $bars = [];

        for ($bar = 0; $bar < self::Bars; $bar++) {
            $from = intdiv($count * $bar, self::Bars);
            $to = intdiv($count * ($bar + 1), self::Bars);

            // Phones are little-endian, as WAV is, so the machine's own signed shorts read it.
            $slice = $to > $from ? unpack('s*', substr($samples, $from * 2, ($to - $from) * 2)) : [];
            $peak = $slice === [] || $slice === false ? 0 : max(max($slice), -min($slice));

            $bars[] = (int) round(sqrt(min($peak / 32768, 1)) * 100);
        }

        return $bars;
    }

    /**
     * "0:07", "1:00", as the web's clock reads.
     */
    public static function clock(int|float $milliseconds): string
    {
        $seconds = max(0, (int) floor($milliseconds / 1000));

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The speed after this one, back to 1× after 2×.
     */
    public static function nextSpeed(float $speed): float
    {
        $index = array_search($speed, self::Speeds, true);

        return self::Speeds[$index === false ? 0 : ($index + 1) % count(self::Speeds)];
    }

    /**
     * "1×", "1.5×", "2×".
     */
    public static function speedLabel(float $speed): string
    {
        return rtrim(rtrim(number_format($speed, 1), '0'), '.').'×';
    }

    /**
     * The samples of a WAV the phone recorded, 16 kHz mono 16-bit as devnull takes it, or null when it isn't one.
     * The chunks are walked as devnull walks them, so any a recorder adds (iOS pads with one) are stepped over.
     */
    private static function samples(string $wav): ?string
    {
        if (! str_starts_with($wav, 'RIFF') || substr($wav, 8, 4) !== 'WAVE') {
            return null;
        }

        $format = null;
        $offset = 12;

        while ($offset + 8 <= strlen($wav)) {
            $id = substr($wav, $offset, 4);
            $size = unpack('V', substr($wav, $offset + 4, 4))[1];

            if ($offset + 8 + $size > strlen($wav)) {
                return null;
            }

            if ($id === 'data') {
                return $format === pack('vvVVvv', 1, 1, self::SampleRate, self::SampleRate * 2, 2, 16) && $size % 2 === 0
                    ? substr($wav, $offset + 8, $size)
                    : null;
            }

            if ($id === 'fmt ' && $size >= 16) {
                $format = substr($wav, $offset + 8, 16);
            }

            $offset += 8 + $size + $size % 2;
        }

        return null;
    }

    private function directory(): string
    {
        $directory = storage_path('app/voice-notes');

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory;
    }

    private function prune(): void
    {
        $held = array_flip($this->held());

        foreach (glob($this->directory().'/*') ?: [] as $file) {
            if (! isset($held[$file]) && filemtime($file) < time() - self::KeptForSeconds) {
                @unlink($file);
            }
        }
    }

    /**
     * The recordings the messages in the outbox hold.
     *
     * @return list<string>
     */
    private function held(): array
    {
        return app(Outbox::class)->pending(PendingWrite::Message, withFailed: true)
            ->map(fn (PendingWrite $write): ?string => $write->voice()?->url)
            ->filter()
            ->values()
            ->all();
    }
}
