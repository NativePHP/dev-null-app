<?php

namespace App\Devnull\Data;

/**
 * A URL in a commit's prose, shown as the web's LinkifiedText shows it: a commit
 * on dev/null as its author and short hash, a GitHub repository, issue or pull
 * request as GitHub writes it, and anything else shortened the way X does.
 *
 * The rules are the web's, from linkified-text.tsx and app/Services/Urls.php.
 * A commit opens in the app, and so does any other page on dev/null the app
 * has a screen for (see SiteLink). Everything else opens in the browser.
 */
final readonly class BodyLink
{
    /**
     * Top-level domains a bare domain like "nativephp.com" may end with. It's
     * an allowlist because most file extensions are real TLDs too, so the ones
     * that read like code or files (sh, py, rs, md, ...) are left out.
     */
    private const array TopLevelDomains = [
        'com', 'org', 'net', 'edu', 'gov', 'mil', 'info', 'biz', 'io', 'dev', 'app', 'co', 'ai', 'me', 'xyz', 'tech',
        'gg', 'tv', 'fm', 'ly', 'to', 'is', 'blog', 'cloud', 'site', 'online', 'design', 'studio', 'tools', 'software',
        'codes', 'wiki', 'news', 'website', 'club', 'ninja', 'rocks', 'academy', 'guide', 'social', 'digital', 'agency',
        'fyi', 'lol', 'live',
        'uk', 'de', 'fr', 'nl', 'es', 'it', 'pt', 'br', 'ca', 'au', 'jp', 'ch', 'se', 'no', 'dk', 'fi', 'be', 'at', 'ie',
        'nz', 'us', 'eu', 'cz', 'sk', 'hu', 'ro', 'bg', 'gr', 'hr', 'si', 'ee', 'lv', 'lt', 'ru', 'ua', 'tr', 'il', 'za',
        'mx', 'ar', 'kr', 'cn', 'tw', 'hk', 'sg', 'vn', 'th',
    ];

    /**
     * First path segments that are GitHub's own pages rather than an owner.
     */
    private const array GitHubPages = [
        'about', 'apps', 'codespaces', 'collections', 'customer-stories', 'enterprise', 'events', 'explore', 'features',
        'issues', 'login', 'marketplace', 'new', 'notifications', 'orgs', 'organizations', 'pricing', 'pulls', 'search',
        'security', 'settings', 'site', 'sponsors', 'topics', 'trending', 'users',
    ];

    /**
     * A commit on dev/null with nothing after its id.
     */
    private const string CommitLink = '/^https?:\/\/(?:www\.)?dev-null\.sh\/@([A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38})\/commits\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i';

    /**
     * A GitHub repository, issue or pull request with nothing after its name or number.
     */
    private const string GitHubLink = '/^https?:\/\/(?:www\.)?github\.com\/([A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38})\/([A-Za-z0-9._-]{1,100})(?:\/(issues|pull)\/([1-9][0-9]{0,9}))?$/i';

    public const int MaxLength = 2048;

    private const int MaxShortenedPathLength = 20;

    /**
     * @param  string  $href  Where it goes: the URL, with https:// before a bare domain.
     * @param  string  $shown  What it shows.
     * @param  string  $prefix  What it shows muted before that: a commit's author or a repository's owner, and "/".
     * @param  string|null  $commit  The id of the dev/null commit it opens in the app.
     */
    private function __construct(public string $href, public string $shown, public string $prefix = '', public ?string $commit = null) {}

    /**
     * Every link in some prose, in order, with where it starts and how long it
     * is as written, in code points. Punctuation that ends the sentence rather
     * than the URL stays prose.
     *
     * @return list<array{0: int, 1: int, 2: self}>
     */
    public static function in(string $prose): array
    {
        preg_match_all(self::pattern(), $prose, $matches, PREG_OFFSET_CAPTURE);

        $links = [];

        foreach ($matches[0] as [$candidate, $byte]) {
            $written = self::withoutTrailingPunctuation($candidate);
            $href = preg_match('/^(https?)(:\/\/.*)$/is', $written, $scheme) === 1 ? strtolower($scheme[1]).$scheme[2] : 'https://'.$written;

            if (str_ends_with($href, '://') || mb_strlen($href) > self::MaxLength) {
                continue;
            }

            $links[] = [mb_strlen(substr($prose, 0, $byte)), mb_strlen($written), self::to($written, $href)];
        }

        return $links;
    }

    public static function hasOversizedUrl(string $prose): bool
    {
        preg_match_all(self::pattern(), $prose, $matches);

        foreach ($matches[0] as $candidate) {
            $written = self::withoutTrailingPunctuation($candidate);
            $url = preg_match('/^https?:\/\//i', $written) === 1 ? $written : 'https://'.$written;

            if (mb_strlen($url) > self::MaxLength) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether it shows as a name and a short hash or number rather than as a URL.
     */
    public function isNamed(): bool
    {
        return $this->prefix !== '';
    }

    private static function to(string $written, string $href): self
    {
        if (preg_match(self::CommitLink, $written, $commit) === 1) {
            $id = strtolower($commit[2]);

            return new self($href, substr(str_replace('-', '', $id), -7), $commit[1].'/', $id);
        }

        if (preg_match(self::GitHubLink, $href, $gitHub) === 1 && ! in_array(strtolower($gitHub[1]), self::GitHubPages, true)) {
            return new self($href, $gitHub[2].(isset($gitHub[4]) ? '#'.$gitHub[4] : ''), $gitHub[1].'/');
        }

        return new self($href, self::shorten($written));
    }

    /**
     * Like X: without its scheme or "www.", with the whole host, and at most 20
     * characters after it, the last an ellipsis when it's cut. A cut never splits
     * a percent-encoded character.
     */
    private static function shorten(string $url): string
    {
        $url = (string) preg_replace('/^(?:https?:\/\/)?(?:www\.(?=[^\/?#]*\.))?/i', '', $url);
        $host = (string) preg_replace('/[\/?#].*$/su', '', $url);
        $path = mb_substr($url, mb_strlen($host));

        if (mb_strlen($path) <= self::MaxShortenedPathLength) {
            return $url;
        }

        $kept = (string) preg_replace('/%[0-9A-Fa-f]?$/', '', mb_substr($path, 0, self::MaxShortenedPathLength - 1));

        return $host.$kept.'…';
    }

    /**
     * A URL with a scheme, or a bare domain: ASCII labels ending in an allowed
     * TLD, then maybe a path, query or fragment. A bare domain can't follow a
     * letter, digit, "_", ".", "/", "@" or "-", its TLD is all lowercase or all
     * uppercase, and it ends the word without a "(" or another label after it.
     */
    private static function pattern(): string
    {
        $topLevelDomains = implode('|', [...self::TopLevelDomains, ...array_map(strtoupper(...), self::TopLevelDomains)]);

        return '/(?<![\p{L}\p{N}\p{M}\p{Pc}])[Hh][Tt][Tt][Pp][Ss]?:\/\/[^\s<>]+'
            .'|(?<![\p{L}\p{N}_.\/@-])(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+(?:'.$topLevelDomains.')'
            .'(?![\p{L}\p{N}_(-]|\.[A-Za-z0-9])(?:[\/?#][^\s<>]*)?/u';
    }

    /**
     * Strips punctuation that ends a sentence rather than the URL, keeping
     * closing brackets that pair with one inside it.
     */
    private static function withoutTrailingPunctuation(string $url): string
    {
        do {
            $previous = $url;
            $url = mb_rtrim($url, '.,;:!?\'"…”’');

            foreach (['(' => ')', '[' => ']'] as $open => $close) {
                if (str_ends_with($url, $close) && mb_substr_count($url, $open) < mb_substr_count($url, $close)) {
                    $url = mb_substr($url, 0, -1);
                }
            }
        } while ($url !== $previous);

        return $url;
    }
}
