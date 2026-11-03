<?php

namespace App\Devnull;

use Native\Mobile\Facades\Device;
use Native\Mobile\Facades\System;

/**
 * What maintainers need to reproduce an issue opened from the app, as the
 * web's collectEnvironment() sends its browser and screen: the app and its
 * version, the system and its version, the language and the appearance.
 * devnull puts them in a collapsed Environment table under the issue.
 *
 * It's kept coarse, since the issue is public, so never the phone's name. A
 * detail the phone doesn't give is left out, and none is longer than devnull
 * takes, so a long one can't stop the issue opening.
 */
final class IssueEnvironment
{
    public const int MaxLength = 100;

    /**
     * @return array<string, string>
     */
    public static function collect(): array
    {
        $info = json_decode((string) Device::getInfo(), true);
        $info = is_array($info) ? $info : [];
        $text = fn (string $key): string => is_string($info[$key] ?? null) ? $info[$key] : '';

        $environment = [
            'browser' => 'devnull app '.config('nativephp.version'),
            'os' => $text('operatingSystem').' '.$text('osVersion'),
            'language' => $text('language'),
            'color_scheme' => System::appearance(),
        ];

        $environment = array_map(fn (string $value): string => mb_substr(trim($value), 0, self::MaxLength), $environment);

        return array_filter($environment, fn (string $value): bool => $value !== '');
    }
}
