<?php

use App\Devnull\Account;
use App\NativeComponents\Guild;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Answers #rust's board by page number: $pages maps '' (the first page, asked for with no number) and '2', '3'… to
 * their answers. Anything else is a 404.
 *
 * @param  array<string, array<string, mixed>>  $pages
 */
function serveBoard(array $pages): void
{
    serveApi(function (Request $request) use ($pages) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (parse_url($request->url(), PHP_URL_PATH) !== '/api/v1/guilds/rust' || ! isset($pages[$query['page'] ?? ''])) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        return Http::response($pages[$query['page'] ?? '']);
    });
}

/**
 * The commits the board shows, top to bottom, by id.
 *
 * @return list<string>
 */
function boardShows(TestableComponent $screen): array
{
    $rows = findNodes($screen->tree(), fn (array $node): bool => preg_match('/^commit-c\d+$/', $node['ref'] ?? '') === 1);

    return array_map(fn (array $node): string => substr($node['ref'], strlen('commit-')), $rows);
}

/**
 * The words of the header's line under the name, as one line.
 */
function guildMeta(TestableComponent $screen): string
{
    $texts = findNodes(nodeByRef($screen, 'guild-meta'), fn (array $node): bool => ($node['type'] ?? null) === 'text');

    return implode(' ', array_map(fn (array $node): string => (string) ($node['text'] ?? $node['props']['text'] ?? ''), $texts));
}

it('shows the guild\'s header as the web does', function () {
    serveBoard(['' => boardOf(['c1'], overrides: ['guild' => guildJson(['name' => 'Rustaceans', 'member_count' => 2, 'description' => 'Crabs welcome.'])])]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->assertNavTitle('Rustaceans')
        ->assertSee('Rustaceans')
        ->assertSee('Crabs welcome.')
        ->assertSee('Include #rust in any commit to post here.')
        ->assertSee('Bulletin board · Latest commits')
        ->assertDontSee('This guild is paused until it has a moderator. Its board and members are preserved.');

    expect(guildMeta($screen))->toBe('#rust · Public guild · 2 members');

    serveBoard(['' => boardOf(['c1'], overrides: ['guild' => guildJson(['member_count' => 1, 'is_paused' => true])])]);

    $paused = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->assertSee('This guild is paused until it has a moderator. Its board and members are preserved.')
        ->assertDontSee('Include #rust in any commit to post here.');

    expect(guildMeta($paused))->toBe('#rust · Public guild · Paused · 1 member');
});

it('opens a guilds directory link on top of the board, with a way back', function () {
    serveBoard(['' => boardOf(['c1'], overrides: ['data' => [commitJson(['id' => 'c1', 'body' => 'See https://dev-null.sh/guilds'])]])]);

    finishRefreshes(Native::visit('/guilds/guilds/rust'))
        ->press("openLink('c1', 0)")
        ->assertNavigatedTo('/guilds/guilds')
        ->assertNativeNotCalled('Browser.OpenInApp');
});

it('opens the members from the member count', function () {
    serveBoard(['' => boardOf(['c1'])]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->tap('guild-members')
        ->assertNavigatedTo('/log/guilds/rust/members');

    expect($screen->navigationIntent()->data)->toBe(['guildName' => 'Rust']);
});

it('joins and leaves, counting the members to match', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['member_count' => 3])])],
        'POST /api/v1/guilds/rust/membership' => [null, 204],
        'DELETE /api/v1/guilds/rust/membership' => [null, 204],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'));

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Join'))->toBeTrue();

    $screen->tap('guild-join');

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Leave'))->toBeTrue()
        ->and(guildMeta($screen))->toBe('#rust · Public guild · 4 members')
        ->and(kept('guilds/rust')['guild']['is_joined'])->toBeTrue()
        ->and(kept('guilds/rust')['guild']['member_count'])->toBe(4);

    $screen->tap('guild-join');

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Join'))->toBeTrue()
        ->and(guildMeta($screen))->toBe('#rust · Public guild · 3 members')
        ->and(kept('guilds/rust')['guild']['is_joined'])->toBeFalse()
        ->and(kept('guilds/rust')['guild']['member_count'])->toBe(3);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://devnull.test/api/v1/guilds/rust/membership');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/guilds/rust/membership');
});

