<?php

use App\Devnull\Account;
use App\Devnull\AppleSignIn;
use App\Devnull\Data\User;
use App\Devnull\DeviceKey;
use App\Devnull\ResponseCache;
use App\Devnull\SshKey;
use App\NativeComponents\Log;
use App\NativeComponents\Notifications;
use App\NativeComponents\Profile;
use Codingwithrk\NativephpSocialAuth\Events\AppleSignInCompleted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\AsyncTask;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskRunner;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Fakes the devnull API by "METHOD /path", ignoring the query string.
 * Each route answers with [body, status], built fresh for every request.
 * Anything else gets a 404, so a stray request shows up in the test.
 *
 * @param  array<string, array{0: mixed, 1?: int}>  $routes
 */
function fakeApi(array $routes): void
{
    Http::fake(function (Request $request) use ($routes) {
        $route = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

        if (! array_key_exists($route, $routes)) {
            return Http::response(['message' => "No fake for {$route}."], 404);
        }

        return Http::response($routes[$route][0], $routes[$route][1] ?? 200);
    });
}

/**
 * Fakes the devnull API like fakeApi(), but a later call swaps what it
 * answers, as devnull moving on between two refreshes. A closure answers
 * every request itself.
 *
 * @param  array<string, array{0: mixed, 1?: int}>|Closure(Request): mixed  $routes
 */
function serveApi(array|Closure $routes): void
{
    $installed = app()->bound('test.api');
    app()->instance('test.api', $routes);

    if ($installed) {
        return;
    }

    Http::fake(function (Request $request) {
        $routes = app('test.api');

        if ($routes instanceof Closure) {
            return $routes($request);
        }

        $route = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

        if (! array_key_exists($route, $routes)) {
            return Http::response(['message' => "No fake for {$route}."], 404);
        }

        return Http::response($routes[$route][0], $routes[$route][1] ?? 200);
    });
}

/**
 * devnull can't be reached: every request fails to connect, as offline.
 */
function fakeOffline(): void
{
    serveApi(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: devnull.test'));
}

/**
 * Keeps an answer in the response cache, as a visit before this one would have.
 *
 * @param  array<string, mixed>  $json
 * @param  array<string, string|null>  $query
 */
function keep(string $path, array $json, array $query = []): void
{
    app(ResponseCache::class)->put($path, $query, $json);
}

/**
 * The answer kept for a GET.
 *
 * @param  array<string, string|null>  $query
 * @return array<string, mixed>|null
 */
function kept(string $path, array $query = []): ?array
{
    return app(ResponseCache::class)->get($path, $query);
}

/**
 * Background refreshes wait, as on a phone, until finishRefreshes() runs them,
 * so a test sees the frame a screen paints before devnull answers.
 */
function holdRefreshes(): FakeBridge
{
    AsyncTask::clearFake();

    return Native::fakeBridge()->respondTo('AsyncTask.Dispatch', ['success' => true]);
}

/**
 * Runs every held background refresh, and any it leads to, and hands each
 * answer to the screen the way the background lane does.
 */
function finishRefreshes(TestableComponent $screen): TestableComponent
{
    do {
        $ran = false;

        foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
            // A screen that has navigated away has stopped listening.
            if ($screen->navigationIntent() !== null) {
                return $screen;
            }

            $id = $call['params']['id'];
            $payload = AsyncTaskTransport::readPayload($id);

            if ($payload === null) {
                continue;
            }

            $ran = true;

            try {
                $result = AsyncTaskRunner::normalizeResult(AsyncTaskRunner::invoke(unserialize($payload)));
                $screen->emitNative(AsyncTaskFinished::class, ['id' => $id, 'result' => $result]);
            } catch (Throwable $exception) {
                $screen->emitNative(AsyncTaskFailed::class, ['id' => $id, 'exceptionClass' => $exception::class, 'message' => $exception->getMessage()]);
            }
        }
    } while ($ran);

    return $screen;
}

/**
 * How many background refreshes a screen has sent so far, held or not.
 */
