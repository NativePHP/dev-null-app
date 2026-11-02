<?php

namespace App\Devnull;

use App\Devnull\Data\User;
use App\Icons\Lucide;
use App\NativeComponents\OrgMembers;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * What More on someone in an org opens for a maintainer, as the web's member
 * menu has it, in its order and words: Make maintainer, or Make contributor,
 * which is off for the last maintainer since an org needs one, then after a
 * divider Remove from org, in red, which asks first. Each item names the
 * member, so a tap never rebuilds anything from an attribute.
 */
final class OrgMemberMenu
{
    /**
     * @return list<TopBarAction|NavAction>
     */
    public static function items(User $member, ?string $role, bool $isLastMaintainer): array
    {
        return [
            $role === OrgMembers::Maintainer
                ? self::item($member, 'make-contributor', 'Make contributor', Lucide::UserRound, 'makeContributor', disabled: $isLastMaintainer)
                : self::item($member, 'make-maintainer', 'Make maintainer', Lucide::Shield, 'makeMaintainer'),
            NavAction::divider(),
            self::item($member, 'remove', 'Remove from org', Lucide::UserMinus, 'confirmRemove', destructive: true),
        ];
    }

    private static function item(User $member, string $action, string $label, Lucide $icon, string $method, bool $destructive = false, bool $disabled = false): TopBarAction
    {
        return NavAction::make($action)
            ->label($label)
            ->icon(ios: $icon->ios(), android: $icon->android())
            ->destructive($destructive)
            ->disabled($disabled)
            ->press("{$method}('{$member->username}')")
            ->toElement()
            ->ref("{$action}-{$member->username}");
    }
}
