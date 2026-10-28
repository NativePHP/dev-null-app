<?php

namespace App\Devnull\Data;

use Carbon\CarbonImmutable;

/**
 * A Remotes thread as devnull sends it, for the first load, the page before a
 * message, or a poll for what came after one: its messages, oldest first, the
 * reactions that changed since the version a poll last saw, how far you and
 * the others have pulled, who is typing, and what the composer can do. An
 * org's also says which messages it pinned, latest pinned first, whatever
 * history you were given, unless a poll found them unchanged since the
 * reactions version it sent (pinning and unpinning move that version).
 *
 * `access` is the web's: open to push, a request of theirs to merge first,
 * your own request waiting on them, or nothing at all because they don't
 * follow you while requests are off (restricted) or have Remotes off (closed).
 * You may have pinned it in your inbox, or muted it until some time.
 */
final readonly class Thread
{
    public const string Open = 'open';

    public const string Request = 'request';

    public const string Waiting = 'waiting';

    public const string Restricted = 'restricted';

    public const string Closed = 'closed';

    /**
     * @param  list<Message>  $messages
     * @param  array<int, list<array{user_id: string, emoji: string}>>  $reactionChanges
     * @param  list<array{user: User, role: ?string, read_through_id: int}>  $members
     * @param  list<string>  $typing
     * @param  list<Message>|null  $pinned
     */
    public function __construct(
        public array $messages,
        public bool $hasOlder = false,
        public int $reactionsVersion = 0,
        public array $reactionChanges = [],
        public int $readThroughId = 0,
        public ?User $partner = null,
        public int $partnerReadThroughId = 0,
        public ?CarbonImmutable $partnerReadAt = null,
        public array $typing = [],
        public bool $isArchived = false,
        public string $access = self::Open,
        public ?string $orgId = null,
        public ?string $orgName = null,
        public array $members = [],
        public ?string $role = null,
        public ?array $pinned = null,
        public ?CarbonImmutable $pinnedAt = null,
        public ?CarbonImmutable $mutedUntilAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $changes = [];
        $members = [];

        foreach (is_array($data['reaction_changes'] ?? null) ? $data['reaction_changes'] : [] as $change) {
            if (is_array($change) && is_int($change['id'] ?? null)) {
                $changes[$change['id']] = Message::reactionsFrom($change['reactions'] ?? []);
            }
        }

        foreach (is_array($data['members'] ?? null) ? $data['members'] : [] as $member) {
            if (is_array($member) && is_array($member['user'] ?? null)) {
                $members[] = [
                    'user' => User::fromArray($member['user']),
                    'role' => is_string($member['role'] ?? null) ? $member['role'] : null,
                    'read_through_id' => is_int($member['read_through_id'] ?? null) ? $member['read_through_id'] : 0,
                ];
            }
        }

        $partner = is_array($data['partner'] ?? null) ? User::fromArray($data['partner']) : null;
        $org = is_array($data['org'] ?? null) ? $data['org'] : null;
        $typing = is_array($data['typing'] ?? null) ? array_values(array_filter($data['typing'], is_string(...))) : [];

        return new self(
            messages: self::messagesFrom(is_array($data['messages'] ?? null) ? $data['messages'] : []),
            hasOlder: ($data['has_older'] ?? false) === true,
            reactionsVersion: is_int($data['reactions_version'] ?? null) ? $data['reactions_version'] : 0,
            reactionChanges: $changes,
            readThroughId: is_int($data['read_through_id'] ?? null) ? $data['read_through_id'] : 0,
            partner: $partner,
            partnerReadThroughId: is_int($data['partner_read_through_id'] ?? null) ? $data['partner_read_through_id'] : 0,
            partnerReadAt: Commit::date($data['partner_read_at'] ?? null),
            typing: $partner !== null && ($data['partner_typing'] ?? false) === true ? [$partner->username] : $typing,
            isArchived: ($data['is_archived'] ?? false) === true,
            access: in_array($data['access'] ?? null, [self::Request, self::Waiting, self::Restricted, self::Closed], true) ? $data['access'] : self::Open,
            orgId: $org === null ? null : (string) ($org['id'] ?? ''),
            orgName: is_string($org['name'] ?? null) && $org['name'] !== '' ? $org['name'] : null,
            members: $members,
            role: is_string($data['role'] ?? null) ? $data['role'] : null,
            pinned: is_array($data['pinned'] ?? null) ? self::messagesFrom($data['pinned']) : null,
            pinnedAt: Commit::date($data['pinned_at'] ?? null),
            mutedUntilAt: Commit::date($data['muted_until_at'] ?? null),
        );
    }

    /**
     * @param  array<mixed>  $messages
     * @return list<Message>
     */
    private static function messagesFrom(array $messages): array
    {
        return array_values(array_map(fn (array $message): Message => Message::fromArray($message), array_filter($messages, is_array(...))));
    }

    public function isOrg(): bool
    {
        return $this->orgId !== null;
    }

    public function isMuted(): bool
    {
        return $this->mutedUntilAt !== null && $this->mutedUntilAt->isFuture();
    }
}
