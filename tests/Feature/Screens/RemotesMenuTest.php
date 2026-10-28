<?php

use App\Devnull\Data\Conversation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskRunner;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo('2026-10-19 15:30:00');
});

/**
 * An org with Ada and Grace in it, called "Mobile app testers".
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orgConversationJson(array $overrides = []): array
{
    return conversationJson([
        'id' => 'org-1',
        'kind' => 'org',
        'name' => 'Mobile app testers',
        'partner' => null,
        'members' => [
            ['user' => userJson(), 'role' => 'maintainer'],
            ['user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']), 'role' => 'contributor'],
            ['user' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace Hopper']), 'role' => 'contributor'],
        ],
        ...$overrides,
    ]);
}

/**
 * The inbox with Ada's conversation and the org, then devnull taking every write: the pin, mute and stash answers
 * devnull sends, and a block or a leave.
 *
 * @param  list<array<string, mixed>>  $conversations
 */
function serveInbox(array $conversations, int $writeStatus = 200): void
{
    serveApi(fn (Request $request) => match (true) {
        $request->method() === 'GET' => Http::response(remotesInboxJson($conversations)),
        $writeStatus !== 200 => Http::response(['message' => 'Server Error'], $writeStatus),
        str_ends_with($request->url(), '/pin') => Http::response(['pinned_at' => $request['pinned'] ? '2026-10-19T15:30:05.000000Z' : null]),
        str_ends_with($request->url(), '/mute') => Http::response(['muted_until_at' => match ($request['duration']) {
            'hour' => '2026-10-19T16:30:00.000000Z',
            'forever' => '9999-12-31T00:00:00.000000Z',
            default => null,
        }]),
        str_ends_with($request->url(), '/block') => Http::response(['data' => userJson(['id' => 'u2', 'handle' => 'ada']), 'is_blocking' => true]),
        default => Http::response(null, 204),
    });
}

it('opens the web row menu on a long press, in its order and words, and still opens the thread on a tap', function () {
    serveInbox([conversationJson(), orgConversationJson()]);

    $screen = finishRefreshes(Native::visit('/remotes', platform: 'ios'));

    expect(nodeByRef($screen, 'remote-conv-1')['props']['has_context_menu'] ?? null)->toBeTrue()
        ->and(menuItems($screen, 'remote-conv-1'))->toBe([
            ['view-profile-conv-1', 'View profile', 'custom:user-round'],
            ['pin-conv-1', 'Pin', 'custom:pin'],
            ['stash-conv-1', 'Stash', 'custom:archive'],
            ['mute-conv-1', 'Mute', 'custom:bell-off'],
            'divider',
            ['block-conv-1', 'Block @ada', 'custom:ban'],
        ])
        ->and(menuItems($screen, 'remote-org-1'))->toBe([
            ['pin-org-1', 'Pin', 'custom:pin'],
            ['stash-org-1', 'Stash', 'custom:archive'],
            ['mute-org-1', 'Mute', 'custom:bell-off'],
            'divider',
            ['leave-org-1', 'Leave org', 'custom:log-out'],
        ])
        ->and(nodeByRef($screen, 'block-conv-1')['props']['destructive'] ?? null)->toBeTrue()
        ->and(nodeByRef($screen, 'leave-org-1')['props']['destructive'] ?? null)->toBeTrue();

    $screen->tap('remote-conv-1')->assertNavigatedTo('/remotes/conversations/ada');
});

it('offers Unpin, Pop and Unmute on a row that is pinned, stashed and muted', function () {
    serveInbox([conversationJson(['is_archived' => true, 'pinned_at' => '2026-10-19T12:00:00.000000Z', 'muted_until_at' => '2026-10-19T16:00:00.000000Z'])]);

    $screen = finishRefreshes(Native::visit('/remotes', platform: 'ios'));

    expect(array_column(array_filter(menuItems($screen, 'remote-conv-1'), is_array(...)), 1))->toBe(['View profile', 'Unpin', 'Pop', 'Unmute', 'Block @ada'])
        ->and(nodeByRef($screen, 'unpin-conv-1')['props']['icon'] ?? null)->toBe('custom:pin-off')
        ->and(nodeByRef($screen, 'pop-conv-1')['props']['icon'] ?? null)->toBe('custom:archive-restore')
        ->and(nodeByRef($screen, 'unmute-conv-1')['props']['icon'] ?? null)->toBe('custom:bell');
});

