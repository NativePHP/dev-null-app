<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\Notification;
use App\Devnull\Data\Page;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\Devnull\ShownTabs;
use App\NativeComponents\Concerns\DrawsSkeletonFirst;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Follows, stars, replies, quotes, mentions, and moderation updates for your
 * commits, grouped as the web groups them, on a Rail Gray rail.
 *
 * The web's filters run along the top: All, Replies, Quotes, Stars, Mentions,
 * Follows and Moderation, each kept on its own for next time. A group of
 * more than one opens in place to list them all, as the web expands it.
 *
 * It opens on the notifications as they were last time, all read by then,
 * then asks devnull behind them. devnull marks everything read as it lists
 * any of it, and says when, so the groups read since then are the new ones:
 * their node is ringed in blue, above the last-visit marker. New ones go in
 * at the top quietly, since a group's id moves on as people join it.
 *
 * The filters are pages you swipe between, as on the Log (see Log), and the
 * pager draws the filters along the top and moves them with your finger. The
 * filters either side of the one you're on show the first few groups kept
 * for them, ready for a swipe. Each has its own list, and a filter you leave
 * starts again at the top of a fresh one.
 */
class Notifications extends NativeComponent
{
    use DrawsSkeletonFirst;
    use OpensInTab;
    use RefreshesInBackground;
    use SyncsOutbox;
    use TalksToDevnull;

    /**
     * Every frame goes in full, as on the Log (see Log::$forceFullFrames).
     */
    protected bool $forceFullFrames = true;

    /**
     * The web's filters, their labels and what each says when it has nothing.
     */
    public const array Types = [
        'all' => ['All', 'Nothing here yet. Follows, stars, replies, quotes, mentions, and moderation updates for your commits will show up here.'],
        'replies' => ['Replies', 'No replies yet. When someone replies to one of your commits, it shows up here.'],
        'quotes' => ['Quotes', 'No quotes yet. When someone quotes one of your commits, it shows up here.'],
        'stars' => ['Stars', 'No stars yet. When someone stars one of your commits, it shows up here.'],
        'mentions' => ['Mentions', 'No mentions yet. When someone mentions you in a commit, it shows up here.'],
        'follows' => ['Follows', 'No followers yet. When someone follows you, it shows up here.'],
        'moderation' => ['Moderation', 'No moderation updates. Approved and rejected commits show up here.'],
    ];

    /**
     * How many kept groups a filter beside the one you're on shows, enough to fill the screen while you swipe to it.
     */
    public const int PreviewGroups = 6;

    public string $type = 'all';

    /**
     * Mounts a fresh list for the filter you're on when it changes, at the top.
     */
    public int $listKey = 0;

    /**
     * The list key of each filter you're not on.
     *
     * @var array<string, int>
     */
    public array $listKeys = [];

    /** @var list<Notification> */
    public array $notifications = [];

    public ?string $nextCursor = null;

    public bool $loaded = false;

    /**
     * When devnull marked everything read the first time it answered on this
     * screen. Groups read at or after it arrived since your last visit.
     */
    public ?string $readAt = null;

    /**
     * The notifications of each group opened in place, by the id of the group's newest.
     *
     * @var array<string, list<Notification>>
     */
    public array $expanded = [];

    /**
     * The cursor for an opened group's older notifications, by the group's id.
     *
     * @var array<string, string>
     */
    public array $expandedCursors = [];

    /**
     * Groups that couldn't be opened, which say so with Try again.
     *
     * @var list<string>
     */
    public array $failedGroups = [];

