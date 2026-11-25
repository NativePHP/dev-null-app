<?php

namespace App\NativeComponents;

use App\Devnull\Beat;
use App\Devnull\Client;
use App\Devnull\Data\Notification;
use App\Devnull\Data\Page;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\Devnull\NotificationFilter;
use App\Icons\Lucide;
use App\NativeComponents\Concerns\DrawsSkeletonFirst;
use App\NativeComponents\Concerns\OffersPush;
use App\NativeComponents\Concerns\OpensPushedScreen;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Concerns\WatchesForCalls;
use App\NativeComponents\Concerns\WritesInBackground;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;

/**
 * Follows, stars, replies, quotes, mentions, level-ups, and moderation updates
 * for your commits, grouped as the web groups them, each on its own rail in
 * the lane of whoever it's about, ending in a dot, as the web draws them.
 *
 * Which kinds show is chosen on the notification settings screen, from the
 * button in the title bar, as the web chooses them with its filters: each
 * kind on or off on its own, kept per user on this phone. A group of more
 * than one opens in place to list them all, as the web expands it.
 *
 * A group opens and closes as replies do on the Log: its toggle's chevron
 * turns, and its notifications fade in nudged down into place, and fade out
 * the same way before they go. EDGE can't animate a node as it first appears,
 * so they're drawn see-through for a frame ($fadedItems) and drawn again as
 * they should be once a Beat comes back, which they animate to.
 *
 * It opens on the notifications as they were last time, all read by then,
 * then asks devnull behind them. devnull marks everything read as it lists
 * any of it, and says when, so the groups read since then are the new ones,
 * above the last-visit marker. New ones go in at the top quietly, since a
 * group's id moves on as people join it.
 */
class Notifications extends NativeComponent
{
    use DrawsSkeletonFirst;
    use OffersPush;
    use OpensPushedScreen;
    use RefreshesInBackground;
    use SyncsOutbox;
    use TalksToDevnull;
    use WatchesForCalls;
    use WritesInBackground;

    /**
     * Every frame goes in full, as on the Log (see Log::$forceFullFrames).
     */
    protected bool $forceFullFrames = true;

    /**
     * How long a group's notifications take to fade in or out, and its
     * chevron to turn, in milliseconds: as long as replies take on the Log.
     */
    public const int GroupFade = 220;

    /**
     * The kinds that are on, as the settings screen keeps them (see NotificationFilter).
     *
     * @var list<string>
     */
    public array $kinds = [];

    /**
     * Mounts a fresh list when the kinds change, at the top.
     */
    public int $listKey = 0;

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

    /**
     * What an opened group draws see-through: notifications about to fade in,
     * or a group fading out. A notification goes by its id, a group's guide
     * line and "Show older" by "group-" and the group's id.
     *
     * @var list<string>
     */
    public array $fadedItems = [];

    /**
     * Groups fading out, still drawn until they've faded.
     *
     * @var list<string>
     */
    public array $closingGroups = [];

    public function mount(): void
    {
        $this->kinds = app(NotificationFilter::class)->enabled($this->me()?->id);
        $this->checkPushPrompt();
        $this->openPushedScreen();
        $this->fillInAfterFirstFrame('revalidate');
    }

