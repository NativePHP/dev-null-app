<?php

namespace App\Devnull;

use App\Devnull\Data\BodyBlock;
use App\Devnull\Data\BodyRun;

/**
 * A commit's paragraph that mentions a guild, cut up so each guild can be drawn as a pill with its mark, as the web's
 * guild mention is (see partials/commit-body and partials/guild-mention).
 *
 * EDGE can't put an image inside a run of text, so, as with a bio's tags (see BioText), a line with a guild in it is
 * laid out as a wrapping row of pieces. Prose is cut into words, each keeping its run's look and tap, so a word wraps
 * where text would. A guild, a mention, inline code, a link, a hex color, a hashtag or the wordmark stays whole, as
 * one piece. Each piece says whether a space follows it, so punctuation stays against the piece before it.
 *
 * Only the lines with a guild are cut up. The lines between them stay runs of one text, as any other paragraph, so
 * they keep its spacing. Links are numbered in the body's order across both, as a paragraph numbers them.
 */
final class BodyFlow
{
    /**
     * Whether the paragraph mentions a guild, and so needs pills rather than one run of text.
     */
    public static function needed(BodyBlock $block): bool
    {
        foreach ($block->runs as $run) {
            if ($run->guild !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The paragraph in order, as parts: lines without a guild together as runs of one text ($runs), and lines with
     * one as rows of pieces ($rows). $linkIndex is the number of the body's next link, and is moved past this
     * paragraph's links.
     *
     * @return list<array{runs: list<array{run: BodyRun, linkIndex: ?int}>, rows: list<list<array{run: BodyRun, spaceAfter: bool, linkIndex: ?int}>>}>
     */
    public static function parts(BodyBlock $block, int &$linkIndex): array
    {
        $parts = [];

        foreach (self::lines($block->runs) as $line) {
            $hasGuild = array_filter($line, fn (BodyRun $run): bool => $run->guild !== null) !== [];
            $last = array_key_last($parts);

            if ($hasGuild) {
                $pieces = self::pieces($line, $linkIndex);

                if ($last !== null && $parts[$last]['rows'] !== []) {
                    $parts[$last]['rows'][] = $pieces;
                } else {
                    $parts[] = ['runs' => [], 'rows' => [$pieces]];
                }

                continue;
            }

            $runs = array_map(fn (BodyRun $run): array => ['run' => $run, 'linkIndex' => $run->link !== null ? $linkIndex++ : null], $line);

            if ($last !== null && $parts[$last]['rows'] === []) {
                $parts[$last]['runs'] = [...$parts[$last]['runs'], ['run' => new BodyRun("\n"), 'linkIndex' => null], ...$runs];
            } else {
                $parts[] = ['runs' => $runs, 'rows' => []];
            }
        }

        return $parts;
    }

    /**
     * The paragraph's runs, cut at each line break, which is dropped.
     *
     * @param  list<BodyRun>  $runs
     * @return list<list<BodyRun>>
     */
    private static function lines(array $runs): array
    {
        $lines = [[]];

        foreach ($runs as $run) {
            if (self::isWhole($run)) {
                $lines[array_key_last($lines)][] = $run;

                continue;
            }

            foreach (preg_split('/\R/u', $run->text) ?: [] as $index => $text) {
                if ($index > 0) {
                    $lines[] = [];
                }

                if ($text !== '') {
                    $lines[array_key_last($lines)][] = new BodyRun($text);
                }
            }
        }

        return $lines;
    }

    /**
     * A line's pieces: its prose cut into words, anything else whole.
     *
     * @param  list<BodyRun>  $line
     * @return list<array{run: BodyRun, spaceAfter: bool, linkIndex: ?int}>
     */
    private static function pieces(array $line, int &$linkIndex): array
    {
        $pieces = [];

        foreach ($line as $run) {
            if (self::isWhole($run)) {
                $pieces[] = ['run' => $run, 'spaceAfter' => false, 'linkIndex' => $run->link !== null ? $linkIndex++ : null];

                continue;
            }

            preg_match_all('/\s+|\S+/u', $run->text, $matches);

            foreach ($matches[0] as $token) {
                if (trim($token) !== '') {
                    $pieces[] = ['run' => new BodyRun($token), 'spaceAfter' => false, 'linkIndex' => null];
                } elseif ($pieces !== []) {
                    $pieces[array_key_last($pieces)]['spaceAfter'] = true;
                }
            }
        }

        if ($pieces !== []) {
            $pieces[array_key_last($pieces)]['spaceAfter'] = false;
        }

        return $pieces;
    }

    /**
     * Whether the run is drawn as one piece: anything but prose.
     */
    private static function isWhole(BodyRun $run): bool
    {
        return $run->guild !== null || $run->isMention() || $run->isCode || $run->isWordmark || $run->link !== null
            || $run->isColor() || $run->isHashtag;
    }
}
