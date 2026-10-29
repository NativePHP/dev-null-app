<?php

use App\NativeComponents\Search;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function foundPerson(string $username, array $overrides = []): array
{
    return userJson(['id' => "id-{$username}", 'handle' => $username, 'name' => ucfirst($username), 'is_followed' => false, 'follows_you' => false, ...$overrides]);
}

/**
 * Answers people searches with $people and commit searches with $commits. Anything else, such as the Log behind
 * the search button, gets an empty page.
 *
 * @param  list<array<string, mixed>>  $people
 * @param  list<array<string, mixed>>  $commits
 * @param  array<string, array{0: mixed, 1?: int}>  $writes
 */
function serveSearch(array $people = [], array $commits = [], array $writes = []): void
{
    serveApi(function (Request $request) use ($people, $commits, $writes) {
        $route = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            isset($writes[$route]) => Http::response($writes[$route][0], $writes[$route][1] ?? 200),
            $route === 'GET /api/v1/search/users' => Http::response(['data' => $people]),
            $route === 'GET /api/v1/search/commits' => Http::response(['data' => $commits]),
            default => Http::response(pageJson([])),
        };
    });
}

/**
 * The q each search of $kind ("users" or "commits") was sent with, in order.
 *
 * @return list<string>
 */
function searchedFor(string $kind): array
{
    return collect(Http::recorded())
        ->map(fn (array $call): string => $call[0]->url())
        ->filter(fn (string $url): bool => str_contains($url, "/search/{$kind}"))
        ->map(function (string $url): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return $query['q'];
        })
        ->values()
        ->all();
}

it('opens search from the Log, beside New commit, on the Log stack', function (string $platform) {
    serveSearch();

    $screen = finishRefreshes(Native::visit('/log', platform: $platform));
    $actions = array_column(findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action'), 'props');

    expect(array_column($actions, 'id'))->toBe(['search', 'compose'])
        ->and($actions[0])->toMatchArray(['icon' => 'custom:search', 'a11y_label' => 'Search']);

    $screen->press('openSearch')->assertNavigatedTo('/log/search');
})->with(['ios', 'android']);

it("offers the web's searches to try before you type, and searches the one you pick", function () {
    serveSearch(commits: [commitJson(['id' => 'c1', 'body' => 'Shipped my weekend project'])]);

    $screen = Native::visit('/log/search')
        ->assertNavTitle('Search')
        ->assertSee('Try searching for…');

    foreach (Search::Suggestions as $suggestion) {
        $screen->assertSee($suggestion);
    }

    $screen->tap('suggestion-shipping-a-side-project')
        ->assertSet('query', 'shipping a side project')
        ->assertDontSee('Try searching for…')
        ->assertSee('Most relevant commits')
        ->assertSee('1 match')
        ->assertSee('Shipped my weekend project');

    expect(searchedFor('commits'))->toBe(['shipping a side project']);
});

it('finds developers, then the commits nearest in meaning, closest first', function () {
    serveSearch(
        people: [foundPerson('ada', ['name' => 'Ada Lovelace', 'follows_you' => true]), foundPerson('adam')],
        commits: [commitJson(['id' => 'c2', 'body' => 'Closest']), commitJson(['id' => 'c1', 'body' => 'Further'])],
    );

    $screen = Native::visit('/log/search')
        ->set('query', '@ada')
        ->assertSee('Developers')
        ->assertSee('Ada Lovelace')
        ->assertSee('Follows you')
        ->assertSee('2 matches');

    expect(childRefs($screen, 'search-results'))->toContain('developers-heading', 'person-ada', 'person-adam', 'commits-heading')
        ->and(array_map(fn ($commit): string => $commit->id, $screen->instance()->commits))->toBe(['c2', 'c1'])
        ->and(dayMarkers($screen))->toBe([])
        // A handle is found without its @, and commits by what was typed.
        ->and(searchedFor('users'))->toBe(['ada'])
        ->and(searchedFor('commits'))->toBe(['@ada']);
});

it('only finds developers for a term too short to search commits by', function () {
    serveSearch();

    Native::visit('/log/search')
        ->set('query', 'zz')
        ->assertSee("fatal: nothing matches 'zz'")
        ->assertDontSee('Most relevant commits');

    expect(searchedFor('users'))->toBe(['zz'])
        ->and(searchedFor('commits'))->toBe([]);
});

it("answers a term with no commits near it in git's voice", function () {
    serveSearch();

    Native::visit('/log/search')
        ->set('query', 'quantum knitting')
        ->assertSee('0 matches')
        ->assertSee("fatal: nothing close to 'quantum knitting'");
});

it('keeps an answer for an earlier term from landing under a newer one', function () {
    holdRefreshes();
    // Each term finds the developer it names.
    serveApi(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['data' => [foundPerson($query['q'])]]);
    });

    $screen = Native::visit('/log/search')->set('query', 'ad')->set('query', 'grace');

    // The newer term's answer lands first, and the older one after it.
    finishRefreshes($screen, newestFirst: true);

    expect(array_map(fn ($person): string => $person->user->username, $screen->instance()->people))->toBe(['grace'])
        ->and($screen->instance()->peopleFoundFor)->toBe('grace');
});

it('goes back to the searches to try when the field is emptied', function () {
    serveSearch(people: [foundPerson('ada')]);

    Native::visit('/log/search')
        ->set('query', 'ada')
        ->assertSee('Ada')
        ->set('query', '  ')
        ->assertSee('Try searching for…')
        ->assertDontSee('Developers')
        ->assertSet('people', []);
});

it('opens a developer from the results on the same stack', function () {
    serveSearch(people: [foundPerson('ada')]);

    Native::visit('/log/search')->set('query', 'ada')->tap('person-ada')->assertNavigatedTo('/log/users/ada');
});

it('follows a developer from the results, and puts it back when devnull refuses', function () {
    serveSearch(
        people: [foundPerson('ada'), foundPerson('grace')],
        writes: [
            'POST /api/v1/users/ada/follow' => [null, 204],
            'POST /api/v1/users/grace/follow' => [['message' => 'Too Many Attempts.'], 429],
        ],
    );

    $screen = Native::visit('/log/search')->set('query', 'a')->tap('follow-ada');

    expect(treeShows(nodeByRef($screen, 'person-ada'), 'Following'))->toBeTrue();

    $screen->tap('follow-grace')->assertSee('Too Many Attempts.');

    expect(treeShows(nodeByRef($screen, 'person-grace'), 'Following'))->toBeFalse();
});
