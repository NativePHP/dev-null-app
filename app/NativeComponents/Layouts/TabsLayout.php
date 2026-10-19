<?php

namespace App\NativeComponents\Layouts;

use App\Devnull\Account;
use App\Devnull\Lane;
use App\Icons\Lucide;
use App\NativeComponents\Log;
use Native\Mobile\Edge\Elements\Image;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * The top-level sections: Log, Notifications, Saved and your Profile, with the
 * icons of the web's nav, picked out in the colour you chose for your lane.
 * Notifications wears a badge with the unread count devnull last gave. The
 * web's phone keeps Saved in its account menu; here it's a tab of its own.
 */
class TabsLayout extends NativeLayout
{
    /**
     * Each tab's URI. Commits and profiles open beneath the one they were tapped in.
     */
    public const array Tabs = ['/log', '/notifications', '/saved', '/profile'];

    /**
     * The icon and "/dev/null" wordmark as the web's MobileHeader draws them, 105 x 28pt.
     * EDGE images load neither SVG nor a per-appearance variant, so there is a PNG for
     * each appearance. It's rendered at @3x of the largest size it's drawn at, 300pt wide,
     * since onboarding draws it at two thirds of the screen width. Everything else
     * scales it down rather than up.
     */
    public const string LightLogo = 'images/logo-light@3x.png';

    public const string DarkLogo = 'images/logo-dark@3x.png';

    /**
     * The logo for the system appearance.
     */
    public static function logo(): string
    {
        return isDark() ? self::DarkLogo : self::LightLogo;
    }

    protected ?string $font = 'semibold';

    public function usesNativeChrome(): bool
    {
        return true;
    }

    /**
     * The Log wears the logo in place of its title, as the web's header does.
     */
    public function navBar(NativeComponent $screen): ?NavBar
    {
        $navBar = NavBar::make()
            ->title($screen->navTitle())
            ->displayMode('inline');

        if ($screen instanceof Log) {
            $navBar->titleView(Image::make(self::logo())->height(28)->alt('devnull'));
        }

        return $navBar;
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        $notifications = Tab::link('Notifications', '/notifications', ios: Lucide::Bell->ios(), android: Lucide::Bell->android());
        $unread = app(Account::class)->unreadNotifications();

        if ($unread > 0) {
            $notifications->badge($unread > 99 ? '99+' : (string) $unread);
        }

        return TabBar::make()
            ->activeColor(self::accent())
            ->labelVisibility('labeled')
            ->add(Tab::link('Log', '/log', ios: Lucide::GitCommitVertical->ios(), android: Lucide::GitCommitVertical->android()))
            ->add($notifications)
            ->add(Tab::link('Saved', '/saved', ios: Lucide::Bookmark->ios(), android: Lucide::Bookmark->android()))
            ->add(Tab::link('Profile', '/profile', ios: Lucide::UserRound->ios(), android: Lucide::UserRound->android()));
    }

    /**
     * Your lane's colour, in its light or dark lift, or your custom colour,
     * or Commit Blue before devnull has said who you are.
     */
    public static function accent(): string
    {
        $primary = (string) theme('primary', '#3b6fe0');

        return (string) theme(Lane::token(app(Account::class)->user()?->lane()), $primary);
    }
}
