<?php

use App\Devnull\Data\Achievements;
use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitBody;
use App\Devnull\Data\Mention;
use App\Devnull\Data\Poll;
use Carbon\CarbonImmutable;

/**
 * A commit as a signed list sends it, with what the web's cards carry.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cardJson(array $overrides = []): array
{
    return [
        'id' => 'c1',
        'body' => 'Tabs or spaces?',
        'status' => 'approved',
        'created_at' => '2026-10-16T12:00:00Z',
        'author' => ['id' => 'u1', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 2],
        'body_segments' => [['type' => 'text', 'text' => 'Tabs or spaces?']],
        'preview_segments' => null,
        'quoted_commit_id' => null,
        'quoted_commit' => null,
        'poll' => null,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pollJson(array $overrides = []): array
{
    return [
        'id' => 'p1',
        'closes_at' => '2026-10-17T12:00:00.000000Z',
        'is_closed' => false,
        'has_voted' => true,
        'total_votes' => 4,
        'options' => [
            ['id' => 'o1', 'label' => 'Tabs', 'votes_count' => 3, 'voted' => true, 'percentage' => 75],
            ['id' => 'o2', 'label' => 'Spaces', 'votes_count' => 1, 'voted' => false, 'percentage' => 25],
        ],
        ...$overrides,
    ];
}

it('reads a poll with its results, your vote, and the time left', function () {
    $poll = Commit::fromArray(cardJson(['poll' => pollJson()]))->poll;
    $now = CarbonImmutable::parse('2026-10-16T21:30:00Z');

    expect($poll)->toBeInstanceOf(Poll::class)
        ->and($poll->showsResults())->toBeTrue()
        ->and($poll->hasVoted)->toBeTrue()
        ->and(array_map(fn ($option): array => [$option->label, $option->share(), $option->voted], $poll->options))->toBe([['Tabs', 75, true], ['Spaces', 25, false]])
        ->and($poll->status($now))->toBe('14h left')
        ->and($poll->summary($now))->toBe('4 votes · 14h left');
});

it("words a poll's time the way the web does", function (?string $closesAt, bool $isClosed, ?int $total, string $status) {
    $poll = Poll::fromArray(pollJson(['closes_at' => $closesAt, 'is_closed' => $isClosed, 'total_votes' => $total]));

    expect($poll->status(CarbonImmutable::parse('2026-10-16T12:00:00Z')))->toBe($status);
})->with([
    'days' => ['2026-10-19T13:00:00Z', false, 4, '3d left'],
    'hours' => ['2026-10-16T14:59:00Z', false, 4, '2h left'],
    'minutes' => ['2026-10-16T12:20:30Z', false, 4, '21m left'],
    'never less than a minute' => ['2026-10-16T12:00:10Z', false, 4, '1m left'],
    'still in moderation' => [null, false, null, 'Voting opens once approved'],
    'closed with results' => ['2026-10-16T11:00:00Z', true, 4, 'Final results'],
    'closed by the clock' => ['2026-10-16T11:00:00Z', false, 4, 'Final results'],
    'closed with results kept back' => ['2026-10-16T11:00:00Z', true, null, 'Poll closed'],
]);

it('keeps the results of a poll you have not voted on back, as devnull sends it', function () {
    $poll = Poll::fromArray(pollJson([
        'has_voted' => false,
        'total_votes' => null,
        'options' => [['id' => 'o1', 'label' => 'Tabs', 'votes_count' => null, 'voted' => false, 'percentage' => null]],
    ]));

    expect($poll->showsResults())->toBeFalse()
        ->and($poll->options[0]->votesCount)->toBeNull()
        ->and($poll->options[0]->share())->toBe(0);
});

it('reads the commit it quotes, a deleted one, and one you may no longer see', function () {
    $quoted = Commit::fromArray(cardJson([
        'quoted_commit_id' => 'q1',
        'quoted_commit' => cardJson(['id' => 'q1', 'body' => 'The original', 'author' => ['id' => 'u2', 'handle' => 'grace', 'name' => 'Grace'], 'poll' => pollJson()]),
    ]));
    $deleted = Commit::fromArray(cardJson(['quoted_commit_id' => 'q1', 'quoted_commit' => ['id' => 'q1', 'parent_id' => null, 'deleted_at' => '2026-10-16T13:00:00Z']]));
    $hidden = Commit::fromArray(cardJson(['quoted_commit_id' => 'q1', 'quoted_commit' => null]));

    expect($quoted->isQuote())->toBeTrue()
        ->and($quoted->quotedCommit->body)->toBe('The original')
        ->and($quoted->quotedCommit->author->username)->toBe('grace')
        ->and($quoted->quotedCommit->poll)->not->toBeNull()
        ->and($deleted->quotedCommit->isDeleted())->toBeTrue()
        ->and($hidden->isQuote())->toBeTrue()
        ->and($hidden->quotedCommit)->toBeNull()
        ->and(Commit::fromArray(cardJson())->isQuote())->toBeFalse();
});

it("reads a long commit's preview, ellipsis and mentions included", function () {
    $body = 'Hey @ada, '.str_repeat('word ', 80).'the end.';
    $preview = 'Hey @ada, '.rtrim(str_repeat('word ', 50)).'…';

    $commit = Commit::fromArray(cardJson([
        'body' => $body,
        'body_segments' => [['type' => 'text', 'text' => $body]],
        'preview_segments' => [['type' => 'text', 'text' => $preview]],
        'mentions' => [['handle' => 'ada', 'offset' => 4, 'length' => 4]],
    ]));
    $runs = $commit->previewContent[0]->runs;

    expect($commit->isFolded())->toBeTrue()
        ->and($runs[1]->username)->toBe('ada')
        ->and(end($runs)->text)->toBe('…')
        ->and(implode('', array_map(fn ($run): string => $run->text, $runs)))->toBe($preview)
        ->and(Commit::fromArray(cardJson())->isFolded())->toBeFalse();
});

it('finds a preview cut off right after inline code', function () {
    $body = 'Run `composer test` and then wait for it all to finish.';

    $blocks = CommitBody::blocks($body, [['type' => 'text', 'text' => 'Run '], ['type' => 'code', 'code' => 'composer test'], ['type' => 'text', 'text' => ' and…']], [new Mention('nobody', 50, 7)]);

    expect(array_map(fn ($run): array => [$run->text, $run->isCode], $blocks[0]->runs))->toBe([['Run ', false], ['composer test', true], [' and', false], ['…', false]]);
});

it('reads the reply preview under a commit, and whether more are behind it', function () {
    $commit = Commit::fromArray(cardJson([
        'replies_count' => 3,
        'reply_preview' => ['data' => [cardJson(['id' => 'r3', 'parent_id' => 'c1', 'body' => 'Newest reply'])], 'next_cursor' => 'eyJjcmVhdGVkX2F0Ijo'],
    ]));
    $empty = Commit::fromArray(cardJson(['reply_preview' => ['data' => [], 'next_cursor' => null]]));

    expect($commit->replyPreview->replies[0]->body)->toBe('Newest reply')
        ->and($commit->replyPreview->hasMore)->toBeTrue()
        ->and($empty->replyPreview->isEmpty())->toBeTrue()
        ->and($empty->replyPreview->hasMore)->toBeFalse()
        ->and(Commit::fromArray(cardJson())->replyPreview)->toBeNull();

    $starred = $commit->withPreviewReplies(fn (Commit $reply): Commit => $reply->withStar(true));

    expect($starred->replyPreview->replies[0]->starred)->toBeTrue()
        ->and($starred->replyPreview->hasMore)->toBeTrue();
});

it('reads achievements with the Unix rank, the XP to the next one and the badges', function () {
    $achievements = Achievements::fromArray([
        'level' => 2,
        'rank' => 'user',
        'xp' => 120,
        'next_level_xp' => 250,
        'level_progress' => 40,
        'ranks' => [
            ['rank' => 'guest', 'level' => 1, 'min_xp' => 0, 'description' => '', 'achieved' => true],
            ['rank' => 'user', 'level' => 2, 'min_xp' => 50, 'description' => '', 'achieved' => true],
            ['rank' => 'operator', 'level' => 3, 'min_xp' => 250, 'description' => '', 'achieved' => false],
        ],
        'badges' => [
            ['id' => 'init', 'name' => 'init', 'description' => 'First commit', 'icon' => 'terminal', 'unlocked' => true, 'progress' => 1, 'target' => 1],
            ['id' => 'stdout', 'name' => 'stdout', 'description' => '100 commits', 'icon' => 'box', 'unlocked' => false, 'progress' => 12, 'target' => 100],
        ],
    ]);

    expect($achievements->label())->toBe('Lv. 2 · user')
        ->and($achievements->xpLabel())->toBe('120 / 250 XP')
        ->and($achievements->nextRankLabel())->toBe('130 XP to operator')
        ->and($achievements->unlockedCount())->toBe(1)
        ->and($achievements->badges[1]->showsProgress())->toBeTrue()
        ->and($achievements->badges[0]->showsProgress())->toBeFalse()
        ->and(Achievements::fromArray(null))->toBeNull()
        ->and(Achievements::fromArray(['level' => 9, 'rank' => 'root', 'xp' => 9000, 'next_level_xp' => null, 'ranks' => []])->nextRankLabel())->toBe('Maximum rank (UID 0)');
});
