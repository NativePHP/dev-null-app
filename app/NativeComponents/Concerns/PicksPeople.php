<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Data\User;
use App\NativeComponents\NewOrg;

/**
 * The web's org people picker, for a new org or one you maintain: the people
 * you pick from a search, which you tap again to take back out. Someone who
 * can't join can't be picked, and nor can anyone else once the org would be
 * full, which their row then says, as on the web.
 */
trait PicksPeople
{
    /** @var list<User> */
    public array $picked = [];

    /**
     * Why devnull wouldn't take them, in its words, beside the button as on the web.
     */
    public ?string $pickError = null;

    /**
     * How many are in the org before anyone picked: you, for a new one.
     */
    abstract protected function alreadyIn(): int;

    /**
     * Picks someone, or takes them back out.
     */
    public function toggle(string $username): void
    {
        $this->pickError = null;

        if ($this->isPicked($username)) {
            $this->picked = array_values(array_filter($this->picked, fn (User $person): bool => $person->username !== $username));

            return;
        }

        $candidate = $this->candidate($username);

        if ($candidate === null || $candidate['reason'] !== null || $this->isFull()) {
            return;
        }

        $this->picked = [...$this->picked, $candidate['user']];
    }

    /**
     * Everyone the org would hold with the people picked.
     */
    protected function total(): int
    {
        return $this->alreadyIn() + count($this->picked);
    }

    protected function isFull(): bool
    {
        return $this->total() >= NewOrg::MaxMembers;
    }

    /**
     * @return list<string>
     */
    protected function pickedUsernames(): array
    {
        return array_map(fn (User $person): string => $person->username, $this->picked);
    }

    /**
     * Who the search found, picked or not, and why each one who can't be picked can't.
     *
     * @return list<array{user: User, isPicked: bool, reason: string|null}>
     */
    protected function candidateRows(): array
    {
        $isFull = $this->isFull();

        return array_map(fn (array $candidate): array => [
            'user' => $candidate['user'],
            'isPicked' => $this->isPicked($candidate['user']->username),
            'reason' => $candidate['reason'] ?? ($isFull && ! $this->isPicked($candidate['user']->username) ? 'the org is full ('.NewOrg::MaxMembers.')' : null),
        ], $this->candidates);
    }

    private function isPicked(string $username): bool
    {
        foreach ($this->picked as $person) {
            if ($person->username === $username) {
                return true;
            }
        }

        return false;
    }
}