it('counts a join in the kept directory and forgets the kept members', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'POST /api/v1/guilds/rust/membership' => [null, 204],
    ]);
    keep('guilds', ['data' => [guildJson(['handle' => 'go']), guildJson(['member_count' => 3])], 'links' => ['next' => null]]);
    keep('guilds/rust/members', guildMembersJson(['grace' => []]));

    finishRefreshes(Native::visit('/log/guilds/rust'))->tap('guild-join');

    expect(kept('guilds')['data'][1]['is_joined'])->toBeTrue()
        ->and(kept('guilds')['data'][1]['member_count'])->toBe(4)
        ->and(kept('guilds')['data'][0]['is_joined'])->toBeFalse()
        ->and(kept('guilds/rust/members'))->toBeNull();
});

it('unmutes a guild by joining it, as devnull does', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_muted' => true])])],
        'POST /api/v1/guilds/rust/membership' => [null, 204],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))->tap('guild-join');

    expect($screen->instance()->guild->isMuted)->toBeFalse()
        ->and(kept('guilds/rust')['guild']['is_muted'])->toBeFalse();
});

it('puts Join back when devnull refuses', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['member_count' => 3])])],
        'POST /api/v1/guilds/rust/membership' => [['message' => 'Too Many Attempts.'], 429],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))->tap('guild-join')->assertSee('Too Many Attempts.');

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Join'))->toBeTrue()
        ->and(guildMeta($screen))->toBe('#rust · Public guild · 3 members')
        ->and(kept('guilds/rust')['guild']['is_joined'])->toBeFalse();
});

it('offers Join on a paused guild', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_paused' => true])])]]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'));

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Join'))->toBeTrue();
});

it('opens the members from the member count', function () {
    serveBoard(['' => boardOf(['c1'])]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->tap('guild-members')
        ->assertNavigatedTo('/log/guilds/rust/members');

    expect($screen->navigationIntent()->data)->toBe(['guildName' => 'Rust']);
});

it('joins and leaves, counting the members to match', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['member_count' => 3])])],
        'POST /api/v1/guilds/rust/membership' => [null, 204],
        'DELETE /api/v1/guilds/rust/membership' => [null, 204],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'));

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Join'))->toBeTrue();

    $screen->tap('guild-join');

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Leave'))->toBeTrue()
        ->and(guildMeta($screen))->toBe('#rust · Public guild · 4 members')
        ->and(kept('guilds/rust')['guild']['is_joined'])->toBeTrue()
        ->and(kept('guilds/rust')['guild']['member_count'])->toBe(4);

    $screen->tap('guild-join');

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Join'))->toBeTrue()
        ->and(guildMeta($screen))->toBe('#rust · Public guild · 3 members')
        ->and(kept('guilds/rust')['guild']['is_joined'])->toBeFalse()
        ->and(kept('guilds/rust')['guild']['member_count'])->toBe(3);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://devnull.test/api/v1/guilds/rust/membership');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/guilds/rust/membership');
});

it('counts a join in the kept directory and forgets the kept members', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'POST /api/v1/guilds/rust/membership' => [null, 204],
    ]);
    keep('guilds', ['data' => [guildJson(['handle' => 'go']), guildJson(['member_count' => 3])], 'links' => ['next' => null]]);
    keep('guilds/rust/members', guildMembersJson(['grace' => []]));

    finishRefreshes(Native::visit('/log/guilds/rust'))->tap('guild-join');

    expect(kept('guilds')['data'][1]['is_joined'])->toBeTrue()
        ->and(kept('guilds')['data'][1]['member_count'])->toBe(4)
        ->and(kept('guilds')['data'][0]['is_joined'])->toBeFalse()
        ->and(kept('guilds/rust/members'))->toBeNull();
});

it('unmutes a guild by joining it, as devnull does', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_muted' => true])])],
        'POST /api/v1/guilds/rust/membership' => [null, 204],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))->tap('guild-join');

    expect($screen->instance()->guild->isMuted)->toBeFalse()
        ->and(kept('guilds/rust')['guild']['is_muted'])->toBeFalse();
});