    /**
     * Back from the settings screen, or another tab: a change to the kinds starts the list again for them.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->checkPushPrompt();

        $kinds = app(NotificationFilter::class)->enabled($this->me()?->id);

        if ($kinds !== $this->kinds) {
            $this->showKinds($kinds);

            return;
        }

        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Notifications';
    }

    /**
     * The small button in the title bar: the notification settings screen, to choose which kinds show.
     */
    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()
            ->action(
                NavAction::make('notification-settings')
                    ->icon(ios: Lucide::Settings2->ios(), android: Lucide::Settings2->android())
                    ->a11yLabel('Notification settings')
                    ->press('openSettings'),
            );
    }

    public function openSettings(): void
    {
        $this->openInTab('/settings');
    }

    /**
     * The empty list's Show all notifications, as on the web: every kind on again.
     */
    public function showAll(): void
    {
        app(NotificationFilter::class)->save($this->me()?->id, array_keys(NotificationFilter::Kinds));
        $this->showKinds(app(NotificationFilter::class)->enabled($this->me()?->id));
    }

    /**
     * What devnull is asked for, from the kinds that are on.
     */
    private function typeQuery(): string
    {
        return NotificationFilter::type($this->kinds);
    }

    /**
     * The list for other kinds: a fresh one at the top, from what was kept for them, then asked again.
     *
     * @param  list<string>  $kinds
     */
    private function showKinds(array $kinds): void
    {
        $this->kinds = $kinds;
        $this->listKey++;
        $this->notifications = [];
        $this->nextCursor = null;
        $this->loaded = false;
        $this->error = null;
        $this->refreshFailed = false;
        $this->expanded = [];
        $this->expandedCursors = [];
        $this->failedGroups = [];
        $this->fadedItems = [];
        $this->closingGroups = [];
        $this->showKept();
        $this->revalidate();
    }

    /**
     * Pull to refresh.
     */
    public function load(): void
    {
        $type = $this->typeQuery();
        $this->refresh('notifications', Client::notificationsQuery($type), fn (array $json) => $this->takeFresh($type, $json), again: true);
    }

    /**
     * devnull marks a page read as it sends it, so the badge is asked for
     * again once it lands, which clears it unless more are waiting further down.
     */
    protected function revalidate(): void
    {
        $type = $this->typeQuery();
        $this->refresh('notifications', Client::notificationsQuery($type), fn (array $json) => $this->takeFresh($type, $json));

        foreach (array_keys($this->expanded) as $id) {
            if ($this->isOpenGroup((string) $id)) {
                $this->refreshGroup((string) $id);
            }
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

        $page = $this->attempt(fn ($devnull) => $devnull->notifications($this->typeQuery(), $this->nextCursor));

        if ($page !== null) {
            $this->notifications = [...$this->notifications, ...$page->items];
            $this->nextCursor = $page->nextCursor;
            $this->refreshUnreadNotifications();
        }
    }

    /**
     * A tap on a notification opens its commit, the profile of whoever
     * followed you, or your own for a level-up or a timeout. A tap on a
     * group of followers lists them in place instead, as its toggle does,
     * rather than opening only the latest of them. One star in an opened
     * group opens whoever gave it, as the web's does: the group above it
     * already opens the commit.
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

        $isGroupMember = ! in_array($id, array_map(fn (Notification $group): string => $group->id, $this->notifications), true);
        $commitId = $notification->isStar() && $isGroupMember ? null : $notification->commitToOpen();

        $this->openInTab(match (true) {
            $commitId !== null => '/commits/'.$commitId,
            $notification->actor->isOrganization => '/organizations/'.rawurlencode($notification->actor->username),
            default => '/users/'.$notification->actor->username,
        });
    }

    /**
     * Names and avatars open their profile independently of the notification's commit: an organization's page for
     * one that replied, quoted or mentioned you.
     */
    public function openActor(string $username): void
    {
        foreach ([...$this->notifications, ...array_merge([], ...array_values($this->expanded))] as $notification) {
            if ($notification->isModeration()) {
                continue;
            }

            foreach ([$notification->actor, ...$notification->actors] as $actor) {
                if ($username !== '' && $actor->username === $username) {
                    $actor->isOrganization
                        ? $this->openInTab('/organizations/'.rawurlencode($actor->username))
                        : $this->openInTab('/users/'.$actor->username, ['user' => $actor]);

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
        if (array_key_exists($id, $this->expanded)) {
            $this->fadeOutGroup($id);

            return;
        }

        if (in_array($id, $this->failedGroups, true)) {
            $this->failedGroups = array_values(array_diff($this->failedGroups, [$id]));

            return;
        }

        if (($kept = $this->cached($this->groupPath($id), Client::notificationsQuery($this->typeQuery()))) !== null) {
            $this->takeGroup($id, $kept);
            $this->refreshGroup($id);
            $this->fadeIn([...$this->itemIds($this->expanded[$id]), 'group-'.$id]);

            return;
        }

        $this->loadGroup($id);
    }

    /**
     * Whether a group is open, and not on its way out.
     */
    public function isOpenGroup(string $id): bool
    {
        return array_key_exists($id, $this->expanded) && ! in_array($id, $this->closingGroups, true);
    }

    /**
     * "Show older stars", or Try again after a group couldn't be opened.
     */
    public function loadGroup(string $id, bool $older = false): void
    {
        $cursor = $older ? ($this->expandedCursors[$id] ?? null) : null;

        if (($older && $cursor === null) || in_array($id, $this->closingGroups, true)) {
            return;
        }

        $this->failedGroups = array_values(array_diff($this->failedGroups, [$id]));

        try {
            $page = app(Client::class)->notificationGroup($id, $this->typeQuery(), $cursor);
        } catch (Unauthenticated $exception) {
            $this->signOut($exception);

            return;
        } catch (ApiException|KeychainUnavailable) {
            $this->failedGroups[] = $id;

            return;
        }

        $this->expanded[$id] = $older ? [...($this->expanded[$id] ?? []), ...$page->items] : $page->items;
        $this->setGroupCursor($id, $page->nextCursor);
        $this->fadeIn($older ? $this->itemIds($page->items) : [...$this->itemIds($page->items), 'group-'.$id]);
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
        $type = $this->typeQuery();

        $this->refresh($this->groupPath($id), Client::notificationsQuery($type), function (array $json) use ($id, $type): void {
            // Folded away, or another filter, while it was on its way.
            if ($type === $this->typeQuery() && $this->isOpenGroup($id)) {
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

    /**
     * Draws a group's notifications see-through for a frame, then as they are, which they fade to.
     *
     * @param  list<string>  $items
     */
    private function fadeIn(array $items): void
    {
        if ($items === []) {
            return;
        }

        $this->fadedItems = array_values(array_unique([...$this->fadedItems, ...$items]));

        $shown = fn () => $this->fadedItems = array_values(array_diff($this->fadedItems, $items));

        Beat::dispatch(Beat::Step)->finished($shown)->failed($shown);
    }

    /**
     * Fades a group's notifications out, then folds it away.
     */
    private function fadeOutGroup(string $id): void
    {
        if (in_array($id, $this->closingGroups, true)) {
            return;
        }

        $items = [...$this->itemIds($this->expanded[$id] ?? []), 'group-'.$id];
        $this->closingGroups[] = $id;
        $this->fadedItems = array_values(array_unique([...$this->fadedItems, ...$items]));

        $closed = function () use ($id, $items): void {
            unset($this->expanded[$id], $this->expandedCursors[$id]);
            $this->failedGroups = array_values(array_diff($this->failedGroups, [$id]));
            $this->closingGroups = array_values(array_diff($this->closingGroups, [$id]));
            $this->fadedItems = array_values(array_diff($this->fadedItems, $items));
        };

        Beat::dispatch(self::GroupFade)->finished($closed)->failed($closed);
    }

    /**
     * @param  list<Notification>  $items
     * @return list<string>
     */
    private function itemIds(array $items): array
    {
        return array_map(fn (Notification $item): string => $item->id, $items);
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
        if (($json = $this->cached('notifications', Client::notificationsQuery($this->typeQuery()))) === null) {
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
        if ($type !== $this->typeQuery()) {
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

        return view('native.notifications', [
            'myColour' => $this->myColour(),
            'kinds' => $this->kinds,
            'readAt' => $readAt,
            'end' => match (true) {
                $this->notifications === [] => null,
                $this->nextCursor !== null => 'more',
                default => 'oldest',
            },
            'lastVisitBefore' => self::firstSeen($this->notifications, $readAt),
        ]);
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
