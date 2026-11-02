<?php

use App\Devnull\Data\User;
use App\Devnull\ErrorCode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A signed profile with its achievements, as devnull sends them.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function standingJson(array $extra = []): array
{
    return [
        'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1], commits: 12),
        'is_following' => false,
        'follows_you' => true,
        'is_muting' => false,
        'activity' => ['scale' => 'week', 'buckets' => []],
        'streaks' => ['longest' => 4, 'current' => 2],
        'achievements' => [
            'level' => 2,
            'rank' => 'user',
            'xp' => 120,
            'next_level_xp' => 250,
            'level_progress' => 40,
            'xp_breakdown' => [],
            'ranks' => [
                ['rank' => 'guest', 'level' => 1, 'min_xp' => 0, 'description' => 'Just arrived.', 'achieved' => true],
                ['rank' => 'user', 'level' => 2, 'min_xp' => 50, 'description' => 'Has a shell.', 'achieved' => true],
                ['rank' => 'operator', 'level' => 3, 'min_xp' => 250, 'description' => 'Runs things.', 'achieved' => false],
            ],
            'badges' => [
                ['id' => 'init', 'name' => 'init', 'description' => 'Made your first commit.', 'icon' => 'terminal', 'unlocked' => true, 'progress' => 1, 'target' => 1],
                ['id' => 'stdout', 'name' => 'stdout', 'description' => 'Made 100 commits.', 'icon' => 'box', 'unlocked' => false, 'progress' => 12, 'target' => 100],
                ['id' => 'max_payload', 'name' => 'max_payload', 'description' => 'Used every character.', 'icon' => 'maximize', 'unlocked' => false, 'progress' => 0, 'target' => 1],
            ],
        ],
        'pinned_commit' => null,
        ...$extra,
    ];
}

