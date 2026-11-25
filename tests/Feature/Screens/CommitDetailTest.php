<?php

use App\Devnull\Account;
use App\Devnull\Data\Commit;
use App\Devnull\Display;
use App\Devnull\Stash;
use App\Models\PendingWrite;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Edge\Enums\AlignItems;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Tests\Support\GlassOpacity;
use Tests\Support\ScrollEdge;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * @param  array<string, mixed>  $commit
 * @param  list<array<string, mixed>>  $replies
 * @param  array<string, array{0: mixed, 1?: int}>  $extra
 */
function fakeThread(array $commit = [], array $replies = [], array $extra = []): void
{
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(
            commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'The focused commit', ...$commit]),
            [
                commitJson(['id' => 'c0', 'body' => 'The root commit', 'author' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace'])]),
                commitJson(['id' => 'c1', 'parent_id' => 'c0', 'body' => 'The parent commit', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])]),
            ],
        )],
        'GET /api/v1/commits/c2/replies' => [pageJson($replies)],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
        ...$extra,
    ]);
}

it('opens on the commit under its parent, with the older ancestors and the replies folded away', function () {
    fakeThread(['replies_count' => 1], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'author' => userJson(['handle' => 'grace', 'name' => 'Grace'])])]);

    $screen = Native::visit('/log/commits/c2')
        ->assertNavTitle('Commit')
        ->assertSee('The focused commit')
        ->assertSee('The parent commit')
        ->assertSee('1 earlier commit')
        ->assertSee('1 reply')
        ->assertDontSee('The root commit')
        ->assertDontSee('First reply')
        ->assertSee('Reply to @local_dev');

    // The commit comes after the one it replies to, and the row standing in for what that replies to.
    expect(childRefs($screen, 'thread-scroll'))->toBe(['earlier-commits', 'commit-c1', 'focused-commit', 'reply-bar-clearance']);

    $screen->tap('earlier-commits')
        ->assertSee('The root commit')
        ->assertDontSee('1 earlier commit');

    expect(childRefs($screen, 'thread-scroll'))->toBe(['commit-c0', 'commit-c1', 'focused-commit', 'reply-bar-clearance']);

    $screen->tap('replies-c2')
        ->assertSee('First reply')
        ->assertSee('Hide replies');

    expect(childRefs($screen, 'thread-scroll'))->toBe(['commit-c0', 'commit-c1', 'focused-commit', 'thread-reply-r1', 'reply-bar-clearance']);

    $screen->tap('replies-c2')
        ->assertDontSee('First reply')
        ->assertSee('1 reply');
});

it('leaves the page where it is when you tap into the reply bar', function () {
    fakeThread(['replies_count' => 1], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'author' => userJson(['handle' => 'grace', 'name' => 'Grace'])])]);

    $screen = Native::visit('/log/commits/c2')->tap('earlier-commits')->assertSee('The root commit');

    // The field has nothing to do on a press: the earlier commits stay open and the scroll view isn't mounted again.
    expect(nodeByRef($screen, 'reply-field')['props']['on_press_up'] ?? null)->toBeNull();
});

it('keeps the reply field the same view while the thread above it changes, so the keyboard stays up', function () {
    fakeThread(['replies_count' => 1], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'author' => userJson(['handle' => 'grace', 'name' => 'Grace'])])]);

    $screen = Native::visit('/log/commits/c2')->tap('earlier-commits')->input('reply-input', 'Nice');
    $field = idsDownTo($screen->tree(), 'reply-input');

    // The earlier commits folding away above it.
    $screen->set('earlierShown', false)->assertDontSee('The root commit');
    expect(idsDownTo($screen->tree(), 'reply-input'))->toBe($field);

    // More of the thread drawn above it.
    $screen->set('repliesShown', true)->assertSee('First reply');
    expect(idsDownTo($screen->tree(), 'reply-input'))->toBe($field);

    // A refresh failing, which puts its banner at the top of the screen.
    $screen->set('refreshFailed', true);
    expect(nodeByRef($screen, 'refresh-failed'))->not->toBeEmpty()
        ->and(idsDownTo($screen->tree(), 'reply-input'))->toBe($field);
});

it('draws the reply bar with the field over the photo and preview buttons and a round Reply, on both platforms', function (string $platform) {
    fakeThread();

    $screen = Native::visit('/log/commits/c2', platform: $platform);
    $actions = childRefs($screen, 'reply-actions');

    expect(childRefs($screen, 'reply-row'))->toBe(['reply-box'])
        ->and(childRefs($screen, 'reply-box'))->toBe(['reply-field-row', 'reply-actions'])
        ->and(childRefs($screen, 'reply-field-row'))->toBe(['reply-field'])
        ->and(array_slice($actions, 0, 2))->toBe(['reply-add-photos', 'reply-preview'])
        ->and(end($actions))->toBe('send-reply')
        ->and(array_column(menuItems($screen, 'reply-add-photos'), 0))->toBe(['reply-pick-photos', 'reply-take-photo'])
        ->and(nodeByRef($screen, 'send-reply')['props']['a11y_label'] ?? nodeByRef($screen, 'send-reply')['a11y_label'] ?? null)->toBe('Reply')
        ->and(rendersRef($screen, 'reply-expand'))->toBeFalse()
        ->and(canSend($screen, 'send-reply'))->toBeFalse();

    $screen->input('reply-input', 'Ready');

    expect(canSend($screen, 'send-reply'))->toBeTrue();
})->with(['ios', 'android']);

it('keeps the same field, up to five lines and then scrolling, however long the reply grows', function (string $platform) {
    fakeThread();

    $screen = Native::visit('/log/commits/c2', platform: $platform)->input('reply-input', 'A short one');
    $field = idsDownTo($screen->tree(), 'reply-input');

    $screen->input('reply-input', str_repeat("A line of a long reply\n", 12));

    // The field sizes itself: the page only says how many lines it can take, which never changes as you type.
    expect(idsDownTo($screen->tree(), 'reply-input'))->toBe($field)
        ->and(nodeByRef($screen, 'reply-input')['props']['max_lines'])->toBe(Display::ReplyBarMaxLines)
        ->and(nodeByRef($screen, 'reply-input')['props']['min_lines'] ?? null)->toBeNull()
        ->and(nodeByRef($screen, 'sheet')['props']['visible'] ?? false)->toBeFalse();
})->with(['ios', 'android']);

