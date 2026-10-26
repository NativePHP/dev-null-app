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
 * A `GET /users/{username}` response, as the API sends it.
 *
 * @param  array<string, mixed>  $user
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function profileJson(bool $isFollowing = false, array $user = [], array $extra = []): array
{
    return [
        'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'bio' => 'Wrote the first program.', 'lane' => 1, ...$user], commits: 1, followers: 5, following: 2),
        'is_following' => $isFollowing,
        'activity' => ['scale' => 'week', 'buckets' => array_map(fn (int $day): array => ['date' => now()->subDays(6 - $day)->toDateString(), 'count' => 0], range(0, 6))],
        'streaks' => ['longest' => 0, 'current' => 0],
        'pinned_commit' => null,
        ...$extra,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adaCommitJson(array $overrides = []): array
{
    return commitJson(['author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1]), ...$overrides]);
}

it('shows who they are, their counts and their commits', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson(['body' => 'Notes on the engine', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])])],
    ]);

    Native::visit('/log/users/ada')
        ->assertNavTitle('@ada')
        ->assertSee('Ada Lovelace')
        ->assertSee('@ada')
        ->assertSee('Wrote the first program.')
        ->assertSee('1')->assertSee('commit')
        ->assertSee('5')->assertSee('followers')
        ->assertSee('2')->assertSee('following')
        ->assertSee('Follow')
        ->assertSee('Notes on the engine')
        ->assertSee("That's @ada's root commit.");
});

it("shows Reply as just its icon beside a commit's replies, so the row still fits a phone", function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([
            adaCommitJson(['id' => 'c1', 'replies_count' => 3]),
            adaCommitJson(['id' => 'c2', 'replies_count' => 0]),
        ])],
    ]);

    $screen = Native::visit('/log/users/ada')->assertSee('3 replies');
    $labels = fn (string $ref): array => findNodes(nodeByRef($screen, $ref), fn (array $node): bool => ($node['type'] ?? null) === 'text');

    expect(nodeByRef($screen, 'reply-to-c1')['props']['a11y_label'])->toBe('Reply')
        ->and($labels('reply-to-c1'))->toBe([])
        ->and($labels('reply-to-c2'))->toHaveCount(1);
});

it('lists what GitHub says about them under the bio, as the web does', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson(user: [
            'company' => 'Analytical Engines',
            'location' => 'London',
            'website' => 'https://ada.example/',
            'github_url' => 'https://github.com/ada',
            'created_at' => '2026-09-01T08:00:00Z',
        ])],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    Native::visit('/log/users/ada')
        ->assertSee('Analytical Engines')
        ->assertSee('London')
        ->assertSee('ada.example')
        ->assertSee('github.com/ada')
        ->assertSee('Joined September 2026')
        ->tap('profile-website')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://ada.example/')
        ->tap('profile-github')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/ada');
});

it('leaves out what GitHub does not say, and opens a website given without its scheme', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson(user: ['company' => null, 'location' => null, 'website' => 'ada.example'])],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    Native::visit('/log/users/ada')
        ->assertMissingElement('row', fn (array $node): bool => in_array($node['ref'] ?? null, ['profile-company', 'profile-location'], true))
        ->assertSee('github.com/ada')
        ->tap('profile-website')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://ada.example');
});

it('rings the avatar in the author lane', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson(user: ['lane' => 5])],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    Native::visit('/log/users/ada')
        ->assertElement('stack', fn (array $node): bool => ($node['layout']['width'] ?? null) === 92.0
            && ($node['style']['bg_color'] ?? null) === config('native-ui.theme.light.lane-5'))
        ->assertSee('fatal: no commits yet');
});

it('follows and unfollows', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
        'POST /api/v1/users/ada/follow' => [null, 204],
        'DELETE /api/v1/users/ada/follow' => [null, 204],
    ]);

    Native::visit('/log/users/ada')
        ->tap('follow')
        ->assertSee('Unfollow')
        ->assertSee('6')
        ->tap('follow')
        ->assertSee('Follow')
        ->assertSee('5');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://devnull.test/api/v1/users/ada/follow');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/users/ada/follow');
});

