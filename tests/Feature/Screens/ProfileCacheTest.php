<?php

use App\Devnull\Data\Commit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

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

/**
 * The ids of the commits on the profile's tab, top first.
 *
 * @return list<string>
 */
function profileCommitIds(TestableComponent $screen): array
{
    return array_map(fn (Commit $commit): string => $commit->id, $screen->instance()->commits);
}

function showsNewCommitsPill(TestableComponent $screen): bool
{
    return findNode($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['new-commits', 'new-commits-slot'], true)) !== null;
}

it('never shows a new commits pill, and puts new commits on top when it opens', function () {
    holdRefreshes();
    keep('users/ada', adaProfile());
    keep('users/ada/commits', pageJson([adaCommit(['id' => 'a1', 'body' => 'Older'])]));
    serveAda([adaCommit(['id' => 'a2', 'body' => 'Brand new']), adaCommit(['id' => 'a1', 'body' => 'Older'])]);

    $screen = Native::visit('/log/users/ada');

    expect(showsNewCommitsPill($screen))->toBeFalse();

    $listKey = $screen->get('listKey');

    finishRefreshes($screen)->assertSee('Brand new')->assertDontSee('1 new commit');

    expect(profileCommitIds($screen))->toBe(['a2', 'a1'])
        ->and(showsNewCommitsPill($screen))->toBeFalse()
        // The same list, so rows already showing keep their place.
        ->and($screen->get('listKey'))->toBe($listKey);
});

it('holds new commits out of sight when you come back to it, until you pull to refresh', function () {
    holdRefreshes();
    keep('users/ada', adaProfile());
    serveAda([adaCommit(['id' => 'a1', 'body' => 'Older'])]);

    $screen = finishRefreshes(Native::visit('/log/users/ada'));

    // Back from a commit you may be anywhere in the list, and rows put above the ones you're reading would push them down.
    serveAda([adaCommit(['id' => 'a2', 'body' => 'Brand new']), adaCommit(['id' => 'a1', 'body' => 'Older'])]);
    finishRefreshes($screen->call('onResume'))->assertDontSee('Brand new')->assertDontSee('1 new commit');

    expect(profileCommitIds($screen))->toBe(['a1'])
        ->and(showsNewCommitsPill($screen))->toBeFalse();

    $listKey = $screen->get('listKey');

    finishRefreshes($screen->call('load'))->assertSee('Brand new');

    expect(profileCommitIds($screen))->toBe(['a2', 'a1'])
        ->and($screen->get('listKey'))->toBe($listKey);
});

it('holds new commits once the list has asked for its next page', function () {
    holdRefreshes();
    keep('users/ada', adaProfile());
    serveAda([adaCommit(['id' => 'a1', 'body' => 'Older'])]);

    $screen = finishRefreshes(Native::visit('/log/users/ada'))->call('loadMore');

    serveAda([adaCommit(['id' => 'a2', 'body' => 'Brand new']), adaCommit(['id' => 'a1', 'body' => 'Older'])]);
    finishRefreshes($screen->call('retry'))->assertDontSee('Brand new');

    expect(profileCommitIds($screen))->toBe(['a1']);
});

it('puts held commits on top when you tap the tab you are on, which goes back to the top', function () {
    holdRefreshes();
    keep('users/ada', adaProfile());
    serveAda([adaCommit(['id' => 'a1', 'body' => 'Older'])]);

    $screen = finishRefreshes(Native::visit('/log/users/ada'));

    serveAda([adaCommit(['id' => 'a2', 'body' => 'Brand new']), adaCommit(['id' => 'a1', 'body' => 'Older'])]);
    finishRefreshes($screen->call('onResume'))->assertDontSee('Brand new');

    $listKey = $screen->get('listKey');
    $requests = count(Http::recorded());

    tapProfileTab($screen, 'commits')->assertSee('Brand new');

    expect(profileCommitIds($screen))->toBe(['a2', 'a1'])
        ->and($screen->get('listKey'))->toBe($listKey + 1)
        ->and(count(Http::recorded()))->toBe($requests);
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
