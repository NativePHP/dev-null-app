<?php

namespace App\Devnull\Data;

use Carbon\CarbonImmutable;

/**
 * A group of notifications, as the web shows it: the newest one, with the
 * first few people in the group, how many events and people there are and
 * how many are unread. Expanded, a group lists its notifications one by one
 * in the same shape. The commit's parent only comes along when you may see it.
 *
 * The whole commit comes along too, as the API sends it: its images beside a
 * star on an image-only commit, and the commit of yours a quote quotes, which
 * devnull only sends along when it loaded it — absent rather than null, so a
 * quote with none of it sent shows no box at all, as on the web. A level-up
 * uses the level, rank and description when sent, without inventing missing data.
 */
final readonly class Notification
{
    /**
     * @param  list<User>  $actors  the newest first, up to three
     */
    public function __construct(
        public string $id,
        public string $type,
        public User $actor,
        public ?CarbonImmutable $createdAt = null,
        public bool $isUnread = false,
        public ?string $commitId = null,
        public ?string $body = null,
        public ?string $parentBody = null,
        public ?string $reason = null,
        public ?string $subjectId = null,
        public array $actors = [],
        public int $actorsCount = 1,
        public int $unreadCount = 0,
        public ?CarbonImmutable $readAt = null,
        public string $groupId = '',
        public int $eventCount = 1,
        public ?bool $actorIsFollowed = null,
        public ?Commit $commit = null,
        public bool $quotedCommitSent = false,
        public ?int $level = null,
        public ?string $rank = null,
        public ?string $rankDescription = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $commit = is_array($data['commit'] ?? null) ? $data['commit'] : [];
        $parent = is_array($commit['parent'] ?? null) ? $commit['parent'] : [];
        $reason = $data['reason'] ?? null;
        $actorJson = is_array($data['actor'] ?? null) ? $data['actor'] : [];
        $actor = User::fromArray($actorJson);
        $actors = array_values(array_map(User::fromArray(...), array_filter(is_array($data['actors'] ?? null) ? $data['actors'] : [], is_array(...))));
        $isUnread = array_key_exists('unread_count', $data)
            ? (int) $data['unread_count'] > 0
            : array_key_exists('read_at', $data) && $data['read_at'] === null;
        $id = (string) ($data['id'] ?? '');
        $level = $data['level'] ?? null;
        $rank = $data['rank'] ?? null;
        $rankDescription = $data['rank_description'] ?? null;

        return new self(
            id: $id,
            type: (string) ($data['type'] ?? ''),
            actor: $actor,
            createdAt: Commit::date($data['created_at'] ?? null),
            isUnread: $isUnread,
            commitId: isset($commit['id']) ? (string) $commit['id'] : (isset($data['commit_id']) ? (string) $data['commit_id'] : null),
            body: isset($commit['body']) ? self::excerpt($commit) : null,
            parentBody: isset($parent['body']) ? (string) $parent['body'] : null,
            reason: is_string($reason) && trim($reason) !== '' ? $reason : null,
            subjectId: is_string($data['subject_id'] ?? null) && $data['subject_id'] !== '' ? $data['subject_id'] : null,
            actors: $actors === [] ? [$actor] : $actors,
            actorsCount: max(1, count($actors), (int) ($data['actors_count'] ?? 1)),
            unreadCount: max(0, (int) ($data['unread_count'] ?? ($isUnread ? 1 : 0))),
            readAt: Commit::date($data['read_at'] ?? null),
            groupId: is_string($data['group_id'] ?? null) && $data['group_id'] !== '' ? $data['group_id'] : $id,
            eventCount: max(1, (int) ($data['event_count'] ?? 1)),
            actorIsFollowed: is_bool($actorJson['is_followed'] ?? null) ? $actorJson['is_followed'] : null,
            commit: $commit === [] ? null : Commit::fromArray($commit),
            quotedCommitSent: array_key_exists('quoted_commit', $commit),
            level: is_numeric($level) ? (int) $level : null,
            rank: is_string($rank) && trim($rank) !== '' ? $rank : null,
            rankDescription: is_string($rankDescription) && trim($rankDescription) !== '' ? $rankDescription : null,
        );
    }

    public function isReply(): bool
    {
        return $this->type === 'replied' && $this->commitId !== null;
    }

    public function isMention(): bool
    {
        return $this->type === 'mentioned';
    }

    public function isStar(): bool
    {
        return $this->type === 'starred';
    }

    public function isFollow(): bool
    {
        return $this->type === 'followed';
    }

    /**
     * A moderator approved or rejected your commit. Nobody else is named.
     */
    public function isModeration(): bool
    {
        return in_array($this->type, ['approved', 'rejected'], true);
    }

    public function isRejection(): bool
    {
        return $this->type === 'rejected';
    }

    /**
     * You reached a new level. Like a moderator's verdict, devnull names you as its actor.
     */
    public function isLevelUp(): bool
    {
        return $this->type === 'leveled_up';
    }

    /**
     * Whether it happened to you rather than being something someone did, so the web names nobody: a verdict or a level-up.
     */
    public function namesNobody(): bool
    {
        return $this->isModeration() || $this->isLevelUp();
    }

    /**
     * "Lv. 5 · wheel", as the level-up's chip reads, or null when devnull doesn't say which level it was.
     */
    public function levelLabel(): ?string
    {
        return $this->level === null || $this->rank === null ? null : "Lv. {$this->level} · {$this->rank}";
    }

    /**
     * The commit is yours, so the web sets it apart in a muted box: a star or a moderator's verdict.
     */
    public function isAboutYourCommit(): bool
    {
        return $this->isStar() || $this->isModeration();
    }

    /**
     * The commit is the actor's own words: a reply, a quote or a mention, so
     * the web writes their name into the sentence and shows it as their commit.
     */
    public function isActorsCommit(): bool
    {
        return in_array($this->type, ['replied', 'quoted', 'mentioned'], true);
    }

    /**
     * The images of your commit a star came with, as the web shows them beside
     * it: only when devnull loaded them, which its signed API does not today.
     *
     * @return list<CommitImage>
     */
    public function starredImages(): array
    {
        return $this->isStar() && $this->commit !== null ? $this->commit->images : [];
    }

    /**
     * A star on your commit that says nothing but its images, as the web words
     * it "starred your image" and shows nothing of the commit beside them.
     * The body it reads is the one the row shows, so a commit whose segments
     * weren't sent still counts as readable, worded as it reads.
     */
    public function isImageOnlyStar(): bool
    {
        return $this->starredImages() !== [] && trim($this->body ?? '') === '';
    }

    /**
     * Whether the commit of yours this one quotes came along at all, as the
     * web shows the box under a quote only when it did, which its signed API
     * does not load today.
     */
    public function showsQuotedCommit(): bool
    {
        return $this->type === 'quoted' && $this->quotedCommitSent;
    }

    /**
     * The commit of yours a quote quotes: a tombstone once it was deleted, or
     * null once you may no longer see it, either of which the web still boxes,
     * in the web's words.
     */
    public function quotedOwnCommit(): ?Commit
    {
        return $this->showsQuotedCommit() ? $this->commit?->quotedCommit : null;
    }

    /**
     * Whether the group has more than one event, so it opens to list them, as on the web.
     */
    public function isGrouped(): bool
    {
        return $this->eventCount > 1;
    }

    /**
     * Whether it arrived since you last looked: still unread, or marked read
     * by the visit that showed it ($readAt), so the web rings it in blue.
     */
    public function isNewSince(?CarbonImmutable $readAt): bool
    {
        if ($readAt === null) {
            return false;
        }

        return $this->readAt === null || $this->readAt->greaterThanOrEqualTo($readAt);
    }

    /**
     * The commit a tap opens, as on the web: the notification's own commit. A
     * follow, or a level-up, opens a profile instead.
     */
    public function commitToOpen(): ?string
    {
        return $this->isFollow() || $this->isLevelUp() ? null : $this->commitId;
    }

    /**
     * Everything but a follow or a level-up is about a commit, and opens it.
     */
    public function opensCommit(): bool
    {
        return $this->commitToOpen() !== null;
    }

    /**
     * What the group's toggle counts: "5 stars", "2 followers", or "3 updates".
     */
    public function groupNoun(): string
    {
        return match ($this->type) {
            'followed' => 'followers',
            'starred' => 'stars',
            default => 'updates',
        };
    }

    /**
     * The people after the first, as the web adds them to a follow or a star: "and 1 other", "and 3 others".
     */
    public function others(): string
    {
        $others = in_array($this->type, ['followed', 'starred'], true) ? $this->actorsCount - 1 : 0;

        return $others < 1 ? '' : "and {$others} ".($others === 1 ? 'other' : 'others');
    }

    /**
     * What happened, as the web words it. For a person it follows their name and their handle or the others.
     * A star on an image-only commit says it was your image, or images. A
     * level-up names its level and rank only when the API supplies them.
     */
    public function sentence(): string
    {
        return match ($this->type) {
            'followed' => 'followed you',
            'mentioned' => 'mentioned you',
            'starred' => $this->isImageOnlyStar()
                ? 'starred your '.(count($this->starredImages()) === 1 ? 'image' : 'images')
                : 'starred your commit',
            'quoted' => 'quoted your commit',
            'approved', 'rejected' => "Your commit was {$this->type}",
            'leveled_up' => $this->levelLabel() === null ? 'You reached a new level' : "You reached level {$this->level}: {$this->rank}",
            default => 'replied to your commit',
        };
    }

    /**
     * This group with you following, or no longer following, its person.
     */
    public function withActorFollowed(bool $isFollowed): self
    {
        return new self(...[...get_object_vars($this), 'actorIsFollowed' => $isFollowed]);
    }

    /**
     * A commit's body as an excerpt shows it: prose and code as written, without links, from its segments when sent.
     *
     * @param  array<string, mixed>  $commit
     */
    private static function excerpt(array $commit): string
    {
        $segments = is_array($commit['body_segments'] ?? null) ? $commit['body_segments'] : null;

        return $segments === null
            ? (string) $commit['body']
            : Commit::fromArray(['body' => (string) $commit['body'], 'body_segments' => $segments])->plainText();
    }
}
