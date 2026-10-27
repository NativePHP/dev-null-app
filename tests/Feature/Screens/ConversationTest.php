<?php

use App\Devnull\Account;
use App\Devnull\Data\Remotes;
use App\NativeComponents\Conversation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

const RemotesViewerId = '0199e5c4-0000-7000-8000-000000000001';

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo('2026-10-19 15:30:00');
});

/**
 * Devnull answering a thread with Ada: its first page, then whatever a poll asks for, and every write.
 *
 * @param  array<string, mixed>  $thread
 * @param  array<string, mixed>|null  $poll
 */
function serveRemoteThread(array $thread, ?array $poll = null, int $pushStatus = 201): void
{
    serveApi(function (Request $request) use ($thread, $poll, $pushStatus) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);

        return match (true) {
            $request->method() === 'GET' && $path === '/api/v1/remotes/@ada/messages' => Http::response(str_contains($query, 'after=') ? ($poll ?? threadJson([])) : $thread),
            $request->method() === 'POST' && $path === '/api/v1/remotes/@ada/messages' => $pushStatus >= 300
                ? Http::response(['message' => 'Nope.'], $pushStatus)
                : Http::response(['data' => remoteMessageJson([
                    'id' => 99,
                    'user_id' => RemotesViewerId,
                    'client_id' => json_decode($request->body(), true)['client_id'],
                    'body' => json_decode($request->body(), true)['body'],
                ])], $pushStatus),
            $path === '/api/v1/me' => Http::response(currentUserJson()),
            str_ends_with($path, '/reactions') && $request->method() === 'POST' => Http::response(['reactions' => [['user_id' => RemotesViewerId, 'emoji' => json_decode($request->body(), true)['emoji']]]]),
            str_ends_with($path, '/reactions') && $request->method() === 'DELETE' => Http::response(['reactions' => []]),
            default => Http::response(['usage' => []], $request->method() === 'GET' ? 200 : 204),
        };
    });
}

it('shows the thread with your messages on the right and theirs on the left, titled by who it is with', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?', 'created_at' => '2026-10-18T09:00:00Z']),
        remoteMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'merged it', 'created_at' => '2026-10-19T14:00:00Z']),
    ], ['read_through_id' => 2, 'partner_read_through_id' => 2, 'partner_read_at' => '2026-10-19T14:05:00Z']));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->assertNavTitle('Ada Lovelace')
        ->assertSee('did you see the PR?')
        ->assertSee('merged it')
        ->assertSee('Yesterday')
        ->assertSee('Today')
        ->assertSee('pulled 14:05');

    // Theirs at the start of the row with the slot for React and Reply right after the bubble, yours at the end with
    // the slot right before it, in a thread that opens on its latest message.
    expect(nodeByRef($screen, 'content-1')['layout']['justify_content'])->toBe(0)
        ->and(childRefs($screen, 'content-1'))->toBe(['bubble-1', 'actions-1'])
        ->and(nodeByRef($screen, 'content-2')['layout']['justify_content'])->toBe(2)
        ->and(childRefs($screen, 'content-2'))->toBe(['actions-2', 'bubble-2'])
        ->and(nodeByRef($screen, 'thread')['props']['scroll_anchor'] ?? null)->toBe('bottom');
});

it('draws quotes in messages behind a rule, amber in theirs and in the label colour in yours', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => "> did you see the PR?\nnot yet"]),
        remoteMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => "> not yet\nlooking now"]),
    ]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->assertSee('did you see the PR?')
        ->assertDontSee('> did you see the PR?');

    $rule = fn (string $ref): ?string => findNode(nodeByRef($screen, $ref), fn (array $node): bool => (float) ($node['props']['border_l'] ?? 0) === 2.0)['style']['border_color'] ?? null;

    expect(strtolower((string) $rule('bubble-1')))->toBe(strtolower((string) theme('lane-5')))
        ->and(strtolower((string) $rule('bubble-2')))->not->toBe(strtolower((string) theme('lane-5')))
        ->and($rule('bubble-2'))->not->toBeNull();
});

it('marks HEAD where you left off, and pulls what is showing', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'first']),
        remoteMessageJson(['id' => 2, 'body' => 'second']),
        remoteMessageJson(['id' => 3, 'body' => 'third']),
    ], ['read_through_id' => 1]));

    finishRefreshes(Native::visit('/remotes/conversations/ada'))->assertSee('HEAD · 2 new');

    expect(sentTo('PUT', 'remotes/@ada/read'))->toBe(['{"through":3}']);
});

