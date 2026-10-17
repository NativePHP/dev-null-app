<?php

namespace App\Devnull;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * devnull's answers to signed GETs, kept on the phone so a screen can show what
 * it showed last time straight away and refresh behind it.
 *
 * Only first pages are kept, per signed-in user, as the decoded JSON devnull
 * sent: never a cursor page, never the answer to a write, and nothing secret.
 * An answer shows for up to seven days after it was fetched, and anything older
 * counts as missing. The app's own writes patch every copy they change, so going
 * back to a screen shows them at once. Signing out empties the lot (see Account).
 *
 * The store lives under storage/app, which survives launches and app updates.
 */
final class ResponseCache
{
    public const string Store = 'responses';

    public const int ShownForDays = 7;

    /**
     * How many answers are kept per user. The ones touched longest ago go first.
     */
    public const int MaxEntries = 100;

    /**
     * The GETs worth keeping, by path. Anything else is never stored.
     */
    private const array Cacheable = [
        '#^me$#',
        '#^notifications$#',
        '#^notifications/[^/]+/group$#',
        '#^log/[^/]+$#',
        '#^commits/[^/]+$#',
        '#^commits/[^/]+/replies$#',
        '#^users/[^/]+$#',
        '#^users/[^/]+/commits$#',
        '#^saved$#',
    ];

    /**
     * Query parameters that only ask devnull what changed since what's showing.
     * They don't change the page, so an answer is kept and found without them.
     */
    private const array Transient = ['since'];

    /**
     * What a log answers only for ?since=, which is about that moment and not worth keeping.
     */
    private const array TransientLogKeys = ['latest_commits', 'new_commits_count'];

    /**
     * Stands in for the cursor of a reply preview the app put a reply on top of.
     */
    private const string EarlierReplies = 'earlier';

    /**
     * How many of the commits deleted on this phone it remembers.
     */
    private const int RememberedDeletions = 50;

    /**
     * How many of the commits unsaved on this phone it remembers.
     */
    private const int RememberedUnsaves = 50;

    /**
     * What every copy of a user has of their profile, as a commit's author, a
     * notification's actor or a profile's header.
     */
    private const array ProfileFields = ['username', 'name', 'avatar_url', 'bio', 'company', 'location', 'website', 'github_url', 'html_url', 'feed_url', 'lane'];

