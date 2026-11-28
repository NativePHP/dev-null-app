<?php

use App\Devnull\Account;
use App\NativeComponents\Guild;
use App\NativeComponents\Guilds;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A page of the directory, paged by number as the web pages it.
 *
 * @param  list<array<string, mixed>>  $guilds
 * @return array<string, mixed>
 */
function guildsPage(array $guilds, ?int $nextPage = null, string $term = ''): array
{
    $path = 'http://devnull.test/api/v1/guilds';
    $query = $term === '' ? '' : 'q='.rawurlencode($term).'&';

    return [
        'data' => $guilds,
        'links' => ['first' => $path.'?'.$query.'page=1', 'last' => null, 'prev' => null, 'next' => $nextPage === null ? null : $path.'?'.$query.'page='.$nextPage],
        'meta' => ['current_page' => $nextPage === null ? 1 : $nextPage - 1, 'path' => $path, 'per_page' => 24],
    ];
}

/** @return list<string> */
function shownGuilds(TestableComponent $screen): array
{
    return array_map(fn ($guild): string => $guild->handle, $screen->instance()->guilds);
}

/**
 * Answers the directory by search term: $pages maps a term ('' for none) to its answer.
 *
 * @param  array<string, array<string, mixed>>  $pages
 */
function serveGuilds(array $pages): void
{
    serveApi(function (Request $request) use ($pages) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (parse_url($request->url(), PHP_URL_PATH) !== '/api/v1/guilds') {
            return Http::response(['message' => 'No fake.'], 404);
        }

        $key = ($query['q'] ?? '').(isset($query['page']) ? '#'.$query['page'] : '');

        return Http::response($pages[$key] ?? guildsPage([]));
    });
}

it('lists guilds biggest first with the web wording', function (string $platform) {
    serveGuilds(['' => guildsPage([
        guildJson(['handle' => 'rust', 'name' => 'Rust', 'member_count' => 12]),
        guildJson(['handle' => 'go', 'name' => 'Go', 'member_count' => 1, 'description' => 'Gophers.']),
    ])]);

    $screen = finishRefreshes(Native::visit('/profile/guilds', platform: $platform))
        ->assertNavTitle('Guilds')
        ->assertTabActive('Profile')
        ->assertSee('Public groups, biggest first.')
        ->assertSee('Search names, #handles, and descriptions.')
        ->assertSee('Rust')
        ->assertSee('#rust')
        ->assertSee('12 members')
        ->assertSee('Gophers.')
        ->assertSee('1 member');

    expect(shownGuilds($screen))->toBe(['rust', 'go'])
        ->and(nodeByRef($screen, 'guild-rust')['props']['a11y_label'])->toBe('Rust, #rust, 12 members')
        ->and(nodeByRef($screen, 'guild-search-field')['props']['placeholder'])->toBe('Search guilds');
})->with(['ios', 'android']);

it('opens a tapped guild in the tab it was tapped in', function () {
    serveGuilds(['' => guildsPage([guildJson(['handle' => 'rust'])])]);

    finishRefreshes(Native::visit('/log/guilds'))
        ->tap('guild-rust')
        ->assertNavigatedTo('/log/guilds/rust');
});

it('opens a guild on the Guilds tab\'s own stack from the tab', function () {
    serveGuilds(['' => guildsPage([guildJson(['handle' => 'rust'])])]);

    $screen = finishRefreshes(Native::visit('/guilds'));
    $screen->press("openGuild('rust')")->assertNavigatedTo('/guilds/guilds/rust');

    $guild = $screen->follow();

    expect($guild->instance())->toBeInstanceOf(Guild::class);
    $guild->assertTabActive('Guilds');
});

