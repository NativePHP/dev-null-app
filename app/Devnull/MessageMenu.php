<?php

namespace App\Devnull;

use App\Devnull\Data\Message;
use App\Icons\Lucide;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * What a long press on a message in a thread opens: Reply, React and Copy
 * text, then for an org's maintainers Pin message or Unpin message, as the
 * web's long-press sheet has them, with its icons. A message still being
 * pushed, or one in a thread that can't take a message, only copies its
 * text, and one with no text then opens nothing. Each item names the
 * message, so a tap never rebuilds anything from an attribute (see
 * Conversation).
 */
final class MessageMenu
{
    /**
     * $answerable is whether the message takes Reply and React, and $pinned whether it's pinned, or null when you
     * can't pin it. A message's photos open the same menu as its bubble, so only one of the two names its items
     * ($named), and tests find them.
     *
     * @return list<TopBarAction>
     */
    public static function items(Message $message, bool $answerable, ?bool $pinned, bool $named = true): array
    {
        $key = $message->id > 0 ? $message->id : $message->clientId;
        $argument = $message->id > 0 ? (string) $message->id : "'{$message->clientId}'";
        $items = [];

        if ($answerable) {
            $items[] = self::item('reply', 'Reply', Lucide::Reply, "reply({$argument})", $key, $named);
            $items[] = self::item('react', 'React', Lucide::SmilePlus, "reactFromMenu({$argument})", $key, $named);
        }

        if ($message->body !== '') {
            $items[] = self::item('copy', 'Copy text', Lucide::Copy, "copyText({$argument})", $key, $named);
        }

        if ($answerable && $pinned !== null) {
            $items[] = $pinned
                ? self::item('unpin', 'Unpin message', Lucide::PinOff, "togglePin({$argument})", $key, $named)
                : self::item('pin', 'Pin message', Lucide::Pin, "togglePin({$argument})", $key, $named);
        }

        return $items;
    }

    private static function item(string $action, string $label, Lucide $icon, string $press, int|string $key, bool $named): TopBarAction
    {
        $item = NavAction::make($action)
            ->label($label)
            ->icon(ios: $icon->ios(), android: $icon->android())
            ->press($press)
            ->toElement();

        return $named ? $item->ref("{$action}-menu-{$key}") : $item;
    }
}
