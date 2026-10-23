<?php

use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitImage;
use App\Devnull\Data\Notification;

/**
 * Your commit a star came on, saying nothing but its images.
 *
 * @param  list<array<string, mixed>>  $images
 * @return array<string, mixed>
 */
function starCommitJson(array $images, array $overrides = []): array
{
    return [
        'id' => 'c-starred',
        'body' => '',
        'body_segments' => [],
        'images' => $images,
        'author' => userJson(),
        ...$overrides,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function starImages(int $count): array
{
    return array_map(fn (int $n): array => ['id' => "i{$n}", 'url' => "https://img.test/{$n}", 'width' => 1080, 'height' => 1080], range(1, $count));
}

it('says a star on an image-only commit was your image, or images', function (int $images, string $worded) {
    $star = Notification::fromArray(notificationJson('starred', ['commit' => starCommitJson(starImages($images))]));

    expect($star->isStar())->toBeTrue()
        ->and($star->isImageOnlyStar())->toBeTrue()
        ->and($star->starredImages())->toHaveCount($images)
        ->and($star->starredImages()[0])->toBeInstanceOf(CommitImage::class)
        ->and($star->sentence())->toBe("starred your {$worded}");
})->with([
    [1, 'image'],
    [2, 'images'],
]);

it('still words a star with anything to read as your commit, with the images beside it', function () {
    $withBody = Notification::fromArray(notificationJson('starred', ['commit' => starCommitJson(starImages(1), ['body' => 'Shipped the app.', 'body_segments' => [['type' => 'text', 'text' => 'Shipped the app.']]])]));

    expect($withBody->isImageOnlyStar())->toBeFalse()
        ->and($withBody->starredImages())->toHaveCount(1)
        ->and($withBody->sentence())->toBe('starred your commit')
        // Without the images devnull's signed API never sends, a star is worded as it always was.
        ->and(Notification::fromArray(notificationJson('starred'))->isImageOnlyStar())->toBeFalse()
        ->and(Notification::fromArray(notificationJson('starred'))->starredImages())->toBe([])
        ->and(Notification::fromArray(notificationJson('starred'))->sentence())->toBe('starred your commit');
});

it('words a star with a readable body as your commit even when the body\'s segments were not sent', function () {
    $star = Notification::fromArray(notificationJson('starred', ['commit' => array_diff_key(starCommitJson(starImages(2), ['body' => 'Readable words.']), ['body_segments' => true])]));

    expect($star->commit?->body)->toBe('Readable words.')
        ->and($star->body)->toBe('Readable words.')
        ->and($star->isImageOnlyStar())->toBeFalse()
        ->and($star->sentence())->toBe('starred your commit');
});

it('shows a quote\'s commit of yours when devnull sent it, deleted or no longer visible alike', function (array $quoted, bool $deleted, ?string $body) {
    $quote = Notification::fromArray(notificationJson('quoted', ['commit' => notificationCommitJson(['id' => 'q1', 'body' => 'Quoting this one.']) + ['quoted_commit_id' => 'c-yours', 'quoted_commit' => $quoted]]));

    expect($quote->isActorsCommit())->toBeTrue()
        ->and($quote->showsQuotedCommit())->toBeTrue()
        ->and($quote->quotedOwnCommit()?->isDeleted())->toBe($deleted)
        ->and($quote->quotedOwnCommit()?->plainText())->toBe($body);
})->with([
    'yours' => [['id' => 'c-yours', 'body' => 'Shipped it.', 'body_segments' => [['type' => 'text', 'text' => 'Shipped it.']], 'author' => userJson()], false, 'Shipped it.'],
    'deleted' => [['id' => 'c-yours', 'deleted_at' => '2026-10-19T15:00:00Z', 'replies_count' => 1], true, ''],
]);

it('shows no quote box at all when the quoted commit was not sent, as the signed API does not send it', function () {
    $quote = Notification::fromArray(notificationJson('quoted', ['commit' => notificationCommitJson(['id' => 'q1', 'body' => 'Quoting this one.', 'quoted_commit_id' => 'c-yours'])]));
    $gone = Notification::fromArray(notificationJson('quoted', ['commit' => notificationCommitJson(['id' => 'q1', 'body' => 'Quoting this one.', 'quoted_commit_id' => 'c-yours', 'quoted_commit' => null])]));

    expect($quote->showsQuotedCommit())->toBeFalse()
        ->and($quote->quotedOwnCommit())->toBeNull()
        // Sent, but no longer yours to see: still a box, the web's unavailable one.
        ->and($gone->showsQuotedCommit())->toBeTrue()
        ->and($gone->quotedOwnCommit())->toBeNull();
});

it('words a level-up plainly and opens your profile, telling of no level the API leaves out', function () {
    $level = Notification::fromArray(notificationJson('leveled_up', ['commit' => null]));

    expect($level->isLevelUp())->toBeTrue()
        ->and($level->sentence())->toBe('You reached a new level')
        ->and($level->commitToOpen())->toBeNull()
        ->and($level->opensCommit())->toBeFalse()
        ->and($level->level)->toBeNull()
        ->and($level->rank)->toBeNull()
        ->and($level->rankDescription)->toBeNull()
        ->and($level->isActorsCommit())->toBeFalse()
        ->and($level->isAboutYourCommit())->toBeFalse();
});

it('keeps the level and rank only when devnull actually sent them, as the web\'s page does', function () {
    $level = Notification::fromArray(notificationJson('leveled_up', ['commit' => null, 'level' => 3, 'rank' => 'Committer', 'rank_description' => 'You shipped it.']));

    expect($level->level)->toBe(3)
        ->and($level->rank)->toBe('Committer')
        ->and($level->rankDescription)->toBe('You shipped it.')
        // Still no rank read into the sentence, since the API doesn't guarantee one.
        ->and($level->sentence())->toBe('You reached a new level');
});

it('carries the whole commit along, while a reply keeps reading as it always did', function () {
    $reply = Notification::fromArray(notificationJson('replied'));

    expect($reply->commit)->toBeInstanceOf(Commit::class)
        ->and($reply->commit?->id)->toBe('c-replied')
        ->and($reply->isActorsCommit())->toBeTrue()
        ->and($reply->isReply())->toBeTrue()
        ->and($reply->body)->toBe('About replied')
        ->and($reply->parentBody)->toBe('Shipped the app.')
        // A follow, which has no commit, carries none.
        ->and(Notification::fromArray(notificationJson('followed'))->commit)->toBeNull();

    $followed = Notification::fromArray(notificationJson('starred', ['actors_count' => 3]));

    expect($followed->withActorFollowed(true)->actorIsFollowed)->toBeTrue()
        ->and($followed->withActorFollowed(true)->commit?->id)->toBe('c-starred')
        ->and($followed->withActorFollowed(true)->starredImages())->toBe([]);
});