it('marks pinned and muted rows, and treats a mute that has run out as no mute', function () {
    serveInbox([
        conversationJson(['pinned_at' => '2026-10-19T12:00:00.000000Z', 'muted_until_at' => '9999-12-31T00:00:00.000000Z']),
        orgConversationJson(['muted_until_at' => '2026-10-19T15:00:00.000000Z']),
    ]);

    $screen = finishRefreshes(Native::visit('/remotes'));

    expect(rendersRef($screen, 'pinned-conv-1'))->toBeTrue()
        ->and(rendersRef($screen, 'muted-conv-1'))->toBeTrue()
        ->and(nodeByRef($screen, 'remote-conv-1')['props']['a11y_label'])->toStartWith('Ada Lovelace, pinned, muted.')
        ->and(rendersRef($screen, 'muted-org-1'))->toBeFalse()
        ->and(rendersRef($screen, 'pinned-org-1'))->toBeFalse()
        ->and(array_column(array_filter(menuItems($screen, 'remote-org-1'), is_array(...)), 1))->toContain('Mute');
});

it('pins a row at once, moving it to the top as devnull sorts, and keeps devnull\'s answer', function () {
    holdRefreshes();
    serveInbox([conversationJson(), orgConversationJson(['last_message_at' => '2026-10-19T14:00:00.000000Z'])]);

    $screen = finishRefreshes(Native::visit('/remotes'));

    expect(childRefs($screen, 'remotes'))->toBe(['remote-conv-1', 'remote-org-1']);

    $screen->call('pin', 'org-1');

    expect(childRefs($screen, 'remotes'))->toBe(['remote-org-1', 'remote-conv-1'])
        ->and(rendersRef($screen, 'pinned-org-1'))->toBeTrue()
        ->and(kept('remotes')['data'][0]['id'])->toBe('org-1');

    finishRefreshes($screen);

    expect(sentTo('PUT', 'remotes/orgs/org-1/pin'))->toBe(['{"pinned":true}'])
        ->and(kept('remotes')['data'][0]['pinned_at'])->toBe('2026-10-19T15:30:05.000000Z');

    $screen->call('unpin', 'org-1');
    finishRefreshes($screen);

    expect(sentTo('PUT', 'remotes/orgs/org-1/pin'))->toHaveCount(2)
        ->and(childRefs($screen, 'remotes'))->toBe(['remote-conv-1', 'remote-org-1'])
        ->and(rendersRef($screen, 'pinned-org-1'))->toBeFalse();
});

it('keeps a pin devnull has not answered for yet over a poll that answers first', function () {
    holdRefreshes();
    serveInbox([conversationJson(), orgConversationJson(['last_message_at' => '2026-10-19T14:00:00.000000Z'])]);

    $screen = finishRefreshes(Native::visit('/remotes'))->call('pin', 'org-1')->call('pollInbox');

    // The poll answers before the pin's write: run only the last task sent, the poll.
    $poll = collect($screen->bridge()->callsTo('AsyncTask.Dispatch'))->last()['params']['id'];
    $screen->emitNative(AsyncTaskFinished::class, [
        'id' => $poll,
        'result' => AsyncTaskRunner::normalizeResult(AsyncTaskRunner::invoke(unserialize(AsyncTaskTransport::readPayload($poll)))),
    ]);

    expect(childRefs($screen, 'remotes'))->toBe(['remote-org-1', 'remote-conv-1'])
        ->and(rendersRef($screen, 'pinned-org-1'))->toBeTrue();
});

