<?php

namespace App\Devnull\Data;

use App\Devnull\Display;
use App\Devnull\Lane;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * A commit as the web shows it, with its author and, for a part of a series,
 * its place in it. A deleted commit that still has replies comes back as a
 * tombstone: an id, its parent, when it went, its replies count and its place
 * in a series, with no body or author.
 *
 * Like the web's cards it can carry a poll, the commit it quotes, the start of
 * a body too long to show whole in a list ($previewContent), and in a list the
 * thread the web shows under it ($replyPreview).
 */
final readonly class Commit
{
    public const int MinLength = 1;

    public const int MaxLength = 4000;

    public const int MaxRawLength = 40000;

    /**
     * The web's composer only counts down once this few characters are left.
     */
    public const int CountFrom = 200;

    /**
     * A list shows a body longer than this, or longer than PreviewLines lines, as its start and "Show more".
     */
    public const int PreviewLength = 280;

    public const int PreviewLines = 8;

    /**
     * @param  list<Mention>  $mentions
     * @param  list<CommitImage>  $images
     * @param  list<BodyBlock>  $content
     * @param  list<BodyBlock>|null  $previewContent  The start of a long body, which a list shows with "Show more".
     */
    public function __construct(
        public string $id,
        public string $body,
        public string $status,
        public User $author,
        public ?CarbonImmutable $createdAt = null,
        public ?string $parentId = null,
        public int $repliesCount = 0,
        public ?string $htmlUrl = null,
        public ?CarbonImmutable $deletedAt = null,
        public int $starsCount = 0,
        public bool $starred = false,
        public bool $isPinned = false,
        public array $mentions = [],
        public ?LinkPreview $linkPreview = null,
        public array $images = [],
        public array $content = [],
        public ?Commit $parent = null,
        public string $shortHash = '',
        public ?string $seriesId = null,
        public ?int $seriesPosition = null,
        public ?int $seriesSize = null,
        public ?Poll $poll = null,
        public ?string $quotedCommitId = null,
        public ?Commit $quotedCommit = null,
        public ?array $previewContent = null,
        public ?ReplyPreview $replyPreview = null,
        public int $pasteisCount = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = (string) ($data['id'] ?? '');
        $parentId = isset($data['parent_id']) ? (string) $data['parent_id'] : null;
        $repliesCount = (int) ($data['replies_count'] ?? 0);

        if (is_string($data['deleted_at'] ?? null)) {
            return new self(
                id: $id,
                body: '',
                status: '',
                author: User::fromArray([]),
                parentId: $parentId,
                repliesCount: $repliesCount,
                deletedAt: self::date($data['deleted_at']) ?? CarbonImmutable::now(),
                shortHash: Display::shortHash($id),
                seriesPosition: self::positiveInt($data['series_position'] ?? null),
                seriesSize: self::positiveInt($data['series_size'] ?? null),
            );
        }

        $body = (string) ($data['body'] ?? '');
        $mentions = self::listOf($data['mentions'] ?? null, Mention::fromArray(...));
        $segments = is_array($data['body_segments'] ?? null) ? array_values(array_filter($data['body_segments'], is_array(...))) : null;
        $previewSegments = is_array($data['preview_segments'] ?? null) ? array_values(array_filter($data['preview_segments'], is_array(...))) : null;

        return new self(
            id: $id,
            body: $body,
            status: (string) ($data['status'] ?? 'approved'),
            author: User::fromArray(is_array($data['author'] ?? null) ? $data['author'] : []),
            createdAt: self::date($data['created_at'] ?? null),
            parentId: $parentId,
            repliesCount: $repliesCount,
            htmlUrl: is_string($data['html_url'] ?? null) ? $data['html_url'] : null,
            starsCount: max(0, (int) ($data['stars_count'] ?? 0)),
            starred: ($data['starred'] ?? false) === true,
            isPinned: ($data['is_pinned'] ?? false) === true,
            mentions: $mentions,
            linkPreview: is_array($data['link_preview'] ?? null) ? LinkPreview::fromArray($data['link_preview']) : null,
            images: self::listOf($data['images'] ?? null, CommitImage::fromArray(...)),
            content: CommitBody::blocks($body, $segments, $mentions),
            parent: is_array($data['parent'] ?? null) ? self::fromArray($data['parent']) : null,
            shortHash: is_string($data['short_hash'] ?? null) && $data['short_hash'] !== '' ? $data['short_hash'] : Display::shortHash($id),
            seriesId: is_string($data['series_id'] ?? null) ? $data['series_id'] : null,
            seriesPosition: self::positiveInt($data['series_position'] ?? null),
            seriesSize: self::positiveInt($data['series_size'] ?? null),
            poll: is_array($data['poll'] ?? null) ? Poll::fromArray($data['poll']) : null,
            quotedCommitId: is_scalar($data['quoted_commit_id'] ?? null) && (string) $data['quoted_commit_id'] !== '' ? (string) $data['quoted_commit_id'] : null,
            quotedCommit: is_array($data['quoted_commit'] ?? null) ? self::fromArray($data['quoted_commit']) : null,
            previewContent: $previewSegments === null ? null : CommitBody::blocks($body, $previewSegments, $mentions),
            replyPreview: is_array($data['reply_preview'] ?? null) ? ReplyPreview::fromArray($data['reply_preview']) : null,
            pasteisCount: max(0, (int) ($data['pasteis_count'] ?? 0)),
        );
    }

    /**
     * This commit with your star flipped and its count moved to match.
     */
    public function withStar(bool $starred): self
    {
        $change = $starred === $this->starred ? 0 : ($starred ? 1 : -1);

        return new self(...[...get_object_vars($this), 'starred' => $starred, 'starsCount' => max(0, $this->starsCount + $change)]);
    }

    /**
     * This commit with $change more pastéis than it has, or fewer for a negative change.
     */
    public function withMorePasteis(int $change): self
    {
        return new self(...[...get_object_vars($this), 'pasteisCount' => max(0, $this->pasteisCount + $change)]);
    }

    public function withPinned(bool $isPinned): self
    {
        return new self(...[...get_object_vars($this), 'isPinned' => $isPinned]);
    }

    /**
     * This commit with the poll a fresher copy of it has, as after you voted.
     */
    public function withPoll(?Poll $poll): self
    {
        return new self(...[...get_object_vars($this), 'poll' => $poll]);
    }

    /**
     * This commit with the star and pastéis state of a fresher copy of
     * it, keeping everything else, such as the parent a list loaded with it.
     */
    public function withReactionsFrom(self $fresh): self
    {
        return new self(...[
            ...get_object_vars($this),
            'starred' => $fresh->starred,
            'starsCount' => $fresh->starsCount,
            'pasteisCount' => $fresh->pasteisCount,
        ]);
    }

    /**
     * Whether a body is long enough to commit and short enough for devnull, once trimmed.
     */
    public static function fits(string $body): bool
    {
        $body = CommitBody::normalize($body);
        $length = CommitBody::characterCount($body);

        return mb_strlen($body) <= self::MaxRawLength
            && $length >= self::MinLength
            && $length <= self::MaxLength
            && ! CommitBody::hasOversizedUrl($body);
    }

    /**
     * Whether a list would show only the start of a body, as the web's composer
     * works it out: more than PreviewLength characters or PreviewLines lines,
     * once blank lines in a run are counted as one.
     */
    public static function isFoldedInLists(string $body): bool
    {
        $normalized = CommitBody::normalize($body);

        return mb_strlen($normalized) > self::PreviewLength || count(explode("\n", $normalized)) > self::PreviewLines;
    }

    /**
     * This commit with $update applied to the replies under it, as a feed draws them.
     *
     * @param  Closure(Commit): Commit  $update
     */
    public function withPreviewReplies(Closure $update): self
    {
        if ($this->replyPreview === null || $this->replyPreview->isEmpty()) {
            return $this;
        }

        return new self(...[...get_object_vars($this), 'replyPreview' => new ReplyPreview(array_map($update, $this->replyPreview->replies), $this->replyPreview->hasMore)]);
    }

    /**
     * Whether it quotes a commit, even one you may no longer see.
     */
    public function isQuote(): bool
    {
        return $this->quotedCommitId !== null;
    }

    /**
     * Whether a list shows only the start of it, with "Show more".
     */
    public function isFolded(): bool
    {
        return $this->previewContent !== null;
    }

    /**
     * The body as plain text, for an excerpt that can't link anything: prose
     * and inline code as written, minus the backticks, and code blocks whole.
     */
    public function plainText(): string
    {
        return implode("\n", array_map(
            fn (BodyBlock $block): string => $block->isCode()
                ? (string) $block->code
                : implode('', array_map(fn (BodyRun $run): string => $run->text, $block->runs)),
            $this->content,
        ));
    }

    /**
     * The links in the body, in the order it draws them. A long commit's
     * preview is cut at a space, so its links are the first of these.
     *
     * @return list<BodyLink>
     */
    public function links(): array
    {
        $links = [];

        foreach ($this->content as $block) {
            foreach ($block->runs as $run) {
                if ($run->link !== null) {
                    $links[] = $run->link;
                }
            }
        }

        return $links;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * One of a series of commits posted together, each a reply to the part before.
     * A deleted part keeps its place, so it knows its position without a series id.
     */
    public function isSeriesPart(): bool
    {
        return $this->seriesPosition !== null && $this->seriesSize !== null;
    }

    /**
     * How many parts of its series come after this one, for the web's
     * "2 more commits in this series" under a part shown on its own.
     */
    public function laterSeriesParts(): int
    {
        return $this->isSeriesPart() ? max(0, $this->seriesSize - $this->seriesPosition) : 0;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Whether $viewer may vote on the commit's poll, as the web decides it: someone else's published commit, whose
     * poll is open and hasn't had their vote yet.
     */
    public function canBeVotedOnBy(?User $viewer): bool
    {
        return $viewer !== null
            && $this->poll !== null
            && $this->isApproved()
            && $this->author->id !== $viewer->id
            && $this->poll->closesAt !== null
            && ! $this->poll->hasVoted
            && ! $this->poll->showsResults()
            && ! $this->poll->hasExpired();
    }

    /**
     * The author's lane, or Rail Gray for a deleted commit, which has no author.
     */
    public function lane(): int|string
    {
        return $this->isDeleted() ? Lane::Rail : $this->author->lane();
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @template TItem
     *
     * @param  callable(array<string, mixed>): ?TItem  $map
     * @return list<TItem>
     */
    private static function listOf(mixed $items, callable $map): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map($map, array_filter($items, is_array(...))), fn (mixed $item): bool => $item !== null));
    }
}
