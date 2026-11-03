<?php

namespace App\Devnull\NataNullers;

/**
 * A high score shared as a commit, and reading one back out of a commit.
 *
 * The first line is for people. The fenced block carries the signed run, so
 * the web shows it as code and the app can turn it into a card. The block is
 * also what a leaderboard will search for later.
 */
final class ScoreCommit
{
    public const string Fence = 'nata-nullers';

    private const string Pattern = '/^```nata-nullers\n(v1)\.([0-9a-f-]{36})\.(\d{1,9})\.(\d{1,12})\.([A-Za-z0-9+\/=]+)\n```$/m';

    public static function body(Run $run): string
    {
        return "i got a new high score on Nata Nu//ers: {$run->score}\n\n```".self::Fence."\n"
            .implode('.', ['v1', $run->id, $run->score, $run->endedAt, $run->signature])
            ."\n```";
    }

    /**
     * The run in a commit's body, attributed to the commit's author, or null
     * when there is none. A run lifted into someone else's commit comes back,
     * but its signature won't hold: check signer().
     */
    public static function parse(string $body, string $authorId): ?Run
    {
        if (! str_contains($body, '```'.self::Fence) || preg_match(self::Pattern, str_replace("\r\n", "\n", $body), $match) !== 1) {
            return null;
        }

        return new Run($match[2], $authorId, (int) $match[3], (int) $match[4], $match[5]);
    }
}
