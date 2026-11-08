<?php

use App\Devnull\Account;
use App\Devnull\Data\Remotes;
use App\NativeComponents\Conversation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

const RemotesViewerId = '0199e5c4-0000-7000-8000-000000000001';

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    $this->bridge = signIn();
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

    // Theirs at the start of the row and yours at the end, each on its own, in a thread that opens on its latest message.
    expect(nodeByRef($screen, 'content-1')['layout']['justify_content'])->toBe(0)
        ->and(childRefs($screen, 'content-1'))->toBe(['bubble-1'])
        ->and(nodeByRef($screen, 'content-2')['layout']['justify_content'])->toBe(2)
        ->and(childRefs($screen, 'content-2'))->toBe(['bubble-2'])
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

it('pulls theirs left and yours right, toward their edges, with Reply coming in from the other side', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?']),
        remoteMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'which one?']),
    ], ['read_through_id' => 2]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));
    $pan = fn (string $key): string => '__sv:'.nodeByRef($screen, "swipe-{$key}")['props']['pan-x-id'];
    $pull = Conversation::ReplyPull;

    // The area pulls the message one way only, triggers at ReplyPull and springs it back on its own, so the message
    // rests at home and follows the same value.
    expect(nodeByRef($screen, 'swipe-1')['props'])->toMatchArray(['pan-x-initial' => 0.0, 'pan-x-trigger' => -(float) $pull])
        ->and(nodeByRef($screen, 'swipe-1')['props'])->not->toHaveKey('pan-x-snap')
        ->and(nodeByRef($screen, 'swipe-2')['props']['pan-x-trigger'])->toEqual($pull)
        ->and(nodeByRef($screen, 'pulled-1')['props']['translate-x_sv'])->toBe($pan('1'))
        ->and(nodeByRef($screen, 'pulled-2')['props']['translate-x_sv'])->toBe($pan('2'));

    // Reply waits just past the far side of the row, off the bubbles, and follows the finger in, growing, turning
    // upright and fading in, all the way by ReplyPull. Insets go top, right, bottom, left.
    expect(nodeByRef($screen, 'swipe-reply-1')['layout'])->toMatchArray(['width' => $pull, 'position' => [-0.0, -$pull, -0.0, 0.0]])
        ->and(nodeByRef($screen, 'swipe-reply-2')['layout']['position'])->toEqual([-0.0, 0.0, -0.0, -$pull])
        ->and(nodeByRef($screen, 'swipe-reply-1')['props'])->toMatchArray([
            'translate-x_sv' => $pan('1'),
            'opacity_sv' => $pan('1')."|interp:-{$pull},0:1,0",
            'scale_sv' => $pan('1')."|interp:-{$pull},0:1,0.4",
            'rotate_sv' => $pan('1')."|interp:-{$pull},0:0,90",
        ])
        ->and(nodeByRef($screen, 'swipe-reply-2')['props'])->toMatchArray([
            'translate-x_sv' => $pan('2'),
            'opacity_sv' => $pan('2')."|interp:0,{$pull}:0,1",
            'rotate_sv' => $pan('2')."|interp:0,{$pull}:-90,0",
        ]);
});

it('stops a short message partway, so some of it stays on the screen while the pull goes on to reply', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'ok']),
        remoteMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'k']),
    ], ['read_through_id' => 2]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));
    $pan = fn (string $key): string => '__sv:'.nodeByRef($screen, "swipe-{$key}")['props']['pan-x-id'];

    expect(nodeByRef($screen, 'pulled-1')['props']['translate-x_sv'])->toBe($pan('1').'|clamp:-19,0')
        ->and(nodeByRef($screen, 'pulled-2')['props']['translate-x_sv'])->toBe($pan('2').'|clamp:0,16')
        ->and(nodeByRef($screen, 'swipe-1')['props']['pan-x-trigger'])->toEqual(-Conversation::ReplyPull);
});

it('replies to a message pulled far enough and let go, and raises the keyboard', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?']),
        remoteMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'which one?']),
    ], ['read_through_id' => 2]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    expect(nodeByRef($screen, 'message-input')['props'])->not->toHaveKey('autofocus');

    $screen->trigger('swipe-1')
        ->assertSee('Replying to Ada Lovelace')
        ->assertSet('replyToId', 1);

    expect(nodeByRef($screen, 'message-input')['props']['autofocus'] ?? null)->toBeTrue();

    $screen->trigger('swipe-2')->assertSee('Replying to yourself');
    $screen->trigger('swipe-1')->set('draft', 'yes')->call('send');

    finishRefreshes($screen);

    expect(json_decode(sentTo('POST', 'remotes/@ada/messages')[0], true)['reply_to_id'])->toBe(1);
});

it("shows the web's quick reactions and More from React in a message's menu, pointing at the end of its bubble", function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1]),
        remoteMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'which one?']),
    ], ['read_through_id' => 2]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->tap('react-menu-1')
        ->assertSet('reactionsFor', 1)
        ->assertSet('emojiPickerFor', null)
        ->assertSet('sheetOpen', false);

    $bubble = nodeByRef($screen, 'reactions-bubble');

    expect($bubble['type'])->toBe('popover')
        ->and($bubble['props']['visible'])->toBeTrue()
        ->and(childRefs($screen, 'content-1'))->toBe(['bubble-1', 'reactions-bubble'])
        ->and(childRefs($screen, 'quick-reactions'))->toBe(['quick-0', 'quick-1', 'quick-2', 'more-reactions']);

    // A tap outside it closes it.
    $screen->dismissSheet('reactions-bubble')->assertSet('reactionsFor', null);

    expect(rendersRef($screen, 'reactions-bubble'))->toBeFalse();

    $screen->tap('react-menu-2');

    expect(childRefs($screen, 'content-2'))->toBe(['bubble-2', 'reactions-bubble']);
});