function refreshesSent(TestableComponent $screen): int
{
    return count($screen->bridge()->callsTo('AsyncTask.Dispatch'));
}

/**
 * Whether a tree shows a spinner or skeleton rows anywhere.
 *
 * @param  array<string, mixed>  $tree
 */
function showsLoading(array $tree): bool
{
    return findNode($tree, fn (array $node): bool => ($node['type'] ?? null) === 'activity_indicator'
        || str_starts_with((string) ($node['ref'] ?? ''), 'loading-')) !== null;
}

/**
 * The list of the log that's showing in the Log's pager. The pages beside it
 * show the other logs, ready for a swipe.
 *
 * @return array<string, mixed>
 */
function showingLog(TestableComponent $screen): array
{
    return nodeByRef($screen, 'log');
}

/**
 * The list of the notifications filter that's showing in its pager. The pages
 * beside it show the filters either side, ready for a swipe.
 *
 * @return array<string, mixed>
 */
function showingNotifications(TestableComponent $screen): array
{
    return nodeByRef($screen, 'notifications');
}

/**
 * A tap on one of the Log's tabs. The pager draws them and scrolls itself to
 * the log, so PHP hears of it as the pager settling there. A tap on the log
 * you're on comes as a reselect instead.
 */
function tapLogTab(TestableComponent $screen, string $log): TestableComponent
{
    return $screen->changeTab($screen->get('log') === $log ? 'backToTop' : 'showPage', (int) array_search($log, Log::Logs, true));
}

/**
 * The list of the profile tab that's showing in its pager. The other tab's page
 * beside it shows the first few commits kept for it, ready for a swipe.
 *
 * @return array<string, mixed>
 */
function showingProfile(TestableComponent $screen): array
{
    return nodeByRef($screen, 'profile');
}

/**
 * A tap on one of a profile's tabs, which the pager draws, as for the Log's
 * tabs (see tapLogTab).
 */
function tapProfileTab(TestableComponent $screen, string $tab): TestableComponent
{
    return $screen->changeTab($screen->get('tab') === $tab ? 'backToTop' : 'showPage', (int) array_search($tab, Profile::Tabs, true));
}

/**
 * A tap on one of the notifications filters along the top, which the pager
 * draws, as for the Log's tabs (see tapLogTab).
 */
function tapFilter(TestableComponent $screen, string $type): TestableComponent
{
    return $screen->changeTab($screen->get('type') === $type ? 'backToTop' : 'showPage', (int) array_search($type, array_keys(Notifications::Types), true));
}

/**
 * Whether a tree shows some text.
 *
 * @param  array<string, mixed>  $tree
 */
function treeShows(array $tree, string $text): bool
{
    return str_contains(json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $text);
}

/**
 * A Keychain held in memory, answering the SecureStorage bridge calls.
 */
function fakeKeychain(?string $seed = null): FakeBridge
{
    $store = $seed === null ? [] : [DeviceKey::StorageKey => base64_encode($seed)];

    return Native::fakeBridge()
        ->respondTo('SecureStorage.Get', function (array $params) use (&$store): array {
            return array_key_exists($params['key'], $store)
                ? ['status' => 'found', 'value' => $store[$params['key']]]
                : ['status' => 'not_found', 'value' => null];
        })
        ->respondTo('SecureStorage.Set', function (array $params) use (&$store): array {
            $store[$params['key']] = $params['value'];

            return ['success' => true];
        })
        ->respondTo('SecureStorage.Delete', function (array $params) use (&$store): array {
            unset($store[$params['key']]);

            return ['success' => true];
        });
}

/**
 * An identity token as Apple signs one. The app never reads it, only passes it on.
 */
const AppleIdentityToken = 'eyJhbGciOiJSUzI1NiJ9.apple-identity-token.signature';

/**
 * An iPhone with its key in the Keychain, whose Apple sheet opens.
 */
function appleBridge(): FakeBridge
{
    return fakeKeychain(testSeed())
        ->respondTo('NativephpSocialAuth.AppleSignIn', ['pending' => true])
        ->respondTo('Device.GetInfo', ['info' => json_encode(['model' => 'iPhone', 'platform' => 'ios'])]);
}

