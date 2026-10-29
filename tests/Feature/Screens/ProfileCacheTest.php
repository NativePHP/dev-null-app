<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Ada's profile, as `GET /users/ada` sends it.
 *
 * @return array<string, mixed>
 */
function adaProfile(int $followers = 5, bool $following = false): array
{
    return [
        'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'bio' => 'Wrote the first program.', 'lane' => 1], commits: 2, followers: $followers, following: 2),
        'is_following' => $following,
        'activity' => null,
        'streaks' => ['longest' => 0, 'current' => 0],
        'pinned_commit' => null,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adaCommit(array $overrides = []): array
{
    return commitJson(['author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'bio' => 'Wrote the first program.', 'lane' => 1]), ...$overrides]);
}

/**
 * @param  list<array<string, mixed>>  $commits
 */
function serveAda(array $commits, int $followers = 5): void
{
    serveApi([
        'GET /api/v1/users/ada' => [adaProfile($followers)],
        'GET /api/v1/users/ada/commits' => [pageJson($commits)],
        'POST /api/v1/users/ada/follow' => [null, 204],
    ]);
}

it('opens on the profile kept last time, with no request and no spinner, then updates the header in place', function () {
    holdRefreshes();
    keep('users/ada', adaProfile(followers: 5));
    keep('users/ada/commits', pageJson([adaCommit(['id' => 'a1', 'body' => 'Analytical engine notes'])]));
    serveAda([adaCommit(['id' => 'a1', 'body' => 'Analytical engine notes'])], followers: 6);

    $screen = Native::visit('/log/users/ada')
        ->assertSee('Ada Lovelace')
        ->assertSee('Analytical engine notes')
        ->assertSee('followers');

    expect(showsLoading(showingProfile($screen)))->toBeFalse()
        ->and(count(Http::recorded()))->toBe(0);

    finishRefreshes($screen);

    expect($screen->instance()->profile->followersCount)->toBe(6)
        ->and(count(Http::recorded()))->toBe(2);
});

it('opens on what a commit row knew of its author, then fills in the rest', function () {
    holdRefreshes();
    keep('log/following', pageJson([adaCommit(['id' => 'a1', 'body' => 'Analytical engine notes'])]));
    serveAda([adaCommit(['id' => 'a1', 'body' => 'Analytical engine notes'])]);

    $profile = Native::visit('/log')->call('openProfile', 'ada')->follow()
        ->assertSee('Ada Lovelace')
        ->assertSee('@ada')
        ->assertSee('Wrote the first program.')
        ->assertMissingElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'counts')
        ->assertMissingElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'follow')
        ->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-1')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-profile');

    expect(count(Http::recorded()))->toBe(0);

    finishRefreshes($profile)
        ->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'counts')
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'follow')
        ->assertSee('Analytical engine notes');

    expect(showsLoading(showingProfile($profile)))->toBeFalse();
});

it('shows a spinner only the first time, when nothing is known of them', function () {
    holdRefreshes();
    serveAda([]);

    $screen = Native::visit('/log/users/ada')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-profile');

    finishRefreshes($screen)->assertSee('Ada Lovelace')->assertSee("@ada hasn't committed anything yet.");
});

it('holds their new commits in the pill and puts them on top without a request', function () {
    holdRefreshes();
    keep('users/ada', adaProfile());
    keep('users/ada/commits', pageJson([adaCommit(['id' => 'a1', 'body' => 'Older'])]));
    serveAda([adaCommit(['id' => 'a2', 'body' => 'Brand new']), adaCommit(['id' => 'a1', 'body' => 'Older'])]);

    $screen = finishRefreshes(Native::visit('/log/users/ada'))
        ->assertSee('1 new commit')
        ->assertDontSee('Brand new');

    $requests = count(Http::recorded());

    $screen->tap('new-commits')->assertSee('Brand new')->assertDontSee('1 new commit');

    expect(count(Http::recorded()))->toBe($requests);
});

it('hangs the pill under the tabs in the pager, there before it shows', function () {
    holdRefreshes();
    keep('users/ada', adaProfile());
    keep('users/ada/commits', pageJson([adaCommit(['id' => 'a1', 'body' => 'Older'])]));
    serveAda([adaCommit(['id' => 'a2', 'body' => 'Brand new']), adaCommit(['id' => 'a1', 'body' => 'Older'])]);

    $screen = Native::visit('/log/users/ada');
    $overlay = fn (): array => findNode(nodeByRef($screen, 'profile-pager'), fn (array $node): bool => ($node['type'] ?? null) === 'pager_overlay');

    // Hidden and empty until there are new commits, but already in its place, outside the lists.
    expect(findNode($overlay(), fn (array $node): bool => ($node['ref'] ?? null) === 'new-commits-slot'))->not->toBeNull()
        ->and(nodeByRef($screen, 'new-commits-slot')['layout']['display'] ?? null)->toBe(1)
        ->and(nodeByRef($screen, 'new-commits-slot')['children'] ?? [])->toBe([])
        ->and(findNode(showingProfile($screen), fn (array $node): bool => ($node['ref'] ?? null) === 'new-commits-slot'))->toBeNull();

    finishRefreshes($screen)->assertSee('1 new commit');

    expect(nodeByRef($screen, 'new-commits-slot')['layout']['display'] ?? null)->toBeNull()
        ->and(findNode($overlay(), fn (array $node): bool => ($node['ref'] ?? null) === 'new-commits'))->not->toBeNull();
});

it('keeps a follow, so their profile opens followed next time', function () {
    keep('users/ada', adaProfile(followers: 5));
    keep('users/local_dev', ['data' => userWithCountsJson(following: 2), 'is_following' => false]);
    serveAda([]);

    Native::visit('/log/users/ada')->tap('follow')->assertSee('Unfollow');

    expect(kept('users/ada'))->toMatchArray(['is_following' => true])
        ->and(kept('users/ada')['data']['followers_count'])->toBe(6)
        ->and(kept('users/local_dev')['data']['following_count'])->toBe(3);

    holdRefreshes();

    Native::visit('/log/users/ada')->assertSee('Unfollow');
});

it('keeps the profile and says it could not refresh when offline', function () {
    holdRefreshes();
    keep('users/ada', adaProfile());
    keep('users/ada/commits', pageJson([adaCommit(['id' => 'a1', 'body' => 'Still here'])]));
    fakeOffline();

    finishRefreshes(Native::visit('/log/users/ada'))
        ->assertSee('Ada Lovelace')
        ->assertSee('Still here')
        ->assertSee("warning: couldn't refresh")
        ->assertSet('error', null);
});

it('opens the replies tab at once when it was kept', function () {
    holdRefreshes();
    keep('users/ada', adaProfile());
    keep('users/ada/commits', pageJson([adaCommit(['id' => 'a1', 'body' => 'A commit'])]));
    keep('users/ada/commits', pageJson([adaCommit(['id' => 'r1', 'parent_id' => 'c0', 'body' => 'A reply'])]), ['replies' => '1']);

    $screen = tapProfileTab(Native::visit('/log/users/ada'), 'replies')->assertSee('A reply');

    expect(treeShows(showingProfile($screen), 'A commit'))->toBeFalse()
        ->and(showsLoading(showingProfile($screen)))->toBeFalse();
    Http::assertNothingSent();
});

it('asks for the replies tab with its own query', function () {
    serveAda([]);

    tapProfileTab(Native::visit('/log/users/ada'), 'replies');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/users/ada/commits?replies=1');
});
