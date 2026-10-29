<?php

use App\Devnull\Account;
use App\Devnull\Stash;
use App\Models\PendingWrite;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

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
    expect(childRefs($screen, 'thread-page'))->toBe(['earlier-commits', 'commit-c1', 'focused-commit', 'reply-bar-clearance']);

    $screen->tap('earlier-commits')
        ->assertSee('The root commit')
        ->assertDontSee('1 earlier commit');

    expect(childRefs($screen, 'thread-page'))->toBe(['commit-c0', 'commit-c1', 'focused-commit', 'reply-bar-clearance']);

    $screen->tap('replies-c2')
        ->assertSee('First reply')
        ->assertSee('Hide replies');

    expect(childRefs($screen, 'thread-page'))->toBe(['commit-c0', 'commit-c1', 'focused-commit', 'thread-reply-r1', 'reply-bar-clearance']);

    $screen->tap('replies-c2')
        ->assertDontSee('First reply')
        ->assertSee('1 reply');
});

it('brings the commit back up under its parent when you tap into the reply bar', function () {
    fakeThread(['replies_count' => 1], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'author' => userJson(['handle' => 'grace', 'name' => 'Grace'])])]);

    $screen = Native::visit('/log/commits/c2')->tap('earlier-commits')->assertSee('The root commit');
    $before = nodeByRef($screen, 'thread-scroll')['id'];

    // EDGE can't scroll from PHP, so the older ancestors fold away and the scroll view mounts again at the top.
    $screen->pressUp('reply-field')
        ->assertSet('earlierShown', false)
        ->assertSet('scrollKey', 1)
        ->assertSee('1 earlier commit')
        ->assertSee('The parent commit')
        ->assertDontSee('The root commit');

    expect(childRefs($screen, 'thread-page'))->toBe(['earlier-commits', 'commit-c1', 'focused-commit', 'reply-bar-clearance'])
        ->and(nodeByRef($screen, 'thread-scroll')['id'])->not->toBe($before);
});

it('keeps the reply field the same view while the thread above it changes, so the keyboard stays up', function () {
    fakeThread(['replies_count' => 1], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'author' => userJson(['handle' => 'grace', 'name' => 'Grace'])])]);

    $screen = Native::visit('/log/commits/c2')->tap('earlier-commits')->input('reply-input', 'Nice');
    $field = idsDownTo($screen->tree(), 'reply-input');

    // Tapping into the bar, which folds the earlier commits away above it.
    $screen->pressUp('reply-field')->assertDontSee('The root commit');
    expect(idsDownTo($screen->tree(), 'reply-input'))->toBe($field);

    // More of the thread drawn above it.
    $screen->set('repliesShown', true)->assertSee('First reply');
    expect(idsDownTo($screen->tree(), 'reply-input'))->toBe($field);

    // A refresh failing, which puts its banner at the top of the screen.
    $screen->set('refreshFailed', true);
    expect(nodeByRef($screen, 'refresh-failed'))->not->toBeEmpty()
        ->and(idsDownTo($screen->tree(), 'reply-input'))->toBe($field);
});

it('puts the expand button inside the reply field on iOS, and ahead of it on Android', function (string $platform, array $row, array $plate) {
    fakeThread();

    $screen = Native::visit('/log/commits/c2', platform: $platform);

    expect(childRefs($screen, 'reply-row'))->toBe($row)
        ->and(childRefs($screen, 'reply-plate'))->toBe($plate);
})->with([
    'iOS' => ['ios', ['reply-plate', 'send-reply'], ['reply-field', 'reply-expand']],
    'Android' => ['android', ['reply-expand', 'reply-plate', 'send-reply'], ['reply-field']],
]);

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

