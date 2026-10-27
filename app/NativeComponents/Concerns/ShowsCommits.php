<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitImage;
use App\Devnull\Data\Pasteis;
use App\Devnull\Data\User;
use App\Devnull\ErrorCode;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\ResponseCache;
use App\Devnull\SiteLink;
use App\Devnull\Toasts;
use Closure;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\SharedValue;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Share;

/**
 * The actions under a commit: the star, the pastel in pastéis mode, Quote,
 * Reply, and the More menu's Save commit, Share link, Pin to profile, Report
 * commit and Delete commit (Quote and Report open a sheet, see
 * OpensCommitSheet), plus opening its link preview in the in-app browser or,
 * for a page on dev/null, the app (see SiteLink), its
 * images full-screen in the app (see partials/image-viewer), "Show more" on a
 * long one, and voting on its poll (see
 * VotesOnPolls). Taps name the commit rather than a URL, so a URL is never
 * rebuilt from an attribute.
 *
 * A commit can be tapped where a row nests it too: the commit a row quotes,
 * and the replies a feed shows under a commit.
 */
trait ShowsCommits
{
    use OpensCommitSheet;
    use VotesOnPolls;
    use WritesInBackground;

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
     * Whether the image viewer is up, over everything else on the screen,
     * sheet included.
     */
    public bool $viewerOpen = false;

    /**
     * The images of the commit being viewed, as they were when it opened, so
     * the viewer keeps what it shows while it slides away even if the commit
     * leaves the screen.
     *
     * @var list<CommitImage>
     */
    public array $viewerImages = [];

    /**
     * Which of the viewer's images is showing.
     */
    public int $viewerIndex = 0;

    /**
     * The zoom and pan of the image being viewed, driven on the phone by the
     * viewer's gesture area. Fresh ones on every open and every move between
     * images, so each starts at rest; nulled on close.
     */
    public ?SharedValue $viewerZoom = null;

    public ?SharedValue $viewerPanX = null;

    public ?SharedValue $viewerPanY = null;

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

        // A staged commit has no page until devnull has it.
        if ($commit?->isStaged() === true) {
            return;
        }

