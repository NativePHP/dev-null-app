<?php

use App\Devnull\Client;
use App\NativeComponents\Conversation;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Remotes;
use App\NativeComponents\RemotesWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo('2026-10-19 15:30:00');
    // As iOS routes the tab, so what opens over it opens beneath /remotes.
    NativeRouter::register('/remotes', RemotesWorkspace::class, TabsLayout::class);
});

afterEach(function () {
    NativeRouter::register('/remotes', Remotes::class, TabsLayout::class);
});

/**
 * devnull answering the inbox with Ada and an org, either thread, and every write. With Remotes off the inbox answers
 * 404, as the web's /remotes does, and a thread that has gone answers 404 too.
 */
function serveRemotesWorkspace(bool $off = false, bool $threadsGone = false): void
{
    serveApi(function (Request $request) use ($off, $threadsGone) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $viewer = '0199e5c4-0000-7000-8000-000000000001';

        return match (true) {
            $path === '/api/v1/remotes' => $off
                ? Http::response(['message' => 'Not found.'], 404)
                : Http::response(remotesInboxJson([
                    conversationJson(),
                    conversationJson(['id' => 'org-1', 'kind' => 'org', 'name' => 'dev/null core', 'partner' => null, 'members' => [
                        ['user' => userJson(), 'role' => 'maintainer'],
                        ['user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']), 'role' => 'contributor'],
                    ]]),
                ])),
            $threadsGone && str_ends_with($path, '/messages') => Http::response(['message' => 'No query results for model [App\\Models\\Conversation] conv-1'], 404),
            $request->method() === 'GET' && $path === '/api/v1/remotes/@ada/messages' => Http::response(threadJson([remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?'])], ['read_through_id' => 1])),
            $request->method() === 'POST' && $path === '/api/v1/remotes/@ada/messages' => Http::response(['data' => remoteMessageJson([
                'id' => 99,
                'user_id' => $viewer,
                'client_id' => json_decode($request->body(), true)['client_id'],
                'body' => json_decode($request->body(), true)['body'],
            ])], 201),
            $request->method() === 'GET' && $path === '/api/v1/remotes/orgs/org-1/messages' => Http::response(['data' => [
                'org' => ['id' => 'org-1', 'name' => 'dev/null core'],
                'messages' => [remoteMessageJson(['id' => 2, 'body' => 'hello org', 'user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace'])])],
                'has_older' => false,
                'reactions_version' => 0,
                'reaction_changes' => [],
                'read_through_id' => 2,
                'members' => [
                    ['user' => userJson(), 'role' => 'maintainer', 'read_through_id' => 2],
                    ['user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']), 'role' => 'contributor', 'read_through_id' => 2],
                ],
                'typing' => [],
                'is_archived' => false,
                'role' => 'maintainer',
                'access' => 'open',
            ]]),
            $path === '/api/v1/me' => Http::response(currentUserJson()),
            $request->method() === 'PUT' && str_ends_with($path, '/pin') => Http::response(['pinned_at' => '2026-10-19T15:30:00.000000Z']),
            default => Http::response(['usage' => []], $request->method() === 'GET' ? 200 : 204),
        };
    });
}

function remoteRowBackground(TestableComponent $screen, string $id): string
{
    return strtolower(findNode(nodeByRef($screen, 'remote-'.$id), fn (array $node): bool => isset($node['style']['bg_color']))['style']['bg_color']);
}

it('opens a thread beside the inbox, and marks its row while it is open', function () {
    serveRemotesWorkspace();
    $screen = finishRefreshes(Native::visit('/remotes', platform: 'ios'));
    $surface = remoteRowBackground($screen, 'conv-1');
    expect(treeShows(nodeByRef($screen, 'no-conversation'), 'no remote selected'))->toBeTrue();

    finishRefreshes($screen->tap('remote-conv-1'))->assertSee('did you see the PR?')->assertNoNavigation();

    expect(array_column($screen->get('conversations'), 'id'))->toBe(['/conversations/ada'])
        ->and($screen->instance()->foregroundPane())->toBeInstanceOf(Conversation::class)
        ->and(remoteRowBackground($screen, 'conv-1'))->toBe(strtolower((string) theme('wash')))
        ->and(remoteRowBackground($screen, 'org-1'))->toBe($surface);

    $screen->call('hostChanged', json_encode(['action' => 'back', 'depth' => 0, 'expected_depth' => 1, 'expected_revision' => 1, 'expected_history' => ['conversation-1']]));

    expect($screen->get('conversations'))->toBe([])
        ->and(remoteRowBackground($screen, 'conv-1'))->toBe($surface);
});

it('opens an org\'s thread beside the inbox by its id', function () {
    serveRemotesWorkspace();
    $screen = finishRefreshes(finishRefreshes(Native::visit('/remotes', platform: 'ios'))->tap('remote-org-1'))->assertSee('hello org');

    expect(array_column($screen->get('conversations'), 'id'))->toBe(['/orgs/org-1'])
        ->and($screen->instance()->foregroundPane()->org)->toBe('org-1')
        ->and(workspaceBar($screen, 'conversation-1')['props']['title'])->toBe('dev/null core')
        ->and(workspaceBarAction($screen, 'members', 'conversation-1'))->not->toBeNull()
        ->and(workspaceBarAction($screen, 'call', 'conversation-1'))->toBeNull();
});

