<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\Thread;
use App\Devnull\Data\User;
use App\Devnull\OrgMemberMenu;
use App\Devnull\ResponseCache;
use App\Devnull\Toasts;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Concerns\WritesInBackground;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * The web's org members dialog as a screen, opened from an org thread's menu:
 * everyone in the org with their role, and Leave org for anyone. A maintainer
 * also renames the org, adds people, and from More on someone else makes them
 * a maintainer or a contributor, or removes them, as the web's member menu
 * does (see OrgMemberMenu). A contributor sees who's in it and can leave, and
 * nothing else.
 *
 * It shows the org as its thread last kept it, then asks devnull behind it.
 * Each change shows at once and goes to devnull in the background; one that
 * devnull turns down goes back as it was, with a toast in devnull's words.
 * Removing someone and leaving ask first. Once you've left, the inbox no
 * longer lists the org, and back goes past its thread to the inbox.
 */
class OrgMembers extends NativeComponent
{
    use OpensInTab;
    use RefreshesInBackground;
    use SyncsOutbox;
    use TalksToDevnull;
    use WritesInBackground;

    public const string Maintainer = 'maintainer';

    public const string Contributor = 'contributor';

    /**
     * The alerts Remove from org and Leave org confirm with. Each one's id names the org, and the member, so an
     * answer only reaches what asked.
     */
    public const string RemoveAlert = 'remove-member';

    public const string LeaveAlert = 'leave-org';

    /**
     * The org's id, from the route.
     */
    public string $org = '';

    public ?string $orgName = null;

    /** @var list<array{user: User, role: ?string}> */
    public array $members = [];

    /**
     * Your role in the org: a maintainer, or a contributor.
     */
    public ?string $role = null;

    public bool $loaded = false;

    /**
     * The name in the rename field.
     */
    public string $name = '';

    public bool $renaming = false;

    /**
     * The members whose role or place devnull hasn't answered for yet, which a refresh leaves alone.
     *
     * @var array<string, true>
     */
    public array $changing = [];

    public bool $leaving = false;

    public function mount(): void
    {
        $this->showKept();
        $this->revalidate();
    }

    /**
     * Back from Add people or a profile: take what's kept, then ask devnull.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return $this->orgName ?? 'Org';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->subtitle($this->loaded ? count($this->members).'/'.NewOrg::MaxMembers.' members' : null);
    }

    protected function revalidate(): void
    {
        $this->refresh($this->messagesPath(), [], fn (array $json) => $this->take($json));
    }

    protected function showsContent(): bool
    {
        return $this->loaded;
    }

    /**
     * Renames the org, or takes its name away when the field is empty, as the web's Rename does.
     */
    public function rename(?string $submitted = null): void
    {
        $name = trim($submitted ?? $this->name);

        if (! $this->isMaintainer() || $this->renaming || $name === ($this->orgName ?? '')) {
            return;
        }

        $before = $this->orgName;
        $this->orgName = $name === '' ? null : $name;
        $this->name = $name;
        $this->renaming = true;

        $this->writeInBackground('PUT', $this->threadPath(), ['name' => $name], function (): void {
            $this->renaming = false;
        }, function (string $message) use ($before): void {
            $this->orgName = $before;
            $this->name = $before ?? '';
            $this->renaming = false;
            Toasts::failed($message);
        });
    }

    public function makeMaintainer(string $username): void
    {
        $this->changeRole($username, self::Maintainer);
    }

    public function makeContributor(string $username): void
    {
        $this->changeRole($username, self::Contributor);
    }

    public function viewProfile(string $username): void
    {
        $this->openInTab('/users/'.rawurlencode($username));
    }

    /**
     * Remove from org, from someone's menu, which asks first. The web doesn't, but taking someone out of the org is
     * as hard to undo as leaving it.
     */
    public function confirmRemove(string $username): void
    {
        if (! $this->canManage($username)) {
            return;
        }

        Dialog::alert("Remove @{$username}?", 'They stop seeing the org and its history. A maintainer would have to add them back.', [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Remove', 'style' => 'destructive'],
        ])->id($this->alertId(self::RemoveAlert, $username));
    }

    /**
     * Leave org, in the words of the web's confirmation.
     */
    public function confirmLeave(): void
    {
        if ($this->leaving || $this->me() === null) {
            return;
        }

        Dialog::alert("Leave {$this->title()}?", 'You stop seeing the org and its history. A maintainer would have to add you back. If you are its only maintainer, the longest-standing member takes over.', [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Leave', 'style' => 'destructive'],
        ])->id($this->alertId(self::LeaveAlert));
    }

    #[On(ButtonPressed::class)]
    public function onAlertButton(string $label = '', ?string $id = null): void
    {
        if ($id === $this->alertId(self::LeaveAlert) && $label === 'Leave') {
            $this->leave();

            return;
        }

        $prefix = $this->alertId(self::RemoveAlert, '');

        if ($id !== null && str_starts_with($id, $prefix) && $label === 'Remove') {
            $this->remove(substr($id, strlen($prefix)));
        }
    }

    /**
     * Takes someone out of the org straight away, and puts them back where they were if devnull says no.
     */
    public function remove(string $username): void
    {
        $index = $this->memberIndex($username);

        if ($index === null || ! $this->canManage($username)) {
            return;
        }

        $member = $this->members[$index];
        $this->members = array_values(array_filter($this->members, fn (array $kept): bool => $kept['user']->username !== $username));
        $this->changing[$username] = true;

        $this->writeInBackground('DELETE', Client::orgMembersPath($this->org, $username), null, function () use ($username): void {
            unset($this->changing[$username]);
            $this->revalidate();
        }, function (string $message) use ($username, $member, $index): void {
            unset($this->changing[$username]);
            array_splice($this->members, min($index, count($this->members)), 0, [$member]);
            Toasts::failed($message);
        });
    }

