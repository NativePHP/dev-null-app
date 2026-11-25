<?php

namespace App\Devnull;

use App\Devnull\Data\Mention;

/**
 * A bio or company synced from GitHub, cut up so each account it tags can be drawn as a badge, as the web's BioText
 * draws them: the account's square GitHub icon beside its handle, in a small tag (see partials/bio-text).
 *
 * EDGE can't put an image inside a run of text, so a text with tags is laid out as a wrapping row of words and
 * badges, one paragraph to a line break. Each piece says whether a space follows it, so punctuation stays against
 * the badge before it.
 */
final class BioText
{
    /**
     * The text's paragraphs, each a list of pieces: a word, or a tag with the handle it names.
     *
     * @return list<list<array{text: string, mention: ?string, spaceAfter: bool}>>
     */
    public static function paragraphs(string $text): array
    {
        return array_map(self::pieces(...), preg_split('/\R/u', trim($text)) ?: []);
    }

    /**
     * Whether the text tags anyone, and so needs badges rather than one run of text.
     */
    public static function hasMentions(string $text): bool
    {
        return Mention::inText($text) !== [];
    }

    /**
     * @return list<array{text: string, mention: ?string, spaceAfter: bool}>
     */
    private static function pieces(string $paragraph): array
    {
        $pieces = [];
        $position = 0;

        foreach (Mention::inText($paragraph) as $mention) {
            self::words(mb_substr($paragraph, $position, $mention->offset - $position), $pieces);
            $pieces[] = ['text' => $mention->username, 'mention' => $mention->username, 'spaceAfter' => false];
            $position = $mention->offset + $mention->length;
        }

        self::words(mb_substr($paragraph, $position), $pieces);

        if ($pieces !== []) {
            $pieces[array_key_last($pieces)]['spaceAfter'] = false;
        }

        return $pieces;
    }

    /**
     * Adds the words of $text to $pieces. Whitespace marks the piece before it as followed by a space.
     *
     * @param  list<array{text: string, mention: ?string, spaceAfter: bool}>  $pieces
     */
    private static function words(string $text, array &$pieces): void
    {
        preg_match_all('/\S+|\s+/u', $text, $matches);

        foreach ($matches[0] as $token) {
            if (trim($token) === '') {
                if ($pieces !== []) {
                    $pieces[array_key_last($pieces)]['spaceAfter'] = true;
                }

                continue;
            }

            $pieces[] = ['text' => $token, 'mention' => null, 'spaceAfter' => false];
        }
    }
}