it('puts the follow back when the server refuses', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
        'POST /api/v1/users/ada/follow' => [['message' => 'Too Many Attempts.'], 429],
    ]);

    $screen = Native::visit('/log/users/ada')->tap('follow')->assertSee('Too Many Attempts.');

    expect($screen->instance()->profile->isFollowing)->toBeFalse()
        ->and($screen->instance()->profile->followersCount)->toBe(5);
});

it('has no follow button on your own profile', function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [profileJson(user: ['handle' => 'local_dev'])],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
    ]);

    Native::visit('/log/users/local_dev')
        ->assertMissingElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'follow')
        ->assertSee('fatal: you do not have any commits yet');
});

it('loads older commits by cursor', function () {
    Http::fake(function (Request $request) {
        return match (true) {
            str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/users/ada') => Http::response(profileJson()),
            str_contains($request->url(), 'cursor=older') => Http::response(pageJson([commitJson(['id' => 'c1', 'body' => 'Older'])])),
            default => Http::response(pageJson([commitJson(['id' => 'c2', 'body' => 'Newer'])], 'older')),
        };
    });

    Native::visit('/log/users/ada')
        ->assertDontSee('Older')
        ->call('loadMore')
        ->assertSee('Older');
});

it('reloads when you come back to it', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson(['id' => 'c1'])])],
        'GET /api/v1/commits/c1' => [commitPageJson(commitJson(['id' => 'c1']))],
        'GET /api/v1/commits/c1/replies' => [pageJson([])],
    ]);

    Native::visit('/log/users/ada')
        ->tap('commit-c1')
        ->follow()
        ->goBack();

    Http::assertSentCount(6);
});

it('shows the pinned commit above the rest, marked as pinned', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson(extra: ['pinned_commit' => adaCommitJson(['id' => 'p1', 'body' => 'Read this first', 'is_pinned' => true])])],
        'GET /api/v1/users/ada/commits' => [pageJson([adaCommitJson(['id' => 'c1', 'body' => 'Everything else'])])],
    ]);

    $screen = Native::visit('/log/users/ada')
        ->assertSee('Pinned')
        ->assertSee('Read this first')
        ->assertSee('Everything else')
        ->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'pinned');

    $texts = json_encode($screen->tree(), JSON_UNESCAPED_UNICODE);
    expect(strpos($texts, 'Pinned'))->toBeLessThan(strpos($texts, 'Read this first'))
        ->and(strpos($texts, 'Read this first'))->toBeLessThan(strpos($texts, 'Everything else'));

    $screen->tap('commit-p1')->assertNavigatedTo('/log/commits/p1');
});

it('shows a profile with only a pinned commit without the empty state', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson(extra: ['pinned_commit' => adaCommitJson(['id' => 'p1', 'body' => 'All there is'])])],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    Native::visit('/log/users/ada')
        ->assertSee('All there is')
        ->assertDontSee('fatal: no commits yet')
        ->assertSee("That's @ada's root commit.");
});