it('puts a pin back and says so when devnull turns it down', function () {
    holdRefreshes();
    serveInbox([conversationJson()]);

    $screen = finishRefreshes(Native::visit('/remotes'));
    serveInbox([conversationJson()], writeStatus: 500);

    finishRefreshes($screen->call('pin', 'conv-1'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Could not update the pin. Try again.');

    expect(rendersRef($screen, 'pinned-conv-1'))->toBeFalse()
        ->and(kept('remotes')['data'][0]['pinned_at'] ?? null)->toBeNull();
});

it('asks how long to mute for, in the web\'s words, and mutes for that long', function () {
    holdRefreshes();
    serveInbox([conversationJson()]);

    $screen = finishRefreshes(Native::visit('/remotes'))->call('chooseMute', 'conv-1')
        ->assertSee('Mute @ada')
        ->assertSee('Silence browser notifications and sound on all your devices. Messages and unread counts stay visible.')
        ->assertSee('For one hour')
        ->assertSee('Until tomorrow')
        ->assertSee('For one week')
        ->assertSee('Forever');

    $screen->tap('mute-hour');

    expect($screen->get('muting'))->toBeNull()
        ->and(rendersRef($screen, 'muted-conv-1'))->toBeTrue();

    finishRefreshes($screen)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Muted @ada.');

    expect(sentTo('PUT', 'remotes/@ada/mute'))->toBe(['{"duration":"hour"}'])
        ->and(kept('remotes')['data'][0]['muted_until_at'])->toBe('2026-10-19T16:30:00.000000Z');
});

it('unmutes straight away from the menu', function () {
    holdRefreshes();
    serveInbox([orgConversationJson(['muted_until_at' => '9999-12-31T00:00:00.000000Z'])]);

    $screen = finishRefreshes(Native::visit('/remotes'))->call('unmute', 'org-1');

    expect(rendersRef($screen, 'muted-org-1'))->toBeFalse();

    finishRefreshes($screen)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Unmuted Mobile app testers.');

    expect(sentTo('PUT', 'remotes/orgs/org-1/mute'))->toBe(['{"duration":"off"}']);
});

it('puts a mute back and says so when devnull turns it down', function () {
    holdRefreshes();
    serveInbox([conversationJson()]);

    $screen = finishRefreshes(Native::visit('/remotes'));
    serveInbox([conversationJson()], writeStatus: 500);

    finishRefreshes($screen->call('chooseMute', 'conv-1')->call('muteFor', 'forever'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Could not update the mute. Try again.');

    expect(rendersRef($screen, 'muted-conv-1'))->toBeFalse();
});

it('stashes a row out of Open, and puts it back if devnull says no', function () {
    holdRefreshes();
    serveInbox([conversationJson(), orgConversationJson()]);

    $screen = finishRefreshes(Native::visit('/remotes'))->call('stash', 'conv-1');

    expect(childRefs($screen, 'remotes'))->toBe(['remote-org-1']);

    finishRefreshes($screen)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Stashed @ada. It pops back when someone writes.');

    expect(sentTo('POST', 'remotes/@ada/archive'))->toHaveCount(1)
        ->and(array_column(kept('remotes')['data'], 'id'))->toBe(['org-1']);

    serveInbox([orgConversationJson()], writeStatus: 500);

    finishRefreshes($screen->call('stash', 'org-1'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Could not update the conversation. Try again.');

    expect(childRefs($screen, 'remotes'))->toBe(['remote-org-1']);
});

it('blocks the partner once you confirm, in the web\'s words, and the row goes', function () {
    holdRefreshes();
    serveInbox([conversationJson(), orgConversationJson()]);

    $screen = finishRefreshes(Native::visit('/remotes'))->call('confirmBlock', 'conv-1')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Block @ada?'
            && str_starts_with($params['message'], 'This conversation goes away for both of you'))
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'remotes-block:conv-1']);

    expect(childRefs($screen, 'remotes'))->toBe(['remote-conv-1', 'remote-org-1']);

    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Block', 'id' => 'remotes-block:conv-1']);

    expect(childRefs($screen, 'remotes'))->toBe(['remote-org-1']);

    finishRefreshes($screen)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => str_starts_with($params['message'], 'Blocked @ada.'));

    expect(sentTo('POST', 'users/ada/block'))->toHaveCount(1)
        ->and(array_column(kept('remotes')['data'], 'id'))->toBe(['org-1']);
});

it('leaves an org once you confirm, in the web\'s words, and the row goes', function () {
    holdRefreshes();
    serveInbox([conversationJson(), orgConversationJson()]);

    $screen = finishRefreshes(Native::visit('/remotes'))->call('confirmLeave', 'org-1')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Leave Mobile app testers?'
            && str_starts_with($params['message'], 'You stop seeing the org and its history.'))
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Leave', 'id' => 'remotes-leave:org-1']);

    expect(childRefs($screen, 'remotes'))->toBe(['remote-conv-1']);

    finishRefreshes($screen);

    expect(sentTo('DELETE', 'remotes/orgs/org-1/members/local_dev'))->toHaveCount(1)
        ->and(array_column(kept('remotes')['data'], 'id'))->toBe(['conv-1']);
});

it('brings a row back and says so when devnull turns a leave down', function () {
    holdRefreshes();
    $org = orgConversationJson(['last_message_at' => '2026-10-19T14:00:00.000000Z']);
    serveInbox([conversationJson(), $org]);

    $screen = finishRefreshes(Native::visit('/remotes'));
    serveInbox([conversationJson(), $org], writeStatus: 500);

    finishRefreshes($screen->call('leave', 'org-1'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Could not leave Mobile app testers. Try again.');

    expect(childRefs($screen, 'remotes'))->toBe(['remote-conv-1', 'remote-org-1']);
});

it('opens the partner\'s profile from the menu', function () {
    serveInbox([conversationJson()]);

    finishRefreshes(Native::visit('/remotes'))->call('viewProfile', 'conv-1')->assertNavigatedTo('/remotes/users/ada');
});

it('reads pin and mute from the inbox payload', function () {
    $conversation = Conversation::fromArray(conversationJson(['pinned_at' => '2026-10-19T12:00:00.000000Z', 'muted_until_at' => '2026-10-19T16:00:00.000000Z']));

    expect($conversation->isPinned())->toBeTrue()
        ->and($conversation->isMuted())->toBeTrue()
        ->and(Conversation::fromArray(conversationJson())->isPinned())->toBeFalse()
        ->and(Conversation::fromArray(conversationJson())->isMuted())->toBeFalse();
});

/**
 * The labels of the thread's top-bar menu.
 *
 * @return list<string>
 */
function threadMenu(TestableComponent $screen): array
{
    $menu = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action' && ($node['props']['id'] ?? null) === 'conversation-actions');

    return array_map(fn (array $item): string => $item['props']['label'] ?? '', $menu['children'] ?? []);
}

/**
 * Ada's thread, then devnull taking a pin or a mute, or turning every write down.
 *
 * @param  array<string, mixed>  $thread
 */
function serveAdaThread(array $thread = [], int $writeStatus = 200): void
{
    serveApi(fn (Request $request) => match (true) {
        str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/remotes/@ada/messages') => Http::response(threadJson([remoteMessageJson()], ['read_through_id' => 1, ...$thread])),
        $request->method() === 'GET' => Http::response(['usage' => []]),
        $writeStatus !== 200 => Http::response(['message' => 'Server Error'], $writeStatus),
        str_ends_with($request->url(), '/pin') => Http::response(['pinned_at' => $request['pinned'] ? '2026-10-19T15:30:05.000000Z' : null]),
        str_ends_with($request->url(), '/mute') => Http::response(['muted_until_at' => $request['duration'] === 'off' ? null : '2026-10-26T15:30:00.000000Z']),
        default => Http::response(null, 204),
    });
}

it('offers Pin and Mute in the thread menu too, in the web\'s order', function () {
    serveAdaThread();

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    expect(threadMenu($screen))->toBe(['View profile', 'Pin', 'Stash', 'Mute']);

    serveAdaThread(['pinned_at' => '2026-10-19T12:00:00.000000Z', 'muted_until_at' => '9999-12-31T00:00:00.000000Z']);

    expect(threadMenu(finishRefreshes(Native::visit('/remotes/conversations/ada'))))->toBe(['View profile', 'Unpin', 'Stash', 'Unmute']);
});

it('pins and mutes the thread, and the inbox kept for going back shows it', function () {
    keep('remotes', remotesInboxJson([conversationJson()]));
    serveAdaThread();

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada', data: ['conversation' => Conversation::fromArray(conversationJson())]));

    finishRefreshes($screen->call('pin'));

    expect(threadMenu($screen))->toContain('Unpin')
        ->and(sentTo('PUT', 'remotes/@ada/pin'))->toBe(['{"pinned":true}'])
        ->and(kept('remotes')['data'][0]['pinned_at'])->toBe('2026-10-19T15:30:05.000000Z');

    $screen->call('chooseMute')->assertSee('Mute @ada')->tap('mute-week');

    finishRefreshes($screen)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Muted @ada.');

    expect(threadMenu($screen))->toContain('Unmute')
        ->and(sentTo('PUT', 'remotes/@ada/mute'))->toBe(['{"duration":"week"}'])
        ->and(kept('remotes')['data'][0]['muted_until_at'])->toBe('2026-10-26T15:30:00.000000Z');
});

it('puts the thread\'s pin back and says so when devnull turns it down', function () {
    serveAdaThread();

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));
    serveAdaThread(writeStatus: 500);

    finishRefreshes($screen->call('pin'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Could not update the pin. Try again.');

    expect(threadMenu($screen))->toContain('Pin');
});