it('previews the reply in the field\'s place, with the body devnull keeps, and brings the field back with the keyboard', function () {
    fakeThread(extra: ['POST /api/v1/commits/preview' => [['data' => [
        'body' => 'Shipped 🚀 with @ada',
        'body_segments' => [['type' => 'text', 'text' => 'Shipped 🚀 with @ada']],
        'preview_segments' => null,
        'mentions' => [['handle' => 'ada', 'offset' => 15, 'length' => 4]],
    ]]]]);
    $screen = Native::visit('/log/commits/c2')->input('reply-input', 'Shipped :rocket: with @ada');
    holdRefreshes();
    $screen->tap('reply-preview');

    // At once, the reply as written, in the field's place, while devnull is asked. The sheet never opens.
    expect(rendersRef($screen, 'reply-input'))->toBeFalse()
        ->and(childRefs($screen, 'reply-field-row'))->toBe(['reply-preview-pane'])
        ->and(nodeByRef($screen, 'reply-preview')['props']['a11y_label'])->toBe('Edit')
        ->and(treeShows(nodeByRef($screen, 'reply-draft-preview'), 'Shipped :rocket: with @ada'))->toBeTrue()
        ->and(nodeByRef($screen, 'sheet')['props']['visible'] ?? false)->toBeFalse();

    finishRefreshes($screen);

    expect(treeShows(nodeByRef($screen, 'reply-draft-preview'), 'Shipped 🚀 with '))->toBeTrue()
        ->and(canSend($screen, 'send-reply'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/commits/preview')
        && $request->body() === '{"body":"Shipped :rocket: with @ada"}');

    $screen->tap('reply-preview');

    expect(nodeByRef($screen, 'reply-input')['props'])->toMatchArray(['value' => 'Shipped :rocket: with @ada', 'autofocus' => true])
        ->and(nodeByRef($screen, 'reply-preview')['props']['a11y_label'])->toBe('Preview');
});

it('shows a drop beside Reply as soon as the reply has anything in it, which throws it away', function () {
    fakeThread();

    $screen = Native::visit('/log/commits/c2');

    expect(rendersRef($screen, 'reply-stash'))->toBeFalse();

    $screen->input('reply-input', 'Not this after all');
    $actions = childRefs($screen, 'reply-actions');

    // A draft you're writing now has drop alone. Only one that came back from the stash says stash@{0}.
    expect(array_slice($actions, -2))->toBe(['reply-stash', 'send-reply'])
        ->and(childRefs($screen, 'reply-stash'))->toBe(['reply-stash-drop'])
        ->and(nodeByRef($screen, 'reply-stash-drop')['props']['a11y_label'] ?? null)->toBe('Discard the draft');

    $screen->tap('reply-stash-drop');

    expect($screen->instance()->reply)->toBe('')
        ->and(nodeByRef($screen, 'reply-input')['props']['value'])->toBe('')
        ->and(rendersRef($screen, 'reply-stash'))->toBeFalse()
        ->and(app(Stash::class)->names(app(Account::class)->user()?->id))->toBe([]);
});

it('says nothing about a long reply being folded in feeds, beside Reply or in the sheet, and still counts down near the limit', function () {
    fakeThread();

    $screen = Native::visit('/log/commits/c2')->input('reply-input', str_repeat('a', 281));

    expect(rendersRef($screen, 'reply-budget-fold'))->toBeFalse()
        ->and(rendersRef($screen, 'reply-budget'))->toBeFalse();

    $screen->input('reply-input', str_repeat('a', Commit::MaxLength - 20));

    expect(nodeByRef($screen, 'reply-budget')['props']['text'])->toBe('20');

    $screen->call('replyInSheet', 'c2');

    expect(rendersRef($screen, 'sheet-budget-fold'))->toBeFalse()
        ->and(nodeByRef($screen, 'sheet-budget')['props']['text'])->toBe('20');
});

it('runs the rail from the folded ancestors into the parent, which shows its avatar', function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 1]);
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(
            commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'Ada again', 'author' => $ada]),
            [
                commitJson(['id' => 'c0', 'body' => 'Ada first', 'author' => $ada]),
                commitJson(['id' => 'c1', 'parent_id' => 'c0', 'body' => 'Ada second', 'author' => $ada]),
            ],
        )],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
    ]);

    $screen = Native::visit('/log/commits/c2', platform: 'ios');
    $lane = config('native-ui.theme.light.lane-1');
    $segment = fn (array $node): bool => ($node['layout']['width'] ?? null) === 2.0 && ($node['style']['bg_color'] ?? null) === $lane;

    expect(railNode($screen, 'commit-c1'))->toBe('avatar')
        ->and(railNode($screen, 'focused-commit'))->toBe('dot')
        ->and(findNode(nodeByRef($screen, 'earlier-commits'), $segment))->not->toBeNull()
        ->and(findNode(nodeByRef($screen, 'commit-c1'), $segment))->not->toBeNull();
});

it('draws the commit as the main thing, and what is around it muted', function () {
    fakeThread(['stars_count' => 2], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply'])]);

    $screen = unfolded(Native::visit('/log/commits/c2', platform: 'ios'));
    $ink = strtolower(config('native-ui.theme.light.ink'));
    $muted = strtolower(config('native-ui.theme.light.ink-muted'));
    $bodyOf = fn (string $ref, string $text): ?array => findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['type'] ?? null) === 'text'
        && str_contains(implode('', array_map(fn (array $run): string => $run['props']['text'] ?? '', $node['children'] ?? [])), $text));
    $avatarRing = fn (string $ref): ?float => findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['type'] ?? null) === 'stack' && in_array($node['layout']['width'] ?? null, [36.0, 48.0], true))['layout']['width'] ?? null;
    $iconSize = fn (string $ref): mixed => findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['type'] ?? null) === 'icon')['props']['size'] ?? null;

    $focused = $bodyOf('focused-commit', 'The focused commit');
    $ancestor = $bodyOf('commit-c1', 'The parent commit');
    $reply = $bodyOf('thread-reply-r1', 'First reply');

    expect($focused['props']['font_size'])->toEqual(17)
        ->and(strtolower($focused['props']['color']))->toBe($ink)
        ->and($ancestor['props']['font_size'])->toEqual(14)
        ->and(strtolower($ancestor['props']['color']))->toBe($muted)
        ->and($reply['props']['font_size'])->toEqual(14)
        ->and(strtolower($reply['props']['color']))->toBe($muted)
        ->and($avatarRing('focused-commit'))->toEqual(48)
        ->and($avatarRing('commit-c1'))->toEqual(36)
        ->and($iconSize('more-c2'))->toEqual(16)
        ->and($iconSize('more-c1'))->toEqual(14)
        ->and($iconSize('more-r1'))->toEqual(14);

    $screen->assertDontSee('+0000')->assertSee('c2');
});