it('switches between commits and replies like the web profile', function () {
    // Like devnull, this answers ?replies=1 with replies and ignores anything else, such as the old ?tab=replies.
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match (true) {
            $path === '/api/v1/users/ada' => Http::response(profileJson(extra: ['pinned_commit' => adaCommitJson(['id' => 'p1', 'body' => 'Pinned thought'])])),
            ($query['replies'] ?? null) === '1' => Http::response(pageJson([adaCommitJson([
                'id' => 'r1',
                'parent_id' => 'c9',
                'body' => 'Agreed',
                'parent' => commitJson(['id' => 'c9', 'author' => userJson(['handle' => 'grace'])]),
            ])])),
            default => Http::response(pageJson([adaCommitJson(['id' => 'c1', 'body' => 'A top-level commit'])])),
        };
    });

    $screen = Native::visit('/log/users/ada')
        ->assertSet('tab', 'commits')
        ->assertSee('A top-level commit');

    expect(nodeByRef($screen, 'profile-pager')['props'])->toMatchArray(['tabs' => ['Commits', 'Replies'], 'page' => 0]);

    tapProfileTab($screen, 'replies')
        ->assertSet('tab', 'replies')
        ->assertSee('Agreed')
        ->assertSee('replying to @grace')
        ->assertSee("That's @ada's first reply.");

    expect(nodeByRef($screen, 'profile-pager')['props']['page'])->toBe(1)
        ->and(treeShows(showingProfile($screen), 'A top-level commit'))->toBeFalse()
        // The web keeps the pinned commit to the commits tab.
        ->and(treeShows(showingProfile($screen), 'Pinned thought'))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/users/ada/commits?replies=1');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/users/ada/commits');

    $screen->tap('replying-to-r1')->assertNavigatedTo('/log/commits/c9');
});

it('loads older replies on the replies tab', function () {
    Http::fake(function (Request $request) {
        return match (true) {
            str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/users/ada') => Http::response(profileJson()),
            str_contains($request->url(), 'replies=1&cursor=older') => Http::response(pageJson([adaCommitJson(['id' => 'r1', 'body' => 'Older reply'])])),
            str_contains($request->url(), 'replies=1') => Http::response(pageJson([adaCommitJson(['id' => 'r2', 'body' => 'Newer reply'])], 'older', 'http://devnull.test/api/v1/users/ada/commits', 'replies=1')),
            default => Http::response(pageJson([])),
        };
    });

    tapProfileTab(Native::visit('/log/users/ada'), 'replies')->call('loadMore')->assertSee('Older reply');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/users/ada/commits?replies=1&cursor=older');
});

it('speaks git when there are no replies yet', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
        'GET /api/v1/users/local_dev' => [profileJson(user: ['handle' => 'local_dev'])],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
    ]);

    tapProfileTab(Native::visit('/log/users/ada'), 'replies')
        ->assertSee('fatal: no replies yet')
        ->assertSee("@ada hasn't replied to anything yet.");

    tapProfileTab(Native::visit('/log/users/local_dev'), 'replies')
        ->assertSee('Your replies show up here. Join a conversation from your log.');
});

it('prints the streaks and an activity strip in the owner lane', function () {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

    fakeApi([
        'GET /api/v1/users/ada' => [profileJson(extra: [
            'activity' => ['scale' => 'week', 'buckets' => array_map(fn (int $day): array => [
                'date' => now()->subDays(6 - $day)->toDateString(),
                'count' => [0, 0, 1, 0, 2, 4, 1][$day],
            ], range(0, 6))],
            'streaks' => ['longest' => 3, 'current' => 2],
        ])],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    $lane = config('native-ui.theme.light.lane-1');

    $screen = Native::visit('/log/users/ada')
        ->assertSee('Commit activity')
        ->assertSee('8')
        ->assertSee(' commits in the last 7 days')
        ->assertSee('Fri 9')
        ->assertSee('Thu 15')
        ->assertSee('longest streak ')
        ->assertSee('3')
        ->assertSee(' days')
        ->assertSee('current ')
        ->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'streaks');

    $cells = [];
    $walk = function (array $node) use (&$walk, &$cells): void {
        if (($node['ref'] ?? null) === 'activity-strip') {
            $cells = $node['children'];

            return;
        }
        foreach ($node['children'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk($screen->tree());

    // Seven days, the busiest at full strength in Ada's lane and the empty ones on the wash.
    expect($cells)->toHaveCount(7)
        ->and($cells[0]['style']['bg_color'])->toBe(config('native-ui.theme.light.wash'))
        ->and($cells[5]['children'][0]['style']['bg_color'])->toBe($lane)
        ->and($cells[5]['children'][0]['style']['opacity'] ?? 1.0)->toEqual(1.0)
        ->and($cells[2]['children'][0]['style']['opacity'])->toEqual(0.32);
});

it('says no commits yet over an empty strip and leaves out the streaks', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    Native::visit('/log/users/ada')
        ->assertSee('No commits yet')
        ->assertDontSee('longest streak ')
        ->assertMissingElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'streaks');
});

it('shows your own profile on the Profile tab', function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [profileJson(user: ['id' => userJson()['id'], 'handle' => 'local_dev', 'name' => 'Local Dev'])],
        'GET /api/v1/users/local_dev/commits' => [pageJson([commitJson(['body' => 'My own commit'])])],
    ]);

    Native::visit('/profile')
        ->assertHasTabBar()
        ->assertTabActive('Profile')
        ->assertNavTitle('@local_dev')
        ->assertSet('username', 'local_dev')
        ->assertSee('Local Dev')
        ->assertSee('My own commit')
        ->assertElement('pager', fn (array $node): bool => ($node['props']['tabs'] ?? null) === ['Commits', 'Replies'])
        ->assertSee('That was your first commit. Everyone starts somewhere.')
        ->assertMissingElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'follow')
        // Where the web puts "Edit on GitHub" in place of Follow.
        ->tap('edit-on-github')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/settings/profile');
});

