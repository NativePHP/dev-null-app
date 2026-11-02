<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Someone who starred a commit, as devnull lists them.
 *
 * @return array<string, mixed>
 */
function stargazerJson(string $handle, string $name): array
{
    return userJson(['id' => 'u-'.$handle, 'handle' => $handle, 'name' => $name]);
}

/**
 * Your log with one commit of your own on it, starred $count times, and who starred it in pages of the given people.
 *
 * @param  list<list<array<string, mixed>>>  $pages
 */
function ownStarredCommit(int $count, array $pages, string $platform = 'ios'): TestableComponent
{
    serveApi(function (Request $request) use ($count, $pages) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['cursor'] ?? 0);

        return match ($path) {
            '/api/v1/log/following' => Http::response(pageJson([commitJson(['id' => 'c1', 'stars_count' => $count])])),
            '/api/v1/commits/c1/stars' => Http::response(pageJson($pages[$page], $page + 1 < count($pages) ? (string) ($page + 1) : null, 'http://devnull.test/api/v1/commits/c1/stars')),
            default => Http::response(['message' => "No fake for {$path}."], 404),
        };
    });

    return Native::visit('/log', platform: $platform);
}

it('opens the web\'s Starred by from the star on your own commit, newest first', function (string $platform) {
    $screen = ownStarredCommit(2, [[stargazerJson('grace', 'Grace Hopper'), stargazerJson('ada', 'Ada Lovelace')]], $platform);

    expect(nodeByRef($screen, 'stargazers-sheet')['props']['visible'])->toBeFalse()
        ->and(nodeByRef($screen, 'stargazers-sheet')['children'] ?? [])->toBe([])
        ->and(nodeByRef($screen, 'star-count-c1'))->type->toBe('pressable')->props->a11y_label->toBe('2 stars, show who starred');

    $screen->tap('star-count-c1')
        ->assertSee('Starred by')
        ->assertSee('2 people starred this commit.')
        ->assertSee('Grace Hopper')
        ->assertSee('@ada')
        ->assertDontSee('Show more')
        ->assertDontSee('fatal: could not load stars');

    expect(nodeByRef($screen, 'stargazers-sheet')['props']['visible'])->toBeTrue()
        ->and(childRefs($screen, 'stargazers'))->toBe(['stargazer-grace', 'stargazer-ada'])
        ->and(nodeByRef($screen, 'stargazer-grace')['props']['a11y_label'])->toBe('Grace Hopper, @grace');
})->with(['ios', 'android']);

it('says one person, as the web does', function () {
    ownStarredCommit(1, [[stargazerJson('grace', 'Grace Hopper')]])->tap('star-count-c1')->assertSee('1 person starred this commit.');
});

it('shows skeleton rows for up to three people while they load', function (int $count, int $skeletons) {
    holdRefreshes();
    $screen = finishRefreshes(ownStarredCommit($count, [array_map(fn (int $n): array => stargazerJson("dev{$n}", "Dev {$n}"), range(1, $count))]));

    $screen->call('openStargazers', 'c1');

    expect(childRefs($screen, 'stargazers'))->toBe(array_map(fn (int $n): string => "stargazer-loading-{$n}", range(1, $skeletons)));

    finishRefreshes($screen);

    expect(childRefs($screen, 'stargazers'))->toHaveCount($count)->not->toContain('stargazer-loading-1');
})->with([
    'one' => [1, 1],
    'five' => [5, 3],
]);

it('loads the next people with Show more, until there are no more', function () {
    $screen = ownStarredCommit(3, [
        [stargazerJson('grace', 'Grace Hopper'), stargazerJson('ada', 'Ada Lovelace')],
        [stargazerJson('linus', 'Linus Torvalds')],
    ])->tap('star-count-c1');

    expect(childRefs($screen, 'stargazers'))->toBe(['stargazer-grace', 'stargazer-ada']);

    $screen->tap('stargazers-more')->assertDontSee('Show more');

    expect(childRefs($screen, 'stargazers'))->toBe(['stargazer-grace', 'stargazer-ada', 'stargazer-linus']);
    Http::assertSent(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/c1/stars') && str_contains($request->url(), 'cursor=1'));
});

it('says the stars could not load, as the web does, and tries again', function () {
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'stars_count' => 1])])],
        'GET /api/v1/commits/c1/stars' => [['message' => 'Server Error'], 500],
    ]);

    $screen = Native::visit('/log')->tap('star-count-c1')
        ->assertSee('fatal: could not load stars')
        ->assertSee('Try again');

    serveApi(['GET /api/v1/commits/c1/stars' => [pageJson([stargazerJson('grace', 'Grace Hopper')])]]);

    $screen->tap('stargazers-retry')
        ->assertDontSee('fatal: could not load stars')
        ->assertSee('Grace Hopper');
});

it('lists everyone once when the sheet is closed and opened again while they load', function () {
    holdRefreshes();
    $screen = finishRefreshes(ownStarredCommit(2, [[stargazerJson('grace', 'Grace Hopper'), stargazerJson('ada', 'Ada Lovelace')]]));

    $screen->call('openStargazers', 'c1')->call('stargazersClosed')->call('openStargazers', 'c1');
    finishRefreshes($screen);

    expect(childRefs($screen, 'stargazers'))->toBe(['stargazer-grace', 'stargazer-ada'])
        ->and($screen->instance()->loadingStargazers)->toBeFalse();
});

it('opens from the star on your own commit on its page too', function () {
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'stars_count' => 1]))],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
        'GET /api/v1/commits/c2/stars' => [pageJson([stargazerJson('grace', 'Grace Hopper')], path: 'http://devnull.test/api/v1/commits/c2/stars')],
    ]);

    Native::visit('/log/commits/c2')->tap('star-count-c2')->assertSee('1 person starred this commit.')->assertSee('Grace Hopper');
});

it('opens someone who starred it, and the sheet goes', function () {
    ownStarredCommit(1, [[stargazerJson('grace', 'Grace Hopper')]])
        ->tap('star-count-c1')
        ->tap('stargazer-grace')
        ->assertSet('stargazersOf', null)
        ->assertSet('stargazers', [])
        ->assertNavigatedTo('/log/users/grace');
});

it('asks devnull again each time it opens, so new stars show', function () {
    $screen = ownStarredCommit(1, [[stargazerJson('grace', 'Grace Hopper')]])->tap('star-count-c1');

    $screen->call('stargazersClosed')->assertSet('stargazersOf', null);

    expect(nodeByRef($screen, 'stargazers-sheet')['children'] ?? [])->toBe([]);

    $screen->tap('star-count-c1')->assertSee('Grace Hopper');

    expect(sentTo('GET', 'commits/c1/stars'))->toHaveCount(2)
        ->and(childRefs($screen, 'stargazers'))->toBe(['stargazer-grace']);
});

it('opens nothing for a commit of yours with no stars, nor for someone else\'s', function () {
    fakeApi(['GET /api/v1/log/following' => [pageJson([
        commitJson(['id' => 'c1', 'stars_count' => 0]),
        commitJson(['id' => 'c2', 'stars_count' => 4, 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])]),
    ])]]);

    $screen = Native::visit('/log')
        ->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'star-count-c1');

    expect(nodeByRef($screen, 'star-count-c1'))->type->toBe('row')->props->a11y_label->toBe('No stars yet');

    $screen->call('openStargazers', 'c1')->call('openStargazers', 'c2')->assertSet('stargazersOf', null);

    expect(sentTo('GET', 'commits/c1/stars'))->toBe([])
        ->and(sentTo('GET', 'commits/c2/stars'))->toBe([]);
});
