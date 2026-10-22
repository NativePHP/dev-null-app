<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use App\Devnull\Data\User;
use App\Icons\Lucide;
use Devnull\CommitRow\MenuItem;
use Devnull\CommitRow\PlatformIcon;
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
        return array_map(fn (MenuItem $item): TopBarAction|NavAction => $item->divider
            ? NavAction::divider()
            : NavAction::make($item->id)
                ->label($item->label)
                ->icon(ios: $item->icon?->ios, android: $item->icon?->android)
                ->destructive($item->destructive)
                ->press((string) $item->press)
                ->toElement()
                ->ref("{$item->id}-{$commit->id}"), self::entries($commit, $viewer));
    }

    /**
     * The same items as plain values, for the native commit row.
     *
     * @return list<MenuItem>
     */
    public static function entries(Commit $commit, ?User $viewer): array
    {
        $isOwn = $viewer !== null && $commit->author->id === $viewer->id;
        $items = [];

        $items[] = self::entry($commit, 'share', 'Share link', Lucide::Link, 'shareCommit');

        if ($isOwn && $commit->isApproved() && $commit->parentId === null) {
            $items[] = self::entry($commit, 'pin', $commit->isPinned ? 'Unpin from profile' : 'Pin to profile', Lucide::Pin, 'togglePin');
        }

        if ($viewer !== null && ! $isOwn && $commit->isApproved()) {
            $items[] = MenuItem::divider();
            $items[] = self::entry($commit, 'report', 'Report commit', Lucide::Flag, 'reportCommit');
        } elseif ($isOwn) {
            $items[] = MenuItem::divider();
            $items[] = self::entry($commit, 'delete', 'Delete commit', Lucide::Trash2, 'confirmDelete', destructive: true);
        }

        return $items;
    }

    private static function entry(Commit $commit, string $action, string $label, Lucide $icon, string $method, bool $destructive = false): MenuItem
    {
        return new MenuItem($action, $label, new PlatformIcon($icon->ios(), $icon->android()), "{$method}('{$commit->id}')", $destructive);
    }
}
