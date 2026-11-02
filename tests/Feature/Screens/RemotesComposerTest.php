<?php

use App\Devnull\DraftImages;
use App\NativeComponents\Conversation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

const ComposerViewerId = '0199e5c4-0000-7000-8000-000000000001';

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo('2026-10-19 15:30:00');
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/draft-images'));
});

/**
 * Devnull answering a thread with Ada: its first page, what a poll asks for, a push (which it echoes back, with an
 * image for each photo sent) and a ping.
 *
 * @param  list<array<string, mixed>>  $messages
 * @param  array<string, mixed>  $thread
 * @param  array<string, mixed>|null  $poll
 * @param  array{0: array<string, mixed>, 1: int}|null  $ping
 */
function serveThreadWithAda(array $messages, array $thread = [], ?array $poll = null, int $pushStatus = 201, ?array $ping = null): void
{
    serveApi(function (Request $request) use ($messages, $thread, $poll, $pushStatus, $ping) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);

        return match (true) {
            $request->method() === 'GET' && $path === '/api/v1/remotes/@ada/messages' => Http::response(str_contains($query, 'after=') ? ($poll ?? threadJson([])) : threadJson($messages, $thread)),
            $request->method() === 'POST' && $path === '/api/v1/remotes/@ada/messages' => $pushStatus >= 300
                ? Http::response(['message' => 'Nope.'], $pushStatus)
                : Http::response(['data' => echoedMessage($request)], $pushStatus),
            $request->method() === 'POST' && $path === '/api/v1/remotes/@ada/ping' => Http::response(...($ping ?? [['data' => remoteMessageJson(['id' => 50, 'user_id' => ComposerViewerId, 'body' => '', 'event' => 'ping'])], 201])),
            $path === '/api/v1/me' => Http::response(currentUserJson()),
            default => Http::response(['usage' => []], $request->method() === 'GET' ? 200 : 204),
        };
    });
}

/**
 * The message devnull stores for a push, with an image for each photo that came with it.
 *
 * @return array<string, mixed>
 */
function echoedMessage(Request $request): array
{
    $multipart = str_starts_with($request->header('Content-Type')[0] ?? '', 'multipart/');
    $parts = $multipart ? multipartParts($request) : [];
    $payload = json_decode($multipart ? $parts[0]['contents'] : $request->body(), true);
    $images = array_values(array_filter($parts, fn (array $part): bool => $part['name'] === 'images[]'));

    return remoteMessageJson([
        'id' => 99,
        'user_id' => ComposerViewerId,
        'client_id' => $payload['client_id'],
        'body' => $payload['body'],
        'images' => array_map(fn (int $index): array => ['id' => $index + 1, 'width' => 40, 'height' => 30, 'description' => null, 'url' => 'http://devnull.test/message-images/'.($index + 1).'?signature=abc'], array_keys($images)),
    ]);
}

/**
 * The multipart pushes of messages to Ada, as their parts.
 *
 * @return list<list<array{name: string, type: string, contents: string}>>
 */
