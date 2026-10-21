<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use App\Devnull\Data\User;
use App\Icons\Lucide;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * What More holds, on the right of a commit's action row (see
 * partials/commit-actions): Share link on every commit; Pin to profile or
 * Unpin from profile on your own published commit, not a reply; then, after a
 * divider, Report commit on someone else's published commit, or Delete commit
 * on your own. Each item names the commit, so a tap never rebuilds anything
 * from an attribute (see ShowsCommits).
 */
final class CommitMenu
{
    /**
     * @return list<TopBarAction|NavAction>
     */
    public static function items(Commit $commit, ?User $viewer): array
    {
        $isOwn = $viewer !== null && $commit->author->id === $viewer->id;
        $items = [];

        $items[] = self::item($commit, 'share', 'Share link', Lucide::Link, 'shareCommit');

        if ($isOwn && $commit->isApproved() && $commit->parentId === null) {
            $items[] = self::item($commit, 'pin', $commit->isPinned ? 'Unpin from profile' : 'Pin to profile', Lucide::Pin, 'togglePin');
        }

        if ($viewer !== null && ! $isOwn && $commit->isApproved()) {
            $items[] = NavAction::divider();
            $items[] = self::item($commit, 'report', 'Report commit', Lucide::Flag, 'reportCommit');
        } elseif ($isOwn) {
            $items[] = NavAction::divider();
            $items[] = self::item($commit, 'delete', 'Delete commit', Lucide::Trash2, 'confirmDelete', destructive: true);
        }

        return $items;
    }

    private static function item(Commit $commit, string $action, string $label, Lucide $icon, string $method, bool $filled = false, bool $destructive = false): TopBarAction
    {
        return NavAction::make($action)
            ->label($label)
            ->icon(ios: $icon->ios($filled), android: $icon->android($filled))
            ->destructive($destructive)
            ->press("{$method}('{$commit->id}')")
            ->toElement()
            ->ref("{$action}-{$commit->id}");
    }
}
