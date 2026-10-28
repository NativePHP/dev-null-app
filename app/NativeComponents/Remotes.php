<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Conversation as ConversationData;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\MuteDuration;
use App\Devnull\RemoteMenu;
use App\Devnull\ResponseCache;
use App\Devnull\ShownTabs;
use App\Devnull\Toasts;
use App\Icons\Lucide;
use App\NativeComponents\Concerns\DrawsSkeletonFirst;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Concerns\WritesInBackground;
use App\NativeComponents\Layouts\TabsLayout;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * Remotes, the web's direct messages: your conversations and orgs, the one
 * with the latest message first, each with who it's with, its last message,
 * and how many messages are ahead of what you pulled, or when it was last
 * written to. Open lists the ones you haven't stashed, Stashed the ones you
 * have, and Fetched, while devnull takes requests at all, the first messages
 * from people you don't follow, waiting for you to merge or drop them.
 *
 * The tabs are pages you swipe between, as on the Log and Notifications: the
 * one you're on and one either side, which show the first few rows kept for
 * them.
 *
 * It opens on the inbox as it was last time, then asks devnull behind it, and
 * again every 15 seconds while it's showing, as the web's inbox polls. Rows
 * change in place, so the list never jumps: a conversation with a new
 * message moves to the top as it does on the web.
 *
 * While you have Remotes off, devnull answers the inbox as the web answers
 * /remotes, with a 404, and the tab shows the web's page for it.
 *
 * A tap opens a row's thread. A long press opens the web's row menu (see
 * RemoteMenu): View profile, Pin, Stash, Mute, then Block or Leave org. Each
 * changes the row at once, pinned rows going to the top as devnull sorts
 * them, and tells devnull from the background. If devnull says no, the row
 * goes back and a toast says so in the web's words. Mute asks how long for,
 * as the web's dialog does, and Block and Leave ask first.
 */
class Remotes extends NativeComponent
{
    use DrawsSkeletonFirst;
    use OpensInTab;
    use RefreshesInBackground;
    use SyncsOutbox;
    use TalksToDevnull;
    use WritesInBackground;

    /**
     * The web's tabs. Fetched only shows while devnull takes requests.
     */
    public const array Tabs = ['open' => 'Open', 'fetched' => 'Fetched', 'stashed' => 'Stashed'];

    /**
     * How many kept rows a tab beside the one you're on shows, enough to fill the screen while you swipe to it.
     */
    public const int PreviewRows = 8;

    /**
     * The alerts Block and Leave org confirm with, as the web words them. Each one's id names the row too.
     */
    public const string BlockAlert = 'remotes-block';

    public const string LeaveAlert = 'remotes-leave';

    /**
     * Every frame goes in full, as on the Log (see Log::$forceFullFrames), since a row moves to the top when it
     * has a new message.
     */
    protected bool $forceFullFrames = true;

    /** @var list<ConversationData> */
    public array $conversations = [];

    public bool $hasMore = false;

    /**
     * Whether the tab has had conversations to show, kept or fresh. Until then it shows skeleton rows.
     */
    public bool $loaded = false;

    public string $tab = 'open';

    /**
     * Mounts a fresh list for the tab you're on when it changes, at the top.
     */
    public int $listKey = 0;

    /**
     * The list key of each tab you're not on.
     *
     * @var array<string, int>
     */
    public array $listKeys = [];

    /**
     * How many requests wait in Fetched, which its tab counts, as the web's does.
     */
    public int $requestsCount = 0;

    /**
     * You have Remotes off, as devnull or your account last said.
     */
    public bool $off = false;

    /**
     * The row whose Mute sheet is open, asking how long for.
     */
    public ?string $muting = null;

