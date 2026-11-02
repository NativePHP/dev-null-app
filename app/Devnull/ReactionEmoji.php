<?php

namespace App\Devnull;

/**
 * The emoji devnull takes as a reaction, and what to send for one the phone's own picker hands over.
 *
 * Devnull only takes the emoji in its shortcode set (resources/emoji-shortcodes.json, a copy of the web's
 * resources/js/lib/emoji-shortcodes.json), in their fully-qualified form and without skin tones. The phone's picker
 * knows more: skin tones, a missing or extra U+FE0F, and emoji newer than the set. A skin tone comes off and the
 * variation selector follows devnull's, so 👍🏽 reacts as 👍 and ❤ as ❤️. One devnull doesn't know at all
 * (🐦‍🔥, 🫩) has no match, and the app says so instead of sending it.
 */
final class ReactionEmoji
{
    /**
     * Devnull's emoji by their key (see key()), loaded once.
     *
     * @var array<string, string>|null
     */
    private static ?array $byKey = null;

    /**
     * What devnull takes for the emoji: itself, or the same emoji without its skin tone or with devnull's variation
     * selector. Null when devnull has nothing like it.
     */
    public static function forDevnull(string $emoji): ?string
    {
        return self::byKey()[self::key($emoji)] ?? null;
    }

    /**
     * The emoji with no skin tone and no variation selector, which no two of devnull's share.
     */
    private static function key(string $emoji): string
    {
        return (string) preg_replace('/[\x{FE0F}\x{1F3FB}-\x{1F3FF}]/u', '', trim($emoji));
    }

    /**
     * @return array<string, string>
     */
    private static function byKey(): array
    {
        if (self::$byKey !== null) {
            return self::$byKey;
        }

        /** @var array<string, string> $shortcodes */
        $shortcodes = json_decode((string) file_get_contents(resource_path('emoji-shortcodes.json')), true);

        $byKey = [];

        foreach ($shortcodes as $emoji) {
            $byKey[self::key($emoji)] = $emoji;
        }

        return self::$byKey = $byKey;
    }
}
