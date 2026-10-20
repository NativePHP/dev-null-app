<?php

namespace App\NativeComponents;

use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Facades\Browser;

/**
 * A dev-null.sh link that the phone opened in the app instead of the browser.
 *
 * iOS and Android hand the app the link's path, which is the web's, like
 * /@nunomaduro/commits/{id}. A screen in a native tab has to sit under that
 * tab's path, so the app's own are /log/commits/{id} and the like (see
 * Concerns\OpensInTab). This screen swaps the one for the other, in the tab
 * you were in, without drawing a frame of its own.
 *
 * A link that starts the app opens at this screen with nothing below it. It
 * goes through Launch, which checks the sign-in as usual, and the log opens
 * the link once it has drawn, so back goes to the log.
 *
 * Android can only claim paths by prefix, so a profile's og.png or feed comes
 * here too. Those open in the in-app browser.
 */
class WebLink extends NativeComponent
{
    /**
     * The web's paths the app has a screen for, and that screen's path under a tab.
     */
    private const array Screens = [
        '#^/@([^/]+)$#' => '/users/$1',
        '#^/@([^/]+)/(followers|following)$#' => '/users/$1/$2',
        '#^/@[^/]+/commits/([^/]+)(?:/photos/[^/]+)?$#' => '/commits/$1',
        '#^/remotes/@([^/]+)$#' => '/conversations/$1',
    ];

    public function mount(): void
    {
        $uri = (string) $this->nativeRouter?->currentUri();
        $screen = self::screen((string) parse_url($uri, PHP_URL_PATH));

        if ($screen === null) {
            Browser::inApp(rtrim((string) config('devnull.url'), '/').$uri);
        }

        if ($this->nativeRouter?->isRootScreen() ?? true) {
            $this->leave(NavigationIntent::REPLACE, '/', $screen === null ? [] : ['link' => TabsLayout::Tabs[0].$screen]);

            return;
        }

        $tab = $this->tabBelow();

        if ($screen === null || $tab === null) {
            $this->leave(NavigationIntent::BACK);

            return;
        }

        $this->leave(NavigationIntent::REPLACE, $tab.$screen);
    }

    /**
     * Never drawn (see leave()).
     */
    public function render(): View
    {
        return view('native.launch');
    }

    private static function screen(string $path): ?string
    {
        foreach (self::Screens as $pattern => $screen) {
            if (preg_match($pattern, $path) === 1) {
                return (string) preg_replace($pattern, $screen, $path);
            }
        }

        return null;
    }

    /**
     * The tab of the screen the link was pushed over. Onboarding has none, and
     * there's nothing a signed-out phone can open.
     */
    private function tabBelow(): ?string
    {
        $entries = $this->nativeRouter?->getStackEntries() ?? [];
        $below = (string) ($entries[count($entries) - 2]['uri'] ?? '');
        $tab = '/'.strtok(ltrim($below, '/'), '/?');

        return in_array($tab, TabsLayout::Tabs, true) ? $tab : null;
    }

    /**
     * replace() and back() draw this screen once on the way out. Over the tabs
     * that frame would swap them for a blank screen for a moment, so this sets
     * the intent alone, as NativePHP does for the link itself.
     *
     * @param  array<string, mixed>  $data
     */
    private function leave(string $type, ?string $uri = null, array $data = []): void
    {
        $this->nativeNavigationIntent = new NavigationIntent($type, $uri, $data);
    }
}
