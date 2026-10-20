<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;

/**
 * What a commit row and a thread reply show besides their partial's data, for
 * RowCache to keep them against: what the partials read from the screen, and
 * what they work out from the time and from the images already on the device.
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
        $viewer = $scope['viewer'] ?? app(Account::class)->user();

        return [
            'viewer' => $viewer?->id,
            'pasteis' => $scope['pasteis'] ?? app(Account::class)->pasteis(),
            'stashed' => $scope['stashed'] ?? [],
            'unfolded' => $scope['unfolded'] ?? [],
            'expandable' => isset($scope['threads']),
            'opened' => array_key_exists($commit->id, $scope['threads'] ?? []),
            'tombstones' => $scope['tombstones'] ?? false,
            'tombstoneReplies' => array_key_exists($commit->id, $scope['tombstoneReplies'] ?? []),
            'shown' => [self::changing($commit), $commit->quotedCommit === null ? null : self::changing($commit->quotedCommit)],
        ];
    }

    /**
     * How a commit shows what changes without devnull saying so: its time, its
     * poll's time left, and each image as the phone's copy once it has one.
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
            ImageCache::src($commit->author->avatarUrl),
            ImageCache::src($commit->linkPreview?->imageUrl),
            array_map(fn ($image): ?string => ImageCache::src($image->url), $commit->images),
        ];
    }
}
