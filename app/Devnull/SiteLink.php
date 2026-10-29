<?php

namespace App\Devnull;

use App\NativeComponents\Connections;

/**
 * A link to a page on dev/null as the app's own screen for it, so a tap on
 * one opens that screen rather than the page in the browser.
 *
 * The pages are the web's (routes/web.php), and each screen is the app's
 * path beneath a tab, without the tab (see Concerns\OpensInTab). A page the
 * app has no screen for, like a profile's og.png or feed, the settings or
 * the Remotes inbox, has none here, so it still opens in the browser.
 */
final class SiteLink
{
    /**
     * The web's pages the app has a screen for, and that screen. {user} is a
     * GitHub username, {id} a commit's or an org's id, and {photo} anything.
     */
    private const array Screens = [
        '/@{user}' => '/users/{user}',
        '/@{user}/{connections}' => '/users/{user}/{connections}',
        '/@{user}/commits/{id}' => '/commits/{id}',
        '/@{user}/commits/{id}/photos/{photo}' => '/commits/{id}',
        '/remotes/@{user}' => '/conversations/{user}',
        '/remotes/orgs/{id}' => '/orgs/{id}',
    ];

    /**
     * Where dev/null's pages are, besides the host the app talks to.
     */
    private const array Hosts = ['dev-null.sh', 'www.dev-null.sh'];

    /**
     * A GitHub username, as BodyLink matches one.
     */
    private const string Username = '[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}';

    /**
     * A UUID in either case.
     */
    private const string Id = '[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}';

    /**
     * The app's path beneath a tab for the page at $url, like /users/ada for
     * https://dev-null.sh/@ada, or null when it isn't a page on dev/null the
     * app has a screen for. The query and fragment don't matter, and one
     * slash after the path is fine.
     */
    public static function screen(?string $url): ?string
    {
        $path = self::pathOnDevnull($url);

        if ($path === null) {
            return null;
        }

        foreach (self::Screens as $page => $screen) {
            if (preg_match(self::pattern($page), $path, $matches) === 1) {
                return strtr($screen, [
                    '{user}' => $matches['user'] ?? '',
                    '{connections}' => $matches['connections'] ?? '',
                    '{id}' => strtolower($matches['id'] ?? ''),
                ]);
            }
        }

        return null;
    }

    /**
     * Whether a URL from devnull is a web page the browser may open: http or
     * https, with a host, and no user or password before it. Nothing else a
     * link might carry, like javascript:, file: or another app's scheme, can
     * take the phone somewhere unexpected, and the in-app browser only opens
     * web pages anyway.
     *
     * @phpstan-assert-if-true string $url
     */
    public static function isWebUrl(?string $url): bool
    {
        $parts = $url === null ? false : parse_url($url);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ($parts['host'] ?? '') !== '';
    }

    /**
     * The path of an http or https URL on one of dev/null's hosts. A URL with
     * a user or password before its host is never one, as browsers and PHP
     * can disagree on where its host starts.
     */
    private static function pathOnDevnull(?string $url): ?string
    {
        if (! self::isWebUrl($url)) {
            return null;
        }

        $parts = (array) parse_url((string) $url);
        $hosts = [...self::Hosts, strtolower((string) parse_url((string) config('devnull.url'), PHP_URL_HOST))];

        if (! in_array(strtolower($parts['host'] ?? ''), $hosts, true)) {
            return null;
        }

        return $parts['path'] ?? '';
    }

    /**
     * The pattern for one of the pages in Screens, with or without a slash after it.
     */
    private static function pattern(string $page): string
    {
        return '#^'.strtr($page, [
            '{user}' => '(?<user>'.self::Username.')',
            '{connections}' => '(?<connections>'.implode('|', Connections::Types).')',
            '{id}' => '(?<id>'.self::Id.')',
            '{photo}' => '[^/]+',
        ]).'/?\z#';
    }
}