it("points the quick reactions at an image-only message's images, and pulls it to reply like any other", function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1, 'body' => '', 'body_segments' => [], 'images' => [['id' => 7, 'width' => 400, 'height' => 300, 'description' => null, 'url' => 'http://devnull.test/i/7.png']]])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->tap('react-menu-1');

    expect(childRefs($screen, 'content-1'))->toBe(['photos-1', 'reactions-bubble']);

    $screen->trigger('swipe-1')->assertSet('replyToId', 1);
});

it('reacts from the bubble, and puts it away', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->tap('react-menu-1');

    finishRefreshes($screen->tap('quick-2'))->assertSet('reactionsFor', null);

    expect(sentTo('POST', 'remotes/messages/1/reactions'))->toBe(['{"emoji":"❤️"}'])
        ->and(rendersRef($screen, 'reactions-bubble'))->toBeFalse()
        ->and(rendersRef($screen, 'chip-1-0'))->toBeTrue();
});

it('takes no swipe on a message still being pushed, or while the thread cannot take a message', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));
    holdRefreshes();

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->set('draft', 'on it')->call('send');
    $staged = $screen->instance()->pending[0]->clientId;

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'swipe-'.$staged))->toBeNull();

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

/**
 * Whether the phone's emoji picker is up.
 */
function pickerShows(TestableComponent $screen): bool
{
    return nodeByRef($screen, 'emoji-picker')['props']['visible'] ?? false;
}

it("opens the phone's emoji picker from More in the bubble, and reacts with the emoji picked", function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->tap('react-menu-1');

    expect(pickerShows($screen))->toBeFalse();

    $screen->tap('more-reactions')
        ->assertSet('reactionsFor', null)
        ->assertSet('emojiPickerFor', 1);

    expect(pickerShows($screen))->toBeTrue()
        ->and(rendersRef($screen, 'reactions-bubble'))->toBeFalse();

    finishRefreshes($screen->select('emoji-picker', '🎉'))->assertSee('🎉');

    expect(sentTo('POST', 'remotes/messages/1/reactions'))->toBe(['{"emoji":"🎉"}'])
        ->and(pickerShows($screen))->toBeFalse();
});

it('takes back a reaction picked again, as the quick reactions do', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1, 'reactions' => [['user_id' => RemotesViewerId, 'emoji' => '🎉']]])], ['read_through_id' => 1]));

    finishRefreshes(finishRefreshes(Native::visit('/remotes/conversations/ada'))->call('openEmojiPicker', 1)->select('emoji-picker', '🎉'));

    expect(sentTo('DELETE', 'remotes/messages/1/reactions'))->toBe(['{"emoji":"🎉"}']);
});

it('reacts with the emoji as devnull has it when the picker adds a skin tone or leaves out the variation selector', function (string $picked, string $sent) {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    finishRefreshes(finishRefreshes(Native::visit('/remotes/conversations/ada'))->call('openEmojiPicker', 1)->select('emoji-picker', $picked));

    expect(array_map(fn (string $body): string => json_decode($body, true)['emoji'], sentTo('POST', 'remotes/messages/1/reactions')))->toBe([$sent]);
})->with([
    'a skin tone' => ['👍🏽', '👍'],
    'a skin tone on an emoji that needs the variation selector' => ['✌🏽', '✌️'],
    'a skin tone inside a sequence' => ['👩🏽‍💻', '👩‍💻'],
    'no variation selector' => ['❤', '❤️'],
    'as it is' => ['🫠', '🫠'],
]);

it("doesn't send an emoji devnull doesn't know, says so, and keeps the picker up for another", function (string $picked) {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    $screen = finishRefreshes(finishRefreshes(Native::visit('/remotes/conversations/ada'))->call('openEmojiPicker', 1)->select('emoji-picker', $picked));

    expect(sentTo('POST', 'remotes/messages/1/reactions'))->toBe([])
        ->and(array_column(array_column($this->bridge->callsTo('ToastKit.Show'), 'params'), 'message'))->toBe(["devnull doesn't take {$picked} as a reaction yet. Pick another."])
        ->and(pickerShows($screen))->toBeTrue();

    finishRefreshes($screen->select('emoji-picker', '🚀'));

    expect(sentTo('POST', 'remotes/messages/1/reactions'))->toBe(['{"emoji":"🚀"}']);
})->with([
    'newer than devnull\'s set' => ['🐦‍🔥'],
    'two skin tones in one' => ['🫱🏻‍🫲🏿'],
]);

