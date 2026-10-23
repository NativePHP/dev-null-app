<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\Notification;
use App\Devnull\Data\Page;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\Devnull\NotificationFilters;
use App\Devnull\ResponseCache;
use App\NativeComponents\Concerns\DrawsSkeletonFirst;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\TalksToDevnull;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;

/**
 * Follows, stars, replies, quotes, mentions, level-ups and moderation updates
 * for your commits, grouped as the web groups them, on a Rail Gray rail.
 *
 * The web's filters run along the top, several on at once: Replies, Quotes and
 * Stars as chips you tap, and the rest under a More button, as the web's phone
 * layout runs them. What's on is remembered per account (see
 * NotificationFilters), sent as one ?type= — all six ask for everything, none
 * asks for nothing, and anything between goes comma-separated in the web's
 * order. A new selection starts a fresh list at the top, since a list kept
 * while every row changed under its scroll offset drew blank on iOS; the one
 * list keeps its key while it refreshes, pages and polls.
 *
 * It opens on the notifications as they were last time, all read by then, then
 * asks devnull behind them. devnull marks everything read as it lists any of
 * it, and says when, so the groups read since then are the new ones: their
 * node is ringed in blue, above the last-visit marker.
 *
 * While it's showing, devnull is asked every 30 seconds for everything since
 * the oldest group still showing (through=), as the web asks. Groups that
 * came in, and groups whose newest event moved on since they were drawn,
 * wait in the "N new notifications" pill rather than going in under you; a
 * tap puts them on top and goes back to the top. A group's id stays put as
 * people join it — only its newest event's id moves — so groups are told
 * apart and counted by it, its fresh copy taking the old one's place rather
 * than sitting beside it, and a group opened in place follows the new id.
 *
 * A group of more than one opens in place to list them all, as the web
 * expands it.
 */
class Notifications extends NativeComponent
{
    use DrawsSkeletonFirst;
    use OpensInTab;
    use RefreshesInBackground;
    use TalksToDevnull;

    /**
     * Every frame goes in full, as on the Log (see Log::$forceFullFrames).
     */
    protected bool $forceFullFrames = true;

    /**
     * The web's categories, their labels and what each says alone, when
     * nothing else is on and it has nothing.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const array Categories = [
        'replies' => ['Replies', 'No replies yet. When someone replies to one of your commits, it shows up here.'],
        'quotes' => ['Quotes', 'No quotes yet. When someone quotes one of your commits, it shows up here.'],
        'stars' => ['Stars', 'No stars yet. When someone stars one of your commits, it shows up here.'],
        'mentions' => ['Mentions', 'No mentions yet. When someone mentions you in a commit, it shows up here.'],
        'follows' => ['Follows', 'No followers yet. When someone follows you, it shows up here.'],
        'moderation' => ['Moderation', 'No moderation updates. Approved and rejected commits show up here.'],
    ];

    /**
     * The categories the web's phone layout shows as chips, before its More.
     */
    public const array QuickFilters = ['replies', 'quotes', 'stars'];

    public const string AllEmpty = 'Nothing here yet. Follows, stars, replies, quotes, mentions, level-ups, and moderation updates for your commits will show up here.';

    public const string CombinedEmpty = 'No notifications in the selected categories yet.';

    public const string NoneEmpty = 'All categories are turned off. Enable a category to see notifications.';

    /**
     * The categories on, in the web's order. All six when nothing was kept.
     *
     * @var list<string>
     */
    public array $enabled = [];

    /**
     * The ?type= the selection on asks for, so a filter's answers can be told
     * from another selection's arriving late.
     */
    public string $type = 'all';

    /**
     * Mounts a fresh list when the selection changes, at the top.
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
     * The groups a poll brought in since the oldest one showing, fetched and
     * waiting for the pill, newest first. Each poll replaces them with its own
     * answer, so the same groups are never counted twice.
     *
     * @var list<Notification>
     */
    public array $staged = [];

    /**
     * The More sheet, holding the filters the web's phone layout keeps in its menu.
     */
    public bool $moreOpen = false;

    /**
     * A poll still out. Another is never sent beside it.
     */
    protected bool $polling = false;

    /**
     * Which ask the poll under way was made under. Selections and resumes
     * move it on, so a poll from before one of those lands too late to
     * apply, or to free the place of a poll since sent — its own type being
     * current again (a switch there and back) would not make it current.
     */
    protected int $pollToken = 0;

