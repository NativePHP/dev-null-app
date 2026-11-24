<?php

namespace App\Devnull;

use App\Devnull\Data\User;
use App\Icons\Lucide;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * Who a composer posts as, as the web's Post as menu chooses: you, or one of
 * the GitHub organizations you're a member of, admin or not (see
 * Account::organizations()). A composer keeps the handle picked, and posts as
 * you once that organization is no longer yours, as the web does, so a commit
 * devnull would refuse isn't sent as it again.
 *
 * The menu opens from the composer's avatar, which shows who it posts as:
 * round for you, square for an organization. Someone in no organization never
 * sees it, as on the web, unless they had picked one they've since left, when
 * their own avatar shows who posts now.
 */
final class PostAs
{
    /**
     * @return list<User>
     */
    public static function organizations(): array
    {
        return app(Account::class)->organizations();
    }

    /**
     * The organization the commit goes out as, or null when it goes out as you.
     */
    public static function author(?string $picked): ?User
    {
        if ($picked === null || $picked === '') {
            return null;
        }

        foreach (self::organizations() as $organization) {
            if (strcasecmp($organization->username, $picked) === 0) {
                return $organization;
            }
        }

        return null;
    }

    /**
     * Whether the composer shows whom it posts as: you're in an organization, or picked one you've left since.
     */
    public static function offered(?string $picked): bool
    {
        return self::organizations() !== [] || ($picked !== null && $picked !== '');
    }

    /**
     * The menu: you first, then your organizations, each with their avatar, round for you and a rounded square for
     * an organization, as elsewhere in the app, and the one posting now ticked. Until an avatar loads, or if it
     * can't, a person or building icon stands in. $method is called with the handle picked, or '' for you.
     *
     * @return list<TopBarAction>
     */
    public static function menu(User $me, ?User $author, string $method): array
    {
        return array_map(function (User $identity) use ($author, $method): TopBarAction {
            $icon = $identity->isOrganization ? Lucide::Building2 : Lucide::UserRound;

            return NavAction::make('post-as-'.$identity->username)
                ->label($identity->name.' (@'.$identity->username.')')
                ->icon(ios: $icon->ios(), android: $icon->android())
                ->image(ImageCache::src($identity->avatarUrl))
                ->press($method."('".($identity->isOrganization ? $identity->username : '')."')")
                ->toElement()
                ->setProp('image_shape', $identity->isOrganization ? 'rounded' : 'circle')
                ->setProp('checked', self::posting($identity, $author))
                ->ref('post-as-'.$identity->username);
        }, [$me, ...self::organizations()]);
    }

    /**
     * What the composer's avatar says to a screen reader.
     */
    public static function label(User $me, ?User $author): string
    {
        return 'Posting as @'.($author ?? $me)->username.'. Choose who to post as';
    }

    /**
     * The lane the composer's main button is drawn in: yours, or the organization's.
     */
    public static function colour(User $me, ?User $author): string
    {
        return Lane::token(($author ?? $me)->lane());
    }

    /**
     * Whether the menu ticks this one: whoever posts now.
     */
    private static function posting(User $identity, ?User $author): bool
    {
        return $author === null ? ! $identity->isOrganization : $identity->isOrganization && $identity->username === $author->username;
    }
}