it('sends nothing when the picker is closed without a pick', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->call('openEmojiPicker', 1)->dismissSheet('emoji-picker');

    expect(pickerShows($screen))->toBeFalse()
        ->and(sentTo('POST', 'remotes/messages/1/reactions'))->toBe([]);
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

/**
 * Someone in dev/null core, with their own avatar.
 *
 * @return array<string, mixed>
 */
function pullerJson(string $handle): array
{
    return userJson(['id' => 'u-'.$handle, 'handle' => $handle, 'name' => ucfirst($handle), 'avatar_url' => "https://avatars.test/{$handle}"]);
}

/**
 * dev/null core's members: you, pulled through the message given, then everyone else by handle with theirs.
 *
 * @param  array<string, int>  $pulledThrough
 * @return list<array<string, mixed>>
 */
function pullersJson(int $mine, array $pulledThrough): array
{
    return [
        ['user' => userJson(), 'role' => 'maintainer', 'read_through_id' => $mine],
        ...array_map(fn (string $handle, int $through): array => ['user' => pullerJson($handle), 'role' => 'contributor', 'read_through_id' => $through], array_keys($pulledThrough), $pulledThrough),
    ];
}

/**
 * The words on a message's status line, in order, with the "+N" after the avatars but not the initials under them.
 *
 * @return list<string>
 */
function statusTexts(TestableComponent $screen, int $id): array
{
    $isText = fn (array $node): bool => ($node['type'] ?? null) === 'text';
    $initials = rendersRef($screen, 'pulled-by-avatars-'.$id) ? findNodes(nodeByRef($screen, 'pulled-by-avatars-'.$id), $isText) : [];
    $words = array_filter(findNodes(nodeByRef($screen, 'status-'.$id), $isText), fn (array $node): bool => ! in_array($node, $initials, true));

    return array_values(array_map(fn (array $node): string => $node['props']['text'] ?? '', $words));
}

/**
 * The avatars stacked after "pulled by", as the image URLs drawn bottom to top.
 *
 * @return list<string>
 */
function pulledAvatars(TestableComponent $screen, int $id): array
{
    return array_map(fn (array $image): string => $image['props']['src'] ?? '', findNodes(nodeByRef($screen, 'pulled-by-avatars-'.$id), fn (array $node): bool => ($node['type'] ?? null) === 'image'));
}

it('stacks the avatars of who in an org pulled your latest message, five of them and a count for the rest', function () {
    serveOrgThread(orgThreadJson([
        orgMessageJson(['id' => 1, 'body' => 'standup in 10?']),
        orgMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'on my way']),
    ], ['members' => pullersJson(2, ['ada' => 2, 'grace' => 2, 'linus' => 2, 'margaret' => 2, 'ken' => 2, 'barbara' => 2, 'dennis' => 1])]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'));

    // Like your own messages anywhere, it goes without its time. Dennis hasn't pulled it yet.
    expect(statusTexts($screen, 2))->toBe(['pulled by', '+1'])
        ->and(pulledAvatars($screen, 2))->toBe(['https://avatars.test/ada', 'https://avatars.test/grace', 'https://avatars.test/linus', 'https://avatars.test/margaret', 'https://avatars.test/ken'])
        ->and(nodeByRef($screen, 'pulled-by-2')['props']['a11y_label'] ?? null)->toBe('@ada, @grace, @linus, @margaret, @ken and 1 other');
});

it("puts the time before who pulled someone else's message, you among them", function () {
    serveOrgThread(orgThreadJson([
        orgMessageJson(['id' => 1, 'user_id' => 'u-grace', 'user' => pullerJson('grace'), 'body' => 'standup in 10?', 'created_at' => '2026-10-19T14:00:00Z']),
    ], ['members' => pullersJson(1, ['grace' => 1, 'linus' => 1, 'ken' => 0])]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'));

    expect(statusTexts($screen, 1))->toBe(['14:00 · pulled by'])
        ->and(pulledAvatars($screen, 1))->toBe([userJson()['avatar_url'], 'https://avatars.test/linus'])
        ->and(nodeByRef($screen, 'pulled-by-1')['props']['a11y_label'] ?? null)->toBe('@local_dev, @linus');
});

it('says everyone pulled a message once all of more than three have, as the web does', function (array $pulledThrough, array $words) {
    serveOrgThread(orgThreadJson([orgMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'on my way'])], ['members' => pullersJson(2, $pulledThrough)]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'));

    expect(statusTexts($screen, 2))->toBe($words)
        ->and(rendersRef($screen, 'pulled-by-2'))->toBe($words === ['pulled by']);
})->with([
    'four of four' => [['ada' => 2, 'grace' => 2, 'linus' => 2, 'ken' => 2], ['pulled by everyone']],
    'three of three' => [['ada' => 2, 'grace' => 2, 'linus' => 2], ['pulled by']],
]);

it("says pushed under your org message nobody has pulled, and only the time under someone else's", function (string $author, array $words) {
    serveOrgThread(orgThreadJson([
        orgMessageJson(['id' => 1, 'user_id' => $author, 'user' => $author === RemotesViewerId ? userJson() : pullerJson('ada'), 'body' => 'standup in 10?', 'created_at' => '2026-10-19T14:00:00Z']),
    ], ['members' => pullersJson(0, ['ada' => 0, 'grace' => 0])]));

    expect(statusTexts(finishRefreshes(Native::visit('/remotes/orgs/org-1')), 1))->toBe($words);
})->with([
    'yours' => [RemotesViewerId, ['pushed']],
    'theirs' => ['u-ada', ['14:00']],
]);

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

it('opens a link in a message body, in the app for a page on dev/null and in the browser for any other', function () {
    $commit = '0199e5c4-0000-7000-8000-0000000000c1';

    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => "read https://nativephp.com/docs or https://dev-null.sh/@grace then https://dev-null.sh/@ada/commits/{$commit}"]),
    ], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    expect(paragraphRuns($screen, 'read'))->toBe([
        ['read '],
        ['nativephp.com/docs', 'link'],
        [' or '],
        ['dev-null.sh/@grace', 'link'],
        [' then '],
        ['ada/00000c1', 'link'],
    ]);

    $screen->press('openMessageLink(1, 0)')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://nativephp.com/docs')
        ->assertNoNavigation();

    finishRefreshes(Native::visit('/remotes/conversations/ada'))->press('openMessageLink(1, 1)')->assertNavigatedTo('/remotes/users/grace');
    finishRefreshes(Native::visit('/remotes/conversations/ada'))->press('openMessageLink(1, 2)')->assertNavigatedTo('/remotes/commits/'.$commit);
});

it("opens a mentioned organization's page, and a mentioned user's profile, in the tab", function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'ask @laravel or @grace', 'mentions' => [
            ['handle' => 'laravel', 'offset' => 4, 'length' => 8, 'kind' => 'organization'],
            ['handle' => 'grace', 'offset' => 16, 'length' => 6, 'kind' => 'user'],
        ]]),
    ], ['read_through_id' => 1]));

    finishRefreshes(Native::visit('/remotes/conversations/ada'))->press("openOrganization('laravel')")->assertNavigatedTo('/remotes/organizations/laravel');
    finishRefreshes(Native::visit('/remotes/conversations/ada'))->press("openProfile('grace')")->assertNavigatedTo('/remotes/users/grace');
});

it('links a hashtag in a message to a search for it in the tab', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'loving #nativephp lately']),
    ], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    expect(paragraphRuns($screen, 'loving'))->toBe([
        ['loving '],
        ['#nativephp', 'hashtag'],
        [' lately'],
    ]);

    $screen->press("openHashtag('#nativephp')")->assertNavigatedTo('/remotes/search');
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

    $screen->trigger('swipe-1');

    expect($clearance())->toEqual(Conversation::ComposerBarHeight + Conversation::ReplyingBarHeight + 8);
});

