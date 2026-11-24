<?php

use App\Devnull\Data\Commit;
use App\Devnull\SelfThread;

/**
 * A commit by $handle, $minutes ago, replying to $parent.
 *
 * @param  array<string, mixed>  $overrides
 */
function threadCommit(string $id, string $handle = 'ada', ?string $parent = null, int $minutes = 10, array $overrides = []): Commit
{
    return Commit::fromArray(commitJson([
        'id' => $id,
        'parent_id' => $parent,
        'author' => userJson(['handle' => $handle]),
        'created_at' => now()->subMinutes($minutes)->toIso8601ZuluString(),
        ...$overrides,
    ]));
}

/**
 * @param  array<string, list<Commit>>  $replies
 * @return Closure(Commit): (list<Commit>|null)
 */
function repliesFrom(array $replies): Closure
{
    return fn (Commit $commit): ?array => $replies[$commit->id] ?? null;
}

it("carries a commit on with its author's earliest reply to it", function () {
    $commit = threadCommit('c1', minutes: 30);
    $replies = [
        threadCommit('r1', 'grace', 'c1', 25),
        threadCommit('p3', 'ada', 'c1', 10),
        threadCommit('p2', 'ada', 'c1', 20),
    ];

    expect(SelfThread::next($commit, $replies)?->id)->toBe('p2')
        ->and(array_map(fn (Commit $reply): string => $reply->id, SelfThread::others($commit, $replies)))->toBe(['r1', 'p3']);
});

it('never carries a commit on with a deleted reply, a quote, or a reply still on its way', function () {
    $commit = threadCommit('c1', minutes: 30);

    expect(SelfThread::next($commit, [
        Commit::fromArray(['id' => 'gone', 'parent_id' => 'c1', 'deleted_at' => now()->toIso8601ZuluString()]),
        threadCommit('quote', 'ada', null, 20, ['quoted_commit_id' => 'c1']),
        threadCommit('staged', 'ada', 'c1', 1, ['status' => Commit::Staged]),
        threadCommit('r1', 'grace', 'c1', 15),
    ]))->toBeNull();
});

it("takes devnull's word for which reply carries the thread on", function () {
    $commit = threadCommit('c1', minutes: 30, overrides: ['continuation_id' => 'p3']);

    expect(SelfThread::next($commit, [threadCommit('p2', 'ada', 'c1', 20), threadCommit('p3', 'ada', 'c1', 10)])?->id)->toBe('p3');
});

it('walks down the parts as far as their replies have come', function () {
    $c1 = threadCommit('c1', minutes: 30);
    $p2 = threadCommit('p2', 'ada', 'c1', 20);
    $p3 = threadCommit('p3', 'ada', 'p2', 10);

    $waiting = SelfThread::below($c1, repliesFrom(['c1' => [$p2]]));
    $ended = SelfThread::below($c1, repliesFrom(['c1' => [$p2], 'p2' => [$p3], 'p3' => [threadCommit('r1', 'grace', 'p3', 5)]]));

    expect(array_map(fn (Commit $part): string => $part->id, $waiting->parts))->toBe(['p2'])
        ->and($waiting->waiting)->toBe('p2')
        ->and($waiting->hasEnded)->toBeFalse()
        ->and(array_map(fn (Commit $part): string => $part->id, $ended->parts))->toBe(['p2', 'p3'])
        ->and($ended->hasEnded)->toBeTrue()
        ->and($ended->waiting)->toBeNull();
});

it('stops at the limit and says another part follows', function () {
    $c1 = threadCommit('c1', minutes: 30);
    $p2 = threadCommit('p2', 'ada', 'c1', 20);
    $p3 = threadCommit('p3', 'ada', 'p2', 10);

    $thread = SelfThread::below($c1, repliesFrom(['c1' => [$p2], 'p2' => [$p3]]), limit: 1);

    expect(array_map(fn (Commit $part): string => $part->id, $thread->parts))->toBe(['p2'])
        ->and($thread->hasMore)->toBeTrue()
        ->and($thread->hasEnded)->toBeFalse();
});

it('counts the earlier parts above a commit, only while each is the one its author carried on', function () {
    $c1 = threadCommit('c1', minutes: 30);
    $p2 = threadCommit('p2', 'ada', 'c1', 20);
    $p3 = threadCommit('p3', 'ada', 'p2', 10);
    $other = threadCommit('o1', 'ada', 'p2', 15);

    expect(SelfThread::above([$c1, $p2], $p3, repliesFrom(['c1' => [$p2], 'p2' => [$p3]])))->toBe([2, null])
        ->and(SelfThread::above([$c1, $p2], $p3, repliesFrom(['p2' => [$p3]])))->toBe([1, 'c1'])
        // Ada's earliest reply to p2 is o1, so p3 isn't part of the thread.
        ->and(SelfThread::above([$c1, $p2], $p3, repliesFrom(['p2' => [$other, $p3]])))->toBe([0, null])
        ->and(SelfThread::above([threadCommit('g1', 'grace')], threadCommit('p9', 'ada', 'g1'), repliesFrom([])))->toBe([0, null]);
});

it("writes a commit's place in its thread as the web does", function () {
    expect(SelfThread::position(2, 5, false))->toBe('2/5')
        ->and(SelfThread::position(1, 12, true))->toBe('1/12+')
        ->and(SelfThread::position(1, 1, false))->toBeNull();
});