it('searches with the term and keeps it when loading more', function () {
    serveGuilds([
        '' => guildsPage([guildJson(['handle' => 'go'])]),
        'rust' => guildsPage([guildJson(['handle' => 'rust'])], nextPage: 2, term: 'rust'),
        'rust#2' => guildsPage([guildJson(['handle' => 'rustaceans'])], term: 'rust'),
    ]);

    $screen = finishRefreshes(Native::visit('/profile/guilds'));
    $screen->input('term', 'rust');
    finishRefreshes($screen);

    expect(shownGuilds($screen))->toBe(['rust']);

    $screen->call('loadMore');

    expect(shownGuilds($screen))->toBe(['rust', 'rustaceans']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/guilds?q=rust&page=2'));
});

it('ignores an answer for a term the field has moved on from', function () {
    holdRefreshes();
    serveGuilds([
        '' => guildsPage([guildJson(['handle' => 'go'])]),
        'ru' => guildsPage([guildJson(['handle' => 'ruby'])], term: 'ru'),
        'rust' => guildsPage([guildJson(['handle' => 'rust'])], term: 'rust'),
    ]);

    $screen = finishRefreshes(Native::visit('/profile/guilds'));
    $screen->input('term', 'ru');
    $screen->input('term', 'rust');

    // The answer for "ru" lands last.
    finishRefreshes($screen, newestFirst: true);

    expect(shownGuilds($screen))->toBe(['rust']);
});

it('drops guilds already shown when the next page repeats one', function () {
    serveGuilds([
        '' => guildsPage([guildJson(['handle' => 'rust']), guildJson(['handle' => 'go'])], nextPage: 2),
        '#2' => guildsPage([guildJson(['handle' => 'go']), guildJson(['handle' => 'zig'])]),
    ]);

    $screen = finishRefreshes(Native::visit('/profile/guilds'));
    $screen->call('loadMore');

    expect(shownGuilds($screen))->toBe(['rust', 'go', 'zig']);
});

it('says when no guild matches, with a way back to all of them', function () {
    serveGuilds([
        '' => guildsPage([guildJson(['handle' => 'rust'])]),
        'cobol' => guildsPage([], term: 'cobol'),
    ]);

    $screen = finishRefreshes(Native::visit('/profile/guilds'));
    $screen->input('term', 'cobol');
    finishRefreshes($screen)
        ->assertSee('No guilds found. Try another keyword.')
        ->assertSee('Browse all guilds');

    $screen->press('browseAll');
    finishRefreshes($screen)->assertSet('term', '');

    expect(shownGuilds($screen))->toBe(['rust']);
});

it('says there are no guilds yet on an empty directory', function () {
    serveGuilds(['' => guildsPage([])]);

    finishRefreshes(Native::visit('/profile/guilds'))
        ->assertSee('No guilds yet.')
        ->assertDontSee('Browse all guilds');
});

it('says guilds are not available when devnull answers 404, without an error', function () {
    serveApi(['GET /api/v1/guilds' => [['message' => 'Not Found'], 404]]);

    $screen = finishRefreshes(Native::visit('/profile/guilds'))
        ->assertSee('fatal: guilds are not available')
        ->assertSee("Guilds aren't open to you yet.")
        ->assertSet('unavailable', true)
        ->assertSet('error', null);

    expect(rendersRef($screen, 'guild-search-field'))->toBeFalse();
});

it('opens on the guilds kept last time and refreshes behind them', function () {
    holdRefreshes();
    keep('guilds', guildsPage([guildJson(['handle' => 'rust'])]));
    serveGuilds(['' => guildsPage([guildJson(['handle' => 'go']), guildJson(['handle' => 'rust'])])]);

    $screen = Native::visit('/profile/guilds');

    expect(shownGuilds($screen))->toBe(['rust']);

    finishRefreshes($screen);

    expect(shownGuilds($screen))->toBe(['go', 'rust']);
});

it('is the Guilds screen', function () {
    expect(Native::visit('/log/guilds')->instance())->toBeInstanceOf(Guilds::class);
});

it('shows a guild\'s uploaded icon in the directory', function () {
    $url = 'https://dev-null.sh/api/v1/guilds/php/icon?expires='.(time() + 3600).'&signature=x';
    serveGuilds(['' => guildsPage([
        guildJson(['handle' => 'php', 'name' => 'PHP', 'emoji' => '💻', 'icon_url' => $url]),
        guildJson(['handle' => 'rust', 'name' => 'Rust', 'emoji' => '🦀']),
    ])]);

    $screen = finishRefreshes(Native::visit('/profile/guilds'));

    expect(nodeByRef($screen, 'guild-mark-php')['type'])->toBe('image')
        ->and(nodeByRef($screen, 'guild-mark-php')['props']['src'])->toBe($url)
        ->and(nodeByRef($screen, 'guild-mark-rust')['props']['text'] ?? null)->toBe('🦀');
    $screen->assertDontSee('💻');
});

it('shows the people glyph, never the stored emoji, for an uploaded icon it cannot load', function () {
    config(['devnull.image_cache_hours' => 24]);
    $expired = 'https://dev-null.sh/api/v1/guilds/php/icon?expires='.(time() - 60).'&signature=x';
    serveGuilds(['' => guildsPage([
        guildJson(['handle' => 'php', 'name' => 'PHP', 'emoji' => '💻', 'icon_url' => $expired]),
    ])]);

    $screen = finishRefreshes(Native::visit('/profile/guilds'));

    expect(nodeByRef($screen, 'guild-mark-php')['type'])->toBe('icon');
    $screen->assertDontSee('💻');
});

it('hides the Guilds tab from the next launch once devnull says guilds are off for you', function () {
    serveApi(function (Request $request) {
        return parse_url($request->url(), PHP_URL_PATH) === '/api/v1/guilds'
            ? Http::response(['message' => 'Not Found'], 404)
            : Http::response(pageJson([]));
    });

    finishRefreshes(Native::visit('/guilds'))->assertSet('unavailable', true);

    Account::forgetGuildsTabShown();

    expect(tabsShown(finishRefreshes(Native::visit('/log'))))->not->toHaveKey('Guilds');
});

it('brings the Guilds tab back at the next launch once guilds are on for you again', function () {
    app(Account::class)->rememberGuildsAvailable(false);
    serveApi(function (Request $request) {
        return parse_url($request->url(), PHP_URL_PATH) === '/api/v1/guilds'
            ? Http::response(guildsPage([guildJson()]))
            : Http::response(pageJson([]));
    });

    $log = finishRefreshes(Native::visit('/log'));

    expect(app(Account::class)->guildsAvailable())->toBeTrue()
        ->and(tabsShown($log))->not->toHaveKey('Guilds');

    Account::forgetGuildsTabShown();

    expect(tabsShown(finishRefreshes(Native::visit('/log'))))->toHaveKey('Guilds');
});

it('forgets whether guilds were off when you sign out', function () {
    app(Account::class)->rememberGuildsAvailable(false);
    app(Account::class)->forget();

    expect(app(Account::class)->guildsAvailable())->toBeNull();
});

it('decides the Guilds tab afresh for whoever signs in next', function () {
    expect(app(Account::class)->guildsTabShown())->toBeTrue();

    app(Account::class)->forget();
    app(Account::class)->rememberGuildsAvailable(false);

    expect(app(Account::class)->guildsTabShown())->toBeFalse();
});

it('marks the guilds you have joined on their members line', function () {
    serveApi(['GET /api/v1/guilds' => [guildsPage([guildJson(['handle' => 'rust', 'is_joined' => true]), guildJson(['handle' => 'go'])])]]);

    $screen = finishRefreshes(Native::visit('/guilds'));

    expect(nodeByRef($screen, 'guild-rust')['props']['a11y_label'] ?? nodeByRef($screen, 'guild-rust')['a11y_label'] ?? null)->toBe('Rust, #rust, 3 members, joined')
        ->and(childRefs($screen, 'guild-members-rust'))->toContain('guild-joined-rust')
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'guild-joined-go'))->toBeNull();
});