    /**
     * What writes devnull hasn't answered yet changed in the rows, by the
     * row's id: a pin or a mute, or `gone` for one you stashed, blocked or
     * left. A poll that answers first gets them laid over it, so a row never
     * flips back and forth. Coming back to the tab forgets them, since
     * NativePHP drops an answer that comes while the tab is hidden.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $changing = [];

    public function mount(): void
    {
        $this->off = app(Account::class)->remotes() === null;
        $this->tab = app(ShownTabs::class)->get($this->me()?->id, 'remotes', array_keys($this->tabs()), 'open');
        $this->fillInAfterFirstFrame('revalidate');
    }

    /**
     * Back from a thread, where you may have pulled, pushed or stashed: take what's kept, then ask devnull.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->changing = [];
        $this->off = app(Account::class)->remotes() === null;
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Remotes';
    }

    /**
     * The web's buttons at the top of the inbox, with its icons: New org, then New remote. With Remotes off there's
     * nothing to start.
     */
    public function navigationOptions(): ?NavBarOptions
    {
        if ($this->off) {
            return null;
        }

        return NavBarOptions::make()
            ->action(
                NavAction::make('new-org')
                    ->icon(ios: Lucide::UsersRound->ios(), android: Lucide::UsersRound->android())
                    ->a11yLabel('New org')
                    ->press('newOrg'),
            )
            ->action(
                NavAction::make('new-remote')
                    ->icon(ios: Lucide::SquarePen->ios(), android: Lucide::SquarePen->android())
                    ->a11yLabel('New remote')
                    ->press('newRemote'),
            );
    }

    public function newRemote(): void
    {
        $this->openInTab('/new');
    }

    public function newOrg(): void
    {
        $this->openInTab('/new-org');
    }

    /**
     * The web's inbox poll, every 15 seconds. A poll that fails says nothing; the next one tries again.
     */
    #[Poll(15000)]
    public function pollInbox(): void
    {
        $tab = $this->tab;
        $this->refresh(Client::RemotesPath, $this->remotesQuery(), fn (array $json) => $this->takeFresh($tab, $json), quiet: true);
    }

    /**
     * Pull to refresh.
     */
    public function load(): void
    {
        $tab = $this->tab;
        $this->refresh(Client::RemotesPath, $this->remotesQuery(), fn (array $json) => $this->takeFresh($tab, $json), again: true);
        $this->refreshUnreadNotifications();
    }

    protected function revalidate(): void
    {
        $tab = $this->tab;
        $this->refresh(Client::RemotesPath, $this->remotesQuery(), fn (array $json) => $this->takeFresh($tab, $json));
        $this->refreshUnreadNotifications();
    }

    protected function showsContent(): bool
    {
        return $this->loaded;
    }

    /**
     * devnull answers a 404 while you have Remotes off, as the web does.
     */
    protected function refused(ApiException $exception): bool
    {
        if ($exception->status !== 404) {
            return false;
        }

        $this->off = true;
        app(Account::class)->rememberRemotes(null);

        return true;
    }

    /**
     * Shows a tab, and the pager follows. The one you're on goes back to its top.
     */
    public function showTab(string $tab): void
    {
        if (! array_key_exists($tab, $this->tabs())) {
            return;
        }

        if ($tab === $this->tab) {
            $this->listKey++;

            return;
        }

        $this->switchTo($tab);
    }

    /**
     * The pager settling on a tab, after a swipe or a tap along the top. The one it's already on changes nothing.
     */
    public function showPage(int $index): void
    {
        $tab = array_keys($this->tabs())[$index] ?? null;

        if ($tab !== null && $tab !== $this->tab) {
            $this->switchTo($tab);
        }
    }

    /**
     * A tap on the tab you're on, which the pager sends instead of moving: back to the top of it.
     */
    public function backToTop(int $index): void
    {
        $this->showTab(array_keys($this->tabs())[$index] ?? '');
    }

    public function loadMore(): void
    {
        $last = $this->conversations[array_key_last($this->conversations) ?? -1] ?? null;

        if (! $this->hasMore || $last === null) {
            return;
        }

        $json = $this->attempt(fn (Client $devnull): array => $devnull->remotes($this->remotesQuery()['tab'] ?? null, $last->id));

        if ($json === null) {
            return;
        }

        $shown = array_flip(array_map(fn (ConversationData $conversation): string => $conversation->id, $this->conversations));

        $this->conversations = [...$this->conversations, ...array_values(array_filter(
            self::conversationsFrom($json),
            fn (ConversationData $conversation): bool => ! isset($shown[$conversation->id]),
        ))];
        $this->hasMore = ($json['has_more'] ?? false) === true;
    }

