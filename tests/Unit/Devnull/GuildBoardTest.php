<?php

use App\Devnull\Data\GuildBoard;

it('reads a guild board with its commits, pinned commit and next page', function () {
    $board = GuildBoard::fromArray(boardJson());

    expect($board->guild->handle)->toBe('rust')
        ->and($board->commits->items)->toHaveCount(2)
        ->and($board->commits->nextCursor)->toBe('2')
        ->and($board->pinned->id)->toBe('c2');
});

it('has no pinned commit and no next page when the answer says so', function () {
    $board = GuildBoard::fromArray(boardJson(['pinned_commit' => null, 'links' => []]));

    expect($board->pinned)->toBeNull()
        ->and($board->commits->nextCursor)->toBeNull();
});

it('is nothing when the guild is missing', function () {
    expect(GuildBoard::fromArray(boardJson(['guild' => null])))->toBeNull();
});
