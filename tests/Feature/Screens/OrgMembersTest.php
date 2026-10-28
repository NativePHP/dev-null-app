<?php

use App\Devnull\ResponseCache;
use App\NativeComponents\NewOrg;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * `GET remotes/orgs/org-1/messages` for dev/null core, with you (local_dev) in it as $role, Ada contributing and
 * Grace maintaining.
 *
 * @param  list<array<string, mixed>>|null  $members
 * @return array<string, mixed>
 */
function orgJson(string $role = 'maintainer', ?string $name = 'dev/null core', ?array $members = null): array
{
    return ['data' => [
        'org' => ['id' => 'org-1', 'name' => $name],
        'messages' => [remoteMessageJson(['id' => 1, 'body' => 'hello org', 'user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace'])])],
        'has_older' => false,
        'reactions_version' => 0,
        'reaction_changes' => [],
        'read_through_id' => 1,
        'members' => $members ?? [
            ['user' => userJson(), 'role' => $role, 'read_through_id' => 1],
            ['user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 1]), 'role' => 'contributor', 'read_through_id' => 1],
            ['user' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace Hopper', 'lane' => 2]), 'role' => 'maintainer', 'read_through_id' => 1],
        ],
        'typing' => [],
        'is_archived' => false,
        'role' => $role,
        'access' => 'open',
    ]];
}

/**
 * devnull answering the org's thread with $thread, people searches with $candidates, and every write with the
 * status $writes gives its "METHOD path", 204 otherwise.
 *
 * @param  array<string, mixed>  $thread
 * @param  array<string, int>  $writes
 * @param  list<array<string, mixed>>  $candidates
 */
function serveOrg(array $thread, array $writes = [], array $candidates = []): void
{
    serveApi(function (Request $request) use ($thread, $writes, $candidates) {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1/'));

        return match (true) {
            $request->method() === 'GET' && $path === 'remotes/orgs/org-1/messages' => Http::response($thread),
            $request->method() === 'GET' && $path === 'remotes/candidates' => Http::response(['candidates' => $candidates]),
            $request->method() === 'GET' => Http::response(currentUserJson()),
            ($writes["{$request->method()} {$path}"] ?? 204) >= 300 => Http::response(['message' => 'Only maintainers can do that.'], $writes["{$request->method()} {$path}"]),
            default => Http::response(null, 204),
        };
    });
}

function writesSent(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $sent): bool => $sent[0]->method() !== 'GET')
        ->map(fn (array $sent): string => $sent[0]->method().' '.substr((string) parse_url($sent[0]->url(), PHP_URL_PATH), strlen('/api/v1/')))
        ->values()
        ->all();
}

it('offers Members from an org thread’s menu, and View profile from a person’s', function () {
    serveOrg(orgJson());

    finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->assertElement('top_bar_action', fn (array $node): bool => ($node['props']['id'] ?? null) === 'members' && ($node['props']['label'] ?? null) === 'Members')
        ->assertMissingElement('top_bar_action', fn (array $node): bool => ($node['props']['id'] ?? null) === 'view-profile')
        ->call('viewMembers')
        ->assertNavigatedTo('/remotes/orgs/org-1/members');

    serveApi(fn (Request $request) => Http::response(str_contains($request->url(), 'remotes/@ada/messages') ? threadJson([remoteMessageJson()]) : ['usage' => []]));

    finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->assertElement('top_bar_action', fn (array $node): bool => ($node['props']['id'] ?? null) === 'view-profile')
        ->assertMissingElement('top_bar_action', fn (array $node): bool => ($node['props']['id'] ?? null) === 'members');
});

it('shows everyone in the org with their role, from what the thread kept, before devnull answers', function () {
    keep('remotes/orgs/org-1/messages', orgJson());
    holdRefreshes();

    Native::visit('/remotes/orgs/org-1/members')
        ->assertNavTitle('dev/null core')
        ->assertSee('Local Dev')
        ->assertSee('(you)')
        ->assertSee('@ada')
        ->assertSee('Grace Hopper')
        ->assertSee('contributor')
        ->assertSee('maintainer')
        ->assertSee('Leave org');
});

it('calls an org without a name Org, and counts its members against the web’s limit', function () {
    serveOrg(orgJson(name: null));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))->assertNavTitle('Org');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['props']['nav_subtitle'] ?? null) === '3/'.NewOrg::MaxMembers.' members'))->not->toBeNull();
});

it('lets a maintainer rename, add people, and manage everyone but themselves', function () {
    serveOrg(orgJson('maintainer'));

    finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))
        ->assertSee('Rename')
        ->assertSee('Add people')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'manage-ada')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'manage-grace')
        ->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'manage-local_dev');
});

it('leaves a contributor to see who is in the org and leave, and nothing else', function () {
    serveOrg(orgJson('contributor'));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))
        ->assertDontSee('Rename')
        ->assertDontSee('Add people')
        ->assertMissingElement('pressable', fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'manage-'))
        ->assertSee('Leave org');

    $screen->call('rename', 'mine now')
        ->call('makeMaintainer', 'ada')
        ->call('confirmRemove', 'ada')
        ->assertNativeNotCalled('Dialog.Alert')
        ->call('remove', 'ada')
        ->call('addPeople')
        ->assertNoNavigation();

    expect(writesSent())->toBe([]);
});

