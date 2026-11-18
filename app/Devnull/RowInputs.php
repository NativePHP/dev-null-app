<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;

/**
 * What a commit row and a thread reply show besides their partial's data, for
 * RowCache to keep them against: what the partials read from the screen, such
 * as the option you picked on a poll and whether your vote is on its way, and
 * what they work out from the time.
 *
 * Images aren't here: ImageCache draws each URL the same way for as long as
 * the app runs, so a row's images only change with its commit. A video's
 * title, channel and thumbnail are, since they land after the row is first drawn (see
 * VideoDetails).
 *
 * A partial that starts reading something new needs it here too, or its row
 * keeps showing what it showed before.
 */
final class RowInputs
{
    /**
     * @param  array<string, mixed>  $scope  The including view's variables.
     * @return array<string, mixed>
     */
    public static function for(Commit $commit, array $scope): array
    {
        // The screen's own answer, null included: null is a real answer (signed out, pastéis mode off), so it's
        // only read from the account when the screen gave none. Reading it again cost a query a row on a phone.
        $viewer = array_key_exists('viewer', $scope) ? $scope['viewer'] : app(Account::class)->user();
        $ownership = $scope['ownership'] ?? app(Account::class)->ownership();

        return [
            'viewer' => $viewer?->id,
            'organizations' => $ownership->organizationIds,
            'pasteis' => array_key_exists('pasteis', $scope) ? $scope['pasteis'] : app(Account::class)->pasteis(),
            // Only this commit's own drafts and unfolding: a draft started anywhere used to build every row again.
            'stashed' => [in_array('quote-'.$commit->id, $scope['stashed'] ?? [], true), in_array('reply-'.$commit->id, $scope['stashed'] ?? [], true)],
            'unfolded' => in_array($commit->id, $scope['unfolded'] ?? [], true),
            'expandable' => isset($scope['threads']),
            'opened' => array_key_exists($commit->id, $scope['threads'] ?? []) && ! in_array($commit->id, $scope['closingThreads'] ?? [], true),
            'faded' => in_array($commit->id, $scope['fadedRows'] ?? [], true),
            // A reply whose replies, opened in place, showed its author carried it on gets "Show thread".
            'continued' => in_array($commit->id, $scope['threadsContinued'] ?? [], true),
            'popping' => [$scope['popping']['star-'.$commit->id] ?? null, $scope['popping']['pastel-'.$commit->id] ?? null],
            'tombstones' => $scope['tombstones'] ?? false,
            'shown' => [self::changing($commit), $commit->quotedCommit === null ? null : self::changing($commit->quotedCommit)],
            'poll' => [$scope['pollChoices'][$commit->id] ?? null, in_array($commit->id, $scope['voting'] ?? [], true)],
            'selected' => ($scope['selectedCommit'] ?? null) === $commit->id,
            'video' => $commit->linkPreview?->embed === null ? null : VideoDetails::card($commit->linkPreview),
            // A poll's Vote button wears your lane colour.
            'colour' => $scope['myColour'] ?? null,
        ];
    }

    /**
     * How a commit shows what changes without devnull saying so: its time, its
     * poll's time left and whether its author's stream is still on.
     *
     * @return list<mixed>
     */
    private static function changing(Commit $commit): array
    {
        return [
            Display::relativeTime($commit->createdAt),
            $commit->poll?->showsResults(),
            $commit->poll?->status(),
            $commit->poll?->summary(),
            $commit->author->isLive(),
        ];
    }
}
