<?php

namespace App\Devnull\Data;

use App\Devnull\NataNullers\ScoreCommit;

/**
 * A Nata Nu//ers high score shared as a commit (see ScoreCommit), as its card
 * shows it: the score, and whether the run's signature holds for the commit's
 * author.
 *
 * Signed means a key signed this run for this author, and nothing more: the
 * app can't yet tell whether that key is one of the author's devices, and the
 * phone that played the game was its own referee. A run lifted into someone
 * else's commit, or edited, is still shown, just not as signed.
 */
final readonly class ScoreCard
{
    public function __construct(
        public int $score,
        public bool $signed,
    ) {}

    public static function fromCommit(string $body, User $author): ?self
    {
        $run = ScoreCommit::parse($body, $author->id);

        return $run === null ? null : new self($run->score, $run->signer() !== null);
    }

    /**
     * The body without the fenced block that carries the run: the line the
     * author's friends read. Mentions keep their place, as anything before the
     * block keeps its offsets.
     */
    public static function withoutRun(string $body): string
    {
        $start = strpos($body, '```'.ScoreCommit::Fence);

        if ($start === false) {
            return $body;
        }

        $end = strpos($body, '```', $start + 3);
        $after = $end === false ? '' : substr($body, $end + 3);

        return rtrim(rtrim(substr($body, 0, $start)).$after);
    }
}
