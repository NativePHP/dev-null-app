<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use App\Devnull\Data\User;

/**
 * Which commits are yours, as devnull decides it: your own, and every commit
 * from an organization you're a member of, whoever in it posted it. It's the
 * server's Commit::isOwnedBy() and the web's useOwnsCommit(), and the app asks
 * it wherever they do. Any member can delete an organization's commit, quote
 * it while it waits for approval, and sees its star count rather than a star,
 * and none of them can star it, report it or vote on its poll.
 *
 * Pins are a person's alone (isYourOwn()): devnull pins a commit to its
 * author's profile, which an organization doesn't have.
 *
 * Your organizations are the ones GET /me last listed (Account::organizations()),
 * so a membership that changed since counts from the next time /me answers.
 * Until then devnull turns down what only a member can do, in its own words.
 */
final readonly class Ownership
{
    /**
     * @param  list<string>  $organizationIds
     */
    public function __construct(
        public ?string $userId = null,
        public array $organizationIds = [],
    ) {}

    /**
     * @param  list<User>  $organizations
     */
    public static function of(?User $user, array $organizations = []): self
    {
        $userId = $user === null || $user->id === '' ? null : $user->id;

        if ($userId === null) {
            return new self;
        }

        return new self($userId, array_values(array_filter(
            array_map(fn (User $organization): string => $organization->id, $organizations),
            fn (string $id): bool => $id !== '' && $id !== $userId,
        )));
    }

    public function isSignedIn(): bool
    {
        return $this->userId !== null;
    }

    /**
     * Whether the commit is yours: by you, or by an organization you're in.
     */
    public function owns(Commit $commit): bool
    {
        $author = $commit->author->id;

        return $this->userId !== null && $author !== '' && ($author === $this->userId || in_array($author, $this->organizationIds, true));
    }

    /**
     * Whether you made the commit as yourself, not as an organization.
     */
    public function isYourOwn(Commit $commit): bool
    {
        return $this->userId !== null && $commit->author->id === $this->userId;
    }
}