it("hangs the replies under the commit on the web's thread, the rail ending in a dot above them", function () {
    fakeThread(replies: [
        replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'author' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace', 'lane' => 4])]),
        replyJson(['id' => 'r2', 'parent_id' => 'c2', 'body' => 'Second reply']),
    ]);

    $screen = unfolded(Native::visit('/log/commits/c2', platform: 'ios'));
    $first = nodeByRef($screen, 'thread-reply-r1');
    $second = nodeByRef($screen, 'thread-reply-r2');

    expect(findNode($first, fn (array $node): bool => ($node['ref'] ?? null) === 'thread-elbow'))->not->toBeNull()
        ->and(findNode($second, fn (array $node): bool => ($node['ref'] ?? null) === 'thread-elbow'))->toBeNull()
        ->and(findNode(nodeByRef($screen, 'focused-commit'), fn (array $node): bool => ($node['ref'] ?? null) === 'end-dot'))->not->toBeNull()
        // Nothing follows the last reply on a commit page, so it has no closing hairline.
        ->and(findNode($screen->tree(), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'thread-end-')))->toBeNull();
});

it('shows the short hash at the top right of the focused commit, without the full date', function () {
    fakeThread(['created_at' => '2026-10-14T20:13:05Z']);

    $screen = Native::visit('/log/commits/c2')
        ->assertDontSee('Wed Oct 14 20:13:05 2026 +0000')
        ->assertDontSee('+0000');

    $hash = nodeByRef($screen, 'focused-hash');
    // The row with the name, the handle and the time, with a spacer pushing the hash to its end.
    $nameRow = findNode(nodeByRef($screen, 'focused-commit'), fn (array $node): bool => ($node['type'] ?? null) === 'row' && in_array('focused-time', array_map(fn (array $child): ?string => $child['ref'] ?? null, $node['children'] ?? []), true));
    $children = $nameRow['children'] ?? [];

    expect($hash['text'] ?? $hash['props']['text'] ?? null)->toBe('c2')
        ->and($children[array_key_last($children)]['ref'] ?? null)->toBe('focused-hash')
        ->and($children[count($children) - 2]['type'] ?? null)->toBe('spacer');
});

it('says when the commit was made, in your timezone, when its time is tapped, as the web does on hover', function (?string $chosen, string $toast) {
    phoneIn('Europe/Lisbon');
    app(Account::class)->rememberTimezone($chosen);
    fakeThread(['created_at' => '2026-10-14T20:13:05Z']);

    Native::visit('/log/commits/c2')
        ->tap('focused-time')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === $toast);
})->with([
    "the phone's" => [null, '9:13 PM · Oct 14, 2026 in Lisbon'],
    'the one you chose' => ['Asia/Tokyo', '5:13 AM · Oct 15, 2026 in Tokyo'],
]);

it('posts a reply, with no countdown while there is room', function () {
    fakeThread(extra: ['POST /api/v1/commits' => [['data' => commitJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => 'A fresh reply'])], 201]]);

    Native::visit('/log/commits/c2')
        ->assertSee('No replies yet.')
        ->input('reply-input', 'A fresh reply')
        ->assertMissingElement('text', fn (array $node): bool => ($node['ref'] ?? null) === 'reply-budget')
        ->tap('send-reply')
        ->assertSet('reply', '')
        ->assertSee('A fresh reply')
        ->assertNoNavigation()
        ->assertNativeNotCalled('ToastKit.Show');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->body() === '{"body":"A fresh reply","parent_id":"c2"}');
});

it('shows a posted reply staged under the commit at once, then the one devnull made in its place', function () {
    holdRefreshes();
    keep('commits/c2', commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit'])));
    keep('commits/c2/replies', pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply'])]));
    fakeApi([
        'POST /api/v1/commits' => [['data' => replyJson(['id' => 'r9', 'parent_id' => 'c2', 'status' => 'pending', 'body' => 'A fresh reply'])], 201],
    ]);

    $screen = Native::visit('/log/commits/c2')
        ->input('reply-input', 'A fresh reply')
        ->tap('send-reply')
        ->assertSet('reply', '')
        ->assertNoNavigation()
        ->assertSee('A fresh reply')
        ->assertSee('staged');

    $staged = $screen->instance()->replies[1]->id;

    expect(childRefs($screen, 'thread-scroll'))->toBe(['focused-commit', 'thread-reply-r1', "thread-reply-{$staged}", 'reply-bar-clearance'])
        ->and(PendingWrite::query()->count())->toBe(1);

    finishRefreshes($screen)
        ->assertSee('A fresh reply')
        ->assertSee('pending')
        ->assertDontSee('staged');

    expect(childRefs($screen, 'thread-scroll'))->toBe(['focused-commit', 'thread-reply-r1', 'thread-reply-r9', 'reply-bar-clearance'])
        ->and(PendingWrite::query()->count())->toBe(0)
        ->and(collect(Http::recorded())->filter(fn (array $sent): bool => $sent[0]->method() === 'POST'))->toHaveCount(1);
});

it('shows a reply you posted once when the next page of replies has it too', function () {
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $request->method() === 'POST' => Http::response(['data' => commitJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => 'A fresh reply'])], 201),
            $path === '/api/v1/commits/c2/replies' => Http::response(str_contains($request->url(), 'cursor=page2')
                ? pageJson([replyJson(['id' => 'r2', 'parent_id' => 'c2', 'body' => 'Second reply']), replyJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => 'A fresh reply'])])
                : pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply'])], 'page2', 'http://devnull.test/api/v1/commits/c2/replies')),
            $path === '/api/v1/commits/r9' => Http::response(commitPageJson(replyJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => 'A fresh reply']))),
            $path === '/api/v1/commits/r9/replies' => Http::response(pageJson([])),
            $path === '/api/v1/commits/c2/related' => Http::response(['data' => []]),
            default => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'replies_count' => 3]))),
        };
    });

    $screen = Native::visit('/log/commits/c2')
        ->tap('replies-c2')
        ->input('reply-input', 'A fresh reply')
        ->tap('send-reply')
        ->call('loadMoreReplies')
        ->assertSet('repliesCursor', null);

    expect(childRefs($screen, 'thread-scroll'))->toBe(['focused-commit', 'thread-reply-r1', 'thread-reply-r2', 'thread-reply-r9', 'reply-bar-clearance']);
});