    /**
     * Opens a conversation's thread in this tab, titled as its row is until devnull answers.
     */
    public function openConversation(string $id): void
    {
        foreach ($this->conversations as $conversation) {
            if ($conversation->id === $id) {
                $this->openInTab($conversation->path(), ['conversation' => $conversation]);

                return;
            }
        }
    }

    public function viewProfile(string $id): void
    {
        $partner = $this->row($id)?->partner;

        if ($partner !== null) {
            $this->openInTab('/users/'.rawurlencode($partner->username), ['user' => $partner]);
        }
    }

    public function pin(string $id): void
    {
        $this->changePin($id, true);
    }

    public function unpin(string $id): void
    {
        $this->changePin($id, false);
    }

    /**
     * Stash takes a row out of Open, and Pop out of Stashed, as the web does, saying where it went.
     */
    public function stash(string $id): void
    {
        $this->changeStash($id, true);
    }

    public function pop(string $id): void
    {
        $this->changeStash($id, false);
    }

    /**
     * Mute, from the menu: the sheet asks how long for, as the web's dialog does.
     */
    public function chooseMute(string $id): void
    {
        if ($this->row($id) !== null) {
            $this->muting = $id;
        }
    }

    public function muteFor(string $duration): void
    {
        $id = $this->muting;
        $duration = MuteDuration::tryFrom($duration);
        $this->muting = null;

        if ($id !== null && $duration !== null && $duration !== MuteDuration::Off) {
            $this->changeMute($id, $duration);
        }
    }

    public function muteSheetClosed(): void
    {
        $this->muting = null;
    }

    /**
     * Unmute goes straight away, as on the web.
     */
    public function unmute(string $id): void
    {
        $this->changeMute($id, MuteDuration::Off);
    }

    /**
     * Block, from the menu, asks first in the web's words.
     */
    public function confirmBlock(string $id): void
    {
        $row = $this->row($id);

        if ($row === null || $row->isOrg()) {
            return;
        }

        Dialog::alert('Block '.$row->menuTitle($this->me()?->id).'?', "This conversation goes away for both of you, and neither of you can message, follow, reply to, or star the other. They won't be told. You can unblock them any time from your settings.", [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Block', 'style' => 'destructive'],
        ])->id(self::BlockAlert.':'.$id);
    }

    /**
     * Leave org, from the menu, asks first in the web's words.
     */
    public function confirmLeave(string $id): void
    {
        $row = $this->row($id);

        if ($row === null || ! $row->isOrg()) {
            return;
        }

        Dialog::alert('Leave '.$row->menuTitle($this->me()?->id).'?', 'You stop seeing the org and its history. A maintainer would have to add you back. If you are its only maintainer, the longest-standing member takes over.', [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Leave', 'style' => 'destructive'],
        ])->id(self::LeaveAlert.':'.$id);
    }

    #[On(ButtonPressed::class)]
    public function onAlertButton(string $label = '', ?string $id = null): void
    {
        [$alert, $row] = array_pad(explode(':', (string) $id, 2), 2, '');

        match (true) {
            $alert === self::BlockAlert && $label === 'Block' => $this->block($row),
            $alert === self::LeaveAlert && $label === 'Leave' => $this->leave($row),
            default => null,
        };
    }

    /**
     * Blocks the partner, which takes the conversation away for both of you, so its row goes at once.
     */
    public function block(string $id): void
    {
        $row = $this->row($id);

        if ($row === null || $row->partner === null) {
            return;
        }

        $partner = $row->partner;
        $this->dropRow($id);

        $this->writeInBackground('POST', 'users/'.rawurlencode($partner->username).'/block', null, function (array $json) use ($id, $partner): void {
            $this->settleDrop($id);
            app(ResponseCache::class)->blockChanged($json);
            app(ResponseCache::class)->conversationGone($id);
            Toasts::say("Blocked {$partner->handle()}. Neither of you can message, follow, reply to, or star the other, or see each other's commits.");
        }, fn () => $this->putBack($row, "Could not block {$partner->handle()}. Try again."));
    }