it('puts Join back when devnull refuses', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['member_count' => 3])])],
        'POST /api/v1/guilds/rust/membership' => [['message' => 'Too Many Attempts.'], 429],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))->tap('guild-join')->assertSee('Too Many Attempts.');

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Join'))->toBeTrue()
        ->and(guildMeta($screen))->toBe('#rust · Public guild · 3 members')
        ->and(kept('guilds/rust')['guild']['is_joined'])->toBeFalse();
});

it('offers Join on a paused guild', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_paused' => true])])]]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'));

    expect(treeShows(nodeByRef($screen, 'guild-join'), 'Join'))->toBeTrue();
});

it('shows the pinned commit first, once', function () {
    serveBoard(['' => boardOf(['c1', 'c2', 'c3'], overrides: ['pinned_commit' => commitJson(['id' => 'c2'])])]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'));

    expect(boardShows($screen))->toBe(['c2', 'c1', 'c3'])
        ->and(treeShows(nodeByRef($screen, 'pinned-label'), 'Pinned to board'))->toBeTrue()
        ->and($screen->get('pinnedId'))->toBe('c2');
});

it('leaves the posted in badge off its own board', function () {
    serveBoard(['' => boardOf([], overrides: ['data' => [commitJson(['id' => 'c1', 'guild_handle' => 'rust', 'guild' => ['handle' => 'rust', 'name' => 'Rust', 'emoji' => '🦀', 'icon_url' => null]])]])]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'));

    expect(boardShows($screen))->toBe(['c1'])
        ->and(rendersRef($screen, 'guild-badge-c1'))->toBeFalse();
});

it('loads the next page without repeating a commit', function () {
    serveBoard([
        '' => boardOf(['c5', 'c4', 'c3'], nextPage: 2),
        '2' => boardOf(['c3', 'c2'], nextPage: 3),
        '3' => boardOf(['c1']),
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))->call('loadMore');

    expect(boardShows($screen))->toBe(['c5', 'c4', 'c3', 'c2']);

    $screen->call('loadMore');

    expect(boardShows($screen))->toBe(['c5', 'c4', 'c3', 'c2', 'c1'])
        ->and($screen->get('nextCursor'))->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/guilds/rust?page=2');
});

it('shows the pinned commit once when a later page carries it too', function () {
    serveBoard([
        '' => boardOf(['c5', 'c4'], nextPage: 2, overrides: ['pinned_commit' => commitJson(['id' => 'c2'])]),
        '2' => boardOf(['c3', 'c2', 'c1'], overrides: ['pinned_commit' => commitJson(['id' => 'c2'])]),
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'));

    expect(boardShows($screen))->toBe(['c2', 'c5', 'c4']);

    $screen->call('loadMore');

    expect(boardShows($screen))->toBe(['c2', 'c5', 'c4', 'c3', 'c1']);
});

it('says when the board is empty', function () {
    serveBoard(['' => boardOf([])]);

    finishRefreshes(Native::visit('/log/guilds/rust'))
        ->assertSee('Include #rust in any commit to post here.')
        ->assertSee('No commits yet.')
        ->assertSee('Post to the board or include #rust in a commit.');
});

it('says when the guild is gone', function () {
    serveApi(function (Request $request) {
        return parse_url($request->url(), PHP_URL_PATH) === '/api/v1/guilds'
            ? Http::response(['data' => [guildJson(['handle' => 'go'])], 'links' => ['next' => null]])
            : Http::response(['message' => 'Not Found'], 404);
    });

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->assertSee('This guild is no longer available.')
        ->assertDontSee('fatal: guilds are not available')
        ->assertSet('missing', true)
        ->assertSet('unavailable', false);

    expect($screen->get('error'))->toBeNull()
        ->and(app(Account::class)->guildsAvailable())->toBeTrue();
});

it('shows guilds switched off when guilds are gone too', function () {
    serveApi(fn () => Http::response(['message' => 'Not Found'], 404));

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->assertSee('fatal: guilds are not available')
        ->assertSee("Guilds aren't open to you yet.")
        ->assertDontSee('This guild is no longer available.')
        ->assertSet('unavailable', true);

    expect($screen->get('error'))->toBeNull()
        ->and(app(Account::class)->guildsAvailable())->toBeFalse();
});

it('says it could not load, not that the guild is gone, when the directory check fails', function (int $status) {
    serveApi(function (Request $request) use ($status) {
        return parse_url($request->url(), PHP_URL_PATH) === '/api/v1/guilds'
            ? ($status === 0 ? throw new ConnectionException('cURL error 6') : Http::response(['message' => 'Server Error'], $status))
            : Http::response(['message' => 'Not Found'], 404);
    });

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->assertDontSee('This guild is no longer available.')
        ->assertSet('missing', false)
        ->assertSet('unavailable', false);

    expect($screen->get('error'))->not->toBeNull()
        ->and(app(Account::class)->guildsAvailable())->toBeNull();
})->with(['no connection' => 0, 'devnull failing' => 500]);

it('signs out when the key stops working during the directory check', function () {
    serveApi(function (Request $request) {
        return parse_url($request->url(), PHP_URL_PATH) === '/api/v1/guilds'
            ? Http::response(['message' => 'This SSH key is not on the GitHub account of a devnull user.'], 401)
            : Http::response(['message' => 'Not Found'], 404);
    });

    finishRefreshes(Native::visit('/log/guilds/rust'))->assertReplacedWith('/onboarding');

    expect(app(Account::class)->user())->toBeNull();
});

it('keeps the board when an opened thread is gone', function () {
    $board = boardOf([], overrides: [
        'guild' => guildJson(['name' => 'Rustaceans']),
        'data' => [commitJson(['id' => 'c1', 'replies_count' => 1]), commitJson(['id' => 'c2'])],
    ]);
    $repliesGone = false;
    serveApi(function (Request $request) use ($board, &$repliesGone) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/v1/guilds/rust' => Http::response($board),
            '/api/v1/commits/c1/replies' => $repliesGone
                ? Http::response(['message' => "This commit isn't available.", 'code' => 'commit_unavailable'], 404)
                : Http::response(pageJson([commitJson(['id' => 'r1', 'parent_id' => 'c1'])])),
            default => Http::response(['message' => 'Not Found'], 404),
        };
    });
    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))->call('toggleReplies', 'c1');
    $repliesGone = true;

    finishRefreshes($screen->call('load'));

    $screen->assertSee('Rustaceans')
        ->assertSet('missing', false)
        ->assertSet('unavailable', false);
    expect(boardShows($screen))->toBe(['c1', 'c2'])
        ->and($screen->get('error'))->toBeNull()
        ->and(rendersRef($screen, 'thread-reply-r1'))->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/v1/guilds');
});