/**
 * The raw nonce of the Apple sign-in under way, as held in memory.
 */
function heldAppleNonce(): ?string
{
    return (fn () => $this->nonce?->getValue())->call(app(AppleSignIn::class));
}

/**
 * The params of the last call that opened Apple's sheet.
 *
 * @return array<string, mixed>
 */
function appleSheetParams(FakeBridge $bridge): array
{
    $calls = $bridge->callsTo('NativephpSocialAuth.AppleSignIn');

    expect($calls)->not->toBeEmpty("Apple's sheet was never opened.");

    return end($calls)['params'];
}

/**
 * Apple answering, as the plugin sends it: the raw nonce echoed back, and no
 * name or email, since the app asks for neither.
 *
 * @param  array<string, mixed>  $overrides
 */
function appleAnswers(TestableComponent $screen, string $nonce, array $overrides = []): TestableComponent
{
    return $screen->emitNative(AppleSignInCompleted::class, [
        'userId' => '001234.abcdef0123456789.0042',
        'identityToken' => AppleIdentityToken,
        'authorizationCode' => 'c0de.apple',
        'email' => null,
        'givenName' => null,
        'familyName' => null,
        'fullName' => null,
        'nonce' => $nonce,
        'state' => null,
        'isRealUser' => 'likelyReal',
        ...$overrides,
    ]);
}

function testSeed(): string
{
    return implode('', array_map(chr(...), range(0, 31)));
}

function testKey(): SshKey
{
    return SshKey::fromSeed(testSeed());
}

/**
 * A device whose key is in the Keychain and verified as the given user.
 *
 * @param  array<string, mixed>  $user
 */
function signIn(array $user = []): FakeBridge
{
    $bridge = fakeKeychain(testSeed());

    app(Account::class)->remember(User::fromArray(userJson($user)));

    return $bridge;
}

/**
 * A user as the API sends one to a signed request: a commit's author, a
 * notification's actor. Its links follow the username unless a test sends its own.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function userJson(array $overrides = []): array
{
    $username = $overrides['handle'] ?? 'local_dev';

    return [
        'id' => '0199e5c4-0000-7000-8000-000000000001',
        'handle' => $username,
        'name' => 'Local Dev',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4',
        'bio' => 'Ships things.',
        'company' => null,
        'location' => null,
        'website' => null,
        'github_url' => "https://github.com/{$username}",
        'html_url' => "http://devnull.test/@{$username}",
        'feed_url' => "http://devnull.test/@{$username}/feed.atom",
        'created_at' => '2026-10-15T15:43:47Z',
        'lane' => 3,
        ...$overrides,
    ];
}

/**
 * A user with their counts, as `/me`, a profile and a key sync send them.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function userWithCountsJson(array $overrides = [], int $commits = 1, int $followers = 0, int $following = 0): array
{
    $user = userJson($overrides);
    $lane = $user['lane'];
    unset($user['lane']);

    return [...$user, 'commits_count' => $commits, 'followers_count' => $followers, 'following_count' => $following, 'lane' => $lane];
}

/**
 * `GET /me` as devnull answers it: you with your counts, and what only you see
 * in your profile settings. Your lane is the one Automatic gives you, until a
 * test picks one.
 *
 * @param  array<string, mixed>  $overrides
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function currentUserJson(array $overrides = [], array $extra = []): array
{
    return [
        'data' => userWithCountsJson(['preferred_lane' => null, 'automatic_lane' => 3, 'sponsors_url' => null, 'github_synced_at' => null, ...$overrides]),
        'unread_notifications_count' => 0,
        ...$extra,
    ];
}

/**
 * A commit as the API sends it to a signed request. The short hash and the link
 * follow the id and the author, and the body's segments follow the body, unless
 * a test sends its own, as the server always works them out from what it stores.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function commitJson(array $overrides = []): array
{
    $id = $overrides['id'] ?? '0199e5c4-1111-7000-8000-00000000c0de';
    $body = $overrides['body'] ?? 'Shipped the mobile app.';
    $author = $overrides['author'] ?? userJson();

    return [
        'id' => $id,
        'short_hash' => substr(str_replace('-', '', $id), -7),
        'body' => $body,
        'parent_id' => null,
        'html_url' => 'http://devnull.test/@'.($author['handle'] ?? '').'/commits/'.$id,
        'created_at' => now()->subMinutes(5)->toIso8601ZuluString(),
        'stars_count' => 0,
        'replies_count' => 0,
        'link_preview' => null,
        'images' => [],
        'author' => $author,
        'body_segments' => $body === '' ? [] : [['type' => 'text', 'text' => $body]],
        'mentions' => [],
        'status' => 'approved',
        'series_id' => null,
        'series_position' => null,
        'series_size' => null,
        'is_pinned' => false,
        'starred' => false,
        'deleted_at' => null,
        ...$overrides,
    ];
}

/**
 * A `GET /commits/{id}` response: the commit, what it replies to (or the earlier
 * parts of its series) and the later parts of its series.
 *
 * @param  array<string, mixed>  $commit
 * @param  list<array<string, mixed>>  $ancestors
 * @param  list<array<string, mixed>>  $series
 * @return array<string, mixed>
 */
