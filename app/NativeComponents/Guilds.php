<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Data\Guild;
use App\Devnull\Data\Page;
use App\Devnull\Exceptions\ApiException;
use App\NativeComponents\Concerns\OpensGuilds;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\TalksToDevnull;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * A list of guilds (app/Devnull/Data/Guild.php), which are public groups
 * around a hashtag that have a custom name, icon and description.
 *
 * The web pages through them with Previous and Next; but in the native app
 * we instead are using infinite scrolling to load more guilds.
 *
 * Tapping a guild opens its own screen, its board (see Guild and OpensGuilds).
 */
class Guilds extends NativeComponent
{
    use OpensGuilds;
    use OpensInTab;
    use RefreshesInBackground;
    use TalksToDevnull;

    public string $term = '';

    /** @var list<Guild> */
    public array $guilds = [];

    /**
     * The number of the next page
     */
    public ?string $nextPage = null;

    /**
     * Whether the list has had guilds to show. Until then it shows skeleton rows.
     */
    public bool $loaded = false;

    /**
     * Guilds are switched off for you: devnull answered the directory with a 404.
     */
    public bool $unavailable = false;

    public function mount(): void
    {
        $this->showKept();
        $this->revalidate();
    }

    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Guilds';
    }

    /**
     * Pull to refresh.
     */
    public function load(): void
    {
        $this->ask(again: true);
    }

    /**
     * The field's term changed.
     */
    public function updatedTerm(): void
    {
        $this->ask();
    }

    /**
     * The keyboard's search key, with the field's text, which a debounced binding may not have caught up with yet.
     */
    public function search(?string $submitted = null): void
    {
        $this->term = $submitted ?? $this->term;
        $this->ask(again: true);
    }

    /**
     * Browse all guilds: the whole directory, from the top.
     */
    public function browseAll(): void
    {
        $this->term = '';
        $this->ask(again: true);
    }

    /**
     * Create a guild, as the web's Guilds page offers above its list: the guild form, beneath this tab.
     */
    public function createGuild(): void
    {
        $this->openInTab('/guilds/_new');
    }

    public function loadMore(): void
    {
        if ($this->nextPage === null) {
            return;
        }

        $page = $this->attempt(fn ($devnull) => $devnull->guilds($this->searchTerm(), $this->nextPage));

        if ($page !== null) {
            $shown = array_flip(array_map(fn (Guild $guild): string => $guild->handle, $this->guilds));
            $this->guilds = [...$this->guilds, ...array_values(array_filter($page->items, fn (Guild $guild): bool => ! isset($shown[$guild->handle])))];
            $this->nextPage = $page->nextCursor;
        }
    }

    protected function revalidate(): void
    {
        $this->ask();
    }

    protected function showsContent(): bool
    {
        return $this->loaded;
    }

    /**
     * A 404 for the directory means guilds are switched off for the
     * current user (unlikely to happen now that it's available to public).
     */
    protected function refused(ApiException $exception, string $path): bool
    {
        if ($exception->status !== 404) {
            return false;
        }

        $this->unavailable = true;
        $this->loaded = true;
        app(Account::class)->rememberGuildsAvailable(false);
        $this->guilds = [];
        $this->nextPage = null;

        return true;
    }

    /**
     * Asks devnull for the first page of guilds for the term in the field.
     */
    private function ask(bool $again = false): void
    {
        $term = $this->searchTerm();

        $this->refresh('guilds', ['q' => $term === '' ? null : $term], function (array $json) use ($term): void {
            if ($this->searchTerm() !== $term) {
                return;
            }

            $this->take($json);
        }, again: $again);
    }

    private function showKept(): void
    {
        if (($json = $this->cached('guilds')) !== null) {
            $this->take($json);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function take(array $json): void
    {
        $page = Page::fromArray($json, Guild::fromArray(...));

        $this->guilds = array_values(array_filter($page->items));
        $this->nextPage = $page->nextCursor;
        $this->loaded = true;
        $this->unavailable = false;
        app(Account::class)->rememberGuildsAvailable(true);
    }

    /**
     * We trim the search term, just like it's trimmed on the web app.
     */
    private function searchTerm(): string
    {
        return trim($this->term);
    }

    public function render(): View
    {
        return view('native.guilds');
    }
}
