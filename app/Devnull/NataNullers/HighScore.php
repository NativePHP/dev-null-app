<?php

namespace App\Devnull\NataNullers;

use App\Models\NataNullersBestRun;

/**
 * Your best Nata Nu//ers run, per user, in the app's database (see
 * App\Models\NataNullersBestRun), with its signature so it can still be
 * shared as a commit later.
 *
 * It isn't cached because Android keeps the cache in files and empties them
 * whenever a new bundle unpacks: on every app update, and on every cold start
 * of a debug build. The database lives outside the bundle and survives.
 */
final class HighScore
{
    public function best(string $userId): ?Run
    {
        return NataNullersBestRun::query()->where('user_id', $userId)->first()?->run();
    }

    /**
     * Keeps the run when it beats your best. True when it did.
     */
    public function record(Run $run): bool
    {
        $best = $this->best($run->userId);

        if ($best !== null && $best->score >= $run->score) {
            return false;
        }

        NataNullersBestRun::query()->updateOrCreate(['user_id' => $run->userId], NataNullersBestRun::columnsFor($run));

        return true;
    }
}
