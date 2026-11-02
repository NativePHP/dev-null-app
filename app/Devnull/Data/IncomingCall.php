<?php

namespace App\Devnull\Data;

/**
 * A call ringing for you: who's calling, the offer to answer it with, and whether their thread is muted, which
 * rings it silently.
 */
final readonly class IncomingCall
{
    public function __construct(
        public User $partner,
        public string $offer,
        public bool $isMuted,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            partner: User::fromArray(is_array($data['partner'] ?? null) ? $data['partner'] : []),
            offer: is_string($data['sdp'] ?? null) ? $data['sdp'] : '',
            isMuted: ($data['is_muted'] ?? false) === true,
        );
    }
}