function messageUploads(): array
{
    return collect(Http::recorded())
        ->map(fn (array $sent): Request => $sent[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/remotes/@ada/messages') && str_starts_with($request->header('Content-Type')[0] ?? '', 'multipart/'))
        ->map(fn (Request $request): array => multipartParts($request))
        ->values()
        ->all();
}

function threadWithAda(string $platform = 'ios'): TestableComponent
{
    return finishRefreshes(Native::visit('/remotes/conversations/ada', platform: $platform));
}

it('pushes photos with a message, showing them staged at once and pushed once devnull has them', function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1, 'body' => 'show me'])], ['read_through_id' => 1]);
    $paths = [testPhoto('jpg', 40, 30), testPhoto('png', 20, 20)];
    $contents = array_map(file_get_contents(...), $paths);

    $screen = threadWithAda()->tap('attach-photos');
    $screen->assertNativeCalled('Camera.PickMedia', fn (array $params): bool => $params['mediaType'] === 'images' && $params['multiple'] === true && $params['maxItems'] === 3);

    holdRefreshes();
    photosPicked($screen, $paths);
    $kept = array_map(fn ($photo): string => $photo->path, $screen->instance()->photos);

    // The field takes focus for a caption, and the keyboard brings the end of the thread above the taller bar.
    expect(nodeByRef($screen, 'message-input')['props']['autofocus'] ?? false)->toBeTrue()
        ->and(nodeByRef($screen, 'photo-1-alt')['props']['a11y_label'])->toBe('Add description to photo 2');

    $screen->input('message-input', 'the new logo');

    $screen->tap('push')->assertSee('the new logo')->assertSee('staged')->assertSet('photos', []);
    $clientId = $screen->instance()->pending[0]->clientId;

    // Drawn from the phone while they go.
    expect(nodeByRef($screen, 'image-'.$clientId.'-0')['props']['src'])->toBe($kept[0])
        ->and(nodeByRef($screen, 'image-'.$clientId.'-1')['props']['src'])->toBe($kept[1]);

    finishRefreshes($screen)->assertSee('pushed')->assertSet('pending', []);

    expect(messageUploads())->toBe([[
        ['name' => 'payload', 'type' => 'application/json', 'contents' => '{"body":"the new logo","client_id":"'.$clientId.'"}'],
        ['name' => 'images[]', 'type' => 'image/jpeg', 'contents' => $contents[0]],
        ['name' => 'images[]', 'type' => 'image/png', 'contents' => $contents[1]],
    ]])
        ->and(nodeByRef($screen, 'image-99-0')['props']['src'])->toBe('http://devnull.test/message-images/1?signature=abc')
        ->and(array_filter($kept, file_exists(...)))->toBe([]);
});

it('pushes photos alone, with the descriptions given them', function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]);

    $screen = threadWithAda();

    expect(nodeByRef($screen, 'push')['props']['disabled'] ?? false)->toBeTrue();

    photosPicked($screen, [testPhoto(), testPhoto()])->tap('photo-0-alt')->input('photo-description-input', 'A terminal.');

    expect(nodeByRef($screen, 'push')['props']['disabled'] ?? false)->toBeFalse();

    finishRefreshes($screen->tap('push'))->assertSet('pending', []);

    $payload = json_decode(messageUploads()[0][0]['contents'], true);

    expect($payload['body'])->toBe('')
        ->and($payload['image_descriptions'])->toBe(['A terminal.', null]);
});

it("keeps a refused message's photos on the phone, showing them, and sends them again with its retry", function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1])], ['read_through_id' => 1], pushStatus: 422);

    $screen = photosPicked(threadWithAda(), [testPhoto()]);
    $kept = $screen->instance()->photos[0]->path;

    finishRefreshes($screen->tap('push'))->assertSee('rejected ·');
    $clientId = $screen->instance()->pending[0]->clientId;

    // Back on the thread later, it's still there with its photo, which stays on the phone until it goes.
    $again = threadWithAda()->assertSee('rejected ·');

    expect(nodeByRef($again, 'image-'.$clientId.'-0')['props']['src'])->toBe($kept)
        ->and(file_exists($kept))->toBeTrue();

    serveThreadWithAda([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]);
    finishRefreshes($again->call('retry', $clientId))->assertSet('pending', []);

    $uploads = messageUploads();

    expect($uploads)->toHaveCount(2)
        ->and(json_decode($uploads[1][0]['contents'], true)['client_id'])->toBe($clientId)
        ->and($uploads[1][1]['name'])->toBe('images[]')
        ->and(file_exists($kept))->toBeFalse();
});

it("says why a photo won't do, in the web's words, and takes no more than three", function (Closure $photos, string $message, int $kept) {
    serveThreadWithAda([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]);

    $screen = photosPicked(threadWithAda(), $photos())
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === $message);

    expect($screen->instance()->photos)->toHaveCount($kept);
})->with([
    'a gif' => [fn (): array => [testPhoto('gif')], DraftImages::Unsupported, 0],
    'too many pixels' => [fn (): array => [testPhoto('png', 6001, 1)], DraftImages::TooLarge, 0],
    'more than three' => [fn (): array => [testPhoto(), testPhoto(), testPhoto(), testPhoto()], DraftImages::TooMany, 3],
]);

