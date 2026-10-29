<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Data\Page;
use App\Devnull\Data\User;

/**
 * The web's Starred by dialog (see partials/stargazers-sheet), which the star
 * on one of your own commits opens once it has stars, since devnull doesn't
 * let you star your own: who starred it, newest first, 20 at a time, each
 * opening their profile. As on the web, only you see who starred your
 * commits, and it asks devnull again each time it opens, so stars given in
 * the meantime show.
 */
trait OpensStargazers
{
    /**
     * The commit whose stargazers the sheet lists, from the tap until iOS says the sheet has gone.
     */
    public ?string $stargazersOf = null;

    /**
     * How many stars the commit had when the sheet opened, as the web's dialog counts them.
     */
    public int $stargazersCount = 0;

    /** @var list<User> */
    public array $stargazers = [];

    public ?string $stargazersCursor = null;

    public bool $loadingStargazers = false;

    /**
     * Whether the last page didn't load, which the sheet says as the web does, with Try again.
     */
    public bool $stargazersFailed = false;

    /**
     * The load the sheet is waiting for. An answer to an earlier one, sent
     * before the sheet closed or opened again, is left alone.
     */
    protected int $stargazersLoad = 0;

    public function openStargazers(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || $commit->isDeleted() || $commit->starsCount === 0 || $commit->author->id !== $this->me()?->id) {
            return;
        }

        $this->stargazersClosed();
        $this->stargazersOf = $id;
        $this->stargazersCount = $commit->starsCount;
        $this->loadStargazers();
    }

    /**
     * The first page, then the next for Show more, or again for Try again.
     */
    public function loadStargazers(): void
    {
        $id = $this->stargazersOf;

        if ($id === null || $this->loadingStargazers) {
            return;
        }

        $load = ++$this->stargazersLoad;
        $this->loadingStargazers = true;
        $this->stargazersFailed = false;

        $this->refresh('commits/'.rawurlencode($id).'/stars', ['cursor' => $this->stargazersCursor], function (array $json) use ($load): void {
            if ($load !== $this->stargazersLoad) {
                return;
            }

            $page = Page::fromArray($json, User::fromArray(...));
            $this->stargazers = [...$this->stargazers, ...$page->items];
            $this->stargazersCursor = $page->nextCursor;
            $this->loadingStargazers = false;
        }, again: true, quiet: true, failed: function () use ($load): void {
            if ($load === $this->stargazersLoad) {
                $this->loadingStargazers = false;
                $this->stargazersFailed = true;
            }
        });
    }

    public function openStargazerProfile(string $username): void
    {
        $stargazer = array_values(array_filter($this->stargazers, fn (User $user): bool => $user->username === $username))[0] ?? null;

        if ($stargazer === null) {
            return;
        }

        $this->stargazersClosed();
        $this->openInTab('/users/'.rawurlencode($username), ['user' => $stargazer]);
    }

    /**
     * iOS says the sheet has gone.
     */
    public function stargazersClosed(): void
    {
        $this->stargazersLoad++;
        $this->stargazersOf = null;
        $this->stargazersCount = 0;
        $this->stargazers = [];
        $this->stargazersCursor = null;
        $this->loadingStargazers = false;
        $this->stargazersFailed = false;
    }
}