    /**
     * Leaves the org once devnull has taken it, then goes back to the inbox, which no longer lists it.
     */
    public function leave(): void
    {
        $me = $this->me();

        if ($me === null || $this->leaving) {
            return;
        }

        $this->leaving = true;

        $this->writeInBackground('DELETE', Client::orgMembersPath($this->org, $me->username), null, function (): void {
            app(ResponseCache::class)->orgLeft($this->org);
            $this->back();
        }, function (string $message): void {
            $this->leaving = false;
            Toasts::failed($message);
        });
    }

    /**
     * Add people, for a maintainer while the org has room.
     */
    public function addPeople(): void
    {
        if ($this->isMaintainer() && count($this->members) < NewOrg::MaxMembers) {
            $this->openInTab('/orgs/'.rawurlencode($this->org).'/members/add', ['members' => count($this->members)]);
        }
    }

    public function render(): View
    {
        $me = $this->me();
        $isMaintainer = $this->isMaintainer();
        $maintainers = $this->maintainers();

        return view('native.org-members', [
            'isMaintainer' => $isMaintainer,
            'isFull' => count($this->members) >= NewOrg::MaxMembers,
            'canRename' => $isMaintainer && ! $this->renaming && trim($this->name) !== ($this->orgName ?? ''),
            'myColour' => $this->myColour(),
            'rows' => array_map(fn (array $member): array => [
                'user' => $member['user'],
                'role' => $member['role'] ?? self::Contributor,
                'isMe' => $member['user']->id === $me?->id,
                'menu' => $this->canManage($member['user']->username)
                    ? OrgMemberMenu::items($member['user'], $member['role'], $member['role'] === self::Maintainer && $maintainers === 1)
                    : [],
                'isChanging' => isset($this->changing[$member['user']->username]),
            ], $this->members),
        ]);
    }

    /**
     * The org as devnull sent its thread. A member whose change is still on its way stays as the change left them.
     *
     * @param  array<string, mixed>  $json
     */
    private function take(array $json): void
    {
        $thread = Thread::fromArray($json);

        if (! $thread->isOrg()) {
            return;
        }

        if (! $this->loaded || trim($this->name) === ($this->orgName ?? '')) {
            $this->name = $thread->orgName ?? '';
        }

        $this->orgName = $this->renaming ? $this->orgName : $thread->orgName;
        $this->role = $thread->role;
        $this->loaded = true;

        if ($this->changing === []) {
            $this->members = array_map(fn (array $member): array => ['user' => $member['user'], 'role' => $member['role']], $thread->members);
        }

    }

    /**
     * Gives someone a role straight away, and puts their old one back if devnull says no. An org keeps at least one
     * maintainer.
     */
    private function changeRole(string $username, string $role): void
    {
        $index = $this->memberIndex($username);

        if ($index === null || ! $this->canManage($username) || ($this->members[$index]['role'] ?? self::Contributor) === $role) {
            return;
        }

        if ($role === self::Contributor && $this->maintainers() === 1) {
            Toasts::failed('An org needs at least one maintainer.');

            return;
        }

        $before = $this->members[$index]['role'];
        $this->setRoleOf($username, $role);
        $this->changing[$username] = true;

        $this->writeInBackground('PUT', Client::orgMembersPath($this->org, $username), ['role' => $role], function () use ($username): void {
            unset($this->changing[$username]);
            $this->revalidate();
        }, function (string $message) use ($username, $before): void {
            unset($this->changing[$username]);
            $this->setRoleOf($username, $before);
            Toasts::failed($message);
        });
    }

    private function setRoleOf(string $username, ?string $role): void
    {
        $this->members = array_map(
            fn (array $member): array => $member['user']->username === $username ? [...$member, 'role' => $role] : $member,
            $this->members,
        );
    }

    private function showKept(): void
    {
        if (($json = $this->cached($this->messagesPath())) !== null) {
            $this->take($json);
        }
    }

    private function isMaintainer(): bool
    {
        return $this->role === self::Maintainer;
    }

    /**
     * Whether you can change someone's role or remove them: you maintain the org, and they're someone else in it.
     */
    private function canManage(string $username): bool
    {
        return $this->isMaintainer() && $username !== $this->me()?->username && $this->memberIndex($username) !== null;
    }

    private function memberIndex(string $username): ?int
    {
        foreach ($this->members as $index => $member) {
            if ($member['user']->username === $username) {
                return $index;
            }
        }

        return null;
    }

    private function maintainers(): int
    {
        return count(array_filter($this->members, fn (array $member): bool => $member['role'] === self::Maintainer));
    }

    /**
     * What the confirmations call the org: its name, or else its other members' first names, as its thread is titled.
     */
    private function title(): string
    {
        if ($this->orgName !== null) {
            return $this->orgName;
        }

        $viewerId = $this->me()?->id;
        $others = array_filter($this->members, fn (array $member): bool => $member['user']->id !== $viewerId);
        $names = array_map(fn (array $member): string => (string) strtok($member['user']->name, ' '), $others);

        return $names === [] ? 'the org' : implode(', ', $names);
    }

    private function alertId(string $alert, ?string $username = null): string
    {
        return "{$alert}:{$this->org}".($username === null ? '' : ":{$username}");
    }

    private function threadPath(): string
    {
        return Client::threadPath(orgId: $this->org);
    }

    private function messagesPath(): string
    {
        return $this->threadPath().'/messages';
    }
}