function commitPageJson(array $commit, array $ancestors = [], array $series = []): array
{
    return ['data' => $commit, 'ancestors' => $ancestors, 'series' => $series];
}

/**
 * A reply as `GET /commits/{id}/replies` sends it: like any commit, but without
 * stars_count or starred, which that list doesn't load.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function replyJson(array $overrides = []): array
{
    return array_diff_key(commitJson($overrides), array_flip(['stars_count', 'starred']));
}

/**
 * A notification's commit, as the API sends it: without its counts, preview,
 * images or your star, and with the commit it replies to when you
 * may see it. That parent comes without a parent of its own.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function notificationCommitJson(array $overrides = [], bool $withParent = true): array
{
    $commit = array_diff_key(commitJson($overrides), array_flip(['stars_count', 'replies_count', 'link_preview', 'images', 'starred', 'parent']));

    return $withParent ? [...$commit, 'parent' => $overrides['parent'] ?? null] : $commit;
}

/**
 * A group of notifications as the API sends it: the newest one, the first few
 * people in the group, how many there are and how many are unread. Stars and
 * replies group on your commit (subject_id); everything else stands alone.
 * A moderation update names you as its actor, as devnull stores it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function notificationJson(string $type, array $overrides = []): array
{
    $isModeration = in_array($type, ['approved', 'rejected'], true);
    $actor = $overrides['actor'] ?? ($isModeration ? userJson() : userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 2]));
    $yours = notificationCommitJson(['id' => 'c-yours', 'body' => 'Shipped the app.'], withParent: false);

    $commit = match ($type) {
        'followed' => null,
        'replied' => notificationCommitJson(['id' => 'c-replied', 'parent_id' => 'c-yours', 'body' => 'About replied', 'author' => $actor, 'parent' => $yours]),
        'starred' => notificationCommitJson(['id' => 'c-starred', 'body' => 'About starred']),
        'mentioned' => notificationCommitJson(['id' => 'c-mentioned', 'body' => 'About mentioned', 'author' => $actor]),
        default => notificationCommitJson(['id' => 'c-'.$type, 'body' => 'About '.$type, 'status' => $type]),
    };

    return [
        'id' => 'n-'.$type,
        'type' => $type,
        'subject_id' => match ($type) {
            'replied' => 'c-yours',
            'starred' => 'c-starred',
            default => null,
        },
        'reason' => null,
        'read_at' => null,
        'created_at' => now()->subHours(3)->toIso8601ZuluString(),
        'actor' => $actor,
        'actors' => [$actor],
        'actors_count' => 1,
        'unread_count' => 1,
        'commit' => $commit,
        ...$overrides,
    ];
}

/**
 * A deleted commit that still has replies, as the API sends it: no body, no author.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tombstoneJson(array $overrides = []): array
{
    return [
        'id' => '0199e5c4-2222-7000-8000-0000000dead0',
        'parent_id' => null,
        'deleted_at' => now()->subHour()->toIso8601ZuluString(),
        'replies_count' => 1,
        ...$overrides,
    ];
}

/**
 * One page of a cursor-paginated list, as Laravel sends it. links.next carries
 * the list's own query, such as a profile's ?replies=1, ahead of the cursor.
 *
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function pageJson(array $items, ?string $nextCursor = null, string $path = 'http://devnull.test/api/v1/log/following', string $query = '', int $perPage = 20): array
{
    return [
        'data' => $items,
        'links' => [
            'first' => null,
            'last' => null,
            'prev' => null,
            'next' => $nextCursor === null ? null : $path.'?'.($query === '' ? '' : $query.'&').'cursor='.$nextCursor,
        ],
        'meta' => ['path' => $path, 'per_page' => $perPage, 'next_cursor' => $nextCursor, 'prev_cursor' => null],
    ];
}

/**
 * The runs of the first paragraph whose text contains $text, as [text, style]:
 * 'mention' for a pressable @handle, 'link' for any other pressable run,
 * 'code' for one on a wash, 'wordmark' for one drawn from runs of its own, or
 * nothing for prose. A run drawn from runs of its own reads as their text,
 * without the invisible line-break hints they hold.
 *
 * @return list<array{0: string, 1?: string}>
 */
