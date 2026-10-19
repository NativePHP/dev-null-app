<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\User;
use App\Devnull\Exceptions\ApiException;

/**
 * Finds developers from the tab bar's search, on whichever screen is showing.
 *
 * The search tab stays in the bar only while the screen overrides
 * onSearchQuery, so every screen under the tabs uses this. A person opens
 * beneath the tab you were on. The query goes to the web's public user
 * search, which sits beside the signed API.
 */
trait SearchesDevnull
{
    /**
     * @return list<array{title: string, subtitle: string, method: string, args: list<string>}>
     */
    public function onSearchQuery(string $query): array
    {
        try {
            $people = app(Client::class)->searchUsers($query);
        } catch (ApiException) {
            return [];
        }

        return array_map(fn (User $user): array => [
            'title' => $user->name,
            'subtitle' => $user->handle(),
            'method' => 'openFoundUser',
            'args' => [$user->username],
        ], $people);
    }

    /**
     * Opens someone the search found, under the tab that was showing.
     */
    public function openFoundUser(string $username): void
    {
        $this->openInTab('/users/'.rawurlencode($username));
    }
}