it('polls for what came after the last message, and for reactions changed since', function () {
    serveRemoteThread(
        threadJson([remoteMessageJson(['id' => 1, 'body' => 'first'])], ['read_through_id' => 1, 'reactions_version' => 4]),
        threadJson([remoteMessageJson(['id' => 2, 'body' => 'second'])], [
            'reactions_version' => 5,
            'reaction_changes' => [['id' => 1, 'reactions' => [['user_id' => 'u2', 'emoji' => '🚀']]]],
            'partner_typing' => true,
        ]),
    );

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    finishRefreshes($screen->call('poll'))
        ->assertSee('second')
        ->assertSee('🚀')
        ->assertSee('@ada is typing…');

    // Under the title, so the composer never changes height for it.
    expect(findNode($screen->tree(), fn (array $node): bool => ($node['props']['nav_subtitle'] ?? null) === '@ada is typing…'))->not->toBeNull();

    $polls = collect(Http::recorded())->map(fn (array $sent): string => (string) parse_url($sent[0]->url(), PHP_URL_QUERY))->filter(fn (string $query): bool => str_contains($query, 'after'));

    expect($polls->first())->toBe('after=1&reactions_after=4')
        ->and(sentTo('PUT', 'remotes/@ada/read'))->toBe(['{"through":2}']);
});

it('keeps the composer the same view while messages arrive above it, so the keyboard stays up', function () {
    serveRemoteThread(
        threadJson([remoteMessageJson(['id' => 1, 'body' => 'first'])], ['read_through_id' => 1]),
        threadJson([remoteMessageJson(['id' => 2, 'body' => 'second'])]),
    );

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->input('message-input', 'on my way');
    $field = idsDownTo($screen->tree(), 'message-input');

    finishRefreshes($screen->call('poll'))->assertSee('second');

    expect(idsDownTo($screen->tree(), 'message-input'))->toBe($field);
});

it('waits longer between polls once nobody has written or typed for a minute', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));
    $polls = fn (): int => collect(Http::recorded())->filter(fn (array $sent): bool => str_contains($sent[0]->url(), 'after='))->count();

    finishRefreshes($screen->call('poll'));
    finishRefreshes($screen->call('poll'));

    expect($polls())->toBe(1);
});

it('pushes a message staged at once, and pushed once devnull has it', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?'])], ['read_through_id' => 1]));
    holdRefreshes();

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->set('draft', 'on it')
        ->tap('push')
        ->assertSee('on it')
        ->assertSee('staged')
        ->assertSet('draft', '');

    finishRefreshes($screen)->assertSee('pushed')->assertSet('pending', []);

    $sent = json_decode(sentTo('POST', 'remotes/@ada/messages')[0], true);

    expect($sent['body'])->toBe('on it')
        ->and($sent['client_id'])->toMatch('/^[0-9a-f-]{36}$/');
});

it('still brings a message that landed just before the one you pushed', function () {
    serveRemoteThread(
        threadJson([remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?'])], ['read_through_id' => 1]),
        threadJson([
            remoteMessageJson(['id' => 2, 'body' => 'it broke main']),
            remoteMessageJson(['id' => 99, 'user_id' => RemotesViewerId, 'client_id' => '9f0f6f3e-0000-4000-8000-000000000099', 'body' => 'on it']),
        ]),
    );

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada')->set('draft', 'on it')->call('send'));

    finishRefreshes($screen->call('poll'))
        ->assertSee('it broke main')
        ->assertSee('on it');

    $polls = collect(Http::recorded())->map(fn (array $sent): string => (string) parse_url($sent[0]->url(), PHP_URL_QUERY))->filter(fn (string $query): bool => str_contains($query, 'after'));

    expect($polls->values()->all())->toBe(['after=1&reactions_after=0'])
        ->and(array_map(fn ($message): int => $message->id, $screen->instance()->messages))->toBe([1, 2, 99]);
});

it('says a message devnull refused was rejected, and retries it under the same client id', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]), pushStatus: 403);

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->set('draft', 'hello')->call('send');
    finishRefreshes($screen)->assertSee('rejected ·')->assertSee('retry');

    $clientId = $screen->instance()->pending[0]->clientId;

    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));
    finishRefreshes($screen->call('retry', $clientId))->assertSet('pending', []);

    expect(array_map(fn (string $body): string => json_decode($body, true)['client_id'], sentTo('POST', 'remotes/@ada/messages')))->toBe([$clientId, $clientId]);
});

