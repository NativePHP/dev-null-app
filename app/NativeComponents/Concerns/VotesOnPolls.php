<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\ResponseCache;
use App\Devnull\Toasts;
use Closure;

/**
 * Votes on the polls a screen shows, as the web's CommitPoll does: tap an
 * option to pick it, then Vote, since votes are final. Vote spins while the
 * vote goes from the background, and the poll then shows its results, here
 * and in every kept copy of the commit. When devnull turns the vote down, a
 * toast says why, in its words, and the pick stays.
 */
trait VotesOnPolls
{
    /**
     * The option picked on each poll, by the commit's id, until Vote sends it.
     *
     * @var array<string, string>
     */
    public array $pollChoices = [];

    /**
     * The commits whose vote is on its way.
     *
     * @var list<string>
     */
    public array $voting = [];

    public function choosePollOption(string $id, string $optionId): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $commit->canBeVotedOnBy($this->me()) || ! in_array($optionId, array_map(fn ($option): string => $option->id, $commit->poll?->options ?? []), true)) {
            return;
        }

        $this->pollChoices[$id] = $optionId;
    }

    public function voteOnPoll(string $id): void
    {
        $commit = $this->shownCommit($id);
        $choice = $this->pollChoices[$id] ?? null;

        if ($commit === null || $choice === null || in_array($id, $this->voting, true) || ! $commit->canBeVotedOnBy($this->me())) {
            return;
        }

        $this->voting[] = $id;

        $this->writeInBackground('POST', Client::pollVotesPath($id), ['option' => $choice], function (array $json) use ($id): void {
            $this->voting = array_values(array_diff($this->voting, [$id]));
            unset($this->pollChoices[$id]);

            $voted = Commit::fromArray(is_array($json['data'] ?? null) ? $json['data'] : []);
            $this->updateShownCommits(fn (Commit $commit): Commit => $commit->id === $id ? $commit->withPoll($voted->poll) : $commit);

            app(ResponseCache::class)->pollVoted(is_array($json['data'] ?? null) ? $json['data'] : []);
        }, function (string $message) use ($id): void {
            $this->voting = array_values(array_diff($this->voting, [$id]));

            Toasts::failed($message);
        });
    }

    /**
     * @param  Closure(Commit): Commit  $update
     */
    abstract protected function updateShownCommits(Closure $update): void;
}
