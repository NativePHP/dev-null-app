<?php

use App\Devnull\Data\GuildMembers;

it('reads the members and who moderates', function () {
    $members = GuildMembers::fromArray([
        'data' => [userJson(['id' => 'u1', 'handle' => 'ada']) + ['is_followed' => true]],
        'moderator_id' => 'u1',
        'links' => ['next' => 'https://dev-null.sh/api/v1/guilds/rust/members?page=2'],
    ]);

    expect($members->people->items)->toHaveCount(1)
        ->and($members->people->items[0]->user->username)->toBe('ada')
        ->and($members->people->items[0]->isFollowed)->toBeTrue()
        ->and($members->people->nextCursor)->toBe('2')
        ->and($members->moderatorId)->toBe('u1');
});

it('has no moderator when the answer has none', function () {
    expect(GuildMembers::fromArray(['data' => []])->moderatorId)->toBeNull()
        ->and(GuildMembers::fromArray(['data' => [], 'moderator_id' => null])->moderatorId)->toBeNull();
});