it('shows React and Reply beside a tapped message, as the web shows them on hover', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?']),
        remoteMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'which one?']),
    ], ['read_through_id' => 2]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));
    $buttons = fn (int $id): array => nodeByRef($screen, "actions-{$id}-buttons");

    // Reply sits nearest the bubble: after theirs, before yours. Until a tap they're faded out, tucked toward it.
    expect(childRefs($screen, 'actions-1-buttons'))->toBe(['reply-1', 'react-1'])
        ->and(childRefs($screen, 'actions-2-buttons'))->toBe(['react-2', 'reply-2'])
        ->and($buttons(1)['style']['opacity'])->toEqual(0)
        ->and($buttons(1)['props'])->toMatchArray(['translate-x' => -8.0, 'animate-duration' => 180.0])
        ->and($buttons(2)['props']['translate-x'])->toEqual(8);

    // A tap fades and slides them into place.
    $screen->tap('bubble-1');
    expect($buttons(1)['style']['opacity'])->toEqual(1)
        ->and($buttons(1)['props']['translate-x'])->toEqual(0);

    $screen->tap('bubble-2');
    expect($buttons(1)['style']['opacity'])->toEqual(0)
        ->and($buttons(2)['style']['opacity'])->toEqual(1);

    // A second tap puts them away.
    $screen->tap('bubble-2');
    expect($buttons(2)['style']['opacity'])->toEqual(0);
});

it('opens the reactions from React, without Reply in them', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->tap('bubble-1')
        ->tap('react-1')
        ->assertSet('sheetOpen', true)
        ->assertSet('actionsForId', null);

    expect(childRefs($screen, 'quick-reactions'))->not->toBeEmpty()
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'reply'))->toBeNull();
});

it('replies to a message from its Reply button', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?'])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->tap('bubble-1')
        ->tap('reply-1')
        ->assertSee('Replying to Ada Lovelace')
        ->assertSet('actionsForId', null)
        ->set('draft', 'yes')
        ->call('send');

    finishRefreshes($screen);

    expect(json_decode(sentTo('POST', 'remotes/@ada/messages')[0], true)['reply_to_id'])->toBe(1);
});

it('replies to a message swiped away from its edge, as the web does on a touch screen, and raises the keyboard', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?']),
        remoteMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'which one?']),
    ], ['read_through_id' => 2]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    expect(nodeByRef($screen, 'message-input')['props'])->not->toHaveKey('autofocus');

    // Theirs goes right; a swipe the other way, or a flick while scrolling, does nothing.
    $screen->swipe('swipe-1', 'left')->swipe('swipe-1', 'up')->assertSet('replyToId', null);
    $screen->swipe('swipe-1', 'right')->assertSet('replyToId', 1)->assertSee('Replying to Ada Lovelace');

    expect(nodeByRef($screen, 'message-input')['props']['autofocus'] ?? null)->toBeTrue();

    // Yours goes left.
    $screen->swipe('swipe-2', 'right')->assertSet('replyToId', 1);
    $screen->swipe('swipe-2', 'left')->assertSet('replyToId', 2)->assertSee('Replying to yourself');
});

it('takes no swipe to reply while the thread cannot take a message', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1, 'access' => 'request']));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'swipe-1'))->toBeNull();
});

it('tells the others you are typing at most every three seconds', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    finishRefreshes($screen->set('draft', 'o'));
    finishRefreshes($screen->set('draft', 'on'));

    expect(sentTo('PUT', 'remotes/@ada/typing'))->toHaveCount(1);

    $this->travel(4)->seconds();
    finishRefreshes($screen->set('draft', 'on it'));

    expect(sentTo('PUT', 'remotes/@ada/typing'))->toHaveCount(2);
});

it('reacts at once, and takes it back if devnull says no', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->call('heart', 1)->assertSee('❤️');

    expect(sentTo('POST', 'remotes/messages/1/reactions'))->toBe(['{"emoji":"❤️"}']);

    serveApi(fn () => Http::response(['message' => 'Nope.'], 403));
    finishRefreshes($screen->call('toggleReaction', 1, '🚀'));

    expect($screen->instance()->messages[0]->reactions)->toBe([['user_id' => RemotesViewerId, 'emoji' => '❤️']]);
});

it("says why a message can't go, in the web's words, and merges or drops a request", function (string $access, string $words) {
    app(Account::class)->rememberRemotes(new Remotes(requests: true));
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1, 'access' => $access]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->assertSee($words);

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'message-input'))->toBeNull();

    if ($access === 'request') {
        finishRefreshes($screen->tap('merge'));

        expect(sentTo('POST', 'remotes/@ada/merge'))->toHaveCount(1)
            ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'message-input'))->not->toBeNull();
    }
})->with([
    'request' => ['request', "@ada wants to push to you. Merge to reply, or drop it. They won't be told."],
    'waiting' => ['waiting', 'pushed. waiting for @ada to merge your request'],
    'restricted' => ['restricted', "@ada doesn't follow you, so you can't push to them yet"],
    'closed' => ['closed', "@ada isn't taking remotes"],
]);

it('drops a request and goes back to the inbox', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1, 'access' => 'request']));

    finishRefreshes(finishRefreshes(Native::visit('/remotes/conversations/ada'))->tap('drop'))->assertWentBack();

    expect(sentTo('POST', 'remotes/@ada/drop'))->toHaveCount(1);
});

it('says hi to an empty thread', function () {
    serveRemoteThread(threadJson([]));

    finishRefreshes(Native::visit('/remotes/conversations/ada'))->assertSee('empty history. say hi to @ada');
});