it('opens the library for the photos there is still room for, and greys the button out at three', function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]);

    $screen = photosPicked(threadWithAda(), [testPhoto()])->tap('attach-photos');
    $screen->assertNativeCalled('Camera.PickMedia', fn (array $params): bool => $params['maxItems'] === 2);

    photosPicked($screen, [testPhoto(), testPhoto()]);

    expect(nodeByRef($screen, 'attach-photos')['props']['disabled'] ?? false)->toBeTrue();
});

it('takes a photo off the draft and the phone with its ✕', function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]);

    $screen = photosPicked(threadWithAda(), [testPhoto(), testPhoto()]);
    $removed = $screen->instance()->photos[0]->path;

    $screen->tap('photo-0-remove');

    expect($screen->instance()->photos)->toHaveCount(1)
        ->and(file_exists($removed))->toBeFalse();
});

it('pads the end of the thread for the photos on the draft and an open description', function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]);

    $screen = threadWithAda();
    $clearance = fn (): mixed => nodeByRef($screen, 'composer-clearance')['layout']['height'] ?? null;

    photosPicked($screen, [testPhoto()]);

    expect($clearance())->toEqual(Conversation::ComposerBarHeight + Conversation::PhotosHeight + 8);

    $screen->tap('photo-0-alt');

    expect($clearance())->toEqual(Conversation::ComposerBarHeight + Conversation::PhotosHeight + Conversation::DescriptionHeight + 8);
});

it('keeps the keyboard up through presses in the thread, and the field in place as the reply strip and photos come and go', function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1, 'body' => 'did you see the PR?'])], ['read_through_id' => 1]);

    $screen = threadWithAda()->input('message-input', 'on it');
    $field = idsDownTo($screen->tree(), 'message-input');
    $rows = childRefs($screen, 'composer-bar');

    expect(nodeByRef($screen, 'message-input')['props']['keep_focus_on_submit'] ?? false)->toBeTrue();

    // Replying while you're typing leaves the field as it is, so the keyboard doesn't drop and come back.
    $screen->dragEnd('swipe-1', -200)->tap('reply-1')->assertSee('Replying to Ada Lovelace');

    // The strip fills a slot the bar always has, so the field's row keeps its place among the bar's rows, which
    // Android needs as well as the same id to keep the field.
    expect(idsDownTo($screen->tree(), 'message-input'))->toBe($field)
        ->and(childRefs($screen, 'composer-bar'))->toBe($rows);

    $screen->tap('cancel-reply');

    expect(idsDownTo($screen->tree(), 'message-input'))->toBe($field);

    // Attaching photos mounts it afresh to take focus once the library's sheet has gone; taking one off doesn't.
    photosPicked($screen, [testPhoto(), testPhoto()]);
    $withPhotos = idsDownTo($screen->tree(), 'message-input');

    $screen->tap('photo-0-remove');

    expect($withPhotos)->not->toBe($field)
        ->and(idsDownTo($screen->tree(), 'message-input'))->toBe($withPhotos)
        ->and(childRefs($screen, 'composer-bar'))->toBe($rows);
});

it('puts a + for photos, a voice note and Ping inside the Liquid Glass field on iOS', function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]);

    $screen = threadWithAda('ios');
    $bar = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_bar');

    expect(childRefs($screen, 'message-plate'))->toBe(['message-field', 'composer-tools'])
        ->and(array_slice(childRefs($screen, 'composer-tools'), 0, 3))->toBe(['attach-photos', 'record-voice-note', 'ping'])
        ->and(nodeByRef($screen, 'message-plate')['props']['glass'] ?? null)->toBe(1)
        ->and($bar['children'][0]['ref'] ?? null)->toBe('composer-bar')
        ->and($bar['children'][0]['style']['bg_color'] ?? null)->toBeNull()
        ->and(nodeByRef($screen, 'ping')['props']['label'])->toBe('Ping @ada');
});