function paragraphRuns(TestableComponent $screen, string $text): array
{
    $find = function (array $node) use (&$find, $text): ?array {
        if (($node['type'] ?? null) === 'text' && ($node['children'] ?? []) !== []
            && str_contains(implode('', array_map(fn (array $run): string => $run['props']['text'] ?? '', $node['children'])), $text)) {
            return $node;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($found = $find($child)) !== null) {
                return $found;
            }
        }

        return null;
    };

    $paragraph = $find($screen->tree());

    expect($paragraph)->not->toBeNull("No paragraph with [{$text}] was rendered.");

    return array_map(function (array $run): array {
        $text = $run['props']['text'] ?? str_replace(["\u{200B}", "\u{2060}"], '', implode('', array_map(fn (array $part): string => $part['props']['text'], $run['children'] ?? [])));

        return match (true) {
            isset($run['on_press']) => [$text, str_starts_with($text, '@') ? 'mention' : 'link'],
            isset($run['style']['bg_color']) => [$text, 'code'],
            isset($run['children']) => [$text, 'wordmark'],
            default => [$text],
        };
    }, $paragraph['children']);
}

/**
 * The first node in a rendered tree, depth first, that matches.
 *
 * @param  array<string, mixed>  $node
 * @param  callable(array<string, mixed>): bool  $matches
 * @return array<string, mixed>|null
 */