it('marks a reply waiting for approval as pending on its row', function () {
    fakeThread(extra: ['POST /api/v1/commits' => [['data' => commitJson(['id' => 'r9', 'status' => 'pending', 'body' => 'Hold on'])], 201]]);

    Native::visit('/log/commits/c2')
        ->input('reply-input', 'Hold on')
        ->tap('send-reply')
        ->assertSee('pending')
        ->assertNativeNotCalled('ToastKit.Show');
});

it('puts a reply devnull turned down back in the bar, and says why in a toast', function () {
    fakeThread(extra: ['POST /api/v1/commits' => [['message' => 'The body field must not be greater than 280 characters.', 'errors' => ['body' => ['The body field must not be greater than 280 characters.']]], 422]]);

    $screen = Native::visit('/log/commits/c2')
        ->input('reply-input', 'Long enough')
        ->tap('send-reply')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'The body field must not be greater than 280 characters.'
            && $params['variant'] === 'error'
            && ! isset($params['action']))
        ->assertSet('reply', 'Long enough')
        ->assertSet('replyRestored', true)
        ->assertDontSee('staged')
        ->assertNoNavigation();

    expect($screen->instance()->replies)->toBe([])
        ->and(app(Stash::class)->get(userJson()['id'], 'reply-c2')->body)->toBe('Long enough')
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('turns the budget red at zero and holds back a blank reply', function () {
    fakeThread();

    Native::visit('/log/commits/c2')
        ->input('reply-input', '  ')
        ->call('sendReply')
        ->input('reply-input', str_repeat('a', 4000))
        ->assertElement('text', fn (array $node): bool => ($node['ref'] ?? null) === 'reply-budget'
            && ($node['text'] ?? $node['props']['text'] ?? null) === '0'
            && ($node['props']['color'] ?? null) === config('native-ui.theme.light.destructive'));

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('accepts one-character replies and URL-aware reply budgets', function (string $body) {
    fakeThread(extra: ['POST /api/v1/commits' => [['data' => replyJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => $body])], 201]]);

    $screen = Native::visit('/log/commits/c2')->input('reply-input', $body);
    expect(canSend($screen, 'send-reply'))->toBeTrue()
        ->and(nodeByRef($screen, 'reply-input')['props']['max_length'])->toBe(40000);

    $screen->tap('send-reply')->assertSet('reply', '');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['body'] === $body);
})->with(['a', str_repeat('a', 3976).' https://example.com/'.str_repeat('x', 100)]);

it('explains an oversized reply URL before it is submitted', function () {
    fakeThread();
    $screen = Native::visit('/log/commits/c2')->input('reply-input', 'https://example.com/'.str_repeat('x', 2030))
        ->assertSee('Each link must not be greater than 2048 characters.');
    expect(canSend($screen, 'send-reply'))->toBeFalse();
    $screen->call('sendReply');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('sends a reply once, since the bar empties as it goes', function () {
    holdRefreshes();
    fakeThread(extra: ['POST /api/v1/commits' => [['data' => replyJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => 'a'])], 201]]);

    $screen = finishRefreshes(Native::visit('/log/commits/c2'))->input('reply-input', 'a')->tap('send-reply')->call('sendReply');

    expect(PendingWrite::query()->count())->toBe(1);

    finishRefreshes($screen);

    expect(collect(Http::recorded())->filter(fn (array $sent): bool => $sent[0]->method() === 'POST'))->toHaveCount(1);
});

it('deletes your own commit after a confirm', function () {
    fakeThread(extra: ['DELETE /api/v1/commits/c2' => [null, 204]]);

    $screen = Native::visit('/log/commits/c2')
        ->press("confirmDelete('c2')")
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Delete this commit?')
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'delete-commit'])
        ->assertNoNavigation();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    $screen->press("confirmDelete('c2')")
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit'])
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'rm c2 → /dev/null')
        ->assertWentBack();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/commits/c2');
});

it('draws the delete action with the web\'s trash icon', function (string $platform) {
    fakeThread();

    $action = findNode(Native::visit('/log/commits/c2', platform: $platform)->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action' && ($node['props']['id'] ?? null) === 'delete');

    expect($action['props'] ?? null)->toMatchArray(['icon' => 'custom:trash-2', 'a11y_label' => 'Delete commit'])
        ->not->toHaveKey('material_variant');
})->with(['ios', 'android']);

it('offers delete only on your own commits', function () {
    fakeThread(['author' => userJson(['id' => 'u2', 'handle' => 'ada'])], extra: ['DELETE /api/v1/commits/c2' => [null, 204]]);

    Native::visit('/log/commits/c2')
        ->assertMissingElement('top_bar_action', fn (array $node): bool => ($node['props']['id'] ?? null) === 'delete')
        ->call('confirmDelete', 'c2')
        ->assertNativeNotCalled('Dialog.Alert')
        ->call('deleteCommit', 'c2')
        ->assertNoNavigation();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('opens ancestors, replies and profiles', function () {
    fakeThread(replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2'])]);

    // Commits in the tree move the page to them, and anything else opens its own page.
    Native::visit('/log/commits/c2')->tap('earlier-commits')->tap('commit-c1')->assertNoNavigation()->assertSet('id', 'c1');
    Native::visit('/log/commits/c2')->tap('replies-c2')->tap('thread-reply-r1')->assertNoNavigation()->assertSet('id', 'r1');
    Native::visit('/log/commits/c2')->call('openProfile', 'ada')->assertNavigatedTo('/log/users/ada');
});

it("turns the replies button's chevron down while the replies show, as the Log does, and back as they close", function (string $platform) {
    fakeThread(['replies_count' => 1], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'replies_count' => 1])]);

    $screen = Native::visit('/log/commits/c2', platform: $platform);
    $chevron = fn (string $ref): array => findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['type'] ?? null) === 'icon');

    // Shut, it points right, and already carries the animation, so the turn animates on iOS.
    expect($chevron('replies-c2')['props'])->toMatchArray(['name' => 'custom:chevron-right', 'rotate' => 0.0, 'animate-duration' => 220.0]);

    $screen->tap('replies-c2')->assertSee('First reply')->assertSee('Hide replies');
    expect($chevron('replies-c2')['props'])->toMatchArray(['name' => 'custom:chevron-right', 'rotate' => 90.0, 'animate-duration' => 220.0]);

    $screen->tap('replies-c2')->assertDontSee('First reply')->assertSee('1 reply');
    expect($chevron('replies-c2')['props'])->toMatchArray(['name' => 'custom:chevron-right', 'rotate' => 0.0, 'animate-duration' => 220.0]);

    // A reply's button opens its replies in place too, so its chevron turns the same way.
    $screen->tap('replies-c2');
    expect($chevron('replies-r1')['props'])->toMatchArray(['rotate' => 0.0, 'animate-duration' => 220.0]);
})->with(['ios', 'android']);