it('leads the bar with the + on Android, on a solid bar', function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1])], ['read_through_id' => 1]);

    $screen = threadWithAda('android');

    expect(array_slice(childRefs($screen, 'message-row'), 0, 2))->toBe(['composer-tools', 'message-plate'])
        ->and(childRefs($screen, 'message-plate'))->toBe(['message-field'])
        ->and(nodeByRef($screen, 'message-plate')['props']['glass'] ?? null)->toBeNull()
        ->and(nodeByRef($screen, 'composer-bar')['style']['bg_color'] ?? null)->toBe(config('native-ui.theme.light.surface'));
});

it('pings Ada, logging it in the thread as the web does and buzzing the phone', function () {
    serveThreadWithAda([remoteMessageJson(['id' => 1, 'body' => 'you there?'])], ['read_through_id' => 1]);

    $screen = threadWithAda()->assertNativeNotCalled('Device.Vibrate');

    finishRefreshes($screen->tap('ping'))
        ->assertSee('* you sent a ping')
        ->assertNativeCalledTimes('Device.Vibrate', 1);

    expect(sentTo('POST', 'remotes/@ada/ping'))->toBe([''])
        ->and($screen->instance()->readThroughId)->toBe(50);
});

it("says why devnull won't take a ping, in its words", function (int $status, string $message) {
    serveThreadWithAda([], ping: [['message' => $message], $status]);

    finishRefreshes(threadWithAda()->tap('ping'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === $message)
        ->assertNativeNotCalled('Device.Vibrate')
        ->assertDontSee('sent a ping');
})->with([
    'before anyone has said anything' => [403, 'Say hi to @ada before pinging them.'],
    'a fourth in a minute' => [429, 'Three pings a minute, tops. Try again in a bit.'],
]);

it('buzzes for a ping that comes in while the thread is open, and not for one that was already there', function () {
    serveThreadWithAda(
        [remoteMessageJson(['id' => 1, 'body' => '', 'event' => 'ping'])],
        ['read_through_id' => 1],
        poll: threadJson([remoteMessageJson(['id' => 2, 'body' => '', 'event' => 'ping'])]),
    );

    // With one person devnull leaves the author off, as it can only be them.
    $screen = threadWithAda()->assertSee('* you got a ping')->assertNativeNotCalled('Device.Vibrate');

    finishRefreshes($screen->call('poll'))->assertNativeCalledTimes('Device.Vibrate', 1);
});

it("leaves someone else's ping still in a thread you've muted", function () {
    serveThreadWithAda(
        [remoteMessageJson(['id' => 1])],
        ['read_through_id' => 1, 'muted_until_at' => '2026-10-20T15:30:00Z'],
        poll: threadJson([remoteMessageJson(['id' => 2, 'body' => '', 'event' => 'ping'])], ['muted_until_at' => '2026-10-20T15:30:00Z']),
    );

    finishRefreshes(threadWithAda()->call('poll'))
        ->assertSee('* you got a ping')
        ->assertNativeNotCalled('Device.Vibrate');
});

it('pings everyone in an org, and shows who pinged it', function () {
    serveApi(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/v1/remotes/orgs/org-1/messages' => Http::response(['data' => [
                'org' => ['id' => 'org-1', 'name' => 'dev/null core'],
                'messages' => [remoteMessageJson(['id' => 2, 'body' => '', 'event' => 'ping', 'user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace'])])],
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
            $path === '/api/v1/remotes/orgs/org-1/ping' => Http::response(['data' => remoteMessageJson(['id' => 3, 'user_id' => ComposerViewerId, 'body' => '', 'event' => 'ping', 'user' => userJson()])], 201),
            default => Http::response(currentUserJson()),
        };
    });

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1'))->assertSee('* @ada sent a ping');

    expect(nodeByRef($screen, 'ping')['props']['label'])->toBe('Ping everyone in the org');

    finishRefreshes($screen->tap('ping'))->assertSee('* you sent a ping');

    expect(sentTo('POST', 'remotes/orgs/org-1/ping'))->toBe(['']);
});