it('loads the messages before the first one showing', function () {
    serveApi(function (Request $request) {
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);

        return Http::response(match (true) {
            str_contains($query, 'before=5') => threadJson([remoteMessageJson(['id' => 4, 'body' => 'older'])]),
            str_contains($request->url(), '/messages') => threadJson([remoteMessageJson(['id' => 5, 'body' => 'newer'])], ['has_older' => true, 'read_through_id' => 5]),
            default => currentUserJson(),
        });
    });

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->tap('load-older')->assertSee('older');

    expect(array_map(fn ($message): int => $message->id, $screen->instance()->messages))->toBe([4, 5])
        ->and($screen->get('hasOlder'))->toBeFalse();
});

it('shows an org with who wrote what, its events, and who is typing', function () {
    serveApi(fn (Request $request) => Http::response(str_contains($request->url(), 'orgs/org-1/messages') ? ['data' => [
        'org' => ['id' => 'org-1', 'name' => 'dev/null core'],
        'messages' => [
            remoteMessageJson(['id' => 1, 'user_id' => RemotesViewerId, 'body' => 'dev/null core', 'event' => 'created', 'user' => userJson()]),
            remoteMessageJson(['id' => 2, 'body' => 'hello org', 'user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace'])]),
        ],
        'has_older' => false,
        'reactions_version' => 0,
        'reaction_changes' => [],
        'read_through_id' => 2,
        'members' => [
            ['user' => userJson(), 'role' => 'maintainer', 'read_through_id' => 2],
            ['user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']), 'role' => 'contributor', 'read_through_id' => 2],
        ],
        'typing' => ['ada'],
        'is_archived' => false,
        'role' => 'maintainer',
        'access' => 'open',
    ]] : currentUserJson()));

    finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->assertNavTitle('dev/null core')
        ->assertSee('* you created dev/null core')
        ->assertSee('Ada Lovelace')
        ->assertSee('hello org')
        ->assertSee('@ada is typing…');
});

it("opens an org member's profile from the name above their messages", function () {
    serveApi(fn (Request $request) => Http::response(str_contains($request->url(), 'orgs/org-1/messages') ? ['data' => [
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
    ]] : currentUserJson()));

    finishRefreshes(Native::visit('/remotes/orgs/org-1'))->tap('author-2')->assertNavigatedTo('/remotes/users/ada');
});

it('stashes the conversation from its menu and goes back', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    finishRefreshes(finishRefreshes(Native::visit('/remotes/conversations/ada'))->call('stash'))->assertWentBack();

    expect(sentTo('POST', 'remotes/@ada/archive'))->toHaveCount(1);
});

it('leaves HEAD out when nothing above it was read, as the web does', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'first']),
        remoteMessageJson(['id' => 2, 'body' => 'second']),
    ]));

    finishRefreshes(Native::visit('/remotes/conversations/ada'))->assertSee('first')->assertDontSee('HEAD');
});

it('opens the partner profile from the menu, in the tab it is in', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    finishRefreshes(Native::visit('/remotes/conversations/ada'))->call('viewProfile')->assertNavigatedTo('/remotes/users/ada');
});

it('opens a link preview of a page on dev/null here, in the tab it is in, and any other in the browser', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'https://nativephp.com', 'link_preview' => ['url' => 'https://nativephp.com', 'title' => 'NativePHP']]),
        remoteMessageJson(['id' => 2, 'body' => 'https://dev-null.sh/@grace', 'link_preview' => ['url' => 'https://dev-null.sh/@grace', 'title' => 'Grace on dev/null']]),
    ], ['read_through_id' => 2]));

    finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->tap('link-1')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://nativephp.com')
        ->assertNoNavigation()
        ->tap('link-2')
        ->assertNavigatedTo('/remotes/users/grace')
        ->assertNativeCalledTimes('Browser.OpenInApp', 1);
});

it('opens no link preview that is not a web page', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'Tap me', 'link_preview' => ['url' => 'javascript:alert(1)', 'title' => 'Tap me']]),
    ], ['read_through_id' => 1]));

    finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->tap('link-1')
        ->assertNoNavigation()
        ->assertNativeNotCalled('Browser.OpenInApp');
});

it('pads the end of the thread for the composer bar alone, and for the reply bar while replying', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));
    $clearance = fn (): mixed => nodeByRef($screen, 'composer-clearance')['layout']['height'] ?? null;

    // The scroll view adds the home indicator itself while the keyboard is down, so it isn't counted here.
    expect($clearance())->toEqual(Conversation::ComposerBarHeight + 8);

    $screen->tap('bubble-1')->tap('reply-1');

    expect($clearance())->toEqual(Conversation::ComposerBarHeight + Conversation::ReplyingBarHeight + 8);
});