it('opens with its replies showing from a replies button', function () {
    fakeThread(['replies_count' => 1], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'replies_count' => 1])], extra: [
        'GET /api/v1/commits/r1' => [commitPageJson(replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'replies_count' => 1]))],
        // Someone other than r1's author, whose reply to themselves would carry r1 on as a thread instead.
        'GET /api/v1/commits/r1/replies' => [pageJson([replyJson(['id' => 'r2', 'parent_id' => 'r1', 'body' => 'Answering the reply', 'author' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace'])])])],
    ]);

    Native::visit('/log/commits/c2', ['replies' => true])
        ->assertSee('First reply')
        ->assertSee('Hide replies');

    // A reply's replies open in place under it, as on the web, rather than on its page.
    Native::visit('/log/commits/c2')
        ->tap('replies-c2')
        ->tap('replies-r1')
        ->assertNoNavigation()
        ->assertSee('Answering the reply');
});

it('asks for the replies a page at a time, and the next page from Show more', function () {
    $replies = fn (int ...$numbers): array => array_map(fn (int $n): array => replyJson(['id' => "r{$n}", 'parent_id' => 'c2', 'body' => "Reply {$n}"]), $numbers);

    Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/v1/commits/c2/replies' => Http::response(str_contains($request->url(), 'cursor=page2')
            ? pageJson($replies(11))
            : pageJson($replies(...range(1, 10)), 'page2', 'http://devnull.test/api/v1/commits/c2/replies')),
        '/api/v1/commits/c2/related' => Http::response(['data' => []]),
        default => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'replies_count' => 11]))),
    });

    $screen = Native::visit('/log/commits/c2', ['replies' => true])
        ->assertSee('Reply 10')
        ->assertDontSee('Reply 11')
        ->tap('more-replies-c2')
        ->assertSee('Reply 11')
        ->assertDontSee('Show more');

    $asked = collect(Http::recorded())->map(fn (array $sent): string => $sent[0]->url())->filter(fn (string $url): bool => str_contains($url, '/replies'));

    expect($asked)->toHaveCount(2)
        ->and($asked->every(fn (string $url): bool => str_contains($url, 'limit=10')))->toBeTrue();

    // Asking again for the first page keeps the one loaded after it.
    $screen->call('load')
        ->assertSee('Reply 11')
        ->assertDontSee('Show more');
});

it('opens the replies of a deleted reply a page at a time too', function () {
    $nested = fn (int ...$numbers): array => array_map(fn (int $n): array => replyJson(['id' => "n{$n}", 'parent_id' => 'r1', 'body' => "Nested {$n}"]), $numbers);

    Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/v1/commits/c2/replies' => Http::response(pageJson([
            tombstoneJson(['id' => 'r1', 'parent_id' => 'c2', 'replies_count' => 6]),
            replyJson(['id' => 'r2', 'parent_id' => 'c2', 'body' => 'Still here']),
        ])),
        '/api/v1/commits/r1/replies' => Http::response(str_contains($request->url(), 'cursor=page2')
            ? pageJson($nested(6))
            : pageJson($nested(1, 2, 3, 4, 5), 'page2', 'http://devnull.test/api/v1/commits/r1/replies')),
        '/api/v1/commits/c2/related' => Http::response(['data' => []]),
        default => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'replies_count' => 2]))),
    });

    $more = fn (array $node): bool => ($node['ref'] ?? null) === 'more-replies-r1';

    Native::visit('/log/commits/c2', ['replies' => true])
        ->tap('tombstone-replies-r1')
        ->assertSee('Nested 5')
        ->assertDontSee('Nested 6')
        ->assertSee('Still here')
        ->tap('more-replies-r1')
        ->assertSee('Nested 6')
        ->assertMissingElement('pressable', $more);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/commits/r1/replies?cursor=page2&limit=10');
});

it('keeps deleted ancestors on the rail as tombstones', function () {
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(
            commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'The focused commit']),
            [
                commitJson(['id' => 'c0', 'body' => 'The root commit', 'author' => userJson(['handle' => 'ada', 'lane' => 1])]),
                tombstoneJson(['id' => 'c1', 'parent_id' => 'c0']),
            ],
        )],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
    ]);

    $rail = config('native-ui.theme.light.rail');

    $screen = Native::visit('/log/commits/c2')
        // The deleted parent shows above the commit, and only the root waits behind the fold.
        ->assertSee('1 earlier commit')
        ->assertSee('This commit was deleted.')
        ->assertDontSee('The root commit')
        ->tap('earlier-commits')
        ->assertSee('The root commit')
        ->assertSee('[deleted]')
        ->assertSee('This commit was deleted.')
        ->assertSee('The focused commit')
        ->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'commit-c1');

    // A dashed Rail Gray ring where the avatar was, as on the web, and a Rail Gray segment running on from it.
    $tombstone = nodeByRef($screen, 'tombstone-c1');
    $rings = findNodes($tombstone, fn (array $node): bool => ($node['props']['border_style'] ?? null) === 1);

    expect($rings)->toHaveCount(1)
        ->and($rings[0]['style'])->toMatchArray(['border_width' => 2.0, 'border_color' => $rail, 'border_radius' => 9999.0])
        ->and(findNode($tombstone, fn (array $node): bool => ($node['type'] ?? null) === 'stack' && ($node['layout']['width'] ?? null) === 40.0))->toBeNull();
});

