<?php

use App\Devnull\Data\Commit;
use App\Devnull\Data\ScoreCard;
use App\Devnull\NataNullers\Run;
use App\Devnull\NataNullers\ScoreCommit;

/**
 * A shared high score as the API sends it: the line, then the run in a fenced
 * block, with segments and a preview as devnull cuts a body that long.
 *
 * @return array<string, mixed>
 */
function scoreCommitJson(string $body, string $authorId = 'u1'): array
{
    [$line] = explode("\n", $body);

    return [
        'id' => 'c1',
        'body' => $body,
        'status' => 'approved',
        'author' => ['id' => $authorId, 'handle' => 'ada', 'name' => 'Ada'],
        'body_segments' => [['type' => 'text', 'text' => $line."\n\n"], ['type' => 'block', 'language' => 'nata-nullers']],
        'preview_segments' => [['type' => 'text', 'text' => $line."\n\n"], ['type' => 'block', 'language' => 'nata-nullers']],
    ];
}

function scoreBody(int $score = 1234, string $userId = 'u1'): string
{
    return ScoreCommit::body(Run::sign(testKey(), $userId, $score, 1760000000));
}

it('turns a shared high score into a signed card and keeps only its line in the body', function () {
    $commit = Commit::fromArray(scoreCommitJson(scoreBody()));

    expect($commit->scoreCard)->toEqual(new ScoreCard(1234, signed: true))
        ->and($commit->plainText())->toBe('i got a new high score on Nata Nu//ers: 1234')
        ->and(array_filter($commit->content, fn ($block) => $block->isCode()))->toBeEmpty()
        ->and($commit->isFolded())->toBeFalse();
});

it('shows a run signed for someone else, or edited, as unsigned', function (string $body, string $authorId) {
    $commit = Commit::fromArray(scoreCommitJson($body, $authorId));

    expect($commit->scoreCard->signed)->toBeFalse()
        ->and($commit->plainText())->toStartWith('i got a new high score');
})->with([
    'lifted into another author\'s commit' => fn () => [scoreBody(10, 'u1'), 'u2'],
    'score edited' => fn () => [str_replace('.10.', '.9999.', scoreBody(10)), 'u1'],
]);

it('leaves ordinary commits alone, code blocks and all', function () {
    $commit = Commit::fromArray([
        'id' => 'c2',
        'body' => "look\n\n```php\necho 1;\n```",
        'author' => ['id' => 'u1', 'handle' => 'ada'],
        'body_segments' => [['type' => 'text', 'text' => "look\n\n"], ['type' => 'block', 'language' => 'php']],
    ]);

    expect($commit->scoreCard)->toBeNull()
        ->and($commit->content[1]->code)->toBe('echo 1;');
});

it('cuts only the run out of a body', function () {
    expect(ScoreCard::withoutRun("hi\n\n```nata-nullers\nv1.x\n```\n\nbye"))->toBe("hi\n\nbye")
        ->and(ScoreCard::withoutRun('no run here'))->toBe('no run here');
});
