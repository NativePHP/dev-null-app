<?php

use App\NativeComponents\Profile;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Someone's profile, as `GET /users/{username}` sends it.
 *
 * @param  array<string, mixed>|null  $pinned
 * @return array<string, mixed>
 */
function pagerProfileJson(string $username = 'ada', ?array $pinned = null): array
{
    return [
        'data' => userWithCountsJson(['id' => $username === 'local_dev' ? userJson()['id'] : 'u2', 'handle' => $username, 'name' => 'Ada Lovelace', 'lane' => 1], commits: 1, followers: 5, following: 2),
        'is_following' => false,
        'activity' => null,
        'streaks' => ['longest' => 0, 'current' => 0],
        'pinned_commit' => $pinned,
    ];
}

/**
 * Keeps a profile with its commits and its replies, so it opens on them.
 *
 * @param  list<array<string, mixed>>  $commits
 * @param  list<array<string, mixed>>  $replies
 * @param  array<string, mixed>|null  $pinned
 */
function keepProfile(string $username, array $commits, array $replies, ?array $pinned = null): void
{
    keep("users/{$username}", pagerProfileJson($username, $pinned));
    keep("users/{$username}/commits", pageJson($commits));
    keep("users/{$username}/commits", pageJson($replies), ['replies' => '1']);
}

/**
 * The list of a tab's page, the one you're on or the other.
 *
 * @return array<string, mixed>
 */
function profileList(TestableComponent $screen, string $tab): array
{
    return nodeByRef($screen, $screen->get('tab') === $tab ? 'profile' : 'profile-'.$tab);
}

it('swipes between commits and replies both ways under the tabs the pager draws, and stops at the ends', function () {
    holdRefreshes();
    keepProfile('ada', [commitJson(['id' => 'c1', 'body' => 'Kept commit'])], [commitJson(['id' => 'r1', 'body' => 'Kept reply'])]);

    $screen = Native::visit('/log/users/ada');
    $pager = fn (): array => nodeByRef($screen, 'profile-pager');

    // The pager draws the tabs, and says where it is once a swipe has settled.
    expect($pager()['props'])->toMatchArray([
        'horizontal' => true,
        'count' => 2,
        'page' => 0,
        'tabs' => ['Commits', 'Replies'],
        'report_on_settle' => true,
    ])->and($pager()['props'])->toHaveKey('on_tab_reselect');

    $screen->changeTab('showPage', 1)->assertSet('tab', 'replies');

    expect($pager()['props']['page'])->toBe(1)
        ->and(treeShows(showingProfile($screen), 'Kept reply'))->toBeTrue();

    // Past the last tab there's nothing.
    $screen->changeTab('showPage', 2)->assertSet('tab', 'replies');

    $screen->changeTab('showPage', 0)->assertSet('tab', 'commits');

    expect($pager()['props']['page'])->toBe(0)
        ->and(treeShows(showingProfile($screen), 'Kept commit'))->toBeTrue();

    $screen->changeTab('showPage', -1)->assertSet('tab', 'commits');
});

it('draws the header once, for the pager to put above its tabs, and hangs the pill under them', function () {
    holdRefreshes();
    keepProfile('ada', [commitJson(['id' => 'c1'])], [commitJson(['id' => 'r1'])]);

    $screen = Native::visit('/log/users/ada');
    $pager = nodeByRef($screen, 'profile-pager');
    $hasRef = fn (string $ref): Closure => fn (array $node): bool => ($node['ref'] ?? null) === $ref;

    expect(array_column($pager['children'], 'type'))->toBe(['pager_header', 'column', 'column', 'pager_overlay'])
        ->and(findNode($pager['children'][0], $hasRef('profile-header')))->not->toBeNull()
        ->and(findNode(showingProfile($screen), $hasRef('profile-header')))->toBeNull()
        ->and(findNode(profileList($screen, 'replies'), $hasRef('profile-header')))->toBeNull()
        ->and(findNode($pager['children'][3], $hasRef('new-commits-slot')))->not->toBeNull();
});