        $this->openInTab('/commits/'.$id, $commit === null ? [] : ['commit' => $commit]);
    }

    /**
     * "› N replies" where they can't open in place: the commit's page, with its replies already showing.
     */
    public function openReplies(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit?->isStaged() === true) {
            return;
        }

        $this->openInTab('/commits/'.$id, ['replies' => true, ...($commit === null ? [] : ['commit' => $commit])]);
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

        if ($commit?->isStaged() === true) {
            return;
        }

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
     * Stars or unstars straight away, here and in every kept copy, while the
     * star goes to devnull from the outbox (see SyncsOutbox). The commit takes
     * the count devnull sends back, or the star comes back off it if devnull
     * says no. Like the web, any visible commit can be starred, including
     * your own and your pending commits.
     */
    public function toggleStar(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canReactTo($commit)) {
            return;
        }

        $this->showStar($id, ! $commit->starred);
        $this->queueStar($id, ! $commit->starred);
    }

    /**
     * Gives the commit a pastel, as the web's pastel button does in pastéis
     * mode: one per tap, from the ten you get each day, on someone else's
     * published commit. Its count goes up and your allowance down at once,
     * and the pastel goes from the background, so you can keep tapping. When
     * devnull turns one down, it comes back, and a toast says why in the web's
     * words. With none left, the toast says so without asking devnull.
     */
    public function givePastel(string $id): void
    {
        $commit = $this->shownCommit($id);
        $pasteis = app(Account::class)->pasteis();

        if ($commit === null || $pasteis === null || ! $this->canGivePastel($commit)) {
            return;
        }

        if ($pasteis->left() === 0) {
            Toasts::failed(Pasteis::Exhausted);

            return;
        }

        app(Account::class)->rememberPasteis($pasteis->afterGiving());
        $this->replaceShownCommit($commit->withMorePasteis(1));

        $this->writeInBackground('POST', Client::pastelPath($id), null, fn (array $json) => $this->pastelGiven($id, $json), function (string $message, ?ApiException $exception = null) use ($id): void {
            $this->pastelTurnedDown($id, $exception);
        });
    }

    /**
     * Pin to profile or Unpin from profile, in your own published commit's
     * More menu. Once devnull answers, a toast says so in the web's words, and
     * a pin takes the place of the one before (see showPinned()).
     */
    public function togglePin(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canPin($commit)) {
            return;
        }

        $fresh = $this->attempt(fn (Client $devnull): Commit => $commit->isPinned
            ? $devnull->unpin($id)
            : $devnull->pin($id));

        if (! $fresh instanceof Commit) {
            return;
        }

        $this->updateShownCommits(fn (Commit $shown): Commit => match (true) {
            $shown->id === $id => $shown->withPinned($fresh->isPinned),
            $fresh->isPinned && $shown->isPinned => $shown->withPinned(false),
            default => $shown,
        });

        Toasts::say($fresh->isPinned ? 'Commit pinned to your profile.' : 'Commit unpinned.', short: true);
        $this->showPinned($commit->withPinned($fresh->isPinned));
    }

    /**
     * Opens a commit's link preview: a page on dev/null the app has a screen
     * for here in the app, anything else in the browser.
     */
    public function openLinkPreview(string $id): void
    {
        $this->openUrl($this->shownCommit($id)?->linkPreview?->url);
    }

    /**
     * Opens a commit's image full-screen in the app (see partials/image-viewer),
     * from the one the row tapped: the image whole rather than cropped, pinch
     * zoom between its whole self and four times, a drag to pan, and the
     * chevrons under it to move between the commit's other images. An image the
     * screen isn't showing, or one at no web address, opens nothing, as it did
     * in the browser.
     */
    public function openImage(string $id, int $index = 0): void
    {
        $images = $this->shownCommit($id)?->images ?? [];
        $image = $images[$index] ?? null;

        if ($image === null || ! $this->isWebUrl($image->url)) {
            return;
        }

        $this->viewerImages = $images;
        $this->viewerIndex = $index;
        $this->restViewerImage();
        $this->viewerOpen = true;
    }

    /**
     * The chevron under the viewer: the commit's next image, at rest again. At
     * the last image there's no chevron to press, and a next at no web address
     * goes nowhere, as an image at one never opens.
     */
    public function viewNextImage(): void
    {
        $this->showInViewedImage($this->viewerIndex + 1);
    }

    /**
     * The chevron under the viewer: the commit's previous image, at rest
     * again. At the first image there's no chevron to press, and a previous at
     * no web address goes nowhere either.
     */
    public function viewPreviousImage(): void
    {
        $this->showInViewedImage($this->viewerIndex - 1);
    }

    /**
     * The viewer's close, back or outside tap, however the phone reports it:
     * the viewer goes and forgets the image and what its gestures left behind,
     * so the next one opens as this one did.
     */
    public function closeImageViewer(): void
    {
        $this->viewerOpen = false;
        $this->viewerImages = [];
        $this->viewerIndex = 0;
        $this->viewerZoom = null;
        $this->viewerPanX = null;
        $this->viewerPanY = null;
    }

    /**
     * Opens a link in a commit's body, by its place among the body's links: a
     * commit or another page on dev/null the app has a screen for here in the
     * app, anything else in the browser.
     */
    public function openLink(string $id, int $index): void
    {
        $link = $this->shownCommit($id)?->links()[$index] ?? null;

        if ($link?->commit !== null) {
            $this->openCommit($link->commit);

            return;
        }

        $this->openUrl($link?->href);
    }

    /**
     * Share link: the web's copy-link button. The share sheet has Copy, and AirDrop and Messages besides.
     */
    public function shareCommit(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || $commit->isDeleted() || $commit->isStaged()) {
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
        $this->answerDeleteAlert($label, $id);
    }

    /**
     * Deletes the commit the delete alert asked about, once you pick Delete. A
     * screen with alerts of its own answers them in its own onAlertButton(),
     * and passes the delete alert on to this.
     */
    protected function answerDeleteAlert(string $label, ?string $id): void
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
     * devnull took the pastel: the commit shows at least the count it sent,
     * since others may still be on their way, and so does every kept copy.
     *
     * @param  array<string, mixed>  $json
     */
    private function pastelGiven(string $id, array $json): void
    {
        $fresh = Commit::fromArray(is_array($json['data'] ?? null) ? $json['data'] : []);
        $shown = $this->shownCommit($id);

        if ($shown !== null && $fresh->pasteisCount > $shown->pasteisCount) {
            $this->replaceShownCommit($shown->withMorePasteis($fresh->pasteisCount - $shown->pasteisCount));
        }

        app(ResponseCache::class)->pastelGiven($json);

        $pasteis = app(Account::class)->pasteis();
        $remaining = $json['pasteis_remaining'] ?? null;

        if ($pasteis === null || ! is_int($remaining)) {
            return;
        }

        $pasteis = $pasteis->settledWith($remaining, Commit::date($json['pasteis_resets_at'] ?? null));
        app(Account::class)->rememberPasteis($pasteis);

        if ($pasteis->left() === 0) {
            Toasts::say(Pasteis::Exhausted);
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
            Toasts::say('rm '.$commit->shortHash.' → /dev/null', short: true);
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

    /**
     * Your profile moves the commit you pinned or unpinned (see Profile).
     */
    protected function showPinned(Commit $commit): void {}

    /**
     * devnull turned the pastel down, so it comes back off the commit. With
     * none left today, you have none until the reset. A rate limit says so in
     * devnull's words; anything else in the web's.
     */
    private function pastelTurnedDown(string $id, ?ApiException $exception): void
    {
        $shown = $this->shownCommit($id);

        if ($shown !== null) {
            $this->replaceShownCommit($shown->withMorePasteis(-1));
        }

        $exhausted = $exception?->is(ErrorCode::PasteisExhausted) === true;
        $pasteis = app(Account::class)->pasteis();

        if ($pasteis !== null) {
            app(Account::class)->rememberPasteis($exhausted ? $pasteis->exhausted() : $pasteis->afterTakingBack());
        }

        Toasts::failed(match (true) {
            $exhausted => Pasteis::Exhausted,
            $exception?->is(ErrorCode::RateLimited) === true => $exception->firstError(),
            default => Pasteis::CouldNotGive,
        });
    }

    private function canReactTo(Commit $commit): bool
    {
        return ! $commit->isDeleted() && ! $commit->isStaged() && ($commit->isApproved() || $commit->author->id === $this->me()?->id);
    }

    private function canGivePastel(Commit $commit): bool
    {
        return ! $commit->isDeleted() && $commit->isApproved() && $commit->author->id !== $this->me()?->id;
    }

    private function canPin(Commit $commit): bool
    {
        return ! $commit->isDeleted() && $commit->isApproved() && $commit->parentId === null && $commit->author->id === $this->me()?->id;
    }

    private function canDelete(Commit $commit): bool
    {
        return ! $commit->isDeleted() && ! $commit->isStaged() && $commit->author->id === $this->me()?->id;
    }

    /**
     * The commit shows your star as it now is, or with devnull's count when it answered ($fresh).
     */
    protected function showStar(string $id, bool $starred, ?Commit $fresh = null): void
    {
        $shown = $this->shownCommit($id);

        if ($shown !== null) {
            $this->replaceShownCommit($fresh === null ? $shown->withStar($starred) : $shown->withReactionsFrom($fresh));
        }
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

    /**
     * Opens a page on dev/null in the app's own screen for it, in this tab,
     * and anything else in the browser (see SiteLink).
     */
    private function openUrl(?string $url): void
    {
        $screen = SiteLink::screen($url);

        if ($screen !== null) {
            $this->openInTab($screen);

            return;
        }

        $this->openInBrowser($url);
    }

    private function openInBrowser(?string $url): void
    {
        if ($this->isWebUrl($url)) {
            Browser::inApp($url);
        }
    }

    /**
     * Only an http or https address is opened, in the browser or the viewer, so
     * nothing else a row might carry can take the phone somewhere unexpected.
     */
    private function isWebUrl(?string $url): bool
    {
        return $url !== null && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    /**
     * Puts the viewer's image back at rest: fresh shared values, which the
     * phone seeds at one and none, so what the last image's gestures left
     * behind can't carry over to it.
     */
    private function restViewerImage(): void
    {
        $this->viewerZoom = SharedValue::make(1.0);
        $this->viewerPanX = SharedValue::make();
        $this->viewerPanY = SharedValue::make();
    }

    /**
     * Moves the viewer to one of its commit's images by its place among them,
     * or nowhere when there's no image there or it's at no web address, so the
     * viewer never comes to show one the phone shouldn't be asked for.
     */
    private function showInViewedImage(int $index): void
    {
        $image = $this->viewerImages[$index] ?? null;

        if (! $this->viewerOpen || $image === null || ! $this->isWebUrl($image->url)) {
            return;
        }

        $this->viewerIndex = $index;
        $this->restViewerImage();
    }
}
