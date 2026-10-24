<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\SuggestedUser;
use App\Devnull\WhoToFollow;

/**
 * Who to follow, as the web's log shows it on smaller screens: three people
 * you might want to follow, each with why, Follow and Not interested, with a
 * refresh for the next few and a way to hide them, which Settings brings back.
 *
 * devnull sends three spares with the three to show, so one marked Not
 * interested gives their place to a spare at once, as on the web.
 */
trait SuggestsWhoToFollow
{
    /**
     * How many people it shows at a time. The rest are spares.
     */
    public const int ShownSuggestions = 3;

    /**
     * Who to follow sits under this many commits, as on the web.
     */
    public const int WhoToFollowAfter = 5;

    /**
     * The people to show first, then the spares.
     *
     * @var list<SuggestedUser>
     */
    public array $suggestedUsers = [];

    /**
     * What who to follow says instead of people: 'hidden' just after you hid
     * it, with an Undo, and 'emptied' once devnull has nobody left to suggest
     * after you followed or dismissed the rest.
     */
    public ?string $whoToFollowNote = null;

    /**
     * The next few people are on their way, so the refresh waits for them.
     */
    public bool $suggestingOtherPeople = false;

    /**
     * The round whose people the screen is waiting for. An answer for any
     * other round, sent before a refresh, is left alone.
     */
    protected ?int $wantedRound = null;

    /**
     * Goes up with every follow and dismissal here, so an answer sent before
     * one of them is asked again rather than taken.
     */
    protected int $whoToFollowChanges = 0;

    /**
     * The people you dismissed while the Log was open, which an answer that
     * crossed with the dismissal can't bring back.
     *
     * @var list<string>
     */
    protected array $dismissedSuggestions = [];

    /**
     * Asks devnull for the round showing, as the web loads who to follow
     * after the page. They're only kept while the Log is open, as the web
     * keeps them for the visit: devnull picks afresh each day, so a kept
     * page would be yesterday's. Coming back to the Log asks again, since a
     * follow, mute or block elsewhere changes who's suggested.
     */
    protected function openWhoToFollow(bool $again = false): void
    {
        $userId = $this->me()?->id;

        if (! app(WhoToFollow::class)->shows($userId) || $this->suggestingOtherPeople) {
            return;
        }

        $this->wantedRound = app(WhoToFollow::class)->round($userId);
        $this->askForSuggestedUsers($this->wantedRound, again: $again);
    }

    /**
     * The refresh button: the next few people, as the web's moves to the next
     * page of the day's picks. Who's showing stays until they land, and the
     * round only moves on then.
     */
    public function suggestOtherPeople(): void
    {
        if ($this->suggestingOtherPeople) {
            return;
        }

        $this->suggestingOtherPeople = true;
        $this->wantedRound = app(WhoToFollow::class)->round($this->me()?->id) + 1;
        $this->askForSuggestedUsers($this->wantedRound, again: true);
    }

    /**
     * Follows someone suggested, or unfollows them again, straight away, and puts it back if devnull says no.
     */
    public function toggleSuggestedFollow(string $username): void
    {
        $suggested = $this->findSuggested($username);

        if ($suggested === null) {
            return;
        }

        $following = ! $suggested->isFollowed;
        $this->whoToFollowChanges++;
        $this->setSuggestedFollowed($username, $following);

        $done = $this->attempt(function (Client $devnull) use ($username, $following): bool {
            $following ? $devnull->follow($username) : $devnull->unfollow($username);

            return true;
        });

        if ($done !== true) {
            $this->setSuggestedFollowed($username, ! $following);
        }
    }

    /**
     * Not interested: they leave for good, and the first spare takes their
     * place. Then devnull is asked for the round again, as the web tops its
     * suggestions up, so the spares never run out while it has people left.
     * Who's showing stays where they are: the answer only adds new spares.
     */
    public function dismissSuggestion(string $username): void
    {
        $before = $this->suggestedUsers;
        $slot = array_search($username, array_map(fn (SuggestedUser $suggested): string => $suggested->user->username, $before), true);

        if ($slot === false) {
            return;
        }

        $remaining = $before;
        $spare = $slot < self::ShownSuggestions ? array_splice($remaining, self::ShownSuggestions, 1) : [];
        array_splice($remaining, $slot, 1, $spare);
        $this->suggestedUsers = $remaining;
        $this->whoToFollowNote = $remaining === [] ? 'emptied' : null;
        $this->whoToFollowChanges++;

        $done = $this->attempt(function (Client $devnull) use ($username): bool {
            $devnull->dismissSuggestion($username);

            return true;
        });

        if ($done !== true) {
            $this->suggestedUsers = $before;
            $this->whoToFollowNote = null;

            return;
        }

        $this->dismissedSuggestions[] = $username;

        if ($this->wantedRound !== null && ! $this->suggestingOtherPeople) {
            $this->askForSuggestedUsers($this->wantedRound, again: true, topUp: true);
        }
    }

