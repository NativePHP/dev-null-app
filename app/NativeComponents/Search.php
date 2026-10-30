<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Connection;
use App\Devnull\RowCache;
use App\Devnull\Stash;
use App\Devnull\Timeline;
use App\NativeComponents\Concerns\ExpandsThreads;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\OpensLiveStream;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\ShowsCommits;
use App\NativeComponents\Concerns\TalksToDevnull;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The web's search, as its phone header opens it: one field for developers or
 * commits. As you type, the developers whose name or handle matches come
 * first, with Follow beside them, then the commits nearest in meaning to what
 * you typed, closest first. Before you type it offers the web's searches to
 * try.
 *
 * Both are asked for on a background thread, and each answer only lands
 * under the term it was found for. What's showing stays while the next term's
 * answer is on its way, as on the web, so the list doesn't blink as you type.
 * devnull won't search commits for fewer than three characters, so a shorter
 * term only finds developers.
 *
 * Neither answer is kept (see ResponseCache): a search is about the moment.
 */
class Search extends NativeComponent
{
    use ExpandsThreads;
    use OpensInTab;
    use OpensLiveStream;
    use RefreshesInBackground;
    use ShowsCommits;
    use TalksToDevnull;

    /**
     * The web's "Try searching for…", from its lib/commit-search.ts.
     */
    public const array Suggestions = [
        'shipping a side project',
        'debugging in production',
        'testing strategies that actually work',
        'learning a new programming language',
        'speeding up a slow app',
        'working with AI coding tools',
    ];

    /**
     * The shortest term devnull searches commits for.
     */
    public const int ShortestCommitTerm = 3;

    /**
     * Every frame goes in full, as on Starred, since a new term replaces every row.
     */
    protected bool $forceFullFrames = true;

    public string $query = '';

    /** @var list<Connection> */
    public array $people = [];

    /**
     * The term the people were found for, so a slow answer never lands under a newer one.
     */
    public ?string $peopleFoundFor = null;

    /** @var list<Commit> */
    public array $commits = [];

    /**
     * The term the commits were found for.
     */
    public ?string $commitsFoundFor = null;

    /**
     * The rows from the last frame, so a render only builds the rows that changed, as on the Log.
     */
    private ?RowCache $rowCache = null;

    public function navTitle(): string
    {
        return 'Search';
    }

    public function updatedQuery(): void
    {
        $this->search();
    }

    /**
     * One of the web's searches to try, or a term picked some other way: it goes in the field and is searched.
     */
    public function pick(string $term): void
    {
        $this->query = $term;
        $this->search();
    }

    public function open(string $username): void
    {
        $person = $this->person($username);

        if ($person !== null) {
            $this->openInTab('/users/'.rawurlencode($person->user->username), ['user' => $person->user]);
        }
    }

    /**
     * Follows or unfollows straight away, and puts it back if the server says no, as in a list of followers.
     */
    public function toggleFollow(string $username): void
    {
        $person = $this->person($username);

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

    public function isMe(string $username): bool
    {
        return $this->me()?->username === $username;
    }

    /**
     * The term as typed, trimmed. Developers are found without the @ a handle is typed with, as on the web.
     */
    protected function term(): string
    {
        return trim($this->query);
    }

    protected function searchesCommits(string $term): bool
    {
        return mb_strlen($term) >= self::ShortestCommitTerm;
    }

    /**
     * Asks devnull for the developers and the commits matching the term. An emptied field shows the searches to try.
     */
    protected function search(): void
    {
        $term = $this->term();
        $this->refreshFailed = false;
        $this->error = null;

        if ($term === '') {
            $this->people = [];
            $this->peopleFoundFor = null;
            $this->commits = [];
            $this->commitsFoundFor = null;

            return;
        }

        $this->refresh(Client::UserSearchPath, ['q' => ltrim($term, '@')], function (array $json) use ($term): void {
            if ($this->term() !== $term) {
                return;
            }

            $people = is_array($json['data'] ?? null) ? $json['data'] : [];
            $this->people = array_values(array_map(Connection::fromArray(...), array_filter($people, 'is_array')));
            $this->peopleFoundFor = $term;
        });

        if (! $this->searchesCommits($term)) {
            $this->commits = [];
            $this->commitsFoundFor = null;

            return;
        }

        $this->refresh(Client::CommitSearchPath, ['q' => $term], function (array $json) use ($term): void {
            if ($this->term() !== $term) {
                return;
            }

            $commits = is_array($json['data'] ?? null) ? $json['data'] : [];
            $this->commits = array_values(array_map(Commit::fromArray(...), array_filter($commits, 'is_array')));
            $this->commitsFoundFor = $term;
        });
    }

    /**
     * The quiet line's retry, and a refresh after a failure: the same term again.
     */
    protected function revalidate(): void
    {
        $this->search();
    }

    protected function showsContent(): bool
    {
        return $this->people !== [] || $this->commits !== [];
    }

    /**
     * @return iterable<Commit>
     */
    protected function shownCommits(): iterable
    {
        return [...$this->commits, ...$this->threadCommits()];
    }

    protected function updateShownCommits(Closure $update): void
    {
        $this->commits = array_map($update, $this->commits);
        $this->threads = array_map(fn (array $replies): array => array_map($update, $replies), $this->threads);
    }

    private function person(string $username): ?Connection
    {
        foreach ($this->people as $person) {
            if ($person->user->username === $username) {
                return $person;
            }
        }

        return null;
    }

    private function setFollowed(string $username, bool $following): void
    {
        $this->people = array_map(
            fn (Connection $person): Connection => $person->user->username === $username ? $person->withFollowed($following) : $person,
            $this->people,
        );
    }

    public function render(): View
    {
        $term = $this->term();
        $viewer = $this->me();
        $this->rowCache ??= new RowCache;
        $this->rowCache->startFrame();

        return view('native.search', [
            'term' => $term,
            'searchesCommits' => $this->searchesCommits($term),
            'rowCache' => $this->rowCache,
            'viewer' => $viewer,
            'stashed' => app(Stash::class)->names($viewer?->id),
            'myColour' => $this->myColour(),
            'rows' => Timeline::ranked([], $this->commits),
        ]);
    }
}