it('shows the short hash under the focused commit, without the full date', function () {
    fakeThread(['created_at' => '2026-10-14T20:13:05Z']);

    $screen = Native::visit('/log/commits/c2')
        ->assertDontSee('Wed Oct 14 20:13:05 2026 +0000')
        ->assertDontSee('+0000');

    $hash = nodeByRef($screen, 'focused-hash');

    expect($hash['text'] ?? $hash['props']['text'] ?? null)->toBe('c2');
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

    expect(childRefs($screen, 'thread-page'))->toBe(['focused-commit', 'thread-reply-r1', "thread-reply-{$staged}", 'reply-bar-clearance'])
        ->and(PendingWrite::query()->count())->toBe(1);

    finishRefreshes($screen)
        ->assertSee('A fresh reply')
        ->assertSee('pending')
        ->assertDontSee('staged');

    expect(childRefs($screen, 'thread-page'))->toBe(['focused-commit', 'thread-reply-r1', 'thread-reply-r9', 'reply-bar-clearance'])
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

    expect(childRefs($screen, 'thread-page'))->toBe(['focused-commit', 'thread-reply-r1', 'thread-reply-r2', 'thread-reply-r9', 'reply-bar-clearance']);
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
        ->tap('send-reply')
        ->input('reply-input', str_repeat('a', 4000))
        ->assertElement('text', fn (array $node): bool => ($node['ref'] ?? null) === 'reply-budget'
            && ($node['text'] ?? $node['props']['text'] ?? null) === '0'
            && ($node['props']['color'] ?? null) === config('native-ui.theme.light.destructive'));

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('accepts one-character replies and URL-aware reply budgets', function (string $body) {
    fakeThread(extra: ['POST /api/v1/commits' => [['data' => replyJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => $body])], 201]]);

    $screen = Native::visit('/log/commits/c2')->input('reply-input', $body);
    expect(nodeByRef($screen, 'send-reply')['props']['disabled'] ?? false)->toBeFalse()
        ->and(nodeByRef($screen, 'reply-input')['props']['max_length'])->toBe(40000);

    $screen->tap('send-reply')->assertSet('reply', '');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['body'] === $body);
})->with(['a', str_repeat('a', 3976).' https://example.com/'.str_repeat('x', 100)]);

it('explains an oversized reply URL before it is submitted', function () {
    fakeThread();
    $screen = Native::visit('/log/commits/c2')->input('reply-input', 'https://example.com/'.str_repeat('x', 2030))
        ->assertSee('Each link must not be greater than 2048 characters.');
    expect(nodeByRef($screen, 'send-reply')['props']['disabled'])->toBeTrue();
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
    fakeThread(replies: [replyJson(['id' => 'r1'])]);

    Native::visit('/log/commits/c2')->tap('earlier-commits')->tap('commit-c1')->assertNavigatedTo('/log/commits/c1');
    Native::visit('/log/commits/c2')->tap('replies-c2')->tap('thread-reply-r1')->assertNavigatedTo('/log/commits/r1');
    Native::visit('/log/commits/c2')->call('openProfile', 'ada')->assertNavigatedTo('/log/users/ada');
});

it('opens with its replies showing from a replies button', function () {
    fakeThread(['replies_count' => 1], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'replies_count' => 1])], extra: [
        'GET /api/v1/commits/r1' => [commitPageJson(replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply', 'replies_count' => 1]))],
        'GET /api/v1/commits/r1/replies' => [pageJson([replyJson(['id' => 'r2', 'parent_id' => 'r1', 'body' => 'Answering the reply'])])],
    ]);

    Native::visit('/log/commits/c2', ['replies' => true])
        ->assertSee('First reply')
        ->assertSee('Hide replies');

    // A reply's replies don't open in place on a commit page, so its button opens its page with them showing.
    Native::visit('/log/commits/c2')
        ->tap('replies-c2')
        ->tap('replies-r1')
        ->assertNavigatedTo('/log/commits/r1')
        ->follow()
        ->assertSee('Answering the reply')
        ->assertSee('Hide replies');
});