it('renames the org at once, and takes the name away when the field is empty', function () {
    serveOrg(orgJson());

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))
        ->set('name', '  core team ')
        ->tap('rename-org')
        ->assertNavTitle('core team');

    finishRefreshes($screen)->assertSet('renaming', false);

    expect(json_decode(sentTo('PUT', 'remotes/orgs/org-1')[0], true))->toBe(['name' => 'core team']);

    finishRefreshes($screen->call('rename', ''))->assertNavTitle('Org');

    expect(json_decode(sentTo('PUT', 'remotes/orgs/org-1')[1], true))->toBe(['name' => '']);
});

it('puts the name back with devnull’s reason when it won’t rename the org', function () {
    serveOrg(orgJson(), ['PUT remotes/orgs/org-1' => 403]);

    finishRefreshes(finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))->call('rename', 'core team'))
        ->assertNavTitle('dev/null core')
        ->assertSet('name', 'dev/null core')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Only maintainers can do that.');
});

it('offers a maintainer the web’s menu on everyone else, and makes a contributor a maintainer from it', function (string $platform) {
    serveOrg(orgJson());
    holdRefreshes();

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1/members', platform: $platform));

    // More only opens its menu, which has what the web's member menu has.
    expect(nodeByRef($screen, 'manage-ada')['props']['has_menu'] ?? null)->toBeTrue()
        ->and(menuItems($screen, 'manage-ada'))->toBe([
            ['make-maintainer-ada', 'Make maintainer', 'custom:shield'],
            'divider',
            ['remove-ada', 'Remove from org', 'custom:user-minus'],
        ])
        ->and(menuItems($screen, 'manage-grace')[0])->toBe(['make-contributor-grace', 'Make contributor', 'custom:user-round'])
        ->and(nodeByRef($screen, 'remove-ada')['props']['destructive'] ?? null)->toBeTrue();

    $screen->tap('make-maintainer-ada');

    expect($screen->instance()->members[1]['role'])->toBe('maintainer');

    serveOrg(orgJson(members: [
        ['user' => userJson(), 'role' => 'maintainer', 'read_through_id' => 1],
        ['user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']), 'role' => 'maintainer', 'read_through_id' => 1],
    ]));
    finishRefreshes($screen);

    expect($screen->instance()->members[1]['role'])->toBe('maintainer')
        ->and(writesSent())->toBe(['PUT remotes/orgs/org-1/members/ada'])
        ->and(json_decode(sentTo('PUT', 'remotes/orgs/org-1/members/ada')[0], true))->toBe(['role' => 'maintainer']);
})->with(['ios', 'android']);

it('offers Make contributor for a maintainer, and puts their role back when devnull says no', function () {
    serveOrg(orgJson(), ['PUT remotes/orgs/org-1/members/grace' => 422]);
    holdRefreshes();

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))->tap('make-contributor-grace');

    expect($screen->instance()->members[2]['role'])->toBe('contributor');

    finishRefreshes($screen)->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Only maintainers can do that.');

    expect($screen->instance()->members[2]['role'])->toBe('maintainer');
});

it('never leaves an org without a maintainer', function () {
    // devnull says you maintain it, but its list has Grace as the only maintainer.
    serveOrg(orgJson('maintainer', members: [
        ['user' => userJson(), 'role' => 'contributor', 'read_through_id' => 1],
        ['user' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace Hopper']), 'role' => 'maintainer', 'read_through_id' => 1],
    ]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1/members'));

    expect(nodeByRef($screen, 'make-contributor-grace')['props']['disabled'] ?? null)->toBeTrue();

    $screen->call('makeContributor', 'grace')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'An org needs at least one maintainer.');

    expect(writesSent())->toBe([]);
});

it('asks before removing someone, and removes them only once you say so', function () {
    serveOrg(orgJson());
    holdRefreshes();

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))
        ->tap('remove-ada')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Remove @ada?'
            && $params['message'] === 'They stop seeing the org and its history. A maintainer would have to add them back.')
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'remove-member:org-1:ada'])
        ->assertSee('Ada Lovelace');

    expect(writesSent())->toBe([]);

    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Remove', 'id' => 'remove-member:org-1:ada'])
        ->assertDontSee('Ada Lovelace');

    serveOrg(orgJson(members: [orgJson()['data']['members'][0], orgJson()['data']['members'][2]]));
    finishRefreshes($screen)->assertDontSee('Ada Lovelace');

    expect(writesSent())->toBe(['DELETE remotes/orgs/org-1/members/ada']);
});

it('puts someone back where they were when devnull won’t remove them', function () {
    serveOrg(orgJson(), ['DELETE remotes/orgs/org-1/members/ada' => 403]);

    $screen = finishRefreshes(finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))->call('remove', 'ada'))
        ->assertSee('Ada Lovelace')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Only maintainers can do that.');

    expect(array_map(fn (array $member): string => $member['user']->username, $screen->instance()->members))->toBe(['local_dev', 'ada', 'grace']);
});

