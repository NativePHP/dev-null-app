<?php

namespace App\Devnull\Data;

/**
 * Turns a commit's `body_segments` into blocks to draw, the way the web's
 * CommitBody does: prose (with URLs and mentions linked, and `/dev/null` as
 * the wordmark) and inline code flow together in paragraphs, and fenced code
 * blocks stand on their own.
 *
 * Mention offsets count code points into the whole body, but segments aren't
 * exact slices of it: inline code loses its backticks, a block its fences, and
 * prose the line break next to a block. So each segment is found in the body,
 * moving forward, and mentions are placed by where their segment starts.
 *
 * A long commit's preview segments are the start of the body with an ellipsis
 * after the last of them, so they're found in the body the same way.
 */
final class CommitBody
{
    /**
     * A fenced block from where it starts: an opening line of three or more
     * backticks, then up to a line of only backticks. The server's rule.
     */
    private const string Ellipsis = '…';

    private const string FencedBlock = '/\G`{3,}[^`\r\n]*\r?\n(?:([\s\S]*?)\r?\n)?`{3,}[ \t]*(?![^\r\n])/u';

    /**
     * A `/dev/null` that stands on its own, not part of a longer path, a word
     * or a name. The web's rule, from withWordmarks() in linkified-text.tsx.
     */
    private const string Wordmark = '/(?<![\p{L}\p{M}\p{N}_.\/-])(\/dev\/null)(?![\p{L}\p{M}\p{N}_\/-])/u';

    /**
     * @param  list<array<string, mixed>>|null  $segments  The `body_segments`, or null when the API sent none.
     * @param  list<Mention>  $mentions
     * @return list<BodyBlock>
     */
    public static function blocks(string $body, ?array $segments, array $mentions = []): array
    {
        $segments ??= $body === '' ? [] : [['type' => 'text', 'text' => $body]];

        $blocks = [];
        $runs = [];
        $cursor = 0;

        foreach ($segments as $segment) {
            switch ($segment['type'] ?? null) {
                case 'text':
                    $text = is_string($segment['text'] ?? null) ? $segment['text'] : '';

                    if ($text === '') {
                        break;
                    }

                    // A preview's last run ends in the ellipsis it was cut with, which isn't in the body.
                    $cut = str_ends_with($text, self::Ellipsis) && mb_strpos($body, $text, $cursor) === false;
                    $text = $cut ? mb_substr($text, 0, -1) : $text;
                    $start = $text === '' ? false : mb_strpos($body, $text, $cursor);

                    if ($start === false) {
                        array_push($runs, ...self::withLinks($text.($cut ? self::Ellipsis : ''), null, []));

                        break;
                    }

                    array_push($runs, ...self::withLinks($text, $start, $mentions));
                    $cursor = $start + mb_strlen($text);

                    if ($cut) {
                        $runs[] = new BodyRun(self::Ellipsis);
                    }

                    break;
                case 'code':
                    $code = is_string($segment['code'] ?? null) ? $segment['code'] : '';
                    $start = mb_strpos($body, '`'.$code.'`', $cursor);

                    if ($start !== false) {
                        $cursor = $start + mb_strlen($code) + 2;
                    }

                    $runs[] = new BodyRun($code, isCode: true);

                    break;
                case 'block':
                    if ($runs !== []) {
                        $blocks[] = BodyBlock::paragraph($runs);
                        $runs = [];
                    }

                    [$code, $cursor] = self::fencedCode($body, $cursor);
                    $language = $segment['language'] ?? null;

                    $blocks[] = BodyBlock::code($code, is_string($language) && $language !== '' ? $language : null);

                    break;
            }
        }

        if ($runs !== []) {
            $blocks[] = BodyBlock::paragraph($runs);
        }

        return $blocks;
    }

    /**
     * Splits prose that starts at $start in the body around the links inside
     * it, then the prose between them around its mentions, as the web does.
     * Prose that wasn't found in the body has no start, so no mentions.
     *
     * @param  list<Mention>  $mentions
     * @return list<BodyRun>
     */
    private static function withLinks(string $text, ?int $start, array $mentions): array
    {
        $runs = [];
        $position = 0;
        $prose = fn (string $part, int $at): array => $start === null
            ? self::withWordmarks($part)
            : self::withMentions($part, $start + $at, $mentions);

        foreach (BodyLink::in($text) as [$offset, $length, $link]) {
            if ($offset > $position) {
                array_push($runs, ...$prose(mb_substr($text, $position, $offset - $position), $position));
            }

            $runs[] = new BodyRun(mb_substr($text, $offset, $length), link: $link);
            $position = $offset + $length;
        }

        if ($position < mb_strlen($text)) {
            array_push($runs, ...$prose(mb_substr($text, $position), $position));
        }

        return $runs;
    }

    /**
     * Splits prose that starts at $start in the body around the mentions inside it.
     *
     * @param  list<Mention>  $mentions
     * @return list<BodyRun>
     */
    private static function withMentions(string $text, int $start, array $mentions): array
    {
        $runs = [];
        $position = 0;
        $length = mb_strlen($text);

        usort($mentions, fn (Mention $a, Mention $b): int => $a->offset <=> $b->offset);

        foreach ($mentions as $mention) {
            $offset = $mention->offset - $start;

            if ($offset < $position || $offset + $mention->length > $length) {
                continue;
            }

            $handle = mb_substr($text, $offset, $mention->length);

            if (mb_strtolower($handle) !== '@'.mb_strtolower($mention->username)) {
                continue;
            }

            if ($offset > $position) {
                array_push($runs, ...self::withWordmarks(mb_substr($text, $position, $offset - $position)));
            }

            $runs[] = new BodyRun($handle, username: $mention->username);
            $position = $offset + $mention->length;
        }

        if ($position < $length) {
            array_push($runs, ...self::withWordmarks(mb_substr($text, $position)));
        }

        return $runs;
    }

    /**
     * Splits prose around each standalone `/dev/null`, which becomes a
     * wordmark run. Like the web, the prose between two mentions is looked at
     * on its own.
     *
     * @return list<BodyRun>
     */
    private static function withWordmarks(string $prose): array
    {
        $runs = [];

        foreach (preg_split(self::Wordmark, $prose, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [$prose] as $index => $part) {
            if ($part !== '') {
                $runs[] = new BodyRun($part, isWordmark: $index % 2 === 1);
            }
        }

        return $runs;
    }

    /**
     * The code of the next fenced block in the body, as written, and where the
     * block ends. The highlighted HTML isn't something to draw natively.
     *
     * @return array{0: string, 1: int}
     */
    private static function fencedCode(string $body, int $cursor): array
    {
        $start = mb_strpos($body, '```', $cursor);

        if ($start === false) {
            return ['', $cursor];
        }

        if (preg_match(self::FencedBlock, $body, $match, offset: strlen(mb_substr($body, 0, $start))) !== 1) {
            return ['', $start + 3];
        }

        return [str_replace("\r\n", "\n", $match[1] ?? ''), $start + mb_strlen($match[0])];
    }
}