// ── Pins ────────────────────────────────

/**
 * Ada, in the org.
 *
 * @return array<string, mixed>
 */
function orgAda(): array
{
    return userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']);
}

/**
 * A message in the org, by Ada unless a test says otherwise.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orgMessageJson(array $overrides = []): array
{
    return remoteMessageJson(['user' => ($overrides['user_id'] ?? 'u2') === RemotesViewerId ? userJson() : orgAda(), ...$overrides]);
}

/**
 * dev/null core as devnull answers it, its first page or a poll: you and Ada in it, you a maintainer unless a test says
 * otherwise, and what it pinned.
 *
 * @param  list<array<string, mixed>>  $messages
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orgThreadJson(array $messages, array $overrides = []): array
{
    return ['data' => [
        'org' => ['id' => 'org-1', 'name' => 'dev/null core'],
        'messages' => $messages,
        'has_older' => false,
        'reactions_version' => 3,
        'reaction_changes' => [],
        'pinned' => [],
        'read_through_id' => $messages === [] ? 0 : $messages[array_key_last($messages)]['id'],
        'members' => [
            ['user' => userJson(), 'role' => 'maintainer', 'read_through_id' => 0],
            ['user' => orgAda(), 'role' => 'contributor', 'read_through_id' => 0],
        ],
        'typing' => [],
        'is_archived' => false,
        'role' => 'maintainer',
        'access' => 'open',
        ...$overrides,
    ]];
}

/**
 * devnull answering for dev/null core: its first page, whatever a poll asks for, and pins. Unless a test answers pins
 * itself, a pin answers with just that message pinned and an unpin with none.
 *
 * @param  array<string, mixed>  $thread
 * @param  array<string, mixed>|null  $poll
 * @param  (Closure(Request): mixed)|null  $pins
 */
function serveOrgThread(array $thread, ?array $poll = null, ?Closure $pins = null): void
{
    serveApi(function (Request $request) use ($thread, $poll, $pins) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);

        return match (true) {
            $request->method() === 'GET' && $path === '/api/v1/remotes/orgs/org-1/messages' => Http::response(str_contains($query, 'after=') ? ($poll ?? orgThreadJson([], ['pinned' => null])) : $thread),
            str_ends_with($path, '/pin') => $pins !== null ? $pins($request) : Http::response([
                'pinned' => $request->method() === 'PUT' ? [orgMessageJson(['id' => (int) explode('/', $path)[5]])] : [],
                'reactions_version' => 4,
            ]),
            $path === '/api/v1/me' => Http::response(currentUserJson()),
            str_ends_with($path, '/reactions') && $request->method() === 'POST' => Http::response(['reactions' => [['user_id' => RemotesViewerId, 'emoji' => json_decode($request->body(), true)['emoji']]]]),
            default => Http::response(['usage' => []], $request->method() === 'GET' ? 200 : 204),
        };
    });
}

it('asks before pinning for the whole org, then pins at once and takes the pinned messages devnull answers with', function () {
    serveOrgThread(
        orgThreadJson([orgMessageJson(['id' => 1, 'body' => 'standup in 10?'])]),
        pins: fn () => Http::response(['pinned' => [orgMessageJson(['id' => 1, 'body' => 'standup in 10?']), orgMessageJson(['id' => 9, 'body' => 'from before you joined'])], 'reactions_version' => 5]),
    );
    holdRefreshes();

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->tap('pin-menu-1')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Pin for the whole org?'
            && str_contains($params['message'], 'including people added without Share history'));

    // Cancel leaves it be.
    $screen->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => Conversation::PinAlert]);
    expect(rendersRef($screen, 'pinned-bar'))->toBeFalse()
        ->and(sentTo('PUT', 'remotes/messages/1/pin'))->toBe([]);

    // Pin message pins it straight away, with its pin beside it, and takes devnull's list once it answers.
    $screen->tap('pin-menu-1')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => Conversation::PinMessage, 'id' => Conversation::PinAlert])
        ->assertSee('pinned · 1');

    expect(childRefs($screen, 'content-1'))->toBe(['bubble-1', 'pinned-1'])
        ->and(array_column(menuItems($screen, 'bubble-1'), 0))->toContain('unpin-menu-1');

    finishRefreshes($screen)->assertSee('pinned · 2');

    expect(sentTo('PUT', 'remotes/messages/1/pin'))->toHaveCount(1);
});