    /**
     * Don't show again. It stays in place with an Undo until the Log opens again.
     */
    public function hideWhoToFollow(): void
    {
        app(WhoToFollow::class)->show($this->me()?->id, false);
        $this->whoToFollowNote = 'hidden';
    }

    public function undoHideWhoToFollow(): void
    {
        app(WhoToFollow::class)->show($this->me()?->id, true);
        $this->whoToFollowNote = $this->suggestedUsers === [] ? 'emptied' : null;
    }

    public function openSuggestedUser(string $username): void
    {
        $suggested = $this->findSuggested($username);

        $this->openInTab('/users/'.rawurlencode($username), $suggested === null ? [] : ['user' => $suggested->user]);
    }

    /**
     * Whether the log has who to follow in it: it's on, and it has people
     * to show, or something to say about why it hasn't.
     */
    protected function showsWhoToFollow(): bool
    {
        return $this->whoToFollowNote !== null
            || ($this->suggestedUsers !== [] && app(WhoToFollow::class)->shows($this->me()?->id));
    }

    /**
     * What the who to follow row draws.
     *
     * @return array{people: list<SuggestedUser>, note: string|null, refreshing: bool}
     */
    protected function whoToFollow(): array
    {
        return [
            'people' => $this->whoToFollowNote === 'hidden' ? [] : array_slice($this->suggestedUsers, 0, self::ShownSuggestions),
            'note' => $this->whoToFollowNote,
            'refreshing' => $this->suggestingOtherPeople,
        ];
    }

    /**
     * Asks devnull for a round of suggestions in the background. An answer
     * for a round you've moved on from is left alone, and one sent before a
     * follow or a dismissal here is asked for again, so it can't undo either.
     */
    private function askForSuggestedUsers(int $round, bool $again = false, bool $topUp = false): void
    {
        $changes = $this->whoToFollowChanges;

        $this->refresh(Client::SuggestedUsersPath, ['round' => (string) $round], function (array $json) use ($round, $changes, $topUp): void {
            if ($round !== $this->wantedRound) {
                return;
            }

            if ($changes !== $this->whoToFollowChanges) {
                $this->askForSuggestedUsers($round, again: true, topUp: $topUp);

                return;
            }

            if ($topUp) {
                $this->topUpSuggestedUsers($json);

                return;
            }

            if ($this->suggestingOtherPeople) {
                $this->suggestingOtherPeople = false;
                app(WhoToFollow::class)->rememberRound($this->me()?->id, $round);
            }

            $this->takeSuggestedUsers($json);
        }, again: $again, quiet: true, failed: function () use ($round): void {
            if ($this->suggestingOtherPeople && $round === $this->wantedRound) {
                $this->suggestingOtherPeople = false;
                $this->wantedRound = app(WhoToFollow::class)->round($this->me()?->id);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeSuggestedUsers(array $json): void
    {
        $this->showSuggestedUsers($this->freshSuggestedUsers($json));
    }

    /**
     * Keeps who's showing, followed or not, in their places, and puts the
     * people devnull has that aren't there yet under them as spares, as the
     * web merges its top-up by id.
     *
     * @param  array<string, mixed>  $json
     */
    private function topUpSuggestedUsers(array $json): void
    {
        $showing = array_map(fn (SuggestedUser $suggested): string => $suggested->id, $this->suggestedUsers);
        $new = array_filter($this->freshSuggestedUsers($json), fn (SuggestedUser $suggested): bool => ! in_array($suggested->id, $showing, true));

        $this->showSuggestedUsers([...$this->suggestedUsers, ...array_values($new)]);
    }

    /**
     * The people in an answer, without anyone dismissed here since it was sent.
     *
     * @param  array<string, mixed>  $json
     * @return list<SuggestedUser>
     */
    private function freshSuggestedUsers(array $json): array
    {
        return array_values(array_filter(
            array_map(SuggestedUser::fromArray(...), array_filter(is_array($json['data'] ?? null) ? $json['data'] : [], is_array(...))),
            fn (SuggestedUser $suggested): bool => ! in_array($suggested->user->username, $this->dismissedSuggestions, true),
        ));
    }

    /**
     * @param  list<SuggestedUser>  $fresh
     */
    private function showSuggestedUsers(array $fresh): void
    {
        $this->suggestedUsers = $fresh;

        if ($this->whoToFollowNote !== 'hidden') {
            $this->whoToFollowNote = $fresh === [] && $this->dismissedSuggestions !== [] ? 'emptied' : null;
        }
    }

    private function findSuggested(string $username): ?SuggestedUser
    {
        foreach ($this->suggestedUsers as $suggested) {
            if ($suggested->user->username === $username) {
                return $suggested;
            }
        }

        return null;
    }

    private function setSuggestedFollowed(string $username, bool $following): void
    {
        $this->suggestedUsers = array_map(fn (SuggestedUser $suggested): SuggestedUser => $suggested->user->username === $username
            ? $suggested->withFollowed($following)
            : $suggested, $this->suggestedUsers);
    }
}
