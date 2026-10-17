<?php

use App\Devnull\Data\Achievements;
use App\Devnull\Data\Badge;
use App\Devnull\Data\Rank;

it('reads every rank with what it is and whether it is reached, for the dialog', function () {
    $achievements = Achievements::fromArray([
        'level' => 2,
        'rank' => 'daemon',
        'xp' => 120,
        'ranks' => [
            ['rank' => 'user', 'level' => 1, 'min_xp' => 0, 'description' => 'Unprivileged user (UID 1000)', 'achieved' => true],
            ['rank' => 'daemon', 'level' => 2, 'min_xp' => 50, 'description' => 'Background process running in user space', 'achieved' => true],
            ['rank' => 'operator', 'level' => 3, 'min_xp' => 150],
            ['rank' => 'broken'],
            'not a rank',
        ],
        'badges' => [
            ['id' => 'init', 'name' => 'init'],
            ['id' => 'stdout', 'name' => 'stdout'],
        ],
    ]);

    expect($achievements->ranks)->toHaveCount(3)
        ->and($achievements->ranks[1])->toEqual(new Rank('daemon', 2, 50, 'Background process running in user space', true))
        ->and($achievements->ranks[2])->toEqual(new Rank('operator', 3, 150))
        ->and($achievements->nextRank()?->rank)->toBe('operator')
        ->and($achievements->badgeIndex('stdout'))->toBe(1)
        ->and($achievements->badgeIndex('nope'))->toBeNull();
});

it('says how far along a badge is, in words and as a whole percentage', function () {
    expect(new Badge('stdout', 'stdout', unlocked: false, progress: 12, target: 50))
        ->statusLabel()->toBe('12 of 50')
        ->progressPercent()->toBe(24)
        ->and(new Badge('init', 'init', unlocked: true, progress: 1, target: 1))
        ->statusLabel()->toBe('unlocked')
        ->progressPercent()->toBe(100)
        ->and(new Badge('pipe', 'pipe', progress: 9, target: 5)->progressPercent())->toBe(100);
});
