<?php

use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Ada's signed profile, with the ranks and badges devnull sends: one unlocked, one on its way, one not started.
 *
 * @return array<string, mixed>
 */
function achievementsProfileJson(): array
{
    return [
        'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1], commits: 12),
        'is_following' => false,
        'follows_you' => false,
        'is_muting' => false,
        'activity' => ['scale' => 'week', 'buckets' => []],
        'streaks' => ['longest' => 4, 'current' => 2],
        'achievements' => [
            'level' => 2,
            'rank' => 'daemon',
            'xp' => 120,
            'next_level_xp' => 150,
            'level_progress' => 70,
            'xp_breakdown' => [],
            'ranks' => [
                ['rank' => 'user', 'level' => 1, 'min_xp' => 0, 'description' => 'Unprivileged user (UID 1000)', 'achieved' => true],
                ['rank' => 'daemon', 'level' => 2, 'min_xp' => 50, 'description' => 'Background process running in user space', 'achieved' => true],
                ['rank' => 'operator', 'level' => 3, 'min_xp' => 150, 'description' => 'System operator with elevated permissions', 'achieved' => false],
            ],
            'badges' => [
                ['id' => 'init', 'name' => 'init', 'description' => 'Piped your first commit to /dev/null.', 'icon' => 'terminal', 'unlocked' => true, 'progress' => 1, 'target' => 1],
                ['id' => 'stdout', 'name' => 'stdout', 'description' => 'Reached 50 commits piped to /dev/null.', 'icon' => 'box', 'unlocked' => false, 'progress' => 12, 'target' => 50],
                ['id' => 'max_payload', 'name' => 'max payload', 'description' => 'Committed an exact 280-character post.', 'icon' => 'maximize', 'unlocked' => false, 'progress' => 0, 'target' => 1],
                ['id' => 'authorized_keys', 'name' => 'authorized_keys', 'description' => 'Provisioned a public SSH key from GitHub.', 'icon' => 'key', 'unlocked' => false, 'progress' => 0, 'target' => 1],
            ],
        ],
        'pinned_commit' => null,
    ];
}

function adaWithAchievements(): TestableComponent
{
    fakeApi(['GET /api/v1/users/ada' => [achievementsProfileJson()], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    return Native::visit('/log/users/ada', platform: 'ios');
}

/**
 * The refs of the badge cards in the sheet whose border is drawn in Ada's lane.
 *
 * @return list<string>
 */
function markedBadges(TestableComponent $screen): array
{
    $lane = config('native-ui.theme.light.lane-1');
    $cards = findNodes(nodeByRef($screen, 'sheet-badges'), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'sheet-badge-') && ! str_contains($node['ref'], 'progress'));

    return array_values(array_map(fn (array $card): string => $card['ref'], array_filter($cards, fn (array $card): bool => ($card['style']['border_color'] ?? null) === $lane)));
}

it('keeps the sheet down and empty until the shelf is tapped', function () {
    $screen = adaWithAchievements();

    expect(nodeByRef($screen, 'achievements-sheet')['props']['visible'])->toBeFalse()
        ->and(nodeByRef($screen, 'achievements-sheet')['children'] ?? [])->toBe([]);
});

it('opens the web\'s achievements dialog from the shelf, with the rank, the XP and every badge', function () {
    $screen = adaWithAchievements()->tap('achievements-open');

    $screen->assertSet('achievementsOpen', true)
        ->assertSee('Developer Achievements')
        ->assertSee('@ada · 120 XP · 1/4 badges unlocked')
        ->assertSee('120 / 150 XP')
        ->assertSee('30 XP to operator')
        ->assertSee('Badges (4)')
        ->assertSee('Unix Ranks (3)')
        ->assertSee('Reached 50 commits piped to /dev/null.')
        ->assertSee('12/50')
        ->assertSee('0/1')
        ->assertSee('XP: 10/commit · 15/day streak · 5/star received · 5/reply · 2/star given')
        ->assertDontSee('CURRENT');

    $lane = config('native-ui.theme.light.lane-1');

    expect(nodeByRef($screen, 'achievements-sheet')['props'])->toMatchArray(['visible' => true, 'detents' => 'large'])
        ->and(nodeByRef($screen, 'achievements-scroll')['props']['scroll_anchor'])->toBe('top')
        ->and(findNode(nodeByRef($screen, 'sheet-rank'), fn (array $node): bool => ($node['type'] ?? null) === 'text')['props']['color'] ?? null)->toBe($lane)
        ->and(nodeByRef($screen, 'sheet-level-progress')['children'][0]['style']['bg_color'] ?? null)->toBe($lane)
        ->and(childRefs($screen, 'sheet-badges'))->toBe(['sheet-badge-init', 'sheet-badge-stdout', 'sheet-badge-max_payload', 'sheet-badge-authorized_keys'])
        ->and(findNode(nodeByRef($screen, 'sheet-badge-stdout'), fn (array $node): bool => ($node['type'] ?? null) === 'icon')['props']['name'] ?? null)->toBe('custom:box')
        ->and(markedBadges($screen))->toBe([]);

    // A tick on the unlocked badge, and a bar only under a locked one that takes more than one step.
    expect(treeShows(nodeByRef($screen, 'sheet-badge-init'), '✓'))->toBeTrue()
        ->and(findNodes(nodeByRef($screen, 'sheet-badges'), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'sheet-badge-progress-')))->toHaveCount(1)
        ->and(nodeByRef($screen, 'sheet-badge-progress-stdout')['children'][0]['layout']['flex_grow'] ?? null)->toEqual(24);
});

