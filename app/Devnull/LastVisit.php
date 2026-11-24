<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * When you last looked at each log, for the `HEAD@{last visit}` marker.
 *
 * Like the web's `useLastVisit`, this keeps the time of the newest commit you
 * were shown, per user and per log. The marker sits above the first commit at
 * or before that time, and only when someone else committed since.
 */
final class LastVisit
{
    public function seenAt(string $userId, string $log): ?string
    {
        $seen = Cache::get($this->key($userId, $log));

        return is_string($seen) && $seen !== '' ? $seen : null;
    }

    /**
     * @param  list<Commit>  $commits  newest first
     */
    public function remember(string $userId, string $log, array $commits): void
    {
        $newest = $commits[0]->createdAt ?? null;

        if ($newest !== null) {
            Cache::forever($this->key($userId, $log), $newest->toIso8601String());
        }
    }

    /**
     * The commit the marker goes above, or null for a first visit, when nothing is
     * new, or when the only new commits are yours or your organizations' (see Ownership).
     *
     * @param  list<Commit>  $commits  newest first
     */
    public static function firstSeenId(array $commits, ?string $seenAt, Ownership $ownership): ?string
    {
        if ($seenAt === null || ! $ownership->isSignedIn()) {
            return null;
        }

        $seen = Commit::date($seenAt);

        if (! $seen instanceof CarbonInterface) {
            return null;
        }

        foreach ($commits as $index => $commit) {
            if ($commit->createdAt === null || $commit->createdAt->greaterThan($seen)) {
                continue;
            }

            foreach (array_slice($commits, 0, $index) as $newer) {
                if (! $ownership->owns($newer)) {
                    return $commit->id;
                }
            }

            return null;
        }

        return null;
    }

    private function key(string $userId, string $log): string
    {
        return "devnull.last-visit.{$userId}.{$log}";
    }
}