    /**
     * Leaves an org: you take yourself out of its members, and its row goes at once.
     */
    public function leave(string $id): void
    {
        $row = $this->row($id);
        $me = $this->me();

        if ($row === null || ! $row->isOrg() || $me === null) {
            return;
        }

        $title = $row->menuTitle($me->id);
        $this->dropRow($id);

        $this->writeInBackground('DELETE', $row->threadPath().'/members/'.rawurlencode($me->username), null, function () use ($id): void {
            $this->settleDrop($id);
            app(ResponseCache::class)->conversationGone($id);
        }, fn () => $this->putBack($row, "Could not leave {$title}. Try again."));
    }

    /**
     * The 404's way out, as the web's: back to your log.
     */
    public function backToLog(): void
    {
        $this->replace(TabsLayout::Tabs[0])->transition(Transition::None);
    }

    private function changePin(string $id, bool $pinned): void
    {
        $row = $this->row($id);

        if ($row === null || $row->isPinned() === $pinned) {
            return;
        }

        $this->changeRow($id, ['pinnedAt' => $pinned ? CarbonImmutable::now() : null]);

        $this->writeInBackground(
            'PUT',
            $row->threadPath().'/pin',
            ['pinned' => $pinned],
            fn (array $json) => $this->settleRow($id, ['pinnedAt' => Commit::date($json['pinned_at'] ?? null)]),
            function () use ($id, $row): void {
                $this->settleRow($id, ['pinnedAt' => $row->pinnedAt]);
                Toasts::failed('Could not update the pin. Try again.');
            },
        );
    }

    private function changeMute(string $id, MuteDuration $duration): void
    {
        $row = $this->row($id);

        if ($row === null) {
            return;
        }

        $title = $row->menuTitle($this->me()?->id);
        $this->changeRow($id, ['mutedUntilAt' => $duration->until()]);

        $this->writeInBackground(
            'PUT',
            $row->threadPath().'/mute',
            ['duration' => $duration->value],
            function (array $json) use ($id, $duration, $title): void {
                $this->settleRow($id, ['mutedUntilAt' => Commit::date($json['muted_until_at'] ?? null)]);
                Toasts::say($duration === MuteDuration::Off ? "Unmuted {$title}." : "Muted {$title}.");
            },
            function () use ($id, $row): void {
                $this->settleRow($id, ['mutedUntilAt' => $row->mutedUntilAt]);
                Toasts::failed('Could not update the mute. Try again.');
            },
        );
    }

    private function changeStash(string $id, bool $stashed): void
    {
        $row = $this->row($id);

        if ($row === null || $row->isArchived === $stashed) {
            return;
        }

        $title = $row->menuTitle($this->me()?->id);
        $this->dropRow($id);
        app(ResponseCache::class)->conversationStashed($id, $stashed);

        $this->writeInBackground($stashed ? 'POST' : 'DELETE', $row->threadPath().'/archive', null, function () use ($id, $stashed, $title): void {
            $this->settleDrop($id);
            Toasts::say($stashed ? "Stashed {$title}. It pops back when someone writes." : "Popped {$title} back into Open.");
        }, function () use ($id, $row, $stashed): void {
            app(ResponseCache::class)->conversationStashed($id, ! $stashed);
            $this->putBack($row, 'Could not update the conversation. Try again.');
        });
    }

    private function row(string $id): ?ConversationData
    {
        foreach ($this->conversations as $conversation) {
            if ($conversation->id === $id) {
                return $conversation;
            }
        }

        return null;
    }

    /**
     * A row changes before devnull has said so: here, in the kept inbox, and over any poll until it does.
     *
     * @param  array<string, mixed>  $fields
     */
    private function changeRow(string $id, array $fields): void
    {
        $this->changing[$id] = [...$this->changing[$id] ?? [], ...$fields];
        $this->applyToRow($id, $fields);
    }