it('puts the thread\'s call and menu on its own bar, and the inbox\'s buttons stay on the inbox\'s', function () {
    serveRemotesWorkspace();
    $screen = finishRefreshes(finishRefreshes(Native::visit('/remotes', platform: 'ios'))->tap('remote-conv-1'));
    $bar = workspaceBar($screen, 'conversation-1');
    $menu = workspaceBarAction($screen, 'conversation-actions', 'conversation-1');

    expect($bar['props']['title'])->toBe('Ada Lovelace')
        ->and($bar['props']['subtitle'])->toBe('@ada')
        ->and(array_column(array_column(findNodes($bar, fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action'), 'props'), 'id'))
        ->toBe(['call', 'conversation-actions', 'view-profile', 'pin', 'stash', 'mute'])
        ->and(array_column(array_column($menu['children'], 'props'), 'label'))->toBe(['View profile', 'Pin', 'Stash', 'Mute'])
        ->and(workspaceBar($screen)['props']['title'])->toBe('Remotes')
        ->and(workspaceBarAction($screen, 'new-remote'))->not->toBeNull();
});

it('pins, mutes and shows the profile of the thread beside the inbox from its bar', function () {
    serveRemotesWorkspace();
    $screen = finishRefreshes(finishRefreshes(Native::visit('/remotes', platform: 'ios'))->tap('remote-conv-1'));

    finishRefreshes(pressWorkspaceBar($screen, 'pin', 'conversation-1'));
    expect(sentTo('PUT', 'remotes/@ada/pin'))->toHaveCount(1)
        ->and(workspaceBarAction($screen, 'unpin', 'conversation-1'))->not->toBeNull();

    pressWorkspaceBar($screen, 'mute', 'conversation-1');
    expect($screen->instance()->foregroundPane()->choosingMute)->toBeTrue();

    pressWorkspaceBar($screen, 'view-profile', 'conversation-1')->assertNavigatedTo('/remotes/users/ada');
});

it('calls from the thread beside the inbox over the whole screen', function () {
    serveRemotesWorkspace();
    $screen = finishRefreshes(finishRefreshes(Native::visit('/remotes', platform: 'ios'))->tap('remote-conv-1'));

    pressWorkspaceBar($screen, 'call', 'conversation-1')->assertNavigatedTo('/call/ada');
});

it('closes the thread beside the inbox once you stash it from its bar', function () {
    serveRemotesWorkspace();
    $screen = finishRefreshes(finishRefreshes(Native::visit('/remotes', platform: 'ios'))->tap('remote-conv-1'));

    finishRefreshes(pressWorkspaceBar($screen, 'stash', 'conversation-1'))
        ->assertSet('conversations', [])
        ->assertNoNavigation();

    expect(sentTo('POST', 'remotes/@ada/archive'))->toHaveCount(1);
});

it('pushes a message from the thread beside the inbox, and shows it pushed once devnull has it', function () {
    serveRemotesWorkspace();
    holdRefreshes();
    $screen = finishRefreshes(finishRefreshes(Native::visit('/remotes', platform: 'ios'))->tap('remote-conv-1'));

    $screen->input('message-input', 'on it')->tap('push')->assertSee('on it')->assertSee('staged');

    finishRefreshes($screen)->assertSee('pushed')->assertDontSee('staged');

    expect($screen->instance()->foregroundPane()->pending)->toBe([])
        ->and(json_decode(sentTo('POST', 'remotes/@ada/messages')[0], true)['body'])->toBe('on it');
});

it('polls the inbox and the open thread, as they poll as screens', function () {
    serveRemotesWorkspace();
    $screen = finishRefreshes(finishRefreshes(Native::visit('/remotes', platform: 'ios'))->tap('remote-conv-1'));
    $asked = fn (string $path): int => collect(Http::recorded())->filter(fn (array $sent): bool => parse_url($sent[0]->url(), PHP_URL_PATH) === '/api/v1/'.$path && str_contains((string) parse_url($sent[0]->url(), PHP_URL_QUERY), 'after='))->count();
    $inbox = fn (): int => collect(Http::recorded())->filter(fn (array $sent): bool => parse_url($sent[0]->url(), PHP_URL_PATH) === '/api/v1/'.Client::RemotesPath)->count();
    $inboxBefore = $inbox();

    finishRefreshes($screen->firePoll('pollInbox')->firePoll('pollThread'));

    expect($inbox())->toBe($inboxBefore + 1)
        ->and($asked('remotes/@ada/messages'))->toBe(1);
});

it('opens a pushed thread beside the inbox once the tab has drawn', function (string $open) {
    serveRemotesWorkspace();

    $screen = finishRefreshes(Native::visit('/remotes', ['open' => $open], platform: 'ios'))->assertNoNavigation();

    expect($screen->get('conversations'))->toBe([['slot' => 1, 'id' => $open, 'data' => ['pushed' => true]]]);
})->with(['/conversations/ada', '/orgs/org-1']);

it('closes a pushed thread that has gone, saying why, and stays on the inbox', function () {
    serveRemotesWorkspace(threadsGone: true);

    finishRefreshes(Native::visit('/remotes', ['open' => '/conversations/ada'], platform: 'ios'))
        ->assertSet('conversations', [])
        ->assertNoNavigation()
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === "That Remote isn't there any more.");
});

it('keeps one column while you have Remotes off, with nothing to open beside the inbox', function (bool $off) {
    serveRemotesWorkspace(off: $off);
    holdRefreshes();

    $screen = finishRefreshes(Native::visit('/remotes', platform: 'ios'));

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_feed')['props']['split'])->toBe(! $off)
        ->and(rendersRef($screen, 'remotes-off'))->toBe($off);
})->with(['on' => false, 'off' => true]);

it('starts a new remote or org over the whole tab from the inbox\'s bar', function (string $action, string $uri) {
    serveRemotesWorkspace();

    pressWorkspaceBar(finishRefreshes(Native::visit('/remotes', platform: 'ios')), $action)->assertNavigatedTo($uri);
})->with([
    ['new-remote', '/remotes/new'],
    ['new-org', '/remotes/new-org'],
]);
