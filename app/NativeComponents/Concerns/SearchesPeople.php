<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\User;

/**
 * The web's people pickers for Remotes: as you type a name or handle, the
 * developers devnull finds, each with the reason you can't reach them when
 * there is one, the reachable ones first, as the web sorts them.
 */
trait SearchesPeople
{
    public string $query = '';

    /** @var list<array{user: User, reason: string|null}> */
    public array $candidates = [];

    /**
     * The term the candidates were found for, so they never show under one they weren't.
     */
    public ?string $searched = null;

    /**
     * The term as the web searches it, without the @ a handle is typed with.
     */
    protected function term(): string
    {
        return ltrim(trim($this->query), '@');
    }

    /**
     * Who could start a remote with you, or join a new org: `$for` is "remote" or "org". With `$orgId`, who could
     * join that org.
     */
    protected function searchPeople(string $for, ?string $orgId = null): void
    {
        $term = $this->term();

        if ($term === '') {
            $this->candidates = [];
            $this->searched = null;

            return;
        }

        $found = $this->attempt(fn (Client $devnull): array => $devnull->remoteCandidates($term, $for, $orgId));

        if ($found === null) {
            return;
        }

        usort($found, fn (array $a, array $b): int => ($a['reason'] !== null) <=> ($b['reason'] !== null));

        $this->candidates = $found;
        $this->searched = $term;
    }

    /**
     * @return array{user: User, reason: string|null}|null
     */
    protected function candidate(string $username): ?array
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate['user']->username === $username) {
                return $candidate;
            }
        }

        return null;
    }
}
