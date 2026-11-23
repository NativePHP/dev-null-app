<?php

use App\Devnull\Data\Commit;
use App\Devnull\ThreadRows;

/**
 * A reply by $handle to $parent.
 */
function nestedReply(string $id, string $parent, string $handle, array $overrides = []): Commit
{
    return Commit::fromArray(replyJson(['id' => $id, 'parent_id' => $parent, 'author' => userJson(['handle' => $handle, 'lane' => strlen($handle)]), ...$overrides]));
}

/**
 * The rows of a chain five replies deep under c1, with a second reply at the deepest step (r3b), each opened.
 *
 * @return array<string, array<string, mixed>>
 */
function deepRows(int $opensInPlace = PHP_INT_MAX): array
{
    $commit = Commit::fromArray(commitJson(['id' => 'c1', 'author' => userJson(['handle' => 'ada'])]));
    $threads = [
        'c1' => [nestedReply('r1', 'c1', 'grace')],
        'r1' => [nestedReply('r2', 'r1', 'linus')],
        'r2' => [nestedReply('r3', 'r2', 'alan'), nestedReply('r3b', 'r2', 'barbara')],
        'r3' => [nestedReply('r4', 'r3', 'edsger', ['quoted_commit_id' => 'r3'])],
        'r4' => [nestedReply('r5', 'r4', 'ken')],
    ];

    $rows = ThreadRows::under($commit, $threads, ['r4' => 'next'], [], opensInPlace: $opensInPlace, onPage: true);

    return array_column(array_map(fn (array $row): array => [$row['key'], $row['data']], $rows), 1, 0);
}

it('steps replies in up to the web\'s depth of three, and lines deeper ones up under the third', function () {
    $rows = deepRows();

    expect(array_keys($rows))->toBe(['thread-reply-r1', 'thread-reply-r2', 'thread-reply-r3', 'thread-reply-r4', 'thread-reply-r5', 'thread-more-r4', 'thread-reply-r3b'])
        ->and(array_map(fn (array $row): int => $row['indent'], $rows))->toBe([
            'thread-reply-r1' => 0,
            'thread-reply-r2' => 32,
            'thread-reply-r3' => 64,
            'thread-reply-r4' => 64,
            'thread-reply-r5' => 64,
            'thread-more-r4' => 64,
            'thread-reply-r3b' => 64,
        ]);
});

it('says who a flattened reply answers, and draws it no lines of its own', function () {
    $rows = deepRows();

    expect($rows['thread-reply-r3']['replyingTo'])->toBeNull()
        ->and($rows['thread-reply-r3']['links'])->toBeTrue()
        ->and($rows['thread-reply-r4']['replyingTo'])->toBe('quoting @alan')
        ->and($rows['thread-reply-r5']['replyingTo'])->toBe('replying to @edsger')
        ->and($rows['thread-reply-r4']['links'])->toBeFalse()
        ->and($rows['thread-reply-r4']['hasNext'])->toBeFalse()
        ->and($rows['thread-more-r4']['links'])->toBeFalse()
        ->and($rows['thread-more-r4']['to'])->toBe('@edsger');
});

it('hangs each reply at the deepest step on its own elbow, with the line of the reply it answers running on past', function () {
    $rows = deepRows();
    $lane = $rows['thread-reply-r3b']['lane'];

    // r3 and r3b both answer r2, so both hang on elbows off r2's line, which runs past r3's own replies to r3b.
    expect($rows['thread-reply-r3']['previousLane'])->toBeNull()
        ->and($rows['thread-reply-r3b']['previousLane'])->toBeNull()
        ->and($rows['thread-reply-r3']['hasNext'])->toBeFalse()
        ->and($rows['thread-reply-r3']['spines'])->toBe([['left' => 107.5, 'lane' => $lane, 'top' => 22]])
        ->and($rows['thread-reply-r4']['spines'])->toBe([['left' => 107.5, 'lane' => $lane]])
        ->and($rows['thread-more-r4']['spines'])->toBe([['left' => 107.5, 'lane' => $lane]])
        ->and($rows['thread-reply-r3b']['spines'])->toBe([])
        // Above that the replies chain from avatar to avatar as before.
        ->and($rows['thread-reply-r2']['hasNext'])->toBeTrue()
        ->and($rows['thread-reply-r3b']['last'])->toBeTrue();
});

it('opens replies in place as deep as the caller allows', function () {
    $inPlace = fn (array $rows): array => array_map(fn (array $row): bool => $row['repliesInPlace'], array_filter($rows, fn (array $row): bool => isset($row['repliesInPlace'])));

    expect($inPlace(deepRows()))->each->toBeTrue()
        ->and($inPlace(deepRows(opensInPlace: 1)))
        ->toBe(['thread-reply-r1' => true, 'thread-reply-r2' => false, 'thread-reply-r3' => false, 'thread-reply-r4' => false, 'thread-reply-r5' => false, 'thread-reply-r3b' => false]);
});
