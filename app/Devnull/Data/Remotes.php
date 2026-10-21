<?php

namespace App\Devnull\Data;

/**
 * Your Remotes, as `GET /me` sends them while you have them on: who can push
 * to you (people you follow, or everyone), whether you share pulls (read
 * receipts), whether devnull takes requests from people you don't follow and
 * offers Everyone at all, and the unread count the web's nav shows.
 */
final readonly class Remotes
{
    public const string Following = 'following';

    public const string Everyone = 'everyone';

    public function __construct(
        public string $from = self::Following,
        public bool $pullReceipts = true,
        public bool $requests = false,
        public bool $everyone = false,
        public int $unreadCount = 0,
    ) {}

    /**
     * The `remotes` of `GET /me`, or null while you have Remotes off.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        return new self(
            from: ($data['from'] ?? null) === self::Everyone ? self::Everyone : self::Following,
            pullReceipts: ($data['pull_receipts'] ?? true) !== false,
            requests: ($data['requests'] ?? false) === true,
            everyone: ($data['everyone'] ?? false) === true,
            unreadCount: is_int($data['unread_count'] ?? null) ? max(0, $data['unread_count']) : 0,
        );
    }

    /**
     * @return array{from: string, pull_receipts: bool, requests: bool, everyone: bool, unread_count: int}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'pull_receipts' => $this->pullReceipts,
            'requests' => $this->requests,
            'everyone' => $this->everyone,
            'unread_count' => $this->unreadCount,
        ];
    }

    public function with(string $from, bool $pullReceipts): self
    {
        return new self($from, $pullReceipts, $this->requests, $this->everyone, $this->unreadCount);
    }

    public function withUnreadCount(int $unreadCount): self
    {
        return new self($this->from, $this->pullReceipts, $this->requests, $this->everyone, max(0, $unreadCount));
    }
}
