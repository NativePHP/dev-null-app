<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\Connection;
use App\Devnull\Data\Page;
use App\Devnull\ErrorCode;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\FreshPage;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * A profile's followers, or the people it follows, as the web's connections
 * page lists them: newest follow first, each with their avatar ringed in their
 * lane, their name, handle and bio, and a Follow button. A tap opens them.
 *
 * Follow and Following flip straight away and go back if devnull says no,
 * as on a profile. Unfollowing someone on your own Following keeps them in the
 * list as Follow, as the web does, so a slip is one tap to undo. The web has
 * no way to remove a follower, so neither has this.
 *
 * It opens on the first page as it was last time, then asks devnull again
 * behind it. Across a block devnull lists neither of two people the other's
 * connections, as with a profile (see Profile).
 */
class Connections extends NativeComponent
{
    use OpensInTab;
    use RefreshesInBackground;
    use SyncsOutbox;
    use TalksToDevnull;

    public const array Types = ['followers', 'following'];

    public string $username = '';

    public string $type = 'followers';

    /** @var list<Connection> */
    public array $people = [];

    public ?string $nextCursor = null;

    /**
     * Whether the list has had people to show, kept or fresh. Until then it shows skeleton rows.
     */
    public bool $loaded = false;

    /**
     * Why devnull won't list them, when it won't: user_blocked or user_unavailable.
     */
    public ?string $unavailable = null;

    public function mount(): void
    {
        $this->showKept();
        $this->revalidate();
    }

    /**
     * Back from someone's profile: take a follow made there from the cache at once, then ask devnull.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return '@'.$this->username."'s ".$this->type;
    }

    /**
     * Pull to refresh.
     */
    public function load(): void
    {
        $this->refresh($this->listPath(), [], $this->takeFresh(...), again: true);
    }

    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        $page = $this->attempt(fn (Client $devnull): Page => $devnull->connections($this->username, $this->type, $this->nextCursor));

        if ($page !== null) {
            $this->people = [...$this->people, ...$page->items];
            $this->nextCursor = $page->nextCursor;
        }
    }

    public function open(string $username): void
    {
        $person = $this->find($username);

        if ($person !== null) {
            $this->openInTab('/users/'.rawurlencode($person->user->username), ['user' => $person->user]);
        }
    }

    /**
     * Follows or unfollows straight away, and puts it back if the server says no.
     */
    public function toggleFollow(string $username): void
    {
        $person = $this->find($username);

        if ($person === null || $this->isMe($username)) {
            return;
        }

        $following = ! $person->isFollowed;
        $this->setFollowed($username, $following);

        $done = $this->attempt(function (Client $devnull) use ($username, $following): bool {
            $following ? $devnull->follow($username) : $devnull->unfollow($username);

            return true;
        });

        if ($done !== true) {
            $this->setFollowed($username, ! $following);
        }
    }

    /**
     * The web's muted and blocked people, where a block is undone, since the app can't unblock yet.
     */
    public function openSafety(): void
    {
        Browser::inApp(rtrim((string) config('devnull.url'), '/').'/settings/safety');
    }

    public function isMe(string $username): bool
    {
        return $this->me()?->username === $username;
    }

    protected function revalidate(): void
    {
        $this->refresh($this->listPath(), [], $this->takeFresh(...));
    }

    protected function showsContent(): bool
    {
        return $this->loaded && $this->unavailable === null;
    }

    /**
     * A list across a block says so as the profile does, whatever devnull's message says.
     */
    protected function refused(ApiException $exception): bool
    {
        if (! $exception->is(ErrorCode::UserBlocked) && ! $exception->is(ErrorCode::UserUnavailable)) {
            return false;
        }

        $this->unavailable = $exception->errorCode;
        $this->people = [];
        $this->nextCursor = null;
        $this->error = null;

        return true;
    }

    private function showKept(): void
    {
        if (($json = $this->cached($this->listPath())) !== null) {
            $this->takeFresh($json);
        }
    }

    /**
     * The fresh first page over what's showing: new people go on top, people
     * no longer in it go, and pages loaded further down stay. A first page
     * with no more after it is the whole list, so it replaces what's showing.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeFresh(array $json): void
    {
        $page = Page::fromArray($json, Connection::fromArray(...));

        $fresh = $page->hasMore()
            ? FreshPage::over($this->people, $this->nextCursor, $page)->withNewer()
            : new FreshPage($page->items, null);

        $this->people = $fresh->items;
        $this->nextCursor = $fresh->nextCursor;
        $this->loaded = true;
        $this->unavailable = null;
    }

    private function setFollowed(string $username, bool $following): void
    {
        $this->people = array_map(
            fn (Connection $person): Connection => $person->user->username === $username ? $person->withFollowed($following) : $person,
            $this->people,
        );
    }

    private function find(string $username): ?Connection
    {
        foreach ($this->people as $person) {
            if ($person->user->username === $username) {
                return $person;
            }
        }

        return null;
    }

    private function listPath(): string
    {
        return Client::connectionsPath($this->username, $this->type);
    }

    public function render(): View
    {
        return view('native.connections', [
            'isBlocked' => $this->unavailable === ErrorCode::UserBlocked->value,
            'isOwn' => $this->isMe($this->username),
            'viewer' => $this->me(),
            'myColour' => $this->myColour(),
        ]);
    }
}