it('keeps deleted replies as tombstones and opens their replies in place', function () {
    fakeThread(replies: [
        tombstoneJson(['id' => 'r1', 'parent_id' => 'c2', 'replies_count' => 1]),
        replyJson(['id' => 'r2', 'parent_id' => 'c2', 'body' => 'Still here']),
    ], extra: [
        'GET /api/v1/commits/r1/replies' => [pageJson([replyJson(['id' => 'r3', 'parent_id' => 'r1', 'body' => 'Answering the deleted one'])])],
    ]);

    $screen = Native::visit('/log/commits/c2')
        ->tap('replies-c2')
        ->assertSee('This commit was deleted.')
        ->assertSee('Still here')
        ->assertDontSee('Answering the deleted one')
        ->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'thread-reply-r1')
        ->tap('tombstone-replies-r1')
        ->assertSee('Answering the deleted one')
        ->assertSee('Hide replies');

    // One level in, on an elbow off the deleted reply's spine.
    expect(findNode(nodeByRef($screen, 'thread-reply-r3'), fn (array $node): bool => ($node['ref'] ?? null) === 'thread-elbow'))->not->toBeNull();

    $screen->tap('thread-reply-r3')->assertNoNavigation()->assertSet('id', 'r3');

    Native::visit('/log/commits/c2')
        ->tap('replies-c2')
        ->tap('tombstone-replies-r1')
        ->tap('tombstone-replies-r1')
        ->assertDontSee('Answering the deleted one')
        ->assertSee('1 reply');
});

it('draws code blocks as mono text in a hairline box, from the body rather than the HTML', function () {
    $body = "Fixed it, @ada:\n```php\nreturn \$a <=> \$b;\n```\nShip it";

    fakeThread([
        'body' => $body,
        'body_segments' => [
            ['type' => 'text', 'text' => 'Fixed it, @ada:'],
            ['type' => 'block', 'language' => 'php', 'html' => '<span class="hl-keyword">return</span> $a &lt;=&gt; $b;'],
            ['type' => 'text', 'text' => 'Ship it'],
        ],
        'mentions' => [['handle' => 'ada', 'offset' => 10, 'length' => 4]],
    ]);

    $screen = Native::visit('/log/commits/c2')
        ->assertSee('return $a <=> $b;')
        ->assertSee('php')
        ->assertSee('Ship it')
        ->assertDontSee('hl-keyword')
        ->assertElement('text', fn (array $node): bool => ($node['props']['text'] ?? null) === 'return $a <=> $b;'
            && ($node['props']['font_name'] ?? null) === 'mono');

    expect(paragraphRuns($screen, 'Fixed it'))->toBe([['Fixed it, '], ['@ada', 'mention'], [':']]);

    $screen->press("openProfile('ada')")->assertNavigatedTo('/log/users/ada');
});

it('links a hashtag to a search for it in the tab, leaving a hex color and code as they are', function () {
    $body = 'Shipped #laravel in #fff and `#code`';

    fakeThread([
        'body' => $body,
        'body_segments' => [
            ['type' => 'text', 'text' => 'Shipped #laravel in #fff and '],
            ['type' => 'code', 'code' => '#code'],
        ],
    ]);

    $screen = Native::visit('/log/commits/c2');

    expect(paragraphRuns($screen, 'Shipped'))->toBe([
        ['Shipped '],
        ['#laravel', 'hashtag'],
        [' in '],
        ["■\u{202F}#fff", 'wordmark'],
        [' and '],
        ['#code', 'code'],
    ]);

    $screen->press("openHashtag('#laravel')")->assertNavigatedTo('/log/search');

    expect($screen->instance()->getNavigationIntent()->data)->toBe(['query' => '#laravel']);
});

it('draws a hex color after a square of that color, in light and dark mode alike', function () {
    $body = 'Brand orange is #ff8a4c, not #123';

    fakeThread([
        'body' => $body,
        'body_segments' => [['type' => 'text', 'text' => $body]],
    ]);

    $screen = Native::visit('/log/commits/c2')->assertSee('#ff8a4c');

    $swatches = findNodes($screen->tree(), fn (array $node): bool => ($node['props']['text'] ?? null) === '■');

    expect($swatches)->toHaveCount(1)
        ->and($swatches[0]['props']['color'] ?? null)->toBe('#FF8A4C')
        // The paragraph's dark ink would otherwise draw the square in dark mode.
        ->and($swatches[0]['props']['dark_color'] ?? null)->toBe('#FF8A4C');
});

it('draws quote lines behind an amber rule, without their markers', function () {
    $body = "> Ship it on Friday?\n> @ada said so\nNot on my watch";

    fakeThread([
        'body' => $body,
        'body_segments' => [['type' => 'text', 'text' => $body]],
        'mentions' => [['handle' => 'ada', 'offset' => 23, 'length' => 4]],
    ]);

    $screen = Native::visit('/log/commits/c2')
        ->assertSee('Not on my watch')
        ->assertDontSee('> Ship it');

    $quote = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'column'
        && (float) ($node['props']['border_l'] ?? 0) === 2.0);

    expect($quote)->not->toBeNull()
        ->and($quote['props'])->toMatchArray(['border_t' => 0, 'border_r' => 0, 'border_b' => 0])
        ->and(strtolower($quote['style']['border_color']))->toBe(strtolower((string) theme('lane-5')))
        ->and(paragraphRuns($screen, 'Ship it'))->toBe([["Ship it on Friday?\n"], ['@ada', 'mention'], [' said so']])
        ->and(paragraphRuns($screen, 'Not on my watch'))->toBe([['Not on my watch']]);
});