it('asks for the replies five at a time, and the next five from Show more', function () {
    $replies = fn (int ...$numbers): array => array_map(fn (int $n): array => replyJson(['id' => "r{$n}", 'parent_id' => 'c2', 'body' => "Reply {$n}"]), $numbers);

    Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/v1/commits/c2/replies' => Http::response(str_contains($request->url(), 'cursor=page2')
            ? pageJson($replies(6))
            : pageJson($replies(1, 2, 3, 4, 5), 'page2', 'http://devnull.test/api/v1/commits/c2/replies')),
        '/api/v1/commits/c2/related' => Http::response(['data' => []]),
        default => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'replies_count' => 6]))),
    });

    $screen = Native::visit('/log/commits/c2', ['replies' => true])
        ->assertSee('Reply 5')
        ->assertDontSee('Reply 6')
        ->tap('more-replies-c2')
        ->assertSee('Reply 6')
        ->assertDontSee('Show more');

    $asked = collect(Http::recorded())->map(fn (array $sent): string => $sent[0]->url())->filter(fn (string $url): bool => str_contains($url, '/replies'));

    expect($asked)->toHaveCount(2)
        ->and($asked->every(fn (string $url): bool => str_contains($url, 'limit=5')))->toBeTrue();

    // Asking again for the first five keeps the one loaded after them.
    $screen->call('load')
        ->assertSee('Reply 6')
        ->assertDontSee('Show more');
});

it('opens the replies of a deleted reply five at a time too', function () {
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

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/commits/r1/replies?cursor=page2&limit=5');
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

    $screen->tap('thread-reply-r3')->assertNavigatedTo('/log/commits/r3');

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

it('reloads when you come back from a reply you deleted', function () {
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

    $reply = Native::visit('/log/commits/c2')
        ->tap('replies-c2')
        ->assertSee('Soon gone')
        ->tap('thread-reply-r1')
        ->follow()
        ->call('deleteCommit', 'r1')
        ->assertWentBack();

    $reply->goBack()
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
    fakeThread(['author' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace'])], replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2'])], extra: [
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

it('paints the reply bar solid on Android, with its hairline, so the replies never show through', function () {
    fakeThread();

    $screen = Native::visit('/log/commits/c2', platform: 'android');
    $bar = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_bar');

    expect($bar['children'][0]['ref'] ?? null)->toBe('reply-bar')
        ->and($bar['children'][0]['style']['bg_color'] ?? null)->toBe(config('native-ui.theme.light.surface'))
        ->and($bar['children'][0]['props']['dark_bg_color'] ?? null)->toBe(config('native-ui.theme.dark.surface'))
        ->and($bar['children'][0]['children'][0]['style']['bg_color'] ?? null)->toBe(config('native-ui.theme.light.hairline'))
        ->and(nodeByRef($screen, 'reply-plate')['props']['glass'] ?? null)->toBeNull();
});

it('leaves the reply bar bare on iOS and floats the field on Liquid Glass', function () {
    fakeThread();

    $screen = Native::visit('/log/commits/c2', platform: 'ios');
    $bar = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_bar');

    expect($bar['children'][0]['ref'] ?? null)->toBe('reply-bar')
        ->and($bar['children'][0]['style']['bg_color'] ?? null)->toBeNull()
        ->and($bar['children'][0]['children'][0]['style']['bg_color'] ?? null)->toBeNull()
        ->and(nodeByRef($screen, 'reply-plate')['props']['glass'] ?? null)->toBe(1);
});

it('ends the page above the reply bar, and further as the draft grows', function () {
    fakeThread(replies: [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'The last reply'])]);

    $screen = Native::visit('/log/commits/c2', platform: 'ios');
    $scroller = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'scroll_view');
    $page = $scroller['children'][0]['children'];

    // The clearance is the last thing on the page, under the last reply: the bar's line and the home indicator.
    expect(end($page)['ref'] ?? null)->toBe('reply-bar-clearance')
        ->and(nodeByRef($screen, 'reply-bar-clearance')['layout']['height'])->toEqual(24 + 53 + 34);

    $screen->input('reply-input', "Three lines\nof a\ndraft");

    expect(nodeByRef($screen, 'reply-bar-clearance')['layout']['height'])->toEqual(24 + 53 + 2 * 21 + 34);

    $screen->input('reply-input', str_repeat("A line\n", 12));

    expect(nodeByRef($screen, 'reply-bar-clearance')['layout']['height'])->toEqual(24 + 53 + 4 * 21 + 34);
});