it('shows the Unix rank beside the handle and the achievements shelf, as the web does', function () {
    fakeApi(['GET /api/v1/users/ada' => [standingJson()], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    $screen = Native::visit('/log/users/ada', platform: 'ios')
        ->assertSee('Lv. 2 · user')
        ->assertSee('120 XP')
        ->assertSee('1 / 3 unlocked')
        ->assertSee('120 / 250 XP')
        ->assertSee('130 XP to operator')
        ->assertSee('init')
        ->assertDontSee('(12/100)')
        ->assertDontSee('(0/1)');

    $lane = config('native-ui.theme.light.lane-1');
    $rank = nodeByRef($screen, 'rank');

    expect(childRefs($screen, 'profile-handle'))->toContain('rank')
        ->and(findNode($rank, fn (array $node): bool => ($node['type'] ?? null) === 'text')['props']['color'] ?? null)->toBe($lane)
        // The bar fills 40% of the way in the owner's lane.
        ->and(nodeByRef($screen, 'level-progress')['children'][0]['style']['bg_color'] ?? null)->toBe($lane)
        ->and(array_map(fn (array $badge): string => $badge['ref'], findNodes(nodeByRef($screen, 'badges'), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'badge-'))))->toBe(['badge-init'])
        ->and(findNode(nodeByRef($screen, 'badge-init'), fn (array $node): bool => ($node['type'] ?? null) === 'icon')['props']['name'] ?? null)->toBe('custom:terminal');
});

it('keeps earned badges compact and aligned with the shelf', function () {
    fakeApi(['GET /api/v1/users/ada' => [standingJson()], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    $screen = Native::visit('/log/users/ada', platform: 'ios');
    $horizontal = fn (array $node): array => [($node['layout']['padding'] ?? [0, 0, 0, 0])[1], ($node['layout']['padding'] ?? [0, 0, 0, 0])[3]];

    expect($horizontal(nodeByRef($screen, 'achievements')))->toEqual([0, 0])
        ->and($horizontal(nodeByRef($screen, 'badges')))->toEqual([16, 16])
        ->and(nodeByRef($screen, 'badges')['type'])->toBe('row');
});

it('opens all achievements from the compact shelf even with none earned', function (bool $unlocked, string $label) {
    $json = standingJson();
    foreach ($json['achievements']['badges'] as &$badge) {
        $badge['unlocked'] = $unlocked;
    }
    unset($badge);
    fakeApi(['GET /api/v1/users/ada' => [$json], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    $screen = Native::visit('/log/users/ada')->assertSee($label);
    expect(findNodes(nodeByRef($screen, 'badges'), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'badge-')))->toHaveCount($unlocked ? 2 : 0);

    $screen->tap('badges-more')->assertSet('achievementsOpen', true);
    expect(childRefs($screen, 'sheet-badges'))->toBe(['sheet-badge-init', 'sheet-badge-stdout', 'sheet-badge-max_payload']);
})->with(['earned' => [true, '+1 more'], 'none earned' => [false, 'View achievements']]);

it('opens tagged bio and company accounts on GitHub rather than as devnull users', function (string $ref, string $url) {
    $json = standingJson();
    $json['data']['bio'] = 'Builds @laravel with @nunomaduro.';
    $json['data']['company'] = '@nativephp';
    fakeApi(['GET /api/v1/users/ada' => [$json], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    Native::visit('/log/users/ada')->tap($ref)
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === $url)
        ->assertNoNavigation();
})->with([
    'bio' => ['profile-bio-mention-7', 'https://github.com/laravel'],
    'company' => ['profile-company-text-mention-0', 'https://github.com/nativephp'],
]);

it('ignores profile mention actions for accounts that are not in the bio or company', function () {
    fakeApi(['GET /api/v1/users/ada' => [standingJson()], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);
    Native::visit('/log/users/ada')->call('openGitHubMention', 'nobody')->assertNativeNotCalled('Browser.OpenInApp');
});

it('marks a profile you mute, as the web does', function () {
    fakeApi(['GET /api/v1/users/ada' => [standingJson(['is_muting' => true])], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    Native::visit('/log/users/ada')->assertSee('Muted')->assertSee('Follows you');
});

it('leaves the rank and shelf off a profile devnull sends without achievements', function () {
    $json = standingJson();
    unset($json['achievements']);

    fakeApi(['GET /api/v1/users/ada' => [$json], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    Native::visit('/log/users/ada')
        ->assertDontSee('Lv.')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'achievements');
});

it('says you blocked them, by devnull\'s user_blocked code, in the web\'s words', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [['message' => 'Whatever /dev/null says about it.', 'code' => 'user_blocked'], 403],
        'GET /api/v1/users/ada/commits' => [['message' => 'Whatever /dev/null says about it.', 'code' => 'user_blocked'], 403],
    ]);

    $screen = Native::visit('/log/users/ada', data: ['user' => User::fromArray(userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']))])
        ->assertSet('unavailable', ErrorCode::UserBlocked->value)
        ->assertSee('fatal: @ada is blocked')
        ->assertSee("You blocked Ada Lovelace. Neither of you can follow, reply to, or star the other, or see each other's commits.")
        ->assertSee('Muted and blocked people')
        ->assertDontSee('Whatever /dev/null says')
        ->assertDontSee('Follow');

    $screen->tap('muted-and-blocked')->assertNavigatedTo('/log/safety');

    expect(kept('users/ada'))->toBeNull();
});

it("says only that it isn't available when they blocked you, by devnull's user_unavailable code", function () {
    fakeApi([
        'GET /api/v1/users/ada' => [['message' => 'Something else entirely.', 'code' => 'user_unavailable'], 403],
        'GET /api/v1/users/ada/commits' => [['message' => 'Something else entirely.', 'code' => 'user_unavailable'], 403],
    ]);

    Native::visit('/log/users/ada')
        ->assertSee('fatal: @ada is unavailable')
        ->assertSee("This profile isn't available.")
        ->assertDontSee('Something else entirely.')
        ->assertDontSee('blocked')
        ->assertMissingElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'muted-and-blocked');
});

it('drops a kept profile once devnull refuses it across a block, and shows it again once the block is gone', function () {
    holdRefreshes();
    keep('users/ada', standingJson());
    keep('users/ada/commits', pageJson([commitJson(['id' => 'c1', 'body' => 'Kept commit', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])]));
    serveApi([
        'GET /api/v1/users/ada' => [['message' => 'Blocked.', 'code' => 'user_unavailable'], 403],
        'GET /api/v1/users/ada/commits' => [['message' => 'Blocked.', 'code' => 'user_unavailable'], 403],
    ]);

    $screen = Native::visit('/log/users/ada')->assertSee('Kept commit');

    finishRefreshes($screen)
        ->assertSee("This profile isn't available.")
        ->assertDontSee('Kept commit')
        ->assertDontSee("warning: couldn't refresh");

    expect(kept('users/ada'))->toBeNull()
        ->and(kept('users/ada/commits'))->toBeNull();

    serveApi(['GET /api/v1/users/ada' => [standingJson()], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    finishRefreshes($screen->call('load'))
        ->assertSee('Ada Lovelace')
        ->assertDontSee("This profile isn't available.")
        ->assertSet('unavailable', null);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/users/ada');
});