function findNode(array $node, callable $matches): ?array
{
    if ($matches($node)) {
        return $node;
    }

    foreach ($node['children'] ?? [] as $child) {
        if (($found = findNode($child, $matches)) !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * Every node in a rendered tree that matches, in order.
 *
 * @param  array<string, mixed>  $node
 * @param  callable(array<string, mixed>): bool  $matches
 * @return list<array<string, mixed>>
 */
function findNodes(array $node, callable $matches): array
{
    $found = $matches($node) ? [$node] : [];

    foreach ($node['children'] ?? [] as $child) {
        $found = [...$found, ...findNodes($child, $matches)];
    }

    return $found;
}

/**
 * The ids of every node from the root down to the one with the given ref. iOS and Android know a view by its id at
 * each level, so a new id anywhere on the way down builds a new field, and the keyboard goes with the old one.
 *
 * @param  array<string, mixed>  $node
 * @return list<int>|null
 */
function idsDownTo(array $node, string $ref): ?array
{
    if (($node['ref'] ?? null) === $ref) {
        return [$node['id']];
    }

    foreach ($node['children'] ?? [] as $child) {
        if (($ids = idsDownTo($child, $ref)) !== null) {
            return [$node['id'], ...$ids];
        }
    }

    return null;
}

/**
 * The rendered node with the given ref.
 *
 * @return array<string, mixed>
 */
function nodeByRef(TestableComponent $screen, string $ref): array
{
    $node = findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === $ref);

    expect($node)->not->toBeNull("Nothing with ref [{$ref}] was rendered.");

    return $node;
}

/**
 * Whether a round button in a sheet's bar can be tapped. A disabled one is drawn as a plain circle, not a pressable.
 */
function sheetButtonEnabled(TestableComponent $screen, string $ref): bool
{
    return nodeByRef($screen, $ref)['type'] === 'pressable';
}

/**
 * The circle of a round button in a sheet's bar, whether it can be tapped or not.
 *
 * @return array<string, mixed>
 */
function sheetButtonCircle(TestableComponent $screen, string $ref): array
{
    $button = nodeByRef($screen, $ref);

    return $button['type'] === 'pressable' ? $button['children'][0] : $button;
}

/**
 * Every piece of text a screen shows, top to bottom. Button labels are props, not text.
 *
 * @return list<string>
 */
function shownText(TestableComponent $screen): array
{
    return array_map(
        fn (array $node): string => (string) ($node['text'] ?? $node['props']['text'] ?? ''),
        findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'text'),
    );
}

/**
 * The refs of a node's direct children, in order.
 *
 * @return list<string|null>
 */
function childRefs(TestableComponent $screen, string $ref): array
{
    return array_map(fn (array $child): ?string => $child['ref'] ?? null, nodeByRef($screen, $ref)['children'] ?? []);
}

/**
 * The menu a pressable opens, as each item's ref, label and icon, with a divider as 'divider'.
 *
 * @return list<string|array{0: string|null, 1: string|null, 2: string|null}>
 */
function menuItems(TestableComponent $screen, string $ref): array
{
    $items = array_filter(nodeByRef($screen, $ref)['children'] ?? [], fn (array $child): bool => ($child['type'] ?? null) === 'top_bar_action');

    return array_values(array_map(fn (array $item): string|array => ($item['props']['divider'] ?? false) === true
        ? 'divider'
        : [$item['ref'] ?? null, $item['props']['label'] ?? null, $item['props']['icon'] ?? null], $items));
}

/**
 * A commit page with what it folds away opened: the earlier commits above its commit, and the replies under it.
 */
function unfolded(TestableComponent $screen): TestableComponent
{
    return $screen->call('showEarlier')->set('repliesShown', true);
}

/**
 * The refs of everything inside the screen's list or scroll view that draws press-* feedback. EDGE draws it
 * with a zero-distance DragGesture, which on iOS keeps the list from scrolling when a drag starts there.
 *
 * @return list<string>
 */
function pressFeedbackInScroller(TestableComponent $screen): array
{
    $scroller = findNode($screen->tree(), fn (array $node): bool => in_array($node['type'] ?? null, ['list', 'scroll_view'], true));

    expect($scroller)->not->toBeNull('Nothing scrolls on this screen.');

    $pressing = findNodes($scroller, fn (array $node): bool => array_intersect_key($node['props'] ?? [], array_flip(['press-opacity', 'press-scale', 'press-translate-y'])) !== []);

    return array_map(fn (array $node): string => $node['ref'] ?? $node['type'], $pressing);
}

/**
 * What sits on the rail of a commit row: 'avatar' for the author's ringed avatar,
 * 'dot' for a lane dot carrying on their run.
 */
function railNode(TestableComponent $screen, string $ref): ?string
{
    $row = nodeByRef($screen, $ref);
    $stackOfWidth = fn (float $width): Closure => fn (array $node): bool => ($node['type'] ?? null) === 'stack' && ($node['layout']['width'] ?? null) === $width;

    return match (true) {
        findNode($row, $stackOfWidth(48.0)) !== null, findNode($row, $stackOfWidth(36.0)) !== null => 'avatar',
        findNode($row, $stackOfWidth(18.0)) !== null => 'dot',
        default => null,
    };
}

/**
 * The visible text of every day marker, top to bottom, in the log showing when it's the Log.
 *
 * @return list<string>
 */
function dayMarkers(TestableComponent $screen): array
{
    $tree = findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'log') ?? $screen->tree();

    return array_map(function (array $marker): string {
        $text = findNode($marker, fn (array $node): bool => ($node['type'] ?? null) === 'text');

        return $text['text'] ?? $text['props']['text'] ?? '';
    }, findNodes($tree, fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'day-')));
}

