<?php

namespace App\Devnull;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\AsyncTask;

/**
 * Downloads a voice note from the URL its thread was signed for, on one of
 * NativePHP's background PHP threads, so pressing play never waits on it on
 * the UI thread. It's written whole, named for what devnull served it as, MP4
 * (holding Opus) or WAV, since that's how the phones' players tell them
 * apart. Returns where it went, or null when devnull didn't serve one.
 */
final class DownloadVoiceNote extends AsyncTask
{
    /**
     * How long a voice note may take, in seconds. A minute is about 180 KB as Opus, and 1.9 MB as WAV.
     */
    public const int Timeout = 30;

    /**
     * @var array<string, string>
     */
    private const array Extensions = ['audio/mp4' => 'mp4', 'audio/wav' => 'wav'];

    public function handle(string $url, string $name): ?string
    {
        File::ensureDirectoryExists(VoiceNotes::cacheDirectory());
        VoiceNotes::pruneCache();

        try {
            $response = Http::timeout(self::Timeout)->accept('audio/*')->get($url);
        } catch (ConnectionException) {
            return null;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        // Signing out empties the folder (see Account::forget), and this thread can't tell it happened otherwise.
        if (! $response->successful() || ! isset(self::Extensions[$type]) || app(Account::class)->user() === null) {
            return null;
        }

        $path = VoiceNotes::cacheDirectory().'/'.$name.'.'.self::Extensions[$type];

        File::put($path.'.part', $response->body());
        File::move($path.'.part', $path);

        return $path;
    }
}