it('stops asking before pinning once you say not to ask again', function () {
    serveOrgThread(orgThreadJson([
        orgMessageJson(['id' => 1, 'body' => 'standup in 10?']),
        orgMessageJson(['id' => 2, 'body' => 'release checklist']),
    ]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->tap('pin-menu-1')
        ->emitNative(ButtonPressed::class, ['index' => 2, 'label' => Conversation::PinWithoutAsking, 'id' => Conversation::PinAlert]);

    finishRefreshes($screen->tap('pin-menu-2'));

    expect($this->bridge->callsTo('Dialog.Alert'))->toHaveCount(1)
        ->and(sentTo('PUT', 'remotes/messages/1/pin'))->toHaveCount(1)
        ->and(sentTo('PUT', 'remotes/messages/2/pin'))->toHaveCount(1);
});

it('marks a pinned message with a pin on the side of its bubble nearest the middle, for everyone in the org', function () {
    $pinned = [orgMessageJson(['id' => 1, 'body' => 'standup in 10?']), orgMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'on my way'])];
    serveOrgThread(orgThreadJson($pinned, ['role' => 'contributor', 'pinned' => $pinned]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'));

    expect(childRefs($screen, 'content-1'))->toBe(['bubble-1', 'pinned-1'])
        ->and(childRefs($screen, 'content-2'))->toBe(['pinned-2', 'bubble-2'])
        ->and(nodeByRef($screen, 'pinned-1')['type'])->toBe('stack');
});

it("unpins at once, from the message's menu or in the pinned sheet, which closes once nothing is left in it", function () {
    serveOrgThread(orgThreadJson([
        orgMessageJson(['id' => 1, 'body' => 'standup in 10?']),
        orgMessageJson(['id' => 2, 'body' => 'release checklist']),
    ], ['pinned' => [orgMessageJson(['id' => 2, 'body' => 'release checklist']), orgMessageJson(['id' => 1, 'body' => 'standup in 10?'])]]));
    holdRefreshes();

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->assertSee('pinned · 2')
        ->tap('unpin-menu-1')
        ->assertSee('pinned · 1');

    expect(rendersRef($screen, 'pinned-1'))->toBeFalse()
        ->and($this->bridge->callsTo('Dialog.Alert'))->toBe([]);

    finishRefreshes($screen->tap('pinned-bar')->tap('pin-2-unpin'))->assertSet('sheetOpen', false);

    expect(rendersRef($screen, 'pinned-bar'))->toBeFalse()
        ->and(sentTo('DELETE', 'remotes/messages/1/pin'))->toHaveCount(1)
        ->and(sentTo('DELETE', 'remotes/messages/2/pin'))->toHaveCount(1);
});

it("puts a pin back when devnull doesn't take it, and says why", function (int $status, array $answer, string $toast) {
    serveOrgThread(
        orgThreadJson([orgMessageJson(['id' => 1, 'body' => 'standup in 10?'])]),
        pins: fn () => Http::response($answer, $status),
    );
    app(Account::class)->rememberSkipsPinWarning();

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1')->tap('pin-menu-1'));

    expect(rendersRef($screen, 'pinned-bar'))->toBeFalse()
        ->and(array_column(array_column($this->bridge->callsTo('ToastKit.Show'), 'params'), 'message'))->toBe([$toast]);
})->with([
    'in devnull\'s words when it says no' => [403, ['message' => 'An org can pin up to 25 messages. Unpin one first.'], 'An org can pin up to 25 messages. Unpin one first.'],
    'when it goes wrong' => [500, ['message' => 'Server Error'], 'Could not pin. Try again.'],
    'when another maintainer took the last place first' => [200, ['pinned' => [], 'reactions_version' => 5], 'Could not pin. Try again.'],
]);

it("puts an unpinned message back where it was when devnull doesn't take it", function () {
    serveOrgThread(
        orgThreadJson([orgMessageJson(['id' => 1, 'body' => 'standup in 10?'])], ['pinned' => [
            orgMessageJson(['id' => 2, 'body' => 'release checklist']),
            orgMessageJson(['id' => 1, 'body' => 'standup in 10?']),
            orgMessageJson(['id' => 3, 'body' => 'office hours']),
        ]]),
        pins: fn () => Http::response(['message' => 'Server Error'], 500),
    );

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1')->tap('unpin-menu-1'));

    expect(array_map(fn ($message): int => $message->id, $screen->instance()->pinned))->toBe([2, 1, 3])
        ->and(array_column(array_column($this->bridge->callsTo('ToastKit.Show'), 'params'), 'message'))->toBe(['Could not unpin. Try again.']);
});

it("shows the others' pins when a poll brings them, and keeps them when a poll says nothing changed", function () {
    serveOrgThread(
        orgThreadJson([orgMessageJson(['id' => 1, 'body' => 'standup in 10?']), orgMessageJson(['id' => 2, 'body' => 'release checklist'])]),
        orgThreadJson([], ['reactions_version' => 4, 'pinned' => [orgMessageJson(['id' => 2, 'body' => 'release checklist'])]]),
    );

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'));

    expect(rendersRef($screen, 'pinned-bar'))->toBeFalse();

    finishRefreshes($screen->call('poll'))->assertSee('pinned · 1');

    expect(rendersRef($screen, 'pinned-2'))->toBeTrue();

    serveOrgThread(orgThreadJson([]), orgThreadJson([], ['reactions_version' => 4, 'pinned' => null]));
    $this->travel(5)->seconds();
    finishRefreshes($screen->call('poll'))->assertSee('pinned · 1');

    $polls = collect(Http::recorded())->map(fn (array $sent): string => (string) parse_url($sent[0]->url(), PHP_URL_QUERY))->filter(fn (string $query): bool => str_contains($query, 'after='))->values();

    expect($polls->all())->toBe(['after=2&reactions_after=3', 'after=2&reactions_after=4']);
});

it("doesn't let a poll undo a pin of yours still on its way, and asks for the pins again after", function () {
    serveOrgThread(
        orgThreadJson([orgMessageJson(['id' => 1, 'body' => 'standup in 10?'])]),
        orgThreadJson([], ['reactions_version' => 6, 'pinned' => [orgMessageJson(['id' => 7, 'body' => 'office hours']), orgMessageJson(['id' => 1, 'body' => 'standup in 10?'])]]),
        pins: fn () => Http::response(['pinned' => [orgMessageJson(['id' => 1, 'body' => 'standup in 10?'])], 'reactions_version' => 5]),
    );
    app(Account::class)->rememberSkipsPinWarning();
    holdRefreshes();

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'));

    // The poll goes first, but answers while the pin is still out, so the pin's own answer wins.
    finishRefreshes($screen->call('poll')->tap('pin-menu-1'))->assertSee('pinned · 1');

    expect(array_map(fn ($message): int => $message->id, $screen->instance()->pinned))->toBe([1]);

    // The next poll asks from the pins' version, not the poll's, so it brings the pin it left aside.
    $this->travel(5)->seconds();
    finishRefreshes($screen->call('poll'))->assertSee('pinned · 2');

    $polls = collect(Http::recorded())->map(fn (array $sent): string => (string) parse_url($sent[0]->url(), PHP_URL_QUERY))->filter(fn (string $query): bool => str_contains($query, 'after='))->values();

    expect($polls->last())->toBe('after=1&reactions_after=5');
});

it('shows the latest pinned message in a bar over the thread, which opens them all in a sheet', function () {
    serveOrgThread(orgThreadJson([orgMessageJson(['id' => 2, 'body' => 'release checklist'])], ['pinned' => [
        orgMessageJson(['id' => 2, 'body' => 'release checklist']),
        orgMessageJson(['id' => 1, 'body' => 'standup in 10?', 'created_at' => '2026-10-18T10:02:00Z']),
    ]]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->assertSee('pinned · 2')
        ->assertSee(' release checklist');

    // It lies over the thread, which clears it at the top.
    expect(childRefs($screen, 'thread-stack'))->toBe(['thread', 'pinned-bar'])
        ->and(childRefs($screen, 'thread')[0])->toBe('pinned-clearance')
        ->and(nodeByRef($screen, 'pinned-bar')['props']['a11y_label'] ?? null)->toBe('2 pinned messages. Latest from Ada Lovelace: release checklist');

    $screen->tap('pinned-bar')
        ->assertSet('sheetOpen', true)
        ->assertSee('Pinned messages')
        ->assertSee('Yesterday · 10:02');

    expect(childRefs($screen, 'pinned-list'))->toBe(['pin-2', 'pin-1'])
        ->and(rendersRef($screen, 'pin-1-unpin'))->toBeTrue();
});

it('replies and reacts to a pinned message from the sheet, even one from before your history', function () {
    serveOrgThread(orgThreadJson([orgMessageJson(['id' => 5, 'body' => 'hello org'])], ['pinned' => [
        orgMessageJson(['id' => 1, 'body' => 'standup in 10?']),
    ]]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))->tap('pinned-bar');

    // React shows the bubble over the sheet, and reacting leaves the pinned messages up.
    $screen->tap('pin-1-react')
        ->assertSet('reactionsFor', 1)
        ->assertSet('reactsFromPinned', true);

    expect(childRefs($screen, 'pin-1-actions'))->toContain('reactions-bubble');

    finishRefreshes($screen->tap('quick-0'))
        ->assertSet('reactionsFor', null)
        ->assertSet('sheetOpen', true);

    expect(sentTo('POST', 'remotes/messages/1/reactions'))->toHaveCount(1)
        ->and(rendersRef($screen, 'chip-pin-1-0'))->toBeTrue();

    $screen->tap('pin-1-reply')
        ->assertSet('sheetOpen', false)
        ->assertSee('Replying to Ada Lovelace')
        ->set('draft', 'on it')
        ->call('send');

    finishRefreshes($screen);

    expect(json_decode(sentTo('POST', 'remotes/orgs/org-1/messages')[0], true)['reply_to_id'])->toBe(1);
});

it('goes back to the pinned messages from the emoji picker, picked or not', function (?string $picked) {
    serveOrgThread(orgThreadJson([orgMessageJson(['id' => 5, 'body' => 'hello org'])], ['pinned' => [
        orgMessageJson(['id' => 1, 'body' => 'standup in 10?']),
    ]]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))->tap('pinned-bar')->tap('pin-1-react')->tap('more-reactions')
        // iOS tells the sheet it closed once it has made way for the picker.
        ->dismissSheet('message-sheet')
        ->assertSet('sheetOpen', false);

    finishRefreshes($picked === null ? $screen->dismissSheet('emoji-picker') : $screen->select('emoji-picker', $picked))
        ->assertSet('sheetOpen', true)
        ->assertSet('emojiPickerFor', null);

    expect(sentTo('POST', 'remotes/messages/1/reactions'))->toHaveCount($picked === null ? 0 : 1);
})->with([
    'picked' => ['🎉'],
    'closed' => [null],
]);

it('shows three lines of a long pinned message, and the rest with Show more', function () {
    $long = implode("\n", ['release checklist for today:', '1. tag 2.4', '2. post the changelog', '3. ping the mobile folks']);
    serveOrgThread(orgThreadJson([orgMessageJson(['id' => 2, 'body' => 'short'])], ['pinned' => [
        orgMessageJson(['id' => 1, 'body' => $long]),
        orgMessageJson(['id' => 2, 'body' => 'short']),
    ]]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))->tap('pinned-bar');

    expect(rendersRef($screen, 'pin-2-more'))->toBeFalse()
        ->and(findNode(nodeByRef($screen, 'pin-1'), fn (array $node): bool => ($node['props']['max_lines'] ?? null) === 3))->not->toBeNull();

    $screen->tap('pin-1-more')->assertSee('Show less');

    expect(findNode(nodeByRef($screen, 'pin-1'), fn (array $node): bool => ($node['props']['max_lines'] ?? null) === 3))->toBeNull();
});

it('opens a link in a pinned message, in the sheet and in its three-line preview', function () {
    $commit = '0199e5c4-0000-7000-8000-0000000000c1';
    $long = "release checklist: https://nativephp.com/docs\n1. tag 2.4\n2. post the changelog\n3. ping the mobile folks";

    serveOrgThread(orgThreadJson([], ['pinned' => [
        orgMessageJson(['id' => 1, 'body' => $long]),
        orgMessageJson(['id' => 2, 'body' => 'fixed in https://dev-null.sh/@ada/commits/'.$commit]),
    ]]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))->tap('pinned-bar');

    $preview = findNode(nodeByRef($screen, 'pin-1'), fn (array $node): bool => ($node['props']['max_lines'] ?? null) === 3);
    $link = $preview === null ? null : findNode($preview, fn (array $node): bool => ($node['props']['text'] ?? null) === 'nativephp.com/docs');

    expect($preview)->not->toBeNull()
        ->and($link['on_press'] ?? null)->not->toBeNull();

    $screen->press('openMessageLink(1, 0)')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://nativephp.com/docs');

    finishRefreshes(Native::visit('/remotes/orgs/org-1'))->tap('pinned-bar')
        ->press('openMessageLink(2, 0)')
        ->assertNavigatedTo('/remotes/commits/'.$commit);
});

it('opens Reply, React and Copy text on a long press on a message, yours or theirs, and keeps its tap and double tap', function () {
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?']),
        remoteMessageJson(['id' => 2, 'user_id' => RemotesViewerId, 'body' => 'which one?']),
    ], ['read_through_id' => 2]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada', platform: 'ios'));

    expect(nodeByRef($screen, 'bubble-1')['props']['has_context_menu'] ?? null)->toBeTrue()
        ->and(menuItems($screen, 'bubble-1'))->toBe([
            ['reply-menu-1', 'Reply', 'custom:reply'],
            ['react-menu-1', 'React', 'custom:smile-plus'],
            ['copy-menu-1', 'Copy text', 'custom:copy'],
        ])
        ->and(array_column(menuItems($screen, 'bubble-2'), 0))->toBe(['reply-menu-2', 'react-menu-2', 'copy-menu-2'])
        ->and(nodeByRef($screen, 'bubble-1')['props']['on_double_tap'] ?? 0)->toBeGreaterThan(0);
});

it("opens the same menu on a message's photos, without Copy text when it has no text", function () {
    $photo = ['id' => 7, 'width' => 400, 'height' => 300, 'description' => null, 'url' => 'http://devnull.test/i/7.png'];
    serveRemoteThread(threadJson([
        remoteMessageJson(['id' => 1, 'body' => '', 'images' => [$photo]]),
        remoteMessageJson(['id' => 2, 'body' => 'the view from here', 'images' => [[...$photo, 'id' => 8]]]),
    ], ['read_through_id' => 2]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada', platform: 'ios'));

    // The bubble names the items of a message with both, so they're found once.
    expect(menuItems($screen, 'photos-1'))->toBe([
        ['reply-menu-1', 'Reply', 'custom:reply'],
        ['react-menu-1', 'React', 'custom:smile-plus'],
    ])
        ->and(menuItems($screen, 'photos-2'))->toBe([
            [null, 'Reply', 'custom:reply'],
            [null, 'React', 'custom:smile-plus'],
            [null, 'Copy text', 'custom:copy'],
        ])
        ->and(array_column(menuItems($screen, 'bubble-2'), 0))->toBe(['reply-menu-2', 'react-menu-2', 'copy-menu-2']);
});

it('only copies the text of a message still being pushed, or in a thread that cannot take a message', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?'])], ['read_through_id' => 1]));
    holdRefreshes()->respondTo('Clipboard.WriteText', ['success' => true]);

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada', platform: 'ios'))->set('draft', 'on it')->call('send');
    $staged = $screen->instance()->pending[0]->clientId;

    expect(menuItems($screen, 'bubble-'.$staged))->toBe([['copy-menu-'.$staged, 'Copy text', 'custom:copy']]);

    $screen->tap('copy-menu-'.$staged)
        ->assertNativeCalled('Clipboard.WriteText', fn (array $params): bool => $params['text'] === 'on it');

    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?'])], ['read_through_id' => 1, 'access' => 'request']));
    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    expect(array_column(menuItems($screen, 'bubble-1'), 1))->toBe(['Copy text']);
});

it('copies the text of a message as it was written, and says so on iOS, where Android says so itself', function (string $platform, array $toasts) {
    $this->bridge->respondTo('Clipboard.WriteText', ['success' => true]);
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1, 'body' => "run `php artisan test`\n> and tell me"])], ['read_through_id' => 1]));

    finishRefreshes(Native::visit('/remotes/conversations/ada', platform: $platform))->tap('copy-menu-1');

    expect(array_column($this->bridge->callsTo('Clipboard.WriteText'), 'params'))->toBe([['text' => "run `php artisan test`\n> and tell me"]])
        ->and(array_column(array_column($this->bridge->callsTo('ToastKit.Show'), 'params'), 'message'))->toBe($toasts);
})->with([
    'iOS' => ['ios', ['Copied.']],
    'Android' => ['android', []],
]);