it('keeps the list a tab was drawn in when you swipe to it, and starts the tab you leave again in a fresh one', function () {
    holdRefreshes();
    keepProfile('ada', [commitJson(['id' => 'c1'])], [commitJson(['id' => 'r1'])]);

    $screen = Native::visit('/log/users/ada');
    $commits = profileList($screen, 'commits')['id'];
    $replies = profileList($screen, 'replies')['id'];

    $screen->changeTab('showPage', 1);

    expect(profileList($screen, 'replies')['id'])->toBe($replies)
        ->and(profileList($screen, 'commits')['id'])->not->toBe($commits);

    $commits = profileList($screen, 'commits')['id'];
    $screen->changeTab('showPage', 0);

    expect(profileList($screen, 'commits')['id'])->toBe($commits)
        ->and(profileList($screen, 'replies')['id'])->not->toBe($replies);
});

it('takes the tab you are on back to its top when its tab is tapped, and changes nothing when the pager reports where it is', function () {
    holdRefreshes();
    keepProfile('ada', [commitJson(['id' => 'c1'])], [commitJson(['id' => 'r1'])]);

    $screen = Native::visit('/log/users/ada');
    $pager = nodeByRef($screen, 'profile-pager')['id'];
    $sent = refreshesSent($screen);
    $list = showingProfile($screen)['id'];

    $screen->changeTab('showPage', 0);

    expect(refreshesSent($screen))->toBe($sent)
        ->and(showingProfile($screen)['id'])->toBe($list);

    // The pager sends a tap on the tab you're on rather than moving.
    $screen->changeTab('backToTop', 0)->assertSet('tab', 'commits');

    expect(nodeByRef($screen, 'profile-pager')['id'])->toBe($pager)
        ->and(showingProfile($screen)['id'])->not->toBe($list);

    // A reselect of a tab you're not on can't happen, and moves nothing.
    $list = showingProfile($screen)['id'];
    $screen->changeTab('backToTop', 1)->assertSet('tab', 'commits');

    expect(showingProfile($screen)['id'])->toBe($list);
});

it('shows the first few commits kept for the tab you are not on, and has only the tab you are on ask for its next page', function () {
    holdRefreshes();
    keepProfile(
        'ada',
        [commitJson(['id' => 'c1'])],
        array_map(fn (int $n): array => commitJson(['id' => "r{$n}", 'body' => "Reply {$n}"]), range(1, 10)),
        pinned: commitJson(['id' => 'p1', 'body' => 'Pinned thought']),
    );

    $screen = Native::visit('/log/users/ada');
    $replies = profileList($screen, 'replies');

    expect(treeShows($replies, 'Reply '.Profile::PreviewCommits))->toBeTrue()
        ->and(treeShows($replies, 'Reply '.(Profile::PreviewCommits + 1)))->toBeFalse()
        // A skeleton row stands in for the rest, as for the next page.
        ->and(findNode($replies, fn (array $node): bool => ($node['ref'] ?? null) === 'loading-more'))->not->toBeNull();

    // Pull to refresh stays on both lists: iOS rebuilds a list that gains or loses it, at the top.
    foreach ([showingProfile($screen), $replies] as $list) {
        expect($list['props'])->toHaveKey('on_refresh')
            ->and(array_key_exists('on_end_reached', $list['props']))->toBe($list['ref'] === 'profile');
    }

    // Off to the side, the commits tab keeps its pinned commit on top.
    $screen->changeTab('showPage', 1);

    expect(treeShows(profileList($screen, 'commits'), 'Pinned thought'))->toBeTrue()
        ->and(treeShows(showingProfile($screen), 'Pinned thought'))->toBeFalse();
});

it("pages someone else's profile the same as your own", function () {
    holdRefreshes();
    keepProfile('ada', [commitJson(['id' => 'c1'])], [commitJson(['id' => 'r1'])]);
    keepProfile('local_dev', [commitJson(['id' => 'm1'])], [commitJson(['id' => 'm2'])]);

    $pager = fn (string $path): array => nodeByRef(Native::visit($path), 'profile-pager');
    $props = fn (array $pager): array => array_intersect_key($pager['props'], array_flip(['horizontal', 'count', 'tabs', 'report_on_settle', 'tab_font', 'tab_active_color']));

    expect($props($pager('/log/users/ada')))->toBe($props($pager('/profile')))
        ->and(array_column($pager('/profile')['children'], 'type'))->toBe(['pager_header', 'column', 'column', 'pager_overlay']);
});
