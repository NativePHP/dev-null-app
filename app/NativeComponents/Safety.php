<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\Page;
use App\Devnull\Data\User;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The people you mute and the people you block, as the web's Settings →
 * Safety lists them, last first, each with Unmute or Unblock, which the web
 * doesn't confirm either. It opens from your account, and from a profile you
 * blocked, beneath the tab it was opened in. Tapping someone opens their
 * profile.
 *
 * It opens on the lists as they were last time, then asks devnull again behind
 * them. Unmute and Unblock take the person off straight away, and put them
 * back if devnull says no.
 */
class Safety extends NativeComponent
{
    use OpensInTab;
    use RefreshesInBackground;
    use SyncsOutbox;
    use TalksToDevnull;

    /**
     * Where each list comes from, in the order the screen shows them.
     */
    public const array Groups = ['muted' => Client::MutedPath, 'blocked' => Client::BlockedPath];

    /**
     * @var array{muted: list<User>, blocked: list<User>}
     */
    public array $people = ['muted' => [], 'blocked' => []];

    /**
     * @var array{muted: ?string, blocked: ?string}
     */
    public array $cursors = ['muted' => null, 'blocked' => null];

    /**
     * Whether each list has had people to show, kept or fresh. Until then it shows skeleton rows.
     *
     * @var array{muted: bool, blocked: bool}
     */
    public array $loaded = ['muted' => false, 'blocked' => false];

    public function mount(): void
    {
        $this->showKept();
        $this->revalidate();
    }

    /**
     * Back from a profile: take any mute or block changed there from the cache at once, then ask devnull.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Muted and blocked';
    }

    public function unmute(string $username): void
    {
        $this->takeOff('muted', $username, fn (Client $devnull) => $devnull->unmute($username));
    }

    public function unblock(string $username): void
    {
        $this->takeOff('blocked', $username, fn (Client $devnull) => $devnull->unblock($username));
    }

    /**
     * The next page of a list, under the ones showing.
     */
    public function loadMore(string $group): void
    {
        $cursor = $this->cursors[$group] ?? null;

        if ($cursor === null) {
            return;
        }

        $page = $this->attempt(fn (Client $devnull): Page => $group === 'muted' ? $devnull->muted($cursor) : $devnull->blocked($cursor));

        if ($page !== null) {
            $this->people[$group] = [...$this->people[$group], ...$page->items];
            $this->cursors[$group] = $page->nextCursor;
        }
    }

    public function openProfile(string $username): void
    {
        foreach ([...$this->people['muted'], ...$this->people['blocked']] as $person) {
            if ($person->username === $username) {
                $this->openInTab('/users/'.$username, ['user' => $person]);

                return;
            }
        }
    }

    protected function revalidate(): void
    {
        foreach (self::Groups as $group => $path) {
            $this->refresh($path, [], fn (array $json) => $this->take($group, $json));
        }
    }

    protected function showsContent(): bool
    {
        return $this->loaded['muted'] || $this->loaded['blocked'];
    }

    private function showKept(): void
    {
        foreach (self::Groups as $group => $path) {
            if (($kept = $this->cached($path)) !== null) {
                $this->take($group, $kept);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function take(string $group, array $json): void
    {
        $page = Page::fromArray($json, User::fromArray(...));

        $this->people[$group] = $page->items;
        $this->cursors[$group] = $page->nextCursor;
        $this->loaded[$group] = true;
    }

    /**
     * @param  Closure(Client): void  $call
     */
    private function takeOff(string $group, string $username, Closure $call): void
    {
        $before = $this->people[$group] ?? [];
        $after = array_values(array_filter($before, fn (User $person): bool => $person->username !== $username));

        if (count($after) === count($before)) {
            return;
        }

        $this->people[$group] = $after;

        $done = $this->attempt(function (Client $devnull) use ($call): bool {
            $call($devnull);

            return true;
        });

        if ($done !== true) {
            $this->people[$group] = $before;
        }
    }

    public function render(): View
    {
        return view('native.safety', [
            'groups' => [
                [
                    'key' => 'muted',
                    'title' => 'Muted',
                    'description' => "Their commits and replies stay out of your logs, threads, search, and suggestions, and they can't notify you. They aren't told, and you can still visit their profile.",
                    'empty' => "You haven't muted anyone.",
                    'action' => 'Unmute',
                    'press' => 'unmute',
                ],
                [
                    'key' => 'blocked',
                    'title' => 'Blocked',
                    'description' => "Neither of you can follow, reply to, or star the other, or see each other's commits. Unblocking doesn't bring back follows.",
                    'empty' => "You haven't blocked anyone.",
                    'action' => 'Unblock',
                    'press' => 'unblock',
                ],
            ],
        ]);
    }
}
