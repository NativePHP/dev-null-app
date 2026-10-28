<?php

namespace App\Devnull\Data;

use App\Devnull\Display;
use Carbon\CarbonImmutable;

/**
 * A message in a Remotes conversation, as devnull sends it to someone in it:
 * its body, drawn from its segments the way a commit's is, the message it
 * replies to (only as much as the web's ghost shows), its link card, images
 * and reactions. In an org it says who wrote it, and an org's history also
 * logs who joined and left as messages with an event. A ping, as MSN
 * Messenger had, is an event too, in an org or with one person.
 *
 * A message you've just pushed shows straight away, as the web's do: staged
 * until devnull has it, or rejected with a retry when it couldn't take it,
 * its photos drawn from the phone meanwhile. Until then its id is 0 and its
 * client id is what ties the two together.
 */
final readonly class Message
{
    /**
     * The longest message the web's composer takes, in characters.
     */
    public const int MaxLength = 2000;

    public const string Pushed = 'pushed';

    public const string Staged = 'staged';

    public const string Rejected = 'rejected';

    public const string Ping = 'ping';

    /**
     * @param  list<BodyBlock>  $content
     * @param  array{id: int, user_id: string, body: string, images_count: int}|null  $replyTo
     * @param  list<CommitImage>  $images
     * @param  list<array{user_id: string, emoji: string}>  $reactions
     */
    public function __construct(
        public int $id,
        public string $userId,
        public string $clientId,
        public string $body,
        public array $content = [],
        public ?int $replyToId = null,
        public ?array $replyTo = null,
        public ?LinkPreview $linkPreview = null,
        public bool $linkPreviewPending = false,
        public array $images = [],
        public array $reactions = [],
        public ?string $event = null,
        public ?string $subjectId = null,
        public ?User $user = null,
        public ?User $subject = null,
        public ?CarbonImmutable $createdAt = null,
        public string $status = self::Pushed,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $body = is_string($data['body'] ?? null) ? $data['body'] : '';
        $replyTo = is_array($data['reply_to'] ?? null) ? $data['reply_to'] : null;
        $preview = is_array($data['link_preview'] ?? null) ? $data['link_preview'] : null;

        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            userId: (string) ($data['user_id'] ?? ''),
            clientId: (string) ($data['client_id'] ?? ''),
            body: $body,
            content: CommitBody::blocks(
                $body,
                is_array($data['body_segments'] ?? null) ? array_values(array_filter($data['body_segments'], is_array(...))) : null,
                array_values(array_filter(array_map(
                    fn (mixed $mention): ?Mention => is_array($mention) ? Mention::fromArray($mention) : null,
                    is_array($data['mentions'] ?? null) ? $data['mentions'] : [],
                ))),
            ),
            replyToId: is_int($data['reply_to_id'] ?? null) ? $data['reply_to_id'] : null,
            replyTo: $replyTo === null ? null : [
                'id' => (int) ($replyTo['id'] ?? 0),
                'user_id' => (string) ($replyTo['user_id'] ?? ''),
                'body' => is_string($replyTo['body'] ?? null) ? $replyTo['body'] : '',
                'images_count' => (int) ($replyTo['images_count'] ?? 0),
            ],
            linkPreview: $preview === null ? null : LinkPreview::fromArray($preview),
            linkPreviewPending: ($preview['is_pending'] ?? false) === true,
            images: array_values(array_filter(array_map(
                fn (mixed $image): ?CommitImage => is_array($image) ? CommitImage::fromArray($image) : null,
                is_array($data['images'] ?? null) ? $data['images'] : [],
            ))),
            reactions: self::reactionsFrom($data['reactions'] ?? []),
            event: is_string($data['event'] ?? null) ? $data['event'] : null,
            subjectId: is_string($data['subject_id'] ?? null) ? $data['subject_id'] : null,
            user: is_array($data['user'] ?? null) ? User::fromArray($data['user']) : null,
            subject: is_array($data['subject'] ?? null) ? User::fromArray($data['subject']) : null,
            createdAt: Commit::date($data['created_at'] ?? null),
        );
    }

    /**
     * A message you're pushing, shown staged until devnull has it.
     *
     * @param  list<DraftImage>  $images
     */
    public static function staged(string $userId, string $clientId, string $body, ?self $replyTo = null, array $images = []): self
    {
        return new self(
            id: 0,
            userId: $userId,
            clientId: $clientId,
            body: $body,
            content: CommitBody::blocks($body, null),
            replyToId: $replyTo?->id,
            replyTo: $replyTo === null ? null : ['id' => $replyTo->id, 'user_id' => $replyTo->userId, 'body' => $replyTo->body, 'images_count' => count($replyTo->images)],
            images: array_map(fn (DraftImage $image, int $index): CommitImage => $image->asCommitImage($index), $images, array_keys($images)),
            createdAt: CarbonImmutable::now(),
            status: self::Staged,
        );
    }

    /**
     * Reactions as devnull lists them, in the order they were left.
     *
     * @return list<array{user_id: string, emoji: string}>
     */
    public static function reactionsFrom(mixed $reactions): array
    {
        $kept = [];

        foreach (is_array($reactions) ? $reactions : [] as $reaction) {
            if (is_array($reaction) && is_string($reaction['emoji'] ?? null) && is_string($reaction['user_id'] ?? null)) {
                $kept[] = ['user_id' => $reaction['user_id'], 'emoji' => $reaction['emoji']];
            }
        }

        return $kept;
    }

    public function withReactions(array $reactions): self
    {
        return new self(...[...get_object_vars($this), 'reactions' => $reactions]);
    }

    public function withStatus(string $status): self
    {
        return new self(...[...get_object_vars($this), 'status' => $status]);
    }

    public function withLinkPreview(?LinkPreview $linkPreview, bool $pending): self
    {
        return new self(...[...get_object_vars($this), 'linkPreview' => $linkPreview, 'linkPreviewPending' => $pending]);
    }

    public function isEvent(): bool
    {
        return $this->event !== null;
    }

    public function isPing(): bool
    {
        return $this->event === self::Ping;
    }

    public function isPending(): bool
    {
        return $this->status !== self::Pushed;
    }

    /**
     * The chips under the message: each emoji once, in the order it was first left, with how many left it and
     * whether you're one of them.
     *
     * @return list<array{emoji: string, count: int, mine: bool}>
     */
    public function reactionChips(?string $viewerId): array
    {
        $chips = [];

        foreach ($this->reactions as $reaction) {
            $chips[$reaction['emoji']] ??= ['emoji' => $reaction['emoji'], 'count' => 0, 'mine' => false];
            $chips[$reaction['emoji']]['count']++;
            $chips[$reaction['emoji']]['mine'] = $chips[$reaction['emoji']]['mine'] || $reaction['user_id'] === $viewerId;
        }

        return array_values($chips);
    }

    public function hasReacted(?string $viewerId, string $emoji): bool
    {
        foreach ($this->reactions as $reaction) {
            if ($reaction['user_id'] === $viewerId && $reaction['emoji'] === $emoji) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one line a row of the inbox shows of the last message, as the web words it: "you: …" for yours, the first
     * name in an org for someone else's, "sent an image" for a message that is only images, and the event for an
     * org's log line.
     */
    public function preview(?string $viewerId, bool $inOrg): string
    {
        if ($this->isEvent()) {
            return $this->eventText($viewerId);
        }

        $text = trim((string) preg_replace('/\s+/', ' ', $this->body));
        $text = $text === '' ? 'sent an image' : $text;

        return match (true) {
            $this->userId === $viewerId => 'you: '.$text,
            $inOrg && $this->user !== null => strtok($this->user->name, ' ').': '.$text,
            default => $text,
        };
    }

    /**
     * An event, as the web logs it between messages: who did it, and to whom. In a thread with one person devnull
     * leaves the author off a ping, since it can only be them.
     */
    public function eventText(?string $viewerId, ?string $orgName = null): string
    {
        $actor = $this->userId === $viewerId ? 'you' : ($this->user === null ? 'someone' : $this->user->handle());
        $subject = $this->subjectId === $viewerId ? 'you' : ($this->subject === null ? 'someone' : $this->subject->handle());

        return match ($this->event) {
            'created' => $this->body !== '' ? "{$actor} created {$this->body}" : "{$actor} created the org",
            'added' => "{$actor} added {$subject}",
            'removed' => "{$actor} removed {$subject}",
            'left' => "{$actor} left",
            'renamed' => $this->body !== '' ? "{$actor} renamed the org to {$this->body}" : "{$actor} cleared the org's name",
            'promoted' => "{$actor} made {$subject} a maintainer",
            'demoted' => "{$actor} made {$subject} a contributor",
            'inherited' => "{$actor} inherited maintainer",
            self::Ping => match (true) {
                $this->userId === $viewerId => 'you sent a ping',
                $this->user !== null => "{$actor} sent a ping",
                default => 'you got a ping',
            },
            default => $actor,
        };
    }

    /**
     * What the ghost of a reply shows of the message it answers: one line of its body with the code and links
     * stripped, or that it was only images.
     */
    public function replySnippet(): string
    {
        return $this->replyTo === null ? '' : self::snippetOf($this->replyTo['body'], $this->replyTo['images_count']);
    }

    /**
     * The message itself in one line, as the ghost of a reply shows it, for the pinned bar over an org's thread.
     */
    public function snippet(): string
    {
        return self::snippetOf($this->body, count($this->images));
    }

    private static function snippetOf(string $body, int $images): string
    {
        $text = (string) preg_replace('/```[\s\S]*?```/', ' code ', $body);
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace(['/`/', '#https?://#i'], '', $text)));

        if ($text !== '') {
            return $text;
        }

        return $images > 1 ? $images.' images' : 'Image';
    }

    /**
     * The time under a group of messages, as the web prints it.
     */
    public function time(): string
    {
        return $this->createdAt === null ? '' : Display::clock($this->createdAt);
    }
}
