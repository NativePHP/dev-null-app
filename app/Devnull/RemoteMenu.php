<?php

namespace App\Devnull;

use App\Devnull\Data\Conversation;
use App\Icons\Lucide;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * What a long press on a Remotes row opens, as the web's row menu has it, in
 * its order, words and icons: View profile on a conversation with a person,
 * Pin or Unpin, Stash or Pop, Mute or Unmute, then after a divider Block and
 * their handle, or Leave org on an org, in red. Each item names the row, so a
 * tap never rebuilds anything from an attribute (see Remotes).
 */
final class RemoteMenu
{
    /**
     * @return list<TopBarAction|NavAction>
     */
    public static function items(Conversation $conversation, ?string $viewerId): array
    {
        $items = [];

        if (! $conversation->isOrg()) {
            $items[] = self::item($conversation, 'view-profile', 'View profile', Lucide::UserRound, 'viewProfile');
        }

        $items[] = $conversation->isPinned()
            ? self::item($conversation, 'unpin', 'Unpin', Lucide::PinOff, 'unpin')
            : self::item($conversation, 'pin', 'Pin', Lucide::Pin, 'pin');

        $items[] = $conversation->isArchived
            ? self::item($conversation, 'pop', 'Pop', Lucide::ArchiveRestore, 'pop')
            : self::item($conversation, 'stash', 'Stash', Lucide::Archive, 'stash');

        $items[] = $conversation->isMuted()
            ? self::item($conversation, 'unmute', 'Unmute', Lucide::Bell, 'unmute')
            : self::item($conversation, 'mute', 'Mute', Lucide::BellOff, 'chooseMute');

        $items[] = NavAction::divider();

        $items[] = $conversation->isOrg()
            ? self::item($conversation, 'leave', 'Leave org', Lucide::LogOut, 'confirmLeave', destructive: true)
            : self::item($conversation, 'block', 'Block '.$conversation->menuTitle($viewerId), Lucide::Ban, 'confirmBlock', destructive: true);

        return $items;
    }

    private static function item(Conversation $conversation, string $action, string $label, Lucide $icon, string $method, bool $destructive = false): TopBarAction
    {
        return NavAction::make($action)
            ->label($label)
            ->icon(ios: $icon->ios(), android: $icon->android())
            ->destructive($destructive)
            ->press("{$method}('{$conversation->id}')")
            ->toElement()
            ->ref("{$action}-{$conversation->id}");
    }
}
