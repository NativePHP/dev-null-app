<?php

namespace App\Models;

use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitBody;
use App\Devnull\Data\DraftImage;
use App\Devnull\Data\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something you did that devnull hasn't answered for yet: a star, an unstar, a
 * commit (a new one, a quote or a reply) or a Remotes message, kept in the
 * outbox until it has (see App\Devnull\Outbox).
 *
 * $target is what it's about: the commit starred, or the Remotes thread a
 * message goes to. $payload is what's sent, and for a commit what the
 * composer needs to have it back: its body, what it replies to or quotes,
 * its poll, its photos with their descriptions, and the draft it was
 * stashed under.
 *
 * It's queued until it goes, sending while it's on its way, and failed once
 * devnull turned it down, when a commit stays for you to edit and a message
 * stays in its thread for you to retry.
 *
 * @property int $id
 * @property string $user_id
 * @property string $kind
 * @property string|null $target
 * @property string $client_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string|null $task_id
 * @property string|null $error
 * @property int $attempts
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PendingWrite extends Model
{
    public const string Star = 'star';

    public const string Unstar = 'unstar';

    public const string Commit = 'commit';

    public const string Message = 'message';

    public const string Queued = 'queued';

    public const string Sending = 'sending';

    public const string Failed = 'failed';

    /**
     * What a commit still on its way is called in its place on the screen, after its client id.
     */
    public const string StagedPrefix = 'staged-';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isStar(): bool
    {
        return in_array($this->kind, [self::Star, self::Unstar], true);
    }

    public function isCommit(): bool
    {
        return $this->kind === self::Commit;
    }

    public function isMessage(): bool
    {
        return $this->kind === self::Message;
    }

    public function isFailed(): bool
    {
        return $this->status === self::Failed;
    }

    /**
     * Whether sending it again can't do it twice: a star or an unstar sets
     * the star as it says, and devnull stores a message once per client id.
     * A commit has nothing devnull could know it by, so it's never sent again
     * without you.
     */
    public function canBeSentAgain(): bool
    {
        return $this->isStar() || $this->isMessage();
    }

    /**
     * Whether devnull had the commit starred before a star or an unstar went:
     * as it said in answer to the one before it, when that one waited ahead
     * of it (see Outbox::starOnItsWay()), and otherwise the other way round
     * from what this one does.
     */
    public function wasStarred(): bool
    {
        return is_bool($this->payload['was_starred'] ?? null) ? $this->payload['was_starred'] : $this->kind !== self::Star;
    }

    public function body(): string
    {
        return is_string($this->payload['body'] ?? null) ? $this->payload['body'] : '';
    }

    public function parentId(): ?string
    {
        return is_string($this->payload['parent_id'] ?? null) ? $this->payload['parent_id'] : null;
    }

    public function quotedCommitId(): ?string
    {
        return is_string($this->payload['quoted_commit_id'] ?? null) ? $this->payload['quoted_commit_id'] : null;
    }

    /**
     * The commit's poll as devnull takes it, or null with none.
     *
     * @return array{options: list<string>, duration: int}|null
     */
    public function poll(): ?array
    {
        $poll = $this->payload['poll'] ?? null;

        return is_array($poll) && is_array($poll['options'] ?? null) && is_int($poll['duration'] ?? null) ? $poll : null;
    }

    /**
     * The message a Remotes message replies to.
     */
    public function replyToId(): ?int
    {
        return is_int($this->payload['reply_to_id'] ?? null) ? $this->payload['reply_to_id'] : null;
    }

    /**
     * The draft a commit was written under (see App\Devnull\Stash), which it goes back to if devnull turns it down.
     */
    public function stashName(): ?string
    {
        return is_string($this->payload['stash'] ?? null) ? $this->payload['stash'] : null;
    }

    /**
     * Where a commit was written: the reply bar on a commit's page, or the composer sheet ('compose', 'quote' or 'reply').
     */
    public function composer(): string
    {
        return is_string($this->payload['composer'] ?? null) ? $this->payload['composer'] : 'compose';
    }

    /**
     * The photos of a commit or a message still on the phone. Photos nothing holds any more are deleted after a day
     * (see DraftImages).
     *
     * @return list<DraftImage>
     */
    public function images(): array
    {
        $images = is_array($this->payload['images'] ?? null) ? $this->payload['images'] : [];

        return array_values(array_filter(array_map(DraftImage::fromArray(...), $images)));
    }

    /**
     * @param  list<DraftImage>  $images
     * @return list<array{path: string, mime_type: string, width: int, height: int, description: string}>
     */
    public static function imagePayload(array $images): array
    {
        return array_map(fn (DraftImage $image): array => $image->toArray(), $images);
    }

    /**
     * The commit a commit on its way stands in as, by you, at once: what you
     * wrote and its photos, marked staged, as the web's git words go, until
     * devnull has it and the real one takes its place.
     */
    public function stagedCommit(User $author): Commit
    {
        return new Commit(
            id: self::StagedPrefix.$this->client_id,
            body: $this->body(),
            status: Commit::Staged,
            author: $author,
            createdAt: $this->created_at ?? CarbonImmutable::now(),
            parentId: $this->parentId(),
            images: array_map(fn (DraftImage $image, int $index) => $image->asCommitImage($index), $this->images(), array_keys($this->images())),
            content: CommitBody::blocks($this->body(), null, []),
            quotedCommitId: $this->quotedCommitId(),
        );
    }

    public static function stagedId(string $clientId): string
    {
        return self::StagedPrefix.$clientId;
    }
}
