<?php

namespace App\Devnull\NataNullers;

use App\Devnull\ImageCache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The faces on the game's characters, as image bytes for the canvas, which
 * can't fetch anything itself: yours on the player, and Nuno's on the wild
 * Nuno. A face already in the image cache is used however old it is. One that
 * isn't is fetched small, once, and kept beside it. Without either, the character
 * gets a drawn face.
 */
final class Faces
{
    public const string NunoAvatar = 'https://github.com/nunomaduro.png';

    private const int Timeout = 3;

    public function me(?string $avatarUrl): ?string
    {
        return $avatarUrl === null ? null : $this->bytes($avatarUrl);
    }

    public function nuno(): ?string
    {
        return $this->bytes(self::NunoAvatar);
    }

    private function bytes(string $url): ?string
    {
        // The feed's copy first. The small one fetched here is kept apart, so the feed never draws it.
        $small = ImageCache::path($url.'#nata-nullers');

        foreach ([ImageCache::path($url), $small] as $path) {
            $bytes = is_file($path) ? (string) file_get_contents($path) : '';

            if (self::isImage($bytes)) {
                return $bytes;
            }
        }

        try {
            $response = Http::timeout(self::Timeout)->accept('image/*')->get($url, ['s' => 96, 'size' => 96]);
        } catch (Throwable) {
            return null;
        }

        $bytes = $response->successful() ? $response->body() : '';

        if (! self::isImage($bytes)) {
            return null;
        }

        File::ensureDirectoryExists(ImageCache::directory());
        File::put($small, $bytes);

        return $bytes;
    }

    /**
     * PNG or JPEG, which the canvas decodes, and small enough to embed.
     */
    private static function isImage(string $bytes): bool
    {
        return strlen($bytes) <= 4 * 1024 * 1024
            && (str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || str_starts_with($bytes, "\xFF\xD8\xFF"));
    }
}