    /**
     * Whether a GET of $path with $query is kept: a known path, and a first page.
     *
     * @param  array<string, string|null>  $query
     */
    public static function caches(string $path, array $query = []): bool
    {
        if (array_key_exists('cursor', self::normalise($query))) {
            return false;
        }

        foreach (self::Cacheable as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The key an answer is kept under: the user it was fetched for, the method, the path and the query.
     *
     * @param  array<string, string|null>  $query
     */
    public static function key(string $userId, string $path, array $query = []): string
    {
        $query = self::normalise($query);

        return "{$userId} GET /{$path}".($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * The JSON devnull last sent for this GET, or null when there's none for
     * the signed-in user, or it's more than a week old.
     *
     * @param  array<string, string|null>  $query
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query = []): ?array
    {
        return $this->entry($path, $query)['json'] ?? null;
    }

    /**
     * Keeps what devnull just sent for this GET.
     *
     * @param  array<string, string|null>  $query
     * @param  array<string, mixed>  $json
     */
    public function put(string $path, array $query, array $json): void
    {
        $userId = $this->userId();

        if ($userId === null || ! self::caches($path, $query)) {
            return;
        }

        $json = match (true) {
            $path === 'notifications' => self::read($json),
            str_starts_with($path, 'log/') => array_diff_key($json, array_flip(self::TransientLogKeys)),
            default => $json,
        };

        $this->write($userId, $path, $query, $json, CarbonImmutable::now()->getTimestamp());
    }

    /**
     * Changes a kept answer in place, keeping when it was fetched.
     *
     * @param  array<string, string|null>  $query
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    public function patch(string $path, array $query, Closure $change): void
    {
        $userId = $this->userId();
        $entry = $this->entry($path, $query);

        if ($userId === null || $entry === null) {
            return;
        }

        $this->write($userId, $path, $query, $change($entry['json']), $entry['fetched_at']);
    }

    /**
     * Changes every kept copy of a commit, wherever it appears: in a log, a
     * profile, a thread, as a parent or a pinned commit. Returning null takes
     * it out of the lists it's in.
     *
     * @param  Closure(array<string, mixed>): ?array<string, mixed>  $change
     */
    public function patchCommit(string $id, Closure $change): void
    {
        $userId = $this->userId();

        if ($userId === null) {
            return;
        }

        foreach ($this->index($userId) as $meta) {
            if (in_array($id, $meta['commits'], true)) {
                $this->patch($meta['path'], $meta['query'], fn (array $json): array => self::changeCommit($json, $id, $change));
            }
        }
    }

    /**
     * @param  array<string, string|null>  $query
     */
    public function forget(string $path, array $query = []): void
    {
        $userId = $this->userId();

        if ($userId === null) {
            return;
        }

        $key = self::key($userId, $path, $query);
        $index = $this->index($userId);
        unset($index[$key]);

        $this->store()->forget($key);
        $this->store()->forever($this->indexKey($userId), $index);
    }

    /**
     * Empties the cache for everyone who has signed in on this phone.
     */
    public function flush(): void
    {
        $this->store()->flush();
    }

    // ── The app's own writes ──────────────────────

    /**
     * Your star or bookmark changed: every copy of the commit takes the counts devnull sent back.
     *
     * @param  array<string, mixed>  $commit
     */
    public function commitReacted(array $commit): void
    {
        $id = (string) ($commit['id'] ?? '');
        $reactions = array_intersect_key($commit, array_flip(['starred', 'stars_count', 'is_saved']));

        if ($id === '' || $reactions === []) {
            return;
        }

        $this->patchCommit($id, fn (array $kept): array => [...$kept, ...$reactions]);
    }

    /**
     * You saved or unsaved a commit. A commit you saved is the last you saved,
     * so it goes on top of your saved commits; one you unsaved leaves them,
     * and is remembered for the pages of them loaded past the first.
     *
     * @param  array<string, mixed>  $commit
     */
    public function commitSaved(array $commit): void
    {
        $id = (string) ($commit['id'] ?? '');
        $userId = $this->userId();

        if ($id === '' || $userId === null || ! is_bool($commit['is_saved'] ?? null)) {
            return;
        }

        $this->patch('saved', [], fn (array $json): array => $commit['is_saved']
            ? self::prepend($json, $commit)
            : [...$json, 'data' => self::without($json['data'] ?? [], $id)]);

        $unsaved = array_diff($this->unsavedIds(), [$id]);
        $this->store()->forever("{$userId} unsaved", array_slice($commit['is_saved'] ? $unsaved : [$id, ...$unsaved], 0, self::RememberedUnsaves));
    }

    /**
     * Commits unsaved on this phone lately, and not saved again since, for a
     * screen showing more of your saved commits than the first page kept.
     *
     * @return list<string>
     */
    public function unsavedIds(): array
    {
        $userId = $this->userId();
        $ids = $userId === null ? null : $this->store()->get("{$userId} unsaved");

        return is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }

    /**
     * You committed. A commit goes on top of your logs and your profile; a reply
     * goes under its parent and counts there, and on top of your replies.
     *
     * @param  array<string, mixed>  $commit
     */
    public function commitAdded(array $commit): void
    {
        $me = $this->username();

        if (($commit['id'] ?? '') === '' || $me === null) {
            return;
        }

        $parentId = is_string($commit['parent_id'] ?? null) ? $commit['parent_id'] : null;

        if ($parentId === null) {
            // Trending is ranked, so a new commit has no place at the top of it.
            foreach (['log/following', 'log/latest', "users/{$me}/commits"] as $path) {
                $this->patch($path, [], fn (array $json): array => self::prepend($json, $commit));
            }

            $this->patch("users/{$me}", [], fn (array $json): array => self::count($json, 'commits_count', 1));

            return;
        }

        // A reply belongs after the last one, so it's only added when that's on the kept page.
        $this->patch("commits/{$parentId}/replies", [], fn (array $json): array => ($json['meta']['next_cursor'] ?? null) === null
            ? [...$json, 'data' => [...self::without($json['data'] ?? [], $commit['id']), $commit]]
            : $json);
        $this->patch("users/{$me}/commits", ['replies' => '1'], fn (array $json): array => self::prepend($json, $commit));
        $this->patchCommit($parentId, fn (array $parent): array => self::withNewestReply([...$parent, 'replies_count' => (int) ($parent['replies_count'] ?? 0) + 1], $commit));
    }

    /**
     * A list shows a commit with its newest reply, which yours now is. The
     * replies before it are behind "Show more", which only asks whether a
     * cursor is there, as the app can't page them newest first anyway.
     *
     * @param  array<string, mixed>  $parent
     * @param  array<string, mixed>  $reply
     * @return array<string, mixed>
     */
    private static function withNewestReply(array $parent, array $reply): array
    {
        if (! is_array($parent['reply_preview'] ?? null)) {
            return $parent;
        }

        $earlier = ($parent['reply_preview']['data'] ?? []) !== [] || ($parent['reply_preview']['next_cursor'] ?? null) !== null;

        return [...$parent, 'reply_preview' => ['data' => [$reply], 'next_cursor' => $earlier ? self::EarlierReplies : null]];
    }

    /**
     * You deleted a commit: it leaves every list, its page goes, and its parent counts one reply fewer.
     */
    public function commitDeleted(string $id): void
    {
        $parentId = $this->findCommit($id)['parent_id'] ?? null;

        $this->rememberDeletion($id);

        $this->patchCommit($id, fn (array $commit): ?array => null);
        $this->forget("commits/{$id}");
        $this->forget("commits/{$id}/replies");

        if (is_string($parentId) && $parentId !== '') {
            $this->patchCommit($parentId, fn (array $parent): array => [...$parent, 'replies_count' => max(0, (int) ($parent['replies_count'] ?? 0) - 1)]);
        } elseif (($me = $this->username()) !== null) {
            $this->patch("users/{$me}", [], fn (array $json): array => self::count($json, 'commits_count', -1));
        }
    }

    /**
     * You followed or unfollowed someone: their profile and your following count change.
     */
    public function followChanged(string $username, bool $following): void
    {
        $changed = false;

        $this->patch("users/{$username}", [], function (array $json) use ($following, &$changed): array {
            $changed = ($json['is_following'] ?? false) !== $following;

            return $changed ? [...self::count($json, 'followers_count', $following ? 1 : -1), 'is_following' => $following] : $json;
        });

        if ($changed && ($me = $this->username()) !== null) {
            $this->patch("users/{$me}", [], fn (array $json): array => self::count($json, 'following_count', $following ? 1 : -1));
        }
    }

    /**
     * Commits deleted on this phone lately, for a ranked log, which can't tell
     * a commit that went from one that only fell down the ranking.
     *
     * @return list<string>
     */
    public function deletedIds(): array
    {
        $userId = $this->userId();
        $ids = $userId === null ? null : $this->store()->get("{$userId} deleted");

        return is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }

    private function rememberDeletion(string $id): void
    {
        $userId = $this->userId();

        if ($userId !== null) {
            $this->store()->forever("{$userId} deleted", array_slice([$id, ...array_diff($this->deletedIds(), [$id])], 0, self::RememberedDeletions));
        }
    }

    public function appleLinkChanged(bool $linked): void
    {
        $this->patch('me', [], fn (array $json): array => [...$json, 'apple_linked' => $linked]);
    }

    /**
     * devnull sent you afresh, after a sync from GitHub or a new colour, as
     * `GET /me` sends you. The kept `me` takes all of it and keeps whether
     * Apple is linked. When your profile changed, every other kept copy of you
     * takes it too: your profile's header, and you as the author of a commit or
     * the actor of a notification. Only what a copy already has changes.
     *
     * @param  array<string, mixed>  $json
     */
    public function meChanged(array $json): void
    {
        $userId = $this->userId();
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        if ($userId === null || ($data['id'] ?? null) !== $userId) {
            return;
        }

        $profile = array_intersect_key($data, array_flip(self::ProfileFields));
        $before = $this->get('me');

        $this->patch('me', [], fn (array $kept): array => [...$kept, ...$json]);

        // Reading every answer kept is only worth it when there's something to change in them.
        if (is_array($before['data'] ?? null) && array_intersect_key($before['data'], $profile) == $profile) {
            return;
        }

        foreach ($this->index($userId) as $meta) {
            $entry = $meta['path'] === 'me' ? null : $this->entry($meta['path'], $meta['query']);

            if ($entry === null) {
                continue;
            }

            $changed = self::changeUser($entry['json'], $userId, $profile);

            if ($changed !== $entry['json']) {
                $this->write($userId, $meta['path'], $meta['query'], $changed, $entry['fetched_at']);
            }
        }
    }

    // ── Storage ───────────────────────────────────

    /**
     * @param  array<string, string|null>  $query
     * @return array{fetched_at: int, json: array<string, mixed>}|null
     */
    private function entry(string $path, array $query): ?array
    {
        $userId = $this->userId();

        if ($userId === null || ! self::caches($path, $query)) {
            return null;
        }

        $entry = $this->store()->get(self::key($userId, $path, $query));

        if (! is_array($entry) || ! is_int($entry['fetched_at'] ?? null) || ! is_array($entry['json'] ?? null)) {
            return null;
        }

        if (self::expired($entry['fetched_at'])) {
            return null;
        }

        return $entry;
    }

    /**
     * @param  array<string, string|null>  $query
     * @param  array<string, mixed>  $json
     */
    private function write(string $userId, string $path, array $query, array $json, int $fetchedAt): void
    {
        $key = self::key($userId, $path, $query);
        $index = $this->index($userId);
        unset($index[$key]);

        if (self::expired($fetchedAt)) {
            $this->store()->forget($key);
            $this->store()->forever($this->indexKey($userId), $index);

            return;
        }

        // The store lets it go a day after it stops showing, so the check above is the one that counts.
        $this->store()->put($key, ['fetched_at' => $fetchedAt, 'json' => $json], CarbonImmutable::createFromTimestamp($fetchedAt)->addDays(self::ShownForDays + 1));

        $index[$key] = ['path' => $path, 'query' => self::normalise($query), 'commits' => self::commitIds($json)];

        while (count($index) > self::MaxEntries) {
            $oldest = (string) array_key_first($index);
            $this->store()->forget($oldest);
            unset($index[$oldest]);
        }

        $this->store()->forever($this->indexKey($userId), $index);
    }

    /**
     * What's kept for a user, oldest touched first, with the commits each answer holds.
     *
     * @return array<string, array{path: string, query: array<string, string>, commits: list<string>}>
     */
    private function index(string $userId): array
    {
        $index = $this->store()->get($this->indexKey($userId));

        return is_array($index) ? $index : [];
    }

    private function indexKey(string $userId): string
    {
        return "{$userId} index";
    }

    /**
     * Any kept copy of a commit.
     *
     * @return array<string, mixed>|null
     */
    private function findCommit(string $id): ?array
    {
        $userId = $this->userId();

        foreach ($userId === null ? [] : $this->index($userId) as $meta) {
            if (! in_array($id, $meta['commits'], true)) {
                continue;
            }

            $found = null;
            self::changeCommit($this->get($meta['path'], $meta['query']) ?? [], $id, function (array $commit) use (&$found): array {
                $found = $commit;

                return $commit;
            });

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function expired(int $fetchedAt): bool
    {
        return CarbonImmutable::now()->getTimestamp() - $fetchedAt > self::ShownForDays * 86400;
    }

    /**
     * @param  array<string, string|null>  $query
     * @return array<string, string>
     */
    private static function normalise(array $query): array
    {
        $query = array_filter(array_diff_key($query, array_flip(self::Transient)), fn (?string $value): bool => $value !== null && $value !== '');
        ksort($query);

        return $query;
    }

    private function store(): Repository
    {
        return Cache::store(self::Store);
    }

    private function userId(): ?string
    {
        $id = app(Account::class)->user()?->id;

        return $id === null || $id === '' ? null : $id;
    }

    private function username(): ?string
    {
        $username = app(Account::class)->user()?->username;

        return $username === null || $username === '' ? null : rawurlencode($username);
    }

    // ── JSON ──────────────────────────────────────

    /**
     * Whether a JSON object is a commit, or the tombstone of one: users and notifications have no body.
     *
     * @param  array<array-key, mixed>  $node
     */
    private static function isCommit(array $node): bool
    {
        return isset($node['id']) && (array_key_exists('body', $node) || array_key_exists('deleted_at', $node));
    }

    /**
     * @return list<string>
     */
    private static function commitIds(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $ids = self::isCommit($node) ? [(string) $node['id']] : [];

        foreach ($node as $child) {
            $ids = [...$ids, ...self::commitIds($child)];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  Closure(array<string, mixed>): ?array<string, mixed>  $change
     */
    private static function changeCommit(mixed $node, string $id, Closure $change): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $isList = array_is_list($node);
        $changed = [];

        foreach ($node as $key => $child) {
            $child = self::changeCommit($child, $id, $change);

            if (is_array($child) && self::isCommit($child) && (string) $child['id'] === $id) {
                $original = $child;
                $child = $change($child);

                if ($child === null && $isList) {
                    continue;
                }

                // A quote keeps its place for the commit it quoted, which says it was deleted.
                if ($child === null && $key === 'quoted_commit') {
                    $child = ['id' => $original['id'], 'parent_id' => $original['parent_id'] ?? null, 'deleted_at' => CarbonImmutable::now()->toIso8601ZuluString()];
                }
            }

            $changed[$key] = $child;
        }

        return $isList ? array_values($changed) : $changed;
    }

    /**
     * Gives every copy of the user with this id, anywhere in $node, the profile
     * fields it has. Commits have no username, so they never match.
     *
     * @param  array<string, mixed>  $profile
     */
    private static function changeUser(mixed $node, string $id, array $profile): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (($node['id'] ?? null) === $id && array_key_exists('username', $node)) {
            $node = [...$node, ...array_intersect_key($profile, $node)];
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $node[$key] = self::changeUser($child, $id, $profile);
            }
        }

        return $node;
    }

    /**
     * devnull marks notifications read as it sends them, and you've seen them
     * by the time they're shown again, so they're kept as read, and without
     * the moment they were marked, so nothing kept shows as new.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private static function read(array $json): array
    {
        unset($json['read_at']);

        if (! is_array($json['data'] ?? null)) {
            return $json;
        }

        $json['data'] = array_map(fn (mixed $group): mixed => is_array($group)
            ? [...$group, 'unread_count' => 0, 'read_at' => $group['read_at'] ?? CarbonImmutable::now()->toIso8601ZuluString()]
            : $group, $json['data']);

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $commit
     * @return array<string, mixed>
     */
    private static function prepend(array $json, array $commit): array
    {
        return [...$json, 'data' => [$commit, ...self::without($json['data'] ?? [], (string) $commit['id'])]];
    }

    /**
     * @return list<mixed>
     */
    private static function without(mixed $items, string $id): array
    {
        return array_values(array_filter(is_array($items) ? $items : [], fn (mixed $item): bool => ! is_array($item) || (string) ($item['id'] ?? '') !== $id));
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private static function count(array $json, string $count, int $change): array
    {
        if (! is_array($json['data'] ?? null) || ! is_int($json['data'][$count] ?? null)) {
            return $json;
        }

        $json['data'][$count] = max(0, $json['data'][$count] + $change);

        return $json;
    }
}