it('leaves the pinned commit out of the new commits pill', function () {
    keep('guilds/rust', boardOf(['c1']));
    serveBoard(['' => boardOf(['c9', 'c8', 'c1'], overrides: ['pinned_commit' => commitJson(['id' => 'c9'])])]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->assertSee('1 new commit')
        ->assertDontSee('2 new commits');

    expect(boardShows($screen))->toBe(['c9', 'c1']);

    $screen->tap('new-commits');

    expect(boardShows($screen))->toBe(['c9', 'c8', 'c1']);

    keep('guilds/rust', boardOf(['c1']));
    serveBoard(['' => boardOf(['c9', 'c1'], overrides: ['pinned_commit' => commitJson(['id' => 'c9'])])]);

    $pinnedOnly = finishRefreshes(Native::visit('/log/guilds/rust'));

    expect(boardShows($pinnedOnly))->toBe(['c9', 'c1'])
        ->and(rendersRef($pinnedOnly, 'new-commits'))->toBeFalse();
});

it('opens a guild whose handle is a route word', function (string $handle) {
    // The Guilds tab is /guilds, so its own /guilds/search, /guilds/safety… belong to other screens.
    serveApi(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/api/v1/guilds/'.$handle
        ? Http::response(boardOf(['c1'], overrides: ['guild' => guildJson(['handle' => $handle, 'name' => 'Not a screen'])]))
        : Http::response(pageJson([])));

    $guild = finishRefreshes(Native::visit('/guilds')->call('openGuild', $handle)->assertNavigatedTo("/guilds/guilds/{$handle}")->follow());

    expect($guild->instance())->toBeInstanceOf(Guild::class);
    $guild->assertNavTitle('Not a screen');
})->with(['search', 'safety', 'scheduled', 'guilds']);