it('asks before you leave, in the web’s words', function () {
    serveOrg(orgJson('contributor'));

    finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))
        ->tap('leave-org')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Leave dev/null core?'
            && $params['message'] === 'You stop seeing the org and its history. A maintainer would have to add you back. If you are its only maintainer, the longest-standing member takes over.')
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'leave-org:org-1'])
        ->assertNoNavigation();

    expect(writesSent())->toBe([]);
});

it('leaves the org, takes it out of the kept inbox, and goes back past its thread to the inbox', function () {
    serveOrg(orgJson('contributor'));
    keep('remotes', remotesInboxJson([conversationJson(['id' => 'org-1', 'kind' => 'org', 'name' => 'dev/null core']), conversationJson()]));

    $thread = finishRefreshes(Native::visit('/remotes/orgs/org-1'));
    $members = $thread->call('viewMembers')->follow();

    finishRefreshes($members)->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Leave', 'id' => 'leave-org:org-1']);
    finishRefreshes($members)->assertWentBack();

    expect(writesSent())->toContain('DELETE remotes/orgs/org-1/members/local_dev')
        ->and(array_column(kept('remotes')['data'], 'id'))->toBe(['conv-1'])
        ->and(kept('remotes/orgs/org-1/messages'))->toBeNull();

    $members->goBack()->assertWentBack();

    // Only the once: the thread opened again later stays.
    expect(app(ResponseCache::class)->takeLeftOrg('org-1'))->toBeFalse();
});

it('stays in the org with devnull’s reason when it won’t let you leave', function () {
    serveOrg(orgJson('contributor'), ['DELETE remotes/orgs/org-1/members/local_dev' => 422]);

    finishRefreshes(finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))->call('leave'))
        ->assertNoNavigation()
        ->assertSet('leaving', false)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Only maintainers can do that.');

    expect(kept('remotes/orgs/org-1/messages'))->not->toBeNull();
});

it('opens Add people with how many the org holds, and opens a member’s profile from their row', function () {
    serveOrg(orgJson());

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1/members'));

    $screen->call('addPeople')->assertNavigatedTo('/remotes/orgs/org-1/members/add');

    expect($screen->navigationIntent()->data)->toBe(['members' => 3]);

    finishRefreshes(Native::visit('/remotes/orgs/org-1/members'))->call('viewProfile', 'ada')->assertNavigatedTo('/remotes/users/ada');
});

it('finds people who could join this org, and adds the ones picked with or without the history', function () {
    serveOrg(orgJson(), candidates: [
        ['user' => userJson(['id' => 'u4', 'handle' => 'linus', 'name' => 'Linus Torvalds']), 'reason' => null],
        ['user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']), 'reason' => 'already in the org'],
    ]);

    $screen = Native::visit('/remotes/orgs/org-1/members/add', ['members' => 3])
        ->assertNavTitle('Add people')
        ->assertSee('They join as contributors.')
        ->assertSee('3/'.NewOrg::MaxMembers)
        ->set('query', 'a')
        ->assertSee('@ada · already in the org')
        ->tap('candidate-ada')
        ->tap('candidate-linus')
        ->assertSee('4/'.NewOrg::MaxMembers)
        ->assertSee('Add 1')
        ->toggle('share-history-switch', true)
        ->assertSet('sharesHistory', true)
        ->tap('add-people');

    $search = collect(Http::recorded())->first(fn (array $sent): bool => str_contains($sent[0]->url(), 'remotes/candidates'))[0];

    expect($search->url())->toContain('org=org-1')->not->toContain('for=');

    finishRefreshes($screen)->assertWentBack();

    expect(json_decode(sentTo('POST', 'remotes/orgs/org-1/members')[0], true))->toBe(['members' => ['linus'], 'share_history' => true]);
});

it("shows devnull's reason beside Add when it won't add them, and stays", function () {
    serveOrg(orgJson(), ['POST remotes/orgs/org-1/members' => 422], [
        ['user' => userJson(['id' => 'u4', 'handle' => 'linus', 'name' => 'Linus Torvalds']), 'reason' => null],
    ]);

    $screen = Native::visit('/remotes/orgs/org-1/members/add', ['members' => 3])
        ->set('query', 'linus')
        ->tap('candidate-linus')
        ->tap('add-people');

    finishRefreshes($screen)
        ->assertSee('Only maintainers can do that.')
        ->assertSet('adding', false)
        ->assertNoNavigation();

    expect(json_decode(sentTo('POST', 'remotes/orgs/org-1/members')[0], true))->toBe(['members' => ['linus'], 'share_history' => false]);
});

it('says the org is full beside everyone once the people picked would fill it', function () {
    serveOrg(orgJson(), candidates: [
        ['user' => userJson(['id' => 'u4', 'handle' => 'linus', 'name' => 'Linus Torvalds']), 'reason' => null],
    ]);

    Native::visit('/remotes/orgs/org-1/members/add', ['members' => NewOrg::MaxMembers])
        ->set('query', 'linus')
        ->assertSee('@linus · the org is full (100)')
        ->tap('candidate-linus')
        ->assertSet('picked', []);
});
