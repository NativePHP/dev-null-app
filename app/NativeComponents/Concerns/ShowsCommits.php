<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\User;
use Closure;
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Share;

/**
 * The actions under a commit: the star, Quote, Reply, and the More menu's
 * Save commit, Share link, Report commit and Delete commit (Quote and Report
 * open a sheet, see OpensCommitSheet), plus opening its link preview or images
 * in the in-app browser, and "Show more" on a long one. Taps name the commit
 * rather than a URL, so a URL is never rebuilt from an attribute.
 *
 * A commit can be tapped where a row nests it too: the commit a row quotes,
 * and the replies a feed shows under a commit.
 */
trait ShowsCommits
{
    use OpensCommitSheet;

    public const string DeleteAlert = 'delete-commit';

    /**
     * Long commits opened in place with "Show more", by id.
     *
     * @var list<string>
     */
    public array $unfolded = [];

    /**
     * The commit the delete alert is asking about.
     */
    public ?string $deleting = null;

    /**
     * Every commit the screen is showing.
     *
     * @return iterable<Commit>
     */
    abstract protected function shownCommits(): iterable;

    /**
     * Runs $update over every commit the screen is showing, keeping what it returns.
     *
     * @param  Closure(Commit): Commit  $update
     */
    abstract protected function updateShownCommits(Closure $update): void;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function openInTab(string $path, array $data = []): void;

    /**
     * Opens a commit with what its row already knows, so its page shows that at
     * once and fills in its replies and the rest when devnull answers.
     */
    public function openCommit(string $id): void
    {
        $commit = $this->shownCommit($id);

        $this->openInTab('/commits/'.$id, $commit === null ? [] : ['commit' => $commit]);
    }

    /**
     * Opens a profile with what a row already knows of its author.
     */
    public function openProfile(string $username): void
    {
        $author = $this->shownAuthor($username);

        $this->openInTab('/users/'.$username, $author === null ? [] : ['user' => $author]);
    }

    /**
     * Opens the commit with its reply composer focused.
     */
    public function replyTo(string $id): void
    {
        $commit = $this->shownCommit($id);

        $this->openInTab('/commits/'.$id, ['reply' => true, ...($commit === null ? [] : ['commit' => $commit])]);
    }

    /**
     * The web's "Show more" under the start of a long commit: the rest opens where it is.
     */
    public function showMore(string $id): void
    {
        if (! in_array($id, $this->unfolded, true)) {
            $this->unfolded[] = $id;
        }
    }

    /**
     * Stars or unstars straight away, then takes the count devnull sends back,
     * or puts the star back if it says no. Like the web, only someone else's
     * approved commit can be starred: on your own the count is all there is.
     */
    public function toggleStar(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canStar($commit)) {
            return;
        }

        $this->react($commit, $commit->withStar(! $commit->starred), fn ($devnull) => $commit->starred
            ? $devnull->unstar($id)
            : $devnull->star($id));
    }

    /**
     * Saves or unsaves the commit privately, the same way.
     */
    public function toggleBookmark(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || $commit->isSaved === null || ! $this->canReactTo($commit)) {
            return;
        }

        $this->react($commit, $commit->withSaved(! $commit->isSaved), fn ($devnull) => $commit->isSaved
            ? $devnull->unbookmark($id)
            : $devnull->bookmark($id));
    }

    public function openLinkPreview(string $id): void
    {
        $this->openInBrowser($this->shownCommit($id)?->linkPreview?->url);
    }

    public function openImage(string $id, int $index = 0): void
    {
        $this->openInBrowser(($this->shownCommit($id)?->images[$index] ?? null)?->url);
    }

    /**
     * Share link: the web's copy-link button. The share sheet has Copy, and AirDrop and Messages besides.
     */
    public function shareCommit(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || $commit->isDeleted()) {
            return;
        }

        Share::url('', '', $commit->htmlUrl ?? rtrim((string) config('devnull.url'), '/')."/@{$commit->author->username}/commits/{$commit->id}");
    }

    /**
     * Delete commit, in your own commit's More menu, asks first, as the web does.
     */
    public function confirmDelete(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canDelete($commit)) {
            return;
        }

        $this->deleting = $id;

        Dialog::alert('Delete this commit?', "There's no undo.", [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Delete', 'style' => 'destructive'],
        ])->id(self::DeleteAlert);
    }

    #[On(ButtonPressed::class)]
    public function onAlertButton(string $label = '', ?string $id = null): void
    {
        if ($id !== self::DeleteAlert || $this->deleting === null) {
            return;
        }

        $commit = $this->deleting;
        $this->deleting = null;

        if ($label === 'Delete') {
            $this->deleteCommit($commit);
        }
    }

    /**
     * Deletes your commit on devnull, which takes it out of every list kept on
     * this phone, then the screen lets it go (see showDeleted()).
     */
    public function deleteCommit(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canDelete($commit)) {
            return;
        }

        $deleted = $this->attempt(function (Client $devnull) use ($id): bool {
            $devnull->deleteCommit($id);

            return true;
        });

        if ($deleted === true) {
            Dialog::toast('rm '.$commit->shortHash.' → /dev/null', 'short');
            $this->showDeleted($id);
        }
    }

    /**
     * A commit you deleted leaves the screen as one deleted on its own page does
     * when you come back: the screen shows what the cache kept, then asks devnull.
     */
    protected function showDeleted(string $id): void
    {
        $this->onResume();
    }

    private function canReactTo(Commit $commit): bool
    {
        return ! $commit->isDeleted() && ($commit->isApproved() || $commit->author->id === $this->me()?->id);
    }

    private function canStar(Commit $commit): bool
    {
        return ! $commit->isDeleted() && $commit->isApproved() && $commit->author->id !== $this->me()?->id;
    }

    private function canDelete(Commit $commit): bool
    {
        return ! $commit->isDeleted() && $commit->author->id === $this->me()?->id;
    }

    /**
     * @param  Closure(Client): Commit  $call
     */
    private function react(Commit $before, Commit $optimistic, Closure $call): void
    {
        $this->replaceShownCommit($optimistic);

        $fresh = $this->attempt($call);

        $this->replaceShownCommit($fresh instanceof Commit ? $before->withReactionsFrom($fresh) : $before);
    }

    private function replaceShownCommit(Commit $replacement): void
    {
        $replace = fn (Commit $commit): Commit => $commit->id === $replacement->id ? $replacement : $commit;

        $this->updateShownCommits(fn (Commit $commit): Commit => $replace($commit)->withPreviewReplies($replace));
    }

    /**
     * Every commit showing, and the ones nested in their rows.
     *
     * @return iterable<Commit>
     */
    private function everyShownCommit(): iterable
    {
        foreach ($this->shownCommits() as $commit) {
            yield $commit;

            if ($commit->quotedCommit !== null) {
                yield $commit->quotedCommit;
            }

            yield from $commit->replyPreview?->replies ?? [];
        }
    }

    private function shownAuthor(string $username): ?User
    {
        foreach ($this->everyShownCommit() as $commit) {
            if (! $commit->isDeleted() && $commit->author->username === $username) {
                return $commit->author;
            }
        }

        return null;
    }

    private function shownCommit(string $id): ?Commit
    {
        foreach ($this->everyShownCommit() as $commit) {
            if ($commit->id === $id) {
                return $commit;
            }
        }

        return null;
    }

    private function openInBrowser(?string $url): void
    {
        if ($url !== null && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            Browser::inApp($url);
        }
    }
}
