<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Conversation as ConversationData;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\ShownTabs;
use App\Icons\Lucide;
use App\NativeComponents\Concerns\DrawsSkeletonFirst;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;

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
 */
class Remotes extends NativeComponent
{
    use DrawsSkeletonFirst;
    use OpensInTab;
    use RefreshesInBackground;
    use SyncsOutbox;
    use TalksToDevnull;

    /**
     * The web's tabs. Fetched only shows while devnull takes requests.
     */
    public const array Tabs = ['open' => 'Open', 'fetched' => 'Fetched', 'stashed' => 'Stashed'];

    /**
     * How many kept rows a tab beside the one you're on shows, enough to fill the screen while you swipe to it.
     */
    public const int PreviewRows = 8;

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

    /**
     * The 404's way out, as the web's: back to your log.
     */
    public function backToLog(): void
    {
        $this->replace(TabsLayout::Tabs[0])->transition(Transition::None);
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

        $fresh = self::conversationsFrom($json);
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

        return view('native.remotes', [
            'myColour' => $this->myColour(),
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
            ], $conversations),
        ];
    }
}