    public function mount(): void
    {
        $this->type = app(ShownTabs::class)->get($this->me()?->id, 'notifications', array_keys(self::Types), 'all');
        $this->fillInAfterFirstFrame('revalidate');
    }

    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Notifications';
    }

    /**
     * Shows a filter, from a link such as an empty filter's Show all, and the
     * pager follows: its own list, from what was kept for it, then asked again.
     * The one you're on goes back to its top.
     */
    public function show(string $type): void
    {
        if (! array_key_exists($type, self::Types)) {
            return;
        }

        if ($type === $this->type) {
            $this->listKey++;

            return;
        }

        $this->switchTo($type);
    }

    /**
     * The pager settling on a filter, after a swipe or a tap along the top. The one it's already on changes nothing.
     */
    public function showPage(int $index): void
    {
        $type = array_keys(self::Types)[$index] ?? null;

        if ($type !== null && $type !== $this->type) {
            $this->switchTo($type);
        }
    }

    /**
     * A tap on the filter you're on, which the pager sends instead of moving: back to the top of it.
     */
    public function backToTop(int $index): void
    {
        $this->show(array_keys(self::Types)[$index] ?? '');
    }

    /**
     * The filter you go to keeps the list its first groups were drawn in, which
     * can't have been scrolled, and fills it. The one you leave starts again at
     * the top of a fresh one.
     */
    private function switchTo(string $type): void
    {
        app(ShownTabs::class)->remember($this->me()?->id, 'notifications', $type);
        $this->listKeys[$this->type] = $this->listKey + 1;
        $this->listKey = $this->listKeys[$type] ?? 0;
        $this->type = $type;
        $this->notifications = [];
        $this->nextCursor = null;
        $this->loaded = false;
        $this->error = null;
        $this->refreshFailed = false;
        $this->expanded = [];
        $this->expandedCursors = [];
        $this->failedGroups = [];
        $this->showKept();
        $this->revalidate();
    }

    /**
     * Pull to refresh.
     */
    public function load(): void
    {
        $type = $this->type;
        $this->refresh('notifications', Client::notificationsQuery($type), fn (array $json) => $this->takeFresh($type, $json), again: true);
    }

    /**
     * devnull marks a page read as it sends it, so the badge is asked for
     * again once it lands, which clears it unless more are waiting further down.
     */
    protected function revalidate(): void
    {
        $type = $this->type;
        $this->refresh('notifications', Client::notificationsQuery($type), fn (array $json) => $this->takeFresh($type, $json));

        foreach (array_keys($this->expanded) as $id) {
            $this->refreshGroup((string) $id);
        }
    }

    protected function showsContent(): bool
    {
        return $this->loaded;
    }

    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        $page = $this->attempt(fn ($devnull) => $devnull->notifications($this->type, $this->nextCursor));

        if ($page !== null) {
            $this->notifications = [...$this->notifications, ...$page->items];
            $this->nextCursor = $page->nextCursor;
            $this->refreshUnreadNotifications();
        }
    }

    /**
     * A tap on a notification opens its commit, or the profile of whoever
     * followed you. A tap on a group of followers lists them in place instead,
     * as its toggle does, rather than opening only the latest of them.
     */
    public function open(string $id): void
    {
        $notification = $this->find($id);

        if ($notification === null) {
            return;
        }

        if ($notification->isFollow() && $notification->isGrouped()) {
            $this->toggleGroup($id);

            return;
        }

        $commitId = $notification->commitToOpen();

        $this->openInTab($commitId !== null
            ? '/commits/'.$commitId
            : '/users/'.$notification->actor->username);
    }

    /**
     * Names and avatars open their profile independently of the notification's commit.
     */
    public function openActor(string $username): void
    {
        foreach ([...$this->notifications, ...array_merge([], ...array_values($this->expanded))] as $notification) {
            if ($notification->isModeration()) {
                continue;
            }

            foreach ([$notification->actor, ...$notification->actors] as $actor) {
                if ($username !== '' && $actor->username === $username) {
                    $this->openInTab('/users/'.$actor->username, ['user' => $actor]);

                    return;
                }
            }
        }
    }

    /**
     * The group's toggle: "5 stars" lists them in place, "Hide stars" folds them away.
     */
    public function toggleGroup(string $id): void
    {
        if (array_key_exists($id, $this->expanded) || in_array($id, $this->failedGroups, true)) {
            unset($this->expanded[$id], $this->expandedCursors[$id]);
            $this->failedGroups = array_values(array_diff($this->failedGroups, [$id]));

            return;
        }

        if (($kept = $this->cached($this->groupPath($id), Client::notificationsQuery($this->type))) !== null) {
            $this->takeGroup($id, $kept);
            $this->refreshGroup($id);

            return;
        }

        $this->loadGroup($id);
    }

    /**
     * "Show older stars", or Try again after a group couldn't be opened.
     */
    public function loadGroup(string $id, bool $older = false): void
    {
        $cursor = $older ? ($this->expandedCursors[$id] ?? null) : null;

        if ($older && $cursor === null) {
            return;
        }

        $this->failedGroups = array_values(array_diff($this->failedGroups, [$id]));

        try {
            $page = app(Client::class)->notificationGroup($id, $this->type, $cursor);
        } catch (Unauthenticated $exception) {
            $this->signOut($exception);

            return;
        } catch (ApiException|KeychainUnavailable) {
            $this->failedGroups[] = $id;

            return;
        }

        $this->expanded[$id] = $older ? [...($this->expanded[$id] ?? []), ...$page->items] : $page->items;
        $this->setGroupCursor($id, $page->nextCursor);
    }

    /**
     * The web's Follow button on a follow: follows them back, or unfollows, straight away.
     */
    public function toggleFollowBack(string $username): void
    {
        $actor = null;

        foreach ([...$this->notifications, ...array_merge([], ...array_values($this->expanded))] as $notification) {
            if ($notification->isFollow() && $notification->actor->username === $username) {
                $actor = $notification;

                break;
            }
        }

        if ($actor === null || $actor->actorIsFollowed === null) {
            return;
        }

        $following = ! $actor->actorIsFollowed;
        $this->setFollowed($username, $following);

        $done = $this->attempt(function ($devnull) use ($username, $following): bool {
            $following ? $devnull->follow($username) : $devnull->unfollow($username);

            return true;
        });

        if ($done !== true) {
            $this->setFollowed($username, ! $following);
        }
    }

    private function setFollowed(string $username, bool $following): void
    {
        $update = fn (Notification $notification): Notification => $notification->isFollow() && $notification->actor->username === $username
            ? $notification->withActorFollowed($following)
            : $notification;

        $this->notifications = array_map($update, $this->notifications);
        $this->expanded = array_map(fn (array $items): array => array_map($update, $items), $this->expanded);
    }

    private function refreshGroup(string $id): void
    {
        $type = $this->type;

        $this->refresh($this->groupPath($id), Client::notificationsQuery($type), function (array $json) use ($id, $type): void {
            // Folded away, or another filter, while it was on its way.
            if ($type === $this->type && array_key_exists($id, $this->expanded)) {
                $this->takeGroup($id, $json);
            }
        }, quiet: true);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeGroup(string $id, array $json): void
    {
        $page = Page::fromArray($json, Notification::fromArray(...));

        $this->expanded[$id] = $page->items;
        $this->setGroupCursor($id, $page->nextCursor);
    }

    private function setGroupCursor(string $id, ?string $cursor): void
    {
        if ($cursor === null) {
            unset($this->expandedCursors[$id]);

            return;
        }

        $this->expandedCursors[$id] = $cursor;
    }

    private function groupPath(string $id): string
    {
        return 'notifications/'.rawurlencode($id).'/group';
    }

    private function find(string $id): ?Notification
    {
        foreach ([...$this->notifications, ...array_merge([], ...array_values($this->expanded))] as $notification) {
            if ($notification->id === $id) {
                return $notification;
            }
        }

        return null;
    }

    private function showKept(): void
    {
        if (($json = $this->cached('notifications', Client::notificationsQuery($this->type))) === null) {
            return;
        }

        $page = Page::fromArray($json, Notification::fromArray(...));

        $this->notifications = $page->items;
        $this->nextCursor = $page->nextCursor;
        $this->loaded = true;
    }

    /**
     * The fresh first page replaces what's showing. Groups loaded further down
     * stay, past the last one the fresh page also has.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeFresh(string $type, array $json): void
    {
        // An answer for a filter you've left.
        if ($type !== $this->type) {
            return;
        }

        $page = Page::fromArray($json, Notification::fromArray(...));
        $fresh = array_flip(array_map(fn (Notification $group): string => $group->id, $page->items));

        $deepest = -1;

        foreach ($this->notifications as $index => $group) {
            if (isset($fresh[$group->id])) {
                $deepest = $index;
            }
        }

        $later = $deepest < 0 ? [] : array_values(array_filter(
            array_slice($this->notifications, $deepest + 1),
            fn (Notification $group): bool => ! isset($fresh[$group->id]),
        ));

        $this->notifications = [...$page->items, ...$later];
        $this->nextCursor = $later === [] ? $page->nextCursor : $this->nextCursor;
        $this->readAt ??= is_string($json['read_at'] ?? null) ? $json['read_at'] : null;
        $this->loaded = true;
        $this->refreshUnreadNotifications();
    }

    public function render(): View
    {
        $readAt = $this->readAt === null ? null : CarbonImmutable::parse($this->readAt);
        $types = array_keys(self::Types);
        $index = (int) array_search($this->type, $types, true);
        $from = max(0, $index - 1);
        $to = min(count($types) - 1, $index + 1);

        $pages = [];

        foreach (array_slice($types, $from, $to - $from + 1) as $type) {
            $pages[] = $type === $this->type
                ? $this->page($type, $this->notifications, $this->nextCursor, $this->loaded, $this->error, $readAt, $this->listKey)
                : $this->preview($type, $readAt);
        }

        return view('native.notifications', [
            'myColour' => $this->myColour(),
            'types' => self::Types,
            'readAt' => $readAt,
            'pages' => $pages,
            'pageIndex' => $index,
            'pagesFrom' => $from,
            'pagesTo' => $to,
        ]);
    }

    /**
     * A filter beside the one you're on: the first few groups kept for it, as it will open.
     *
     * @return array<string, mixed>
     */
    private function preview(string $type, ?CarbonImmutable $readAt): array
    {
        $json = $this->skeletonFrame ? null : $this->cached('notifications', Client::notificationsQuery($type));
        $kept = is_array($json['data'] ?? null) ? $json['data'] : [];
        $page = $json === null ? null : Page::fromArray(['data' => array_slice($kept, 0, self::PreviewGroups)] + $json, Notification::fromArray(...));

        // Groups past the preview are left out, with nothing under them yet.
        return $this->page($type, $page->items ?? [], $page?->nextCursor, $page !== null, null, $readAt, $this->listKeys[$type] ?? 0, cut: count($kept) > self::PreviewGroups);
    }

    /**
     * What a filter's page shows.
     *
     * @param  list<Notification>  $notifications
     * @return array<string, mixed>
     */
    private function page(string $type, array $notifications, ?string $nextCursor, bool $loaded, ?string $error, ?CarbonImmutable $readAt, int $listKey, bool $cut = false): array
    {
        $showing = $type === $this->type;

        return [
            'end' => match (true) {
                $cut || $notifications === [] => null,
                $nextCursor !== null => 'more',
                default => 'oldest',
            },
            'type' => $type,
            'showing' => $showing,
            'listKey' => $listKey,
            'notifications' => $notifications,
            'loaded' => $loaded,
            'error' => $error,
            'lastVisitBefore' => self::firstSeen($notifications, $readAt),
            'expanded' => $showing ? $this->expanded : [],
            'expandedCursors' => $showing ? $this->expandedCursors : [],
            'failedGroups' => $showing ? $this->failedGroups : [],
        ];
    }

    /**
     * The first group you'd seen before, under the new ones, for the last-visit marker.
     *
     * @param  list<Notification>  $notifications
     */
    private static function firstSeen(array $notifications, ?CarbonImmutable $readAt): ?string
    {
        foreach ($notifications as $index => $notification) {
            if ($index > 0 && ! $notification->isNewSince($readAt) && $notifications[$index - 1]->isNewSince($readAt)) {
                return $notification->id;
            }
        }

        return null;
    }
}
