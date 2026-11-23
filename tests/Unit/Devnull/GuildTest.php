<?php

use App\Devnull\Data\Guild;

it('reads a guild as the directory sends it', function () {
    $guild = Guild::fromArray([
        'handle' => 'rust',
        'name' => 'Rust',
        'description' => 'Crabs.',
        'emoji' => '🦀',
        'icon_url' => 'https://dev-null.sh/guilds/rust/icon?v=1',
        'member_count' => 12,
        'is_joined' => true,
        'is_muted' => false,
        'is_paused' => false,
        'html_url' => 'https://dev-null.sh/guilds/rust',
    ]);

    expect($guild->handle)->toBe('rust')
        ->and($guild->name)->toBe('Rust')
        ->and($guild->description)->toBe('Crabs.')
        ->and($guild->emoji)->toBe('🦀')
        ->and($guild->memberCount)->toBe(12)
        ->and($guild->isJoined)->toBeTrue()
        ->and($guild->isPaused)->toBeFalse()
        ->and($guild->htmlUrl)->toBe('https://dev-null.sh/guilds/rust')
        ->and($guild->hashtag())->toBe('#rust');
});

it('names a guild by its hashtag when the name is missing, and rejects one without a handle', function () {
    expect(Guild::fromArray(['handle' => 'rust'])->name)->toBe('#rust')
        ->and(Guild::fromArray(['handle' => 'rust', 'name' => ''])->name)->toBe('#rust')
        ->and(Guild::fromArray(['name' => 'Rust']))->toBeNull()
        ->and(Guild::fromArray(['handle' => '', 'name' => 'Rust']))->toBeNull()
        ->and(Guild::fromArray(['handle' => 'rust', 'member_count' => -3])->memberCount)->toBe(0);
});

it('keeps the icon url devnull sent', function () {
    $url = 'https://devnull.test/api/v1/guilds/rust/icon?expires='.(time() + 3600).'&signature=x';

    expect(Guild::fromArray(['handle' => 'rust', 'icon_url' => $url])->iconUrl)->toBe($url)
        ->and(Guild::fromArray(['handle' => 'go', 'icon_url' => ''])->iconUrl)->toBeNull()
        ->and(Guild::fromArray(['handle' => 'php'])->iconUrl)->toBeNull();
});

it('takes a lane colour from its handle, the same whatever its case', function () {
    expect(Guild::fromArray(['handle' => 'android'])->lane())->toBe(3)
        ->and(Guild::fromArray(['handle' => 'Android'])->lane())->toBe(3)
        ->and(Guild::fromArray(['handle' => 'apple'])->lane())->toBe(2)
        ->and(Guild::fromArray(['handle' => 'nativephp'])->lane())->toBe(5);
});
