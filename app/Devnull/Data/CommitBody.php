<?php

namespace App\Devnull\Data;

/**
 * Turns a commit's `body_segments` into blocks to draw, the way the web's
 * CommitBody does: prose (with URLs and mentions linked, hex colors beside a
 * swatch, and `/dev/null` as the wordmark) and inline code flow together in
 * paragraphs, lines that start with `>` group into quotes, and fenced code
 * blocks stand on their own.
 *
 * Mention and color offsets count code points into the whole body, but segments aren't
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

    /**
     * The web's CodeSpans rule, retaining delimiters so code counts literally.
     */
    private const string CodeSpans = '/((?<![^\n])`{3,}[^`\r\n]*\r?\n(?:[\s\S]*?\r?\n)?`{3,}[ \t]*(?![^\r\n])|`[^`\r\n]+`)/';

    private const string FencedBlock = '/\G`{3,}[^`\r\n]*\r?\n(?:([\s\S]*?)\r?\n)?`{3,}[ \t]*(?![^\r\n])/u';

    /**
     * A hex color: 3, 4, 6 or 8 hex digits after a `#`. One of 3 or 4 digits
     * also needs a letter or a leading zero, so `#123` stays an issue number
     * while `#000` is black. It's a whole word: it starts a line, follows
     * whitespace or a quoted line's `>`, and only punctuation may follow it
     * before the next space. The web's rule, from colors.ts.
     */
    private const string Color = '/(?:(?<!\S)|(?<=\n>))[#\x{FF03}]([0-9a-f]{8}|[0-9a-f]{6}|(?=[0-9]*[a-f]|0)[0-9a-f]{3,4})(?=\p{P}*(?:[\s\x{00AD}\x{2060}\x{200A}-\x{200D}\x{20E2}]|\z))/iu';

    /**
     * A `/dev/null` that stands on its own, not part of a longer path, a word
     * or a name. The web's rule, from withWordmarks() in linkified-text.tsx.
     */
    private const string Wordmark = '/(?<![\p{L}\p{M}\p{N}_.\/-])(\/dev\/null)(?![\p{L}\p{M}\p{N}_\/-])/u';

    /**
     * The body as the web saves it: line endings normalized, with runs of blank lines collapsed in prose only.
     */
    public static function normalize(string $body): string
    {
        $parts = self::parts(str_replace(["\r\n", "\r"], "\n", trim($body)));

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = (string) preg_replace('/(?:[ \t]*\n){3,}/', "\n\n", $part);
            }
        }

        return implode('', $parts);
    }

    /**
     * Each prose URL counts as at most 23 code points. Code and invalid URLs count literally.
     */
    public static function characterCount(string $body): int
    {
        $body = self::normalize($body);
        $length = mb_strlen($body);

        foreach (self::parts($body) as $index => $part) {
            if ($index % 2 === 0) {
                foreach (BodyLink::in($part) as [, $writtenLength]) {
                    $length -= max(0, $writtenLength - 23);
                }
            }
        }

        return $length;
    }

    public static function hasOversizedUrl(string $body): bool
    {
        foreach (self::parts(self::normalize($body)) as $index => $part) {
            if ($index % 2 === 0 && BodyLink::hasOversizedUrl($part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alternating prose and code, including the code's delimiters.
     *
     * @return list<string>
     */
    private static function parts(string $body): array
    {
        return preg_split(self::CodeSpans, $body, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
    }

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
        $colors = self::colors($body);

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
                        array_push($runs, ...self::withLinks($text.($cut ? self::Ellipsis : ''), null, [], []));

                        break;
                    }

                    array_push($runs, ...self::withLinks($text, $start, $mentions, $colors));
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
                    array_push($blocks, ...self::paragraphs($runs));
                    $runs = [];

                    [$code, $cursor] = self::fencedCode($body, $cursor);
                    $language = $segment['language'] ?? null;

                    $blocks[] = BodyBlock::code($code, is_string($language) && $language !== '' ? $language : null);

                    break;
            }
        }

        array_push($blocks, ...self::paragraphs($runs));

        return $blocks;
    }

    /**
     * Splits a paragraph's runs into paragraphs and quotes, as the web's
     * bodyBlocks() does: prose that starts a line with `>` quotes that line,
     * minus the marker and one space after it, and the lines next to it that
     * do the same join it. A `>` inside code, or anywhere but a line's start,
     * stays as written. The line break before each change is dropped, since
     * the blocks are drawn one under the other.
     *
     * @param  list<BodyRun>  $runs
     * @return list<BodyBlock>
     */
    private static function paragraphs(array $runs): array
    {
        /** @var list<array{quoted: bool, runs: list<BodyRun>}> $groups */
        $groups = [];
        $atLineStart = true;
        $quoted = false;

        foreach ($runs as $run) {
            $prose = ! $run->isMention() && ! $run->isCode && ! $run->isWordmark && $run->link === null && ! $run->isColor();

            $lines = $prose
                ? array_map(fn (string $text): BodyRun => new BodyRun($text), preg_split('/(?<=\n)/', $run->text, flags: PREG_SPLIT_NO_EMPTY) ?: [])
                : [$run];

            foreach ($lines as $line) {
                if ($atLineStart) {
                    $quoted = $prose && str_starts_with($line->text, '>');
                }

                if ($atLineStart && $quoted) {
                    $line = new BodyRun((string) preg_replace('/^> ?/', '', $line->text));
                }

                $last = array_key_last($groups);

                if ($last === null || $groups[$last]['quoted'] !== $quoted) {
                    if ($last !== null) {
                        $groups[$last]['runs'] = self::withoutTrailingBreak($groups[$last]['runs']);
                    }

                    $groups[] = ['quoted' => $quoted, 'runs' => []];
                    $last = array_key_last($groups);
                }

                if ($line->text !== '') {
                    $groups[$last]['runs'][] = $line;
                }

                $atLineStart = $prose && str_ends_with($line->text, "\n");
            }
        }

        $blocks = [];

        foreach ($groups as $group) {
            if ($group['runs'] !== [] || $group['quoted']) {
                $blocks[] = $group['quoted'] ? BodyBlock::quote($group['runs']) : BodyBlock::paragraph($group['runs']);
            }
        }

        return $blocks;
    }

    /**
     * @param  list<BodyRun>  $runs
     * @return list<BodyRun>
     */
    private static function withoutTrailingBreak(array $runs): array
    {
        $last = array_pop($runs);

        if ($last === null) {
            return $runs;
        }

        $text = (string) preg_replace('/\r?\n$/', '', $last->text);

        if ($text !== '') {
            $runs[] = $last->text === $text ? $last : new BodyRun($text);
        }

        return $runs;
    }

    /**
     * Splits prose that starts at $start in the body around the links inside
     * it, then the prose between them around its mentions, as the web does.
     * Prose that wasn't found in the body has no start, so no mentions.
     *
     * @param  list<Mention>  $mentions
     * @param  list<array{0: int, 1: int, 2: string}>  $colors
     * @return list<BodyRun>
     */
    private static function withLinks(string $text, ?int $start, array $mentions, array $colors): array
    {
        $runs = [];
        $position = 0;
        $prose = fn (string $part, int $at): array => $start === null
            ? self::withWordmarks($part)
            : self::withMentions($part, $start + $at, $mentions, $colors);

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
     * @param  list<array{0: int, 1: int, 2: string}>  $colors
     * @return list<BodyRun>
     */
    private static function withMentions(string $text, int $start, array $mentions, array $colors): array
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
                array_push($runs, ...self::withColors(mb_substr($text, $position, $offset - $position), $start + $position, $colors));
            }

            $runs[] = new BodyRun($handle, username: $mention->username);
            $position = $offset + $mention->length;
        }

        if ($position < $length) {
            array_push($runs, ...self::withColors(mb_substr($text, $position), $start + $position, $colors));
        }

        return $runs;
    }

    /**
     * Splits prose that starts at $start in the body around the colors found
     * in the whole body that fall inside it, as the web's withColors() does.
     *
     * @param  list<array{0: int, 1: int, 2: string}>  $colors
     * @return list<BodyRun>
     */
    private static function withColors(string $text, int $start, array $colors): array
    {
        $runs = [];
        $position = 0;
        $length = mb_strlen($text);

        foreach ($colors as [$offset, $colorLength, $value]) {
            $offset -= $start;

            if ($offset < $position || $offset + $colorLength > $length) {
                continue;
            }

            array_push($runs, ...self::withWordmarks(mb_substr($text, $position, $offset - $position)));
            $runs[] = new BodyRun(mb_substr($text, $offset, $colorLength), color: $value);
            $position = $offset + $colorLength;
        }

        array_push($runs, ...self::withWordmarks(mb_substr($text, $position)));

        return $runs;
    }

    /**
     * The hex colors in the body's prose, in order, as where each starts and
     * how long it is in code points, `#` included, and its digits. Code is
     * blanked out first, so nothing in it counts, and the body's start is a
     * line's start.
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private static function colors(string $body): array
    {
        $prose = '';

        foreach (self::parts($body) as $index => $part) {
            $prose .= $index % 2 === 0 ? $part : str_repeat(' ', mb_strlen($part));
        }

        preg_match_all(self::Color, "\n".$prose, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match): array => [
            mb_strlen(substr("\n".$prose, 0, $match[0][1])) - 1,
            mb_strlen($match[0][0]),
            $match[1][0],
        ], $matches);
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