it('draws a badge with the web\'s icon for it, not the terminal', function (string $id, string $icon) {
    $json = achievementsProfileJson();
    $json['achievements']['badges'][] = ['id' => $id, 'name' => $id, 'description' => '', 'icon' => $icon, 'unlocked' => false, 'progress' => 0, 'target' => 1];

    fakeApi(['GET /api/v1/users/ada' => [$json], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    $screen = Native::visit('/log/users/ada', platform: 'ios')->tap('achievements-open');

    expect(findNode(nodeByRef($screen, "sheet-badge-{$id}"), fn (array $node): bool => ($node['type'] ?? null) === 'icon')['props']['name'] ?? null)->toBe("custom:{$icon}");
})->with([
    'sweet tooth' => ['sweet_tooth', 'cookie'],
    'luddite' => ['luddite', 'bot-off'],
    'shell shocked' => ['shell_shocked', 'bomb'],
    'super-saiyan' => ['super_saiyan', 'dragon-ball'],
]);

it('marks the badge it was opened on in the owner\'s lane', function () {
    $screen = adaWithAchievements();

    expect(nodeByRef($screen, 'badge-init')['props']['a11y_label'])->toBe('init, unlocked. Piped your first commit to /dev/null.');

    $screen->tap('badge-init');

    $screen->assertSet('achievementsBadge', 'init');

    expect(markedBadges($screen))->toBe(['sheet-badge-init'])
        ->and(nodeByRef($screen, 'achievements-scroll')['props']['scroll_anchor'])->toBe('top');
});

it('opens at the end of the list on a badge in its second half, where that badge is in view', function () {
    $screen = adaWithAchievements()->call('openAchievements', 'max_payload');

    expect(markedBadges($screen))->toBe(['sheet-badge-max_payload'])
        ->and(nodeByRef($screen, 'achievements-scroll')['props']['scroll_anchor'])->toBe('bottom');
});

it('marks nothing for a badge the profile doesn\'t have', function () {
    $screen = adaWithAchievements()->call('openAchievements', 'nope');

    $screen->assertSet('achievementsOpen', true)->assertSet('achievementsBadge', null);

    expect(markedBadges($screen))->toBe([]);
});

it('lists the Unix ranks with the XP each needs, the current one in the lane', function () {
    $screen = adaWithAchievements()->call('openAchievements', 'max_payload')->tap('achievements-tab-ranks');

    $screen->assertSet('achievementsTab', 'ranks')
        ->assertSee('Background process running in user space')
        ->assertSee('CURRENT')
        ->assertSee('✓ Achieved')
        ->assertSee('Requires 150 XP')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-badges');

    $lane = config('native-ui.theme.light.lane-1');

    expect(childRefs($screen, 'sheet-ranks'))->toBe(['sheet-rank-1', 'sheet-rank-2', 'sheet-rank-3'])
        ->and(nodeByRef($screen, 'sheet-rank-2')['style']['border_color'] ?? null)->toBe($lane)
        ->and(nodeByRef($screen, 'sheet-rank-1')['style']['border_color'] ?? null)->not->toBe($lane)
        // A fresh scroll view at the top, even though the badges opened at the end.
        ->and(nodeByRef($screen, 'achievements-scroll')['props']['scroll_anchor'])->toBe('top');
});

it('forgets the badge and the tab once the sheet has gone, and opens on the badges again', function () {
    $screen = adaWithAchievements()->tap('badge-init')->tap('achievements-tab-ranks')->dismissSheet('achievements-sheet');

    $screen->assertSet('achievementsOpen', false)
        ->assertSet('achievementsBadge', null)
        ->assertSet('achievementsTab', 'badges');

    expect(nodeByRef($screen, 'achievements-sheet')['children'] ?? [])->toBe([]);

    $screen->tap('achievements-open')->assertSee('Reached 50 commits piped to /dev/null.');

    expect(markedBadges($screen))->toBe([]);
});

it('opens nothing on a profile devnull sent without achievements', function () {
    $json = achievementsProfileJson();
    unset($json['achievements']);

    fakeApi(['GET /api/v1/users/ada' => [$json], 'GET /api/v1/users/ada/commits' => [pageJson([])]]);

    Native::visit('/log/users/ada')->call('openAchievements')->assertSet('achievementsOpen', false);
});