    public function mount(): void
    {
        $this->enabled = app(NotificationFilters::class)->enabled($this->me()?->id);
        $this->type = NotificationFilters::typeOf($this->enabled);
        $this->fillInAfterFirstFrame('revalidate');
    }

    public function onResume(): void
    {
        $this->forgetRefreshes();
        // Whatever was under way was dropped with the answers it was owed, so
        // its callback is stale: this moves the poll on, so neither applies
        // nor frees the one that goes out next.
        $this->pollToken++;
        $this->polling = false;
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Notifications';
    }

    /**
     * Shows a selection of the categories, from a link such as an empty
     * filter's Show all: all, one, none, or several as the comma-separated
     * ?type= they were sent as. The one already showing goes back to its top.
     *
     * Something that names no selection the web's filters could have made —
     * a stale link, a mistyped ?type= — changes nothing, rather than quietly
     * selecting none of them and remembering that for the account.
     */
    public function show(string $type): void
    {
        if (! self::isSelection($type)) {
            return;
        }

        $enabled = NotificationFilters::normalize($type);

        if ($enabled === $this->enabled) {
            $this->listKey++;

            return;
        }

        $this->applySelection($enabled);
    }

    /**
     * Whether $type names a selection the web's filters could have made: all,
     * none, or a comma list of nothing but its categories.
     */
    private static function isSelection(string $type): bool
    {
        if ($type === 'all' || $type === 'none') {
            return true;
        }

        return array_diff(explode(',', $type), array_keys(self::Categories)) === [];
    }

    /**
     * A filter chip, or a line in the More sheet: turns a category on or off
     * with the rest, as the web's checkboxes do.
     */
    public function toggle(string $category): void
    {
        if (! array_key_exists($category, self::Categories)) {
            return;
        }

        $on = in_array($category, $this->enabled, true);

        $this->applySelection($on
            ? array_values(array_diff($this->enabled, [$category]))
            : [...$this->enabled, $category]);
    }

    public function openMoreFilters(): void
    {
        $this->moreOpen = true;
    }

    public function moreFiltersClosed(): void
    {
        $this->moreOpen = false;
    }