/**
 * A photo as the camera or the photo library hands one over: a real image file
 * of the given type and size, in a folder of its own outside the app's storage.
 */
function testPhoto(string $type = 'png', int $width = 40, int $height = 30): string
{
    $directory = sys_get_temp_dir().'/devnull-picked-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $path = "{$directory}/picked.{$type}";

    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 4, 171, 166));

    match ($type) {
        'jpg' => imagejpeg($image, $path),
        'webp' => imagewebp($image, $path),
        'gif' => imagegif($image, $path),
        default => imagepng($image, $path),
    };

    return $path;
}

/**
 * A Remotes message as devnull sends it to someone in the conversation. Its segments follow its body unless a test
 * sends its own.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function remoteMessageJson(array $overrides = []): array
{
    $body = $overrides['body'] ?? 'Shipped it.';

    return [
        'id' => 1,
        'user_id' => 'u2',
        'client_id' => '9f0f6f3e-0000-4000-8000-000000000001',
        'body' => $body,
        'body_segments' => $body === '' ? [] : [['type' => 'text', 'text' => $body]],
        'mentions' => [],
        'reply_to_id' => null,
        'reply_to' => null,
        'link_preview' => null,
        'images' => [],
        'reactions' => [],
        'event' => null,
        'subject_id' => null,
        'created_at' => '2026-10-19T15:00:00Z',
        ...$overrides,
    ];
}

/**
 * A row of the Remotes inbox: a conversation with Ada, unless a test says otherwise.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function conversationJson(array $overrides = []): array
{
    return [
        'id' => 'conv-1',
        'kind' => 'direct',
        'name' => null,
        'partner' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1]),
        'members' => null,
        'last_message' => remoteMessageJson(),
        'last_message_at' => '2026-10-19T15:00:00.000000Z',
        'ahead' => 0,
        'partner_read_through_id' => 0,
        'partner_read_at' => null,
        'is_archived' => false,
        ...$overrides,
    ];
}

/**
 * `GET /remotes` as devnull answers it.
 *
 * @param  list<array<string, mixed>>  $conversations
 * @return array<string, mixed>
 */
function remotesInboxJson(array $conversations, bool $hasMore = false, int $unread = 0, int $requests = 0): array
{
    return ['data' => $conversations, 'has_more' => $hasMore, 'unread_count' => $unread, 'requests_count' => $requests];
}

/**
 * A Remotes thread with Ada as devnull answers it, its first page or a poll.
 *
 * @param  list<array<string, mixed>>  $messages
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function threadJson(array $messages, array $overrides = []): array
{
    return ['data' => [
        'partner' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1]),
        'messages' => $messages,
        'has_older' => false,
        'reactions_version' => 0,
        'reaction_changes' => [],
        'read_through_id' => 0,
        'partner_read_through_id' => 0,
        'partner_read_at' => null,
        'partner_typing' => false,
        'is_archived' => false,
        'access' => 'open',
        ...$overrides,
    ]];
}

/**
 * The requests sent to devnull with a method and a path, as their bodies.
 *
 * @return list<string>
 */
function sentTo(string $method, string $path): array
{
    return collect(Http::recorded())
        ->filter(fn (array $sent): bool => $sent[0]->method() === $method && parse_url($sent[0]->url(), PHP_URL_PATH) === '/api/v1/'.$path)
        ->map(fn (array $sent): string => $sent[0]->body())
        ->values()
        ->all();
}