it('shows the focused commit with its media and stars', function () {
    fakeThread([
        'author' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace']),
        'stars_count' => 2,
        'link_preview' => ['url' => 'https://nativephp.com', 'title' => 'NativePHP', 'description' => null, 'image_url' => null, 'site_name' => 'NativePHP'],
    ]);

    Native::visit('/log/commits/c2')
        ->assertSee('NativePHP')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'star-c2' && ($node['props']['a11y_label'] ?? null) === 'Star commit, 2 stars')
        // The reply composer is right there, so the focused commit has no Reply action of its own.
        ->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'reply-to-c2')
        ->tap('link-preview-c2')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://nativephp.com');
});

it('goes back to onboarding when the key stops working', function () {
    fakeApi(['GET /api/v1/commits/c2' => [['message' => 'This SSH key is not on the GitHub account of a devnull user.'], 401]]);

    Native::visit('/log/commits/c2')->assertReplacedWith('/onboarding');

    expect(app(Account::class)->user())->toBeNull();
});

it('moves back to the parent when you delete a reply the page moved to', function () {
    $replyDeleted = false;

    Http::fake(function (Request $request) use (&$replyDeleted) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $request->method() === 'DELETE' => tap(Http::response(null, 204), function () use (&$replyDeleted): void {
                $replyDeleted = true;
            }),
            $path === '/api/v1/commits/c2/replies' => Http::response(pageJson($replyDeleted ? [] : [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'Soon gone'])])),
            $path === '/api/v1/commits/c2' => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'Parent']))),
            $path === '/api/v1/commits/r1' => Http::response(commitPageJson(commitJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'Soon gone']), [commitJson(['id' => 'c2', 'body' => 'Parent'])])),
            default => Http::response(pageJson([])),
        };
    });

    // The page moved to the reply, so deleting it moves the page back to its parent, asked for again.
    Native::visit('/log/commits/c2')
        ->tap('replies-c2')
        ->assertSee('Soon gone')
        ->tap('thread-reply-r1')
        ->assertSet('id', 'r1')
        ->call('deleteCommit', 'r1')
        ->assertNoNavigation()
        ->assertSet('id', 'c2')
        ->assertSee('Parent')
        ->assertDontSee('Soon gone')
        ->assertSee('No replies yet.');
});

it('shows your avatar once when you reply to yourself', function () {
    fakeApi([
        'GET /api/v1/commits/c3' => [commitPageJson(
            commitJson(['id' => 'c3', 'parent_id' => 'c2', 'body' => 'And one more thing']),
            [
                commitJson(['id' => 'c1', 'body' => 'Started it', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])]),
                commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'Replying to Ada']),
            ],
        )],
        'GET /api/v1/commits/c3/replies' => [pageJson([])],
    ]);

    $screen = Native::visit('/log/commits/c3')
        ->assertSee('And one more thing')
        ->assertSee('Replying to Ada');

    // The parent shows, and it's yours, so the two share its avatar.
    expect(railNode($screen, 'commit-c2'))->toBe('avatar')
        ->and(railNode($screen, 'focused-commit'))->toBe('dot');

    $screen->tap('earlier-commits');

    expect(railNode($screen, 'commit-c1'))->toBe('avatar')
        ->and(railNode($screen, 'commit-c2'))->toBe('avatar')
        ->and(railNode($screen, 'focused-commit'))->toBe('dot');
});

it('focuses the reply composer when opened from a Reply action', function () {
    fakeThread();

    $input = fn (array $node): bool => ($node['ref'] ?? null) === 'reply-input' && ($node['props']['autofocus'] ?? false) === true;

    Native::visit('/log/commits/c2', ['reply' => true])->assertElement('bare_text_input', $input);
    Native::visit('/log/commits/c2')->assertMissingElement('bare_text_input', $input);
});

it('stars the focused commit and its replies in place', function () {
    $grace = userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace']);

    fakeThread(['author' => $grace], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2'])], extra: [
        'POST /api/v1/commits/c2/star' => [['data' => commitJson(['id' => 'c2', 'stars_count' => 1, 'starred' => true])]],
        'POST /api/v1/commits/r1/star' => [['data' => commitJson(['id' => 'r1', 'starred' => true])]],
    ]);

    $screen = Native::visit('/log/commits/c2')->tap('star-c2')->tap('replies-c2')->tap('star-r1');

    expect($screen->instance()->commit->starred)->toBeTrue()
        ->and($screen->instance()->commit->body)->toBe('The focused commit')
        ->and($screen->instance()->replies[0]->starred)->toBeTrue();
});