it("says so when the text couldn't be copied", function () {
    $this->bridge->respondTo('Clipboard.WriteText', ['error' => 'clipboard write failed']);
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?'])], ['read_through_id' => 1]));

    finishRefreshes(Native::visit('/remotes/conversations/ada', platform: 'android'))->tap('copy-menu-1');

    expect(array_column(array_column($this->bridge->callsTo('ToastKit.Show'), 'params'), 'message'))->toBe(['Could not copy the text. Try again.']);
});

it('replies from the menu as from a pull, and raises the keyboard', function () {
    serveRemoteThread(threadJson([remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?'])], ['read_through_id' => 1]));

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->tap('reply-menu-1')
        ->assertSee('Replying to Ada Lovelace');

    expect(nodeByRef($screen, 'message-input')['props']['autofocus'] ?? null)->toBeTrue();
});

it("adds Pin message to the menu for an org's maintainers, and Unpin message on a pinned one", function () {
    serveOrgThread(orgThreadJson([
        orgMessageJson(['id' => 1, 'body' => 'standup in 10?']),
        orgMessageJson(['id' => 2, 'body' => 'release checklist']),
    ], ['pinned' => [orgMessageJson(['id' => 2, 'body' => 'release checklist'])]]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1', platform: 'ios'));

    expect(menuItems($screen, 'bubble-1')[3] ?? null)->toBe(['pin-menu-1', 'Pin message', 'custom:pin'])
        ->and(menuItems($screen, 'bubble-2')[3] ?? null)->toBe(['unpin-menu-2', 'Unpin message', 'custom:pin-off']);

    $screen->tap('pin-menu-1')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Pin for the whole org?');

    finishRefreshes($screen->tap('unpin-menu-2'));

    expect(sentTo('DELETE', 'remotes/messages/2/pin'))->toHaveCount(1);

    serveOrgThread(orgThreadJson([orgMessageJson(['id' => 1, 'body' => 'standup in 10?'])], ['role' => 'contributor']));
    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'));

    expect(array_column(menuItems($screen, 'bubble-1'), 1))->toBe(['Reply', 'React', 'Copy text']);
});

/**
 * dev/null core with more members than the list shows, for the "@" suggestions: you, then each person given, as
 * [handle, name, overrides for their member row].
 *
 * @param  list<array{0: string, 1: string, 2?: array<string, mixed>}>  $people
 * @return array<string, mixed>
 */
function orgWithMembers(array $people): array
{
    return orgThreadJson([], ['members' => [
        ['user' => userJson(), 'role' => 'maintainer', 'read_through_id' => 0],
        ...array_map(fn (array $person, int $index): array => [
            'user' => userJson(['id' => 'm'.$index, 'handle' => $person[0], 'name' => $person[1]]),
            'role' => 'contributor',
            'read_through_id' => 0,
            ...($person[2] ?? []),
        ], $people, array_keys($people)),
    ]]);
}

it("lists an org's other members under Members when you type @, and closes once the caret leaves it", function () {
    serveOrgThread(orgThreadJson([]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'mention-suggestions')
        ->input('message-input', 'hey @', 5);

    expect(childRefs($screen, 'mention-suggestions'))->toBe(['mention-heading', 'mention-ada']);

    $screen->moveCaret('message-input', 3)
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'mention-suggestions');
});

it('filters org members as the web ranks them: exact handle, handle prefix, name prefix, then the rest, followed first, six at most', function () {
    serveOrgThread(orgWithMembers([
        ['grace', 'Grace Hopper'],
        ['tobias', 'Ada Tobias'],
        ['adamant', 'Zed'],
        ['adams', 'Yan', ['is_followed' => true]],
        ['ada', 'Ada Lovelace'],
        ['bada', 'Bee'],
        ['cada', 'Cee'],
        ['dada', 'Dee'],
    ]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))->input('message-input', '@ADA', 4);

    expect(childRefs($screen, 'mention-suggestions'))->toBe(['mention-ada', 'mention-adams', 'mention-adamant', 'mention-tobias', 'mention-bada', 'mention-cada']);
});

it('never suggests you, or a member devnull says not to, and suggests nothing in a thread with one person', function () {
    serveOrgThread(orgWithMembers([['ada', 'Ada Lovelace'], ['mallory', 'Mallory', ['suggests_mentions' => false]]]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))->input('message-input', '@', 1);

    expect(childRefs($screen, 'mention-suggestions'))->toBe(['mention-heading', 'mention-ada']);

    serveApi(fn (Request $request) => Http::response(str_contains($request->url(), 'remotes/@ada/messages') ? threadJson([]) : currentUserJson()));

    finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->input('message-input', '@', 1)
        ->assertSet('mention', null);
});

it('suggests nothing for an @ inside an email address or a URL, or while text is selected', function (string $draft, int $start, int $end) {
    serveOrgThread(orgThreadJson([]));

    finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->input('message-input', $draft, $start, $end)
        ->assertSet('mention', null);
})->with([
    'an email address' => ['ping me at me@ada', 17, 17],
    'a URL' => ['see dev-null.sh/@ada', 20, 20],
    'a selection' => ['hi @ada', 3, 7],
]);

it('puts the member picked in place of what was typed, keeping the text either side and adding a space only when needed', function (string $draft, int $caret, string $expected) {
    serveOrgThread(orgThreadJson([]));

    finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->input('message-input', $draft, $caret)
        ->tap('mention-ada')
        ->assertSet('draft', $expected)
        ->assertSet('mention', null);
})->with([
    'at the end' => ['thanks @Lo', 10, 'thanks @ada '],
    'mid-draft, before a space' => ["ok 👋 @a review this\nplease", 7, "ok 👋 @ada review this\nplease"],
    'mid-word' => ['cc @adxyz', 6, 'cc @ada xyz'],
]);

it('puts the caret right after the member picked, wherever in the draft the @ was', function (string $draft, int $caret, string $expected, int $placed) {
    serveOrgThread(orgThreadJson([]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->input('message-input', $draft, $caret)
        ->tap('mention-ada')
        ->assertSet('draft', $expected);

    expect(nodeByRef($screen, 'message-input')['props'])->toMatchArray(['value' => $expected, 'selection_start' => $placed, 'selection_end' => $placed]);
})->with([
    'at the end' => ['thanks @Lo', 10, 'thanks @ada ', 12],
    'mid-draft, after the space that follows' => ["ok 👋 @a review this\nplease", 7, "ok 👋 @ada review this\nplease", 10],
    'mid-word, typed back in the draft' => ['Thanks @adfor the fix', 10, 'Thanks @ada for the fix', 12],
]);

it('counts letters typed onto the @handle after the caret last reported it', function () {
    serveOrgThread(orgThreadJson([]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->input('message-input', 'hi @a for this', 5)
        ->set('draft', 'hi @ad for this')
        ->tap('mention-ada')
        ->assertSet('draft', 'hi @ada for this');

    expect(nodeByRef($screen, 'message-input')['props']['selection_start'])->toBe(8);
});

it('places the caret only until the field reports the picked draft, so it never moves it again', function () {
    serveOrgThread(orgThreadJson([]));

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'));

    expect(nodeByRef($screen, 'message-input')['props']['selection_start'])->toBe(-1);

    $screen->input('message-input', 'hi @a', 5)->tap('mention-ada')->moveCaret('message-input', 5, text: 'hi @a');

    expect(nodeByRef($screen, 'message-input')['props']['selection_start'])->toBe(8);

    $screen->moveCaret('message-input', 8, text: 'hi @ada ');

    expect(nodeByRef($screen, 'message-input')['props']['selection_start'])->toBe(-1);
});

it('leaves the draft alone when it no longer has the @handle where the caret found it', function () {
    serveOrgThread(orgThreadJson([]));

    finishRefreshes(Native::visit('/remotes/orgs/org-1'))
        ->input('message-input', 'hi @a', 5)
        ->set('draft', 'hi')
        ->tap('mention-ada')
        ->assertSet('draft', 'hi');
});