it('leads from your own profile to your account with the Settings2 icon', function () {
    fakeApi([
        'GET /api/v1/users/local_dev' => [profileJson(user: ['handle' => 'local_dev'])],
        'GET /api/v1/users/local_dev/commits' => [pageJson([])],
        'GET /api/v1/users/ada' => [profileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    $action = fn (TestableComponent $screen): ?array => findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action' && ($node['props']['id'] ?? null) === 'account');

    $screen = Native::visit('/profile', platform: 'ios');

    expect($action($screen)['props'] ?? null)->toMatchArray(['icon' => 'custom:settings-2', 'a11y_label' => 'Account'])
        ->and($action(Native::visit('/profile', platform: 'android'))['props']['icon'] ?? null)->toBe('custom:settings-2')
        ->and($action(Native::visit('/log/users/ada')))->toBeNull();

    $screen->press('openAccount')->assertNavigatedTo('/profile/device');
});

it('lets a drag that starts on a commit scroll the profile', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [profileJson()],
        'GET /api/v1/users/ada/commits' => [pageJson([adaCommitJson(['id' => 'c1'])])],
    ]);

    $screen = Native::visit('/log/users/ada', platform: 'ios');

    nodeByRef($screen, 'commit-c1');

    expect(pressFeedbackInScroller($screen))->toBe([]);
});

it('opens your profile on skeleton rows, and fills in what it kept straight after, so a tab tap shows at once', function () {
    config(['devnull.skeleton_first' => true]);
    holdRefreshes();
    $profile = ['data' => userWithCountsJson(['name' => 'Kept Name']), 'is_following' => false, 'activity' => ['scale' => 'week', 'buckets' => []], 'streaks' => ['longest' => 0, 'current' => 0], 'pinned_commit' => null];
    keep('users/local_dev', $profile);
    keep('users/local_dev/commits', pageJson([commitJson(['id' => 'c1', 'body' => 'Kept commit'])]));
    serveApi([
        'GET /api/v1/users/local_dev' => [$profile],
        'GET /api/v1/users/local_dev/commits' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Kept commit'])])],
    ]);

    $screen = Native::visit('/profile')->assertSet('skeletonFrame', true)->assertDontSee('Kept Name')->assertDontSee('Kept commit');

    expect(showsLoading($screen->tree()))->toBeTrue();

    finishRefreshes($screen)->assertSet('skeletonFrame', false)->assertSee('Kept Name')->assertSee('Kept commit');
});
