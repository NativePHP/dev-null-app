<?php

namespace App\Devnull\Data;

/**
 * Someone in a profile's followers or following, as the web lists them, with
 * whether you follow them and whether they follow you.
 */
final readonly class Connection
{
    /**
     * Their user id, so a fresh page can be laid over the list (see FreshPage).
     */
    public string $id;

    public function __construct(
        public User $user,
        public bool $isFollowed = false,
        public bool $followsYou = false,
    ) {
        $this->id = $user->id;
    }

    /**
     * Reads one of `GET /users/{username}/followers` or `/following`: the user,
     * with `is_followed` and `follows_you` beside their profile.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            user: User::fromArray($data),
            isFollowed: ($data['is_followed'] ?? false) === true,
            followsYou: ($data['follows_you'] ?? false) === true,
        );
    }

    public function withFollowed(bool $isFollowed): self
    {
        return new self($this->user, $isFollowed, $this->followsYou);
    }
}