    /**
     * devnull answered for a row's change, or turned it down: it takes these fields, and polls go back to having
     * the last word.
     *
     * @param  array<string, mixed>  $fields
     */
    private function settleRow(string $id, array $fields): void
    {
        $this->changing[$id] = array_diff_key($this->changing[$id] ?? [], $fields);

        if ($this->changing[$id] === []) {
            unset($this->changing[$id]);
        }

        $this->applyToRow($id, $fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function applyToRow(string $id, array $fields): void
    {
        $this->conversations = self::sorted(array_map(
            fn (ConversationData $conversation): ConversationData => $conversation->id === $id ? $conversation->with($fields) : $conversation,
            $this->conversations,
        ));

        app(ResponseCache::class)->conversationChanged($id, array_filter([
            'pinned_at' => array_key_exists('pinnedAt', $fields) ? $fields['pinnedAt']?->toJSON() : false,
            'muted_until_at' => array_key_exists('mutedUntilAt', $fields) ? $fields['mutedUntilAt']?->toJSON() : false,
        ], fn (mixed $value): bool => $value !== false));
    }

    /**
     * A row leaves the tab before devnull has said so: stashed, popped, blocked or left.
     */
    private function dropRow(string $id): void
    {
        $this->changing[$id] = [...$this->changing[$id] ?? [], 'gone' => true];
        $this->conversations = array_values(array_filter($this->conversations, fn (ConversationData $conversation): bool => $conversation->id !== $id));
    }

    private function settleDrop(string $id): void
    {
        unset($this->changing[$id]['gone']);

        if (($this->changing[$id] ?? null) === []) {
            unset($this->changing[$id]);
        }
    }

    /**
     * devnull turned down a row leaving the tab: it comes back where it was, if the tab is still the one it was on.
     */
    private function putBack(ConversationData $row, string $error): void
    {
        $this->settleDrop($row->id);

        if ($this->row($row->id) === null && $row->isArchived === ($this->tab === 'stashed')) {
            $this->conversations = self::sorted([...$this->conversations, $row]);
        }

        Toasts::failed($error);
    }

    /**
     * Rows as devnull sorts them: pinned first, then the latest message first.
     *
     * @param  list<ConversationData>  $conversations
     * @return list<ConversationData>
     */
    private static function sorted(array $conversations): array
    {
        usort($conversations, fn (ConversationData $a, ConversationData $b): int => [$b->isPinned(), $b->lastMessageAt?->getTimestamp() ?? 0, $b->id]
            <=> [$a->isPinned(), $a->lastMessageAt?->getTimestamp() ?? 0, $a->id]);

        return $conversations;
    }

    /**
     * A fresh page with the changes devnull hasn't answered for yet laid over it.
     *
     * @param  list<ConversationData>  $conversations
     * @return list<ConversationData>
     */
    private function withChanges(array $conversations): array
    {
        if ($this->changing === []) {
            return $conversations;
        }

        $changed = [];

        foreach ($conversations as $conversation) {
            $changes = $this->changing[$conversation->id] ?? [];

            if (! ($changes['gone'] ?? false)) {
                $changed[] = $conversation->with(array_diff_key($changes, ['gone' => true]));
            }
        }

        return self::sorted($changed);
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        $tabs = self::Tabs;

        if (! (app(Account::class)->remotes()->requests ?? false)) {
            unset($tabs['fetched']);
        }

        return $tabs;
    }

    /**
     * The tab you go to keeps the list its first rows were drawn in, which can't have been scrolled, and fills it.
     * The one you leave starts again at the top of a fresh one.
     */
    private function switchTo(string $tab): void
    {
        app(ShownTabs::class)->remember($this->me()?->id, 'remotes', $tab);
        $this->listKeys[$this->tab] = $this->listKey + 1;
        $this->listKey = $this->listKeys[$tab] ?? 0;
        $this->tab = $tab;
        $this->conversations = [];
        $this->hasMore = false;
        $this->loaded = false;
        $this->error = null;
        $this->refreshFailed = false;
        $this->showKept();
        $this->revalidate();
    }

    /**
     * Open is the inbox itself, so it's asked for without a tab, as the web's links leave it off.
     *
     * @return array<string, string|null>
     */
    private function remotesQuery(?string $tab = null): array
    {
        $tab ??= $this->tab;

        return $tab === 'open' ? [] : ['tab' => $tab];
    }

    private function showKept(): void
    {
        if (! $this->off && ($json = $this->cached(Client::RemotesPath, $this->remotesQuery())) !== null) {
            $this->takeFresh($this->tab, $json);
        }
    }

    /**
     * A fresh first page replaces the top of the list. The conversations loaded below it stay, unless the fresh page
     * has them, as the web keeps its older rows under a polled first page. The unread count goes to the tab's badge.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeFresh(string $tab, array $json): void
    {
        // An answer for a tab you've left.
        if ($tab !== $this->tab) {
            return;
        }

        $fresh = $this->withChanges(self::conversationsFrom($json));
        $freshIds = array_flip(array_map(fn (ConversationData $conversation): string => $conversation->id, $fresh));
        $oldest = $fresh[array_key_last($fresh) ?? -1]->lastMessageAt ?? null;

        $later = $oldest === null || ! $this->hasMore ? [] : array_values(array_filter(
            $this->conversations,
            fn (ConversationData $conversation): bool => ! isset($freshIds[$conversation->id]) && $conversation->lastMessageAt !== null && $conversation->lastMessageAt <= $oldest,
        ));

        $this->conversations = [...$fresh, ...$later];
        $this->hasMore = $later === [] ? ($json['has_more'] ?? false) === true : $this->hasMore;
        $this->requestsCount = is_int($json['requests_count'] ?? null) ? $json['requests_count'] : 0;
        $this->loaded = true;
        $this->off = false;

        if (is_int($json['unread_count'] ?? null)) {
            app(Account::class)->rememberUnreadRemotes($json['unread_count']);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<ConversationData>
     */
    private static function conversationsFrom(array $json): array
    {
        return array_values(array_map(
            fn (array $conversation): ConversationData => ConversationData::fromArray($conversation),
            array_filter(is_array($json['data'] ?? null) ? $json['data'] : [], is_array(...)),
        ));
    }

    public function render(): View
    {
        $viewer = $this->me();
        $tabs = $this->tabs();
        $keys = array_keys($tabs);
        $index = (int) array_search($this->tab, $keys, true);
        $from = max(0, $index - 1);
        $to = min(count($keys) - 1, $index + 1);

        $pages = [];

        foreach (array_slice($keys, $from, $to - $from + 1) as $tab) {
            $pages[] = $tab === $this->tab
                ? $this->page($tab, $this->conversations, $this->loaded, $this->hasMore, $this->error, $this->listKey)
                : $this->preview($tab);
        }

        $muting = $this->muting === null ? null : $this->row($this->muting);

        return view('native.remotes', [
            'myColour' => $this->myColour(),
            'muting' => $muting,
            'mutingTitle' => $muting?->menuTitle($viewer?->id) ?? '',
            'tabLabels' => array_map(
                fn (string $tab): string => $tab === 'fetched' && $this->requestsCount > 0 ? "{$tabs[$tab]} · {$this->requestsCount}" : $tabs[$tab],
                $keys,
            ),
            'pages' => $pages,
            'pageIndex' => $index,
            'pagesFrom' => $from,
            'pagesTo' => $to,
        ]);
    }

    /**
     * A tab beside the one you're on: the first few rows kept for it, as it will open.
     *
     * @return array<string, mixed>
     */
    private function preview(string $tab): array
    {
        $json = $this->off || $this->skeletonFrame ? null : $this->cached(Client::RemotesPath, $this->remotesQuery($tab));

        return $this->page($tab, array_slice($json === null ? [] : self::conversationsFrom($json), 0, self::PreviewRows), $json !== null, false, null, $this->listKeys[$tab] ?? 0);
    }

    /**
     * What a tab's page shows.
     *
     * @param  list<ConversationData>  $conversations
     * @return array<string, mixed>
     */
    private function page(string $tab, array $conversations, bool $loaded, bool $hasMore, ?string $error, int $listKey): array
    {
        $viewer = $this->me();

        return [
            'tab' => $tab,
            'showing' => $tab === $this->tab,
            'listKey' => $listKey,
            'loaded' => $loaded,
            'hasMore' => $hasMore,
            'error' => $error,
            'rows' => array_map(fn (ConversationData $conversation): array => [
                'conversation' => $conversation,
                'title' => $conversation->title($viewer?->id),
                'others' => $conversation->others($viewer?->id),
                'preview' => $conversation->lastMessage?->preview($viewer?->id, $conversation->isOrg()) ?? '',
                'mine' => $conversation->lastMessage !== null && $conversation->lastMessage->userId === $viewer?->id,
                'viewer' => $viewer,
                'myColour' => $this->myColour(),
                'menu' => RemoteMenu::items($conversation, $viewer?->id),
            ], $conversations),
        ];
    }
}