    /**
     * The selection goes on for the account, and the list starts over at the
     * top of a fresh one: what was kept for it, then asked again. A poll's
     * answer for the selection before is no longer wanted, and what it had
     * staged for it goes.
     *
     * @param  list<string>  $categories
     */
    private function applySelection(array $categories): void
    {
        $this->enabled = NotificationFilters::normalize($categories);
        $this->type = NotificationFilters::typeOf($this->enabled);
        app(NotificationFilters::class)->remember($this->me()?->id, $this->enabled);

        $this->notifications = [];
        $this->nextCursor = null;
        $this->loaded = false;
        $this->error = null;
        $this->refreshFailed = false;
        $this->expanded = [];
        $this->expandedCursors = [];
        $this->failedGroups = [];
        $this->staged = [];
        // A poll under way for the selection before is stale from here on: it
        // neither applies nor frees the place of the one that goes out next.
        $this->pollToken++;
        $this->polling = false;
        $this->listKey++;
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
            $this->appendPage($page);
        }
    }

    /**
     * The web's poll for new notifications, every 30 seconds while the screen
     * is showing. devnull is asked for everything since the oldest group still
     * showing (through=), which it answers on top of a page, so what's new
     * arrives fetched. Nothing showing yet asks for the first page quietly.
     *
     * It is always sent when the timer comes due, never held back by a recent
     * ask of the same thing, but never beside itself: one at a time.
     */
    #[Poll(30000)]
    public function checkForNewNotifications(): void
    {
        if ($this->polling) {
            return;
        }

        $through = $this->notifications[count($this->notifications) - 1]->createdAt ?? null;
        $type = $this->type;
        $token = ++$this->pollToken;
        $current = fn (): bool => $token === $this->pollToken;

        if ($through === null || ! $this->loaded) {
            $this->polling = true;

            $this->refresh('notifications', Client::notificationsQuery($type), function (array $json) use ($type, $current): void {
                if ($current()) {
                    $this->polling = false;
                    $this->takeFresh($type, $json);
                }
            }, quiet: true, again: true, failed: function () use ($current): void {
                if ($current()) {
                    $this->polling = false;
                }
            });

            return;
        }

        $query = Client::notificationsQuery($type, through: $through->toIso8601ZuluString());

        $this->polling = true;

        $this->refresh('notifications', $query, function (array $json) use ($type, $query, $current): void {
            // A poll from before a selection change or a resume lands too
            // late to change anything, not even the pill, or to free the
            // place of a poll since sent.
            if ($current()) {
                $this->polling = false;

                // An answer for a selection you've left changes nothing, not even the pill.
                if ($type === $this->type) {
                    $this->takePolled($json);
                }
            }

            // A poll's answer is about that moment, keyed by the through= it
            // was asked with: every poll would keep another one. The list it
            // staged is in hand, so nothing of it is kept.
            app(ResponseCache::class)->forget('notifications', $query);
        }, quiet: true, again: true, failed: function () use ($current): void {
            if ($current()) {
                $this->polling = false;
            }
        });
    }

    /**
     * The pill: "3 new notifications". What it counts is groups, not the
     * events or the people in them.
     */
    public function newNotificationsLabel(): string
    {
        $count = count($this->staged);

        return $count.' '.($count === 1 ? 'new notification' : 'new notifications');
    }

    /**
     * The pill: puts what a poll staged on top, and goes back to the top.
     * A group already showing takes its pending copy's place — its newest
     * event moved on, say — rather than a second row beside it, and what's
     * loaded further down stays, with its cursor. What the group's row had
     * learnt of you following its person, a copy that didn't come with the
     * answer keeps; a group opened in place follows its leader's new id.
     */
    public function showNewNotifications(): void
    {
        if ($this->staged === []) {
            return;
        }

        $before = $this->groupLeaders($this->notifications);
        $followed = $this->followedByPerson();
        $staged = $this->groupIds($this->staged);

        $this->notifications = [...$this->carryFollowState($this->staged, $followed), ...array_values(array_filter(
            $this->notifications,
            fn (Notification $group): bool => ! isset($staged[$group->groupId]),
        ))];
        $this->staged = [];
        $this->listKey++;
        $this->migrateExpanded($before);
    }

    /**
     * A tap on a notification opens its commit, or the profile of whoever
     * followed you or of the level it says you reached. A tap on a group of
     * followers lists them in place instead, as its toggle does, rather than
     * opening only the latest of them.
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
     * The quoted commit of yours under a quote, which its card opens, as the
     * web's does: the commit quoted, not the one quoting it.
     */
    public function openCommit(string $id): void
    {
        $this->openInTab('/commits/'.$id);
    }

    /**
     * Names and avatars open their profile independently of the notification's commit.
     */
    public function openActor(string $username): void
    {
        foreach ([...$this->notifications, ...$this->staged, ...array_merge([], ...array_values($this->expanded))] as $notification) {
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

        // What a poll staged goes with the row: a newer copy of the same
        // person waiting in the pill must not undo the follow back, or its
        // rollback, when the pill is tapped.
        $this->notifications = array_map($update, $this->notifications);
        $this->staged = array_map($update, $this->staged);
        $this->expanded = array_map(fn (array $items): array => array_map($update, $items), $this->expanded);
    }

    private function refreshGroup(string $id): void
    {
        $type = $this->type;

        $this->refresh($this->groupPath($id), Client::notificationsQuery($type), function (array $json) use ($id, $type): void {
            // Folded away, or another selection, while it was on its way.
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

        // A group asked for again keeps what was loaded under its page: the
        // fresh first page covers the members it covers — whatever was
        // deleted among them gone — and the older ones stay past it, with
        // the cursor that pages on from them (see mergedGroup).
        [$items, $cursor] = $this->mergedGroup($this->expanded[$id] ?? [], $page, $this->expandedCursors[$id] ?? null);

        $this->expanded[$id] = $items;
        $this->setGroupCursor($id, $cursor);
    }

    /**
     * A group's members as a refresh left them, told apart by event: the
     * fresh first page replaces the members it covers, newest first, and
     * the members loaded under the page stay past the last one it also has,
     * never twice, with the cursor they were paged on from when any remain.
     * A group with nothing loaded under its page — opening one kept, say —
     * is simply the page. A page that shares nothing with what was loaded
     * says too much came in to line the two up, and stands for the group.
     *
     * @param  list<Notification>  $loaded
     * @param  Page<Notification>  $page
     * @return array{0: list<Notification>, 1: ?string}
     */
    private function mergedGroup(array $loaded, Page $page, ?string $retainedCursor): array
    {
        if ($loaded === []) {
            return [$page->items, $page->nextCursor];
        }

        $fresh = array_fill_keys(array_map(fn (Notification $item): string => $item->id, $page->items), true);

        $deepest = -1;

        foreach ($loaded as $index => $item) {
            if (isset($fresh[$item->id])) {
                $deepest = $index;
            }
        }

        $tail = $deepest < 0 ? [] : array_values(array_filter(
            array_slice($loaded, $deepest + 1),
            fn (Notification $item): bool => ! isset($fresh[$item->id]),
        ));

        if ($tail === []) {
            return [$page->items, $page->nextCursor];
        }

        return [[...$page->items, ...$tail], $retainedCursor];
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

    /**
     * @param  list<Notification>  $groups
     * @return array<string, true>
     */
    private function groupIds(array $groups): array
    {
        return array_fill_keys(array_map(fn (Notification $group): string => $group->groupId, $groups), true);
    }

    /**
     * Each group's newest event, which is the id a group is told apart by its
     * fresh copy arriving under: same group, newest moved on, is an update to
     * one showing rather than a new one beside it.
     *
     * @param  list<Notification>  $groups
     * @return array<string, string>
     */
    private function groupLeaders(array $groups): array
    {
        $leaders = [];

        foreach ($groups as $group) {
            $leaders[$group->groupId] = $group->id;
        }

        return $leaders;
    }

    /**
     * What the rows showing know of you following each group's person, so a
     * copy arriving without the word doesn't take it away again.
     *
     * @return array<string, ?bool>
     */
    private function followedByPerson(): array
    {
        $followed = [];

        foreach ($this->notifications as $group) {
            if ($group->isFollow()) {
                $followed[$group->actor->username] = $group->actorIsFollowed;
            }
        }

        return $followed;
    }

    /**
     * @param  list<Notification>  $groups
     * @param  array<string, ?bool>  $followed  by the person who followed you
     * @return list<Notification>
     */
    private function carryFollowState(array $groups, array $followed): array
    {
        return array_map(function (Notification $group) use ($followed): Notification {
            // Only the same person's word carries: a group's newest person
            // moves on as others join it, and their copy says nothing of you
            // following the one before them.
            if ($group->isFollow()
                && $group->actorIsFollowed === null
                && array_key_exists($group->actor->username, $followed)
                && $followed[$group->actor->username] !== null) {
                return $group->withActorFollowed($followed[$group->actor->username]);
            }

            return $group;
        }, $groups);
    }

    /**
     * A group opened in place is keyed by its newest event, which moves on as
     * people join it. When a fresh or pending copy takes a group's place, what
     * was opened under the old one moves with it — its members, its older
     * cursor, a failed open — so it stays open, and is asked for again under
     * the id devnull knows the group by now.
     *
     * @param  array<string, string>  $before  each group's newest id before the change
     */
    private function migrateExpanded(array $before): void
    {
        foreach ($this->groupLeaders($this->notifications) as $groupId => $id) {
            $old = $before[$groupId] ?? null;

            if ($old === null || $old === $id || ! array_key_exists($old, $this->expanded)) {
                continue;
            }

            $this->expanded[$id] = $this->expanded[$old];
            unset($this->expanded[$old]);

            if (array_key_exists($old, $this->expandedCursors)) {
                $this->expandedCursors[$id] = $this->expandedCursors[$old];
                unset($this->expandedCursors[$old]);
            }

            $this->failedGroups = array_values(array_map(
                fn (string $failed): string => $failed === $old ? $id : $failed,
                $this->failedGroups,
            ));

            $this->refreshGroup($id);
        }
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
     * A page asked for under the list goes in under it. A group is only ever
     * in once, however its newest event moved on, so paging under a pill that
     * put a fresh copy of one on top never duplicates it.
     */
    private function appendPage(Page $page): void
    {
        $shown = $this->groupIds($this->notifications);

        foreach ($page->items as $group) {
            if (isset($shown[$group->groupId])) {
                continue;
            }

            $this->notifications[] = $group;
            $shown[$group->groupId] = true;
        }

        $this->nextCursor = $page->nextCursor;
        $this->refreshUnreadNotifications();
    }

    /**
     * The answer to a poll: the groups in it that aren't showing yet, and the
     * ones whose newest event moved on since they were last drawn, wait for
     * the pill, as the latest word on what came in. A group back exactly as
     * it shows — the oldest one, through= being inclusive of it — is nothing
     * waiting, and neither is anything else unchanged. What arrived replaces
     * what an earlier poll staged, so nothing is counted twice, and a poll
     * that brought nothing in leaves the pill away.
     *
     * @param  array<string, mixed>  $json
     */
    private function takePolled(array $json): void
    {
        $page = Page::fromArray($json, Notification::fromArray(...));
        $showing = $this->groupLeaders($this->notifications);

        $this->staged = array_values(array_filter(
            $page->items,
            fn (Notification $group): bool => ! isset($showing[$group->groupId]) || $showing[$group->groupId] !== $group->id,
        ));

        // Listing any of it marks it read, so the badge is asked for again.
        $this->refreshUnreadNotifications();
    }

    /**
     * The fresh first page replaces what's showing, told apart by group: a
     * group's newest event moves on as people join it, and its fresh copy
     * takes the old one's place rather than sitting above it. Groups loaded
     * further down stay, past the last one the fresh page also has, with the
     * cursor that pages them. Groups a poll staged that the page brought in
     * are showing now, so they no longer wait for the pill.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeFresh(string $type, array $json): void
    {
        // An answer for a selection you've left.
        if ($type !== $this->type) {
            return;
        }

        $page = Page::fromArray($json, Notification::fromArray(...));
        $before = $this->groupLeaders($this->notifications);
        $fresh = $this->groupIds($page->items);

        $deepest = -1;

        foreach ($this->notifications as $index => $group) {
            if (isset($fresh[$group->groupId])) {
                $deepest = $index;
            }
        }

        $later = $deepest < 0 ? [] : array_values(array_filter(
            array_slice($this->notifications, $deepest + 1),
            fn (Notification $group): bool => ! isset($fresh[$group->groupId]),
        ));

        $this->notifications = [...$page->items, ...$later];
        $this->nextCursor = $later === [] ? $page->nextCursor : $this->nextCursor;
        $this->readAt ??= is_string($json['read_at'] ?? null) ? $json['read_at'] : null;
        $this->loaded = true;
        $this->migrateExpanded($before);

        if ($this->staged !== []) {
            $shown = $this->groupIds($this->notifications);
            $this->staged = array_values(array_filter(
                $this->staged,
                fn (Notification $group): bool => ! isset($shown[$group->groupId]),
            ));
        }

        $this->refreshUnreadNotifications();
    }

    public function render(): View
    {
        $readAt = $this->readAt === null ? null : CarbonImmutable::parse($this->readAt);
        $all = $this->enabled === array_keys(self::Categories);

        return view('native.notifications', [
            'categories' => self::Categories,
            'quickFilters' => self::QuickFilters,
            'moreFilters' => array_values(array_diff(array_keys(self::Categories), self::QuickFilters)),
            'enabled' => $this->enabled,
            'moreOpen' => $this->moreOpen,
            'newLabel' => $this->newNotificationsLabel(),
            'page' => [
                'end' => match (true) {
                    $this->notifications === [] => null,
                    $this->nextCursor !== null => 'more',
                    default => 'oldest',
                },
                'listKey' => $this->listKey,
                'notifications' => $this->notifications,
                'loaded' => $this->loaded,
                'error' => $this->error,
                'lastVisitBefore' => self::firstSeen($this->notifications, $readAt),
                'expanded' => $this->expanded,
                'expandedCursors' => $this->expandedCursors,
                'failedGroups' => $this->failedGroups,
            ],
            'stagedCount' => count($this->staged),
            'emptyCommand' => $all
                ? '$ cat /dev/notifications'
                : '$ cat /dev/notifications | grep -E \''.($this->enabled === [] ? '^$' : implode('|', $this->enabled)).'\'',
            'emptyCopy' => match (true) {
                $this->enabled === [] => self::NoneEmpty,
                $all => self::AllEmpty,
                count($this->enabled) === 1 => self::Categories[$this->enabled[0]][1],
                default => self::CombinedEmpty,
            },
            'allOn' => $all,
            'readAt' => $readAt,
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
