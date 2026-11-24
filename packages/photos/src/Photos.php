<?php

namespace Devnull\Photos;

/**
 * The phone's photo library. Saving is asynchronous: the phone may ask first whether the app can add to it, and the
 * outcome arrives as a Saved or SaveFailed event.
 */
class Photos
{
    /**
     * Saves the image in the file at $path to the photo library. False when the phone couldn't even start, such as
     * outside the app or with no file there.
     */
    public function save(string $path): bool
    {
        if (! function_exists('nativephp_call') || ! is_file($path)) {
            return false;
        }

        $result = nativephp_call('Photos.Save', json_encode(['path' => $path], JSON_THROW_ON_ERROR));

        return (bool) (json_decode((string) $result, true)['success'] ?? false);
    }
}
