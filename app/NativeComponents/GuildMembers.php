<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Connection;
use App\Devnull\Data\GuildBoard;
use App\Devnull\Data\GuildMembers as MembersPage;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\FreshPage;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\TalksToDevnull;
use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;

/**
 * Who's in a guild, as the web lists them at "/guilds/{handle}/members":
 * each member with their avatar, name, handle and bio, the moderator's name
 * followed by "(moderator)". A tap opens them. There is no Follow here, as on
 * the web.
 *
 * It opens from the guild's member count, on the first page as it was last
 * time, then asks devnull again behind it, as Connections does. It is titled
 * with the guild's name, taken from the screen it opened from, else from the
 * guild's own kept page, else its #handle.
 *
 * A guild devnull no longer has says so, as its page does, and so do guilds
 * being switched off for you.
 */
class GuildMembers extends NativeComponent
{
    use OpensInTab;
    use RefreshesInBackground;
    use TalksToDevnull;

    /**
     * The guild's name, for the title, when the screen that opened this knew it.
     */
    public ?string $guildName = null;

    /** @var list<Connection> */
    public array $people = [];

    public ?string $nextCursor = null;

    /**
     * The id of the person moderating the guild. A paused guild has none.
     */
    public ?string $moderatorId = null;

    /**
     * Whether the list has had people to show, kept or fresh. Until then it shows skeleton rows.
     */
    public bool $loaded = false;

    /**
     * devnull has no guild by this handle, though it has guilds.
     */
    public bool $missing = false;

    /**
     * Guilds are switched off for you: devnull answered the directory with a 404 too.
     */
    public bool $unavailable = false;

    public function mount(): void
    {
        $passed = $this->data('guildName');
        $this->guildName = is_string($passed) && $passed !== '' ? $passed : $this->keptGuildName();

        $this->showKept();
        $this->revalidate();
    }

    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return $this->guildName ?? '#'.$this->handle();
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->subtitle('Members');
    }

    /**
     * Pull to refresh.
     */
    public function load(): void
    {
        $this->refresh($this->listPath(), [], $this->takeFresh(...), again: true);
    }

    /**
     * The next page, less the people already showing.
     */
    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        $members = $this->attempt(fn (Client $devnull): MembersPage => $devnull->guildMembers($this->handle(), $this->nextCursor));

        if ($members === null) {
            return;
        }

        $shown = array_flip(array_map(fn (Connection $person): string => $person->user->id, $this->people));
        $this->people = [...$this->people, ...array_values(array_filter($members->people->items, fn (Connection $person): bool => ! isset($shown[$person->user->id])))];
        $this->nextCursor = $members->people->nextCursor;
        $this->moderatorId = $members->moderatorId;
    }

    public function open(string $username): void
    {
        foreach ($this->people as $person) {
            if ($person->user->username === $username) {
                $this->openInTab('/users/'.rawurlencode($username), ['user' => $person->user]);

                return;
            }
        }
    }

    protected function revalidate(): void
    {
        $this->refresh($this->listPath(), [], $this->takeFresh(...));
    }

    /**
     * A 404 for the members is a guild devnull no longer has, unless it has no guilds for you at all, as on the
     * guild's page (see Guild::refused): only the directory can tell, so it's asked.
     */
    protected function refused(ApiException $exception, string $path): bool
    {
        if ($path === 'guilds' && $exception->status === 404) {
            $this->unavailable = true;
            app(Account::class)->rememberGuildsAvailable(false);

            return true;
        }

        if ($path !== $this->listPath() || $exception->status !== 404) {
            return false;
        }

        $this->people = [];
        $this->nextCursor = null;
        $this->moderatorId = null;

        $this->refresh('guilds', [], function (): void {
            $this->missing = true;
            app(Account::class)->rememberGuildsAvailable(true);
        });

        return true;
    }

    protected function showsContent(): bool
    {
        return $this->loaded;
    }

    private function showKept(): void
    {
        if (($json = $this->cached($this->listPath())) !== null) {
            $this->takeFresh($json);
        }
    }

    /**
     * The fresh first page over what's showing: new people go on top, people
     * no longer in it go, and pages loaded further down stay.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeFresh(array $json): void
    {
        $members = MembersPage::fromArray($json);

        $fresh = $members->people->hasMore()
            ? FreshPage::over($this->people, $this->nextCursor, $members->people)->withNewer()
            : new FreshPage($members->people->items, null);

        $this->people = $fresh->items;
        $this->nextCursor = $fresh->nextCursor;
        $this->moderatorId = $members->moderatorId;
        $this->loaded = true;
        $this->missing = false;
        $this->unavailable = false;
    }

    /**
     * The name on the guild's own page, as last kept, for a title when none was passed.
     */
    private function keptGuildName(): ?string
    {
        $json = $this->cached('guilds/'.rawurlencode($this->handle()));
        $name = $json === null ? null : GuildBoard::fromArray($json)?->guild->name;

        return $name === '' ? null : $name;
    }

    private function listPath(): string
    {
        return 'guilds/'.rawurlencode($this->handle()).'/members';
    }

    private function handle(): string
    {
        return (string) $this->param('handle');
    }

    public function render(): View
    {
        return view('native.guild-members', [
            'handle' => $this->handle(),
            'viewer' => $this->me(),
            'moderatorId' => $this->moderatorId,
        ]);
    }
}
