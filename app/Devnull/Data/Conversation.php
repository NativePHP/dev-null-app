<?php

namespace App\Devnull\Data;

use App\Devnull\Client;
use Carbon\CarbonImmutable;

/**
 * A row of the Remotes inbox, as devnull sends it: the person you talk to, or
 * an org's name and members, the last message, how many messages from others
 * you haven't pulled yet (the web's "ahead"), how far the partner pulled when
 * you both share pulls, whether you stashed it, when you pinned it, and until
 * when you muted it.
 */
final readonly class Conversation
{
    /**
     * @param  list<array{user: User, role: ?string}>  $members
     */
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $name,
        public ?User $partner,
        public array $members,
        public ?Message $lastMessage,
        public ?CarbonImmutable $lastMessageAt,
        public int $ahead = 0,
        public int $partnerReadThroughId = 0,
        public bool $isArchived = false,
        public ?CarbonImmutable $pinnedAt = null,
        public ?CarbonImmutable $mutedUntilAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $members = [];

        foreach (is_array($data['members'] ?? null) ? $data['members'] : [] as $member) {
            if (is_array($member) && is_array($member['user'] ?? null)) {
                $members[] = ['user' => User::fromArray($member['user']), 'role' => is_string($member['role'] ?? null) ? $member['role'] : null];
            }
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            kind: ($data['kind'] ?? 'direct') === 'org' ? 'org' : 'direct',
            name: is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : null,
            partner: is_array($data['partner'] ?? null) ? User::fromArray($data['partner']) : null,
            members: $members,
            lastMessage: is_array($data['last_message'] ?? null) ? Message::fromArray($data['last_message']) : null,
            lastMessageAt: Commit::date($data['last_message_at'] ?? null),
            ahead: is_int($data['ahead'] ?? null) ? max(0, $data['ahead']) : 0,
            partnerReadThroughId: is_int($data['partner_read_through_id'] ?? null) ? $data['partner_read_through_id'] : 0,
            isArchived: ($data['is_archived'] ?? false) === true,
            pinnedAt: Commit::date($data['pinned_at'] ?? null),
            mutedUntilAt: Commit::date($data['muted_until_at'] ?? null),
        );
    }

    /**
     * The same row with some of it changed, as a write changes it before devnull answers.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    public function isOrg(): bool
    {
        return $this->kind === 'org';
    }

    public function isPinned(): bool
    {
        return $this->pinnedAt !== null;
    }

    /**
     * Muted until a moment still to come, as the web reads it.
     */
    public function isMuted(): bool
    {
        return $this->mutedUntilAt !== null && $this->mutedUntilAt->isFuture();
    }

    /**
     * What the web's menu and toasts call it: the partner's handle, or the org's title.
     */
    public function menuTitle(?string $viewerId): string
    {
        return $this->isOrg() ? $this->title($viewerId) : ($this->partner?->handle() ?? '');
    }

    /**
     * Where devnull keeps its thread, and takes its pin, mute and stash.
     */
    public function threadPath(): string
    {
        return Client::threadPath($this->partner->username ?? '', $this->isOrg() ? $this->id : null);
    }

    /**
     * The everyone else in an org, in the order they joined.
     *
     * @return list<User>
     */
    public function others(?string $viewerId): array
    {
        return array_values(array_map(
            fn (array $member): User => $member['user'],
            array_filter($this->members, fn (array $member): bool => $member['user']->id !== $viewerId),
        ));
    }

    /**
     * What the row and the thread are called: the partner's name, or an org's name, or else the first names of
     * the others in it, as the web titles an org it hasn't named.
     */
    public function title(?string $viewerId): string
    {
        if (! $this->isOrg()) {
            return $this->partner->name ?? '';
        }

        if ($this->name !== null) {
            return $this->name;
        }

        $names = array_map(fn (User $user): string => (string) strtok($user->name, ' '), $this->others($viewerId));

        return $names === [] ? 'Just you' : implode(', ', $names);
    }

    /**
     * Where its thread opens in the Remotes tab.
     */
    public function path(): string
    {
        return $this->isOrg() ? '/orgs/'.rawurlencode($this->id) : '/conversations/'.rawurlencode($this->partner->username ?? '');
    }

    /**
     * Whether the last message is yours and the partner has pulled it, which the row marks as the web does.
     */
    public function lastPulled(): bool
    {
        return $this->lastMessage !== null && $this->partnerReadThroughId >= $this->lastMessage->id;
    }
}