it('lets a drag that starts on an ancestor or a reply scroll the thread', function () {
    fakeThread(replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2'])]);

    $screen = unfolded(Native::visit('/log/commits/c2', platform: 'ios'));

    nodeByRef($screen, 'commit-c1');
    nodeByRef($screen, 'thread-reply-r1');

    expect(pressFeedbackInScroller($screen))->toBe([]);
});

it('puts the reply bar on glass on iOS and the surface on Android, with no line, and fades the page out above it', function (string $platform) {
    fakeThread();

    if ($platform === 'ios') {
        GlassOpacity::skipUnlessSupported();
    }

    $screen = Native::visit('/log/commits/c2', platform: $platform);
    $bottomBar = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_bar');
    $bar = $bottomBar['children'][0];
    $surface = substr(strtoupper(config('native-ui.theme.light.surface')), 1);
    $darkSurface = substr(strtoupper(config('native-ui.theme.dark.surface')), 1);

    expect($bar['ref'] ?? null)->toBe('reply-bar')
        ->and($bar['props']['glass'] ?? null)->toBeNull()
        ->and(childRefs($screen, 'reply-content')[0])->toBe('reply-mention-slot');

    if ($platform === 'ios') {
        // The page runs on under the bar, which is glass, so it shows through blurred. The bar draws the fade at its
        // top, ending at the glass's tint, and its rows carry the tint. The bar itself has no background, which iOS
        // would paint behind the fade.
        expect($bottomBar['props']['glass'] ?? null)->toBe(1)
            ->and($bar['style']['bg_color'] ?? null)->toBeNull()
            ->and(nodeByRef($screen, 'reply-rows')['style']['bg_color'] ?? null)->toBe('#66'.$surface)
            ->and(nodeByRef($screen, 'reply-rows')['props']['dark_bg_color'] ?? null)->toBe('#66'.$darkSurface)
            ->and(nodeByRef($screen, 'reply-fade')['props']['gradient_stops'] ?? null)->toBe('#00'.$surface.',#66'.$surface)
            ->and(childRefs($screen, 'reply-bar'))->toBe(['reply-fade', 'reply-rows'])
            ->and(childRefs($screen, 'thread-stack'))->toBe(['thread-scroll']);
    } else {
        // No glass on Android. The page stops at the bar, which is the surface, and the fade lies over its foot.
        expect($bottomBar['props']['glass'] ?? null)->toBeNull()
            ->and($bar['style']['bg_color'] ?? null)->toBe(config('native-ui.theme.light.surface'))
            ->and(nodeByRef($screen, 'reply-rows')['style']['bg_color'] ?? null)->toBe(config('native-ui.theme.light.surface'))
            ->and(nodeByRef($screen, 'reply-rows')['props']['dark_bg_color'] ?? null)->toBe(config('native-ui.theme.dark.surface'))
            ->and(str_ends_with(nodeByRef($screen, 'reply-fade')['props']['gradient_stops'] ?? '', ',#'.$surface))->toBeTrue()
            ->and(childRefs($screen, 'reply-bar'))->toBe(['reply-rows'])
            ->and(childRefs($screen, 'thread-stack'))->toBe(['thread-scroll', 'reply-fade']);
    }
})->with(['ios', 'android']);

it('keeps the reply field closer to the fade than to the row of tools under it', function (string $platform) {
    fakeThread();

    $screen = Native::visit('/log/commits/c2', platform: $platform);

    // [top, right, bottom, left]. The row has no top padding, so the field starts 4pt under the fade, and the field
    // row leaves 8pt between the field and the tools.
    expect(nodeByRef($screen, 'reply-row')['layout']['padding'])->toEqual([0, 16, 8, 16])
        ->and(nodeByRef($screen, 'reply-field-row')['layout']['padding'])->toEqual([4, 12, 8, 16]);
})->with(['ios', 'android']);

it('fades the glass under the reply bar to 80% on iOS only', function (string $platform) {
    GlassOpacity::skipUnlessSupported();
    fakeThread();

    $screen = Native::visit('/log/commits/c2', platform: $platform);
    $bottomBar = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_bar');

    expect($bottomBar['props']['glass_opacity'] ?? null)->toBe($platform === 'ios' ? Display::ComposerGlassOpacity / 100 : null);
})->with(['ios', 'android']);

it('stands the reply bar on the scroll edge effect from iOS 26, with no fade or tint, and glass for older iOS', function () {
    fakeThread(replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'The last reply'])]);

    ScrollEdge::onIos('26.1', function (): void {
        $screen = Native::visit('/log/commits/c2', platform: 'ios');
        $bottomBar = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_bar');

        // The bar asks for the edge effect and keeps its glass for older iOS, which mobile-air leaves out here.
        // The field and tools sit in the glass box. The page ends where the bar starts, with no fade above it.
        expect($bottomBar['props']['edge_blur'] ?? null)->toBe(Display::ComposerEdgeBlur)
            ->and($bottomBar['props']['glass'] ?? null)->toBe(1)
            ->and(childRefs($screen, 'reply-bar'))->toBe(['reply-rows'])
            ->and(nodeByRef($screen, 'reply-rows')['style']['bg_color'] ?? null)->toBeNull()
            ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'reply-fade'))->toBeNull()
            ->and(nodeByRef($screen, 'reply-box')['props']['glass'] ?? null)->toBe(Display::ComposerInGlassBox ? 1 : null)
            ->and(nodeByRef($screen, 'reply-bar-clearance')['layout']['height'])->toEqual(24 + Display::composerBoxBarHeight() + 34);
    });
});

it('keeps the fade at the top of the reply bar before iOS 26', function () {
    fakeThread();

    ScrollEdge::onIos('18.5', function (): void {
        $screen = Native::visit('/log/commits/c2', platform: 'ios');

        expect(childRefs($screen, 'reply-bar'))->toBe(['reply-fade', 'reply-rows'])
            ->and(nodeByRef($screen, 'reply-box')['props']['glass'] ?? null)->toBeNull();
    });
});

it("keeps the reply bar's root full width and centres a readable column in it, for Android's full-width bar box", function (string $platform) {
    fakeThread();

    $screen = Native::visit('/log/commits/c2', platform: $platform);
    $bar = nodeByRef($screen, 'reply-bar');
    $rows = nodeByRef($screen, 'reply-rows');
    $content = nodeByRef($screen, 'reply-content');
    $variant = json_decode($content['props']['_variants'], true)[0];

    // The rows under the fade carry the tint full width, so they're what centres the readable column.
    expect($bar['props'])->not->toHaveKey('_variants')
        ->and($bar['layout']['width'] ?? null)->toBe('fill')
        ->and($rows['props'])->not->toHaveKey('_variants')
        ->and($rows['layout'])->toMatchArray(['width' => 'fill', 'align_items' => AlignItems::Center->value])
        ->and($variant)->toMatchArray(['min' => TailwindParser::breakpointMinWidth('expanded'), 'layout' => TailwindParser::parse(Display::ReadableColumn)['variants']['expanded']])
        ->and(childRefs($screen, 'reply-rows'))->toBe(['reply-content'])
        ->and(childRefs($screen, 'reply-content'))->toContain('reply-mention-slot', 'reply-row');
})->with(['ios', 'android']);

it('ends the page above the reply bar, and further as the draft grows', function () {
    fakeThread(replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'The last reply'])]);

    $screen = Native::visit('/log/commits/c2', platform: 'ios');
    $scroller = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'scroll_view');
    $page = $scroller['children'];

    // The clearance is the last thing on the page, under the last reply: the fade, the bar and the home indicator.
    $bar = 24 + Display::ComposerFadeHeight + Display::ComposerBoxBarHeight + 34;

    expect(end($page)['ref'] ?? null)->toBe('reply-bar-clearance')
        ->and(nodeByRef($screen, 'reply-bar-clearance')['layout']['height'])->toEqual($bar);

    $screen->input('reply-input', "Three lines\nof a\ndraft");

    expect(nodeByRef($screen, 'reply-bar-clearance')['layout']['height'])->toEqual($bar + 2 * 21);

    $screen->input('reply-input', str_repeat("A line\n", 12));

    expect(nodeByRef($screen, 'reply-bar-clearance')['layout']['height'])->toEqual($bar + 4 * 21);
});
