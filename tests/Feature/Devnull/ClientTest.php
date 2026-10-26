<?php

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\CurrentUser;
use App\Devnull\Data\DraftImage;
use App\Devnull\Data\PollDraft;
use App\Devnull\Data\User;
use App\Devnull\DeviceKey;
use App\Devnull\ErrorCode;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\Devnull\ReportReason;
use App\Devnull\RequestSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    fakeKeychain(testSeed());
    $this->travelTo(now()->setTimestamp(1760000000));
});

/**
 * The message the server rebuilds from a request it received.
 */
function receivedMessage(Request $request): string
{
    $uri = $request->toPsrRequest()->getUri();
    $target = $uri->getPath().($uri->getQuery() === '' ? '' : '?'.$uri->getQuery());

    return RequestSigner::message(
        $request->header('X-Devnull-Timestamp')[0],
        $request->header('X-Devnull-Nonce')[0],
        $request->method(),
        $target,
        $request->body(),
    );
}

it('signs a GET with the device key, the query string as sent and an empty body', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson()], 'next+page/1='))]);

    $page = app(Client::class)->log('latest', 'abc def=');

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0])->toBeInstanceOf(Commit::class)
        ->and($page->nextCursor)->toBe('next+page/1=');

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe('http://devnull.test/api/v1/log/latest?cursor=abc%20def%3D')
            ->and($request->method())->toBe('GET')
            ->and($request->body())->toBe('')
            ->and($request->hasHeader('Content-Type'))->toBeFalse()
            ->and($request->header('Accept'))->toBe(['application/json'])
            ->and($request->header('X-Devnull-Timestamp'))->toBe(['1760000000'])
            ->and($request->header('X-Devnull-Nonce')[0])->toMatch('/^[0-9a-f]{32}$/')
            ->and(receivedMessage($request))->toStartWith("1760000000\n")
            ->toEndWith("\nGET\n/api/v1/log/latest?cursor=abc%20def%3D\n")
            ->and($request->header('X-SSH-Signature')[0])->toBe(testKey()->sign(receivedMessage($request), 'devnull-api'));

        return true;
    });
});

it('leaves the cursor off the first page', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    app(Client::class)->notifications();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/notifications');
});

it('asks for a profile\'s replies with ?replies=1 and signs it', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    app(Client::class)->userCommits('ada', 'replies', 'older');
    app(Client::class)->userCommits('ada');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/users/ada/commits?replies=1&cursor=older'
        && str_ends_with(receivedMessage($request), "\nGET\n/api/v1/users/ada/commits?replies=1&cursor=older\n"));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/users/ada/commits');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tab='));
});

it('sends the JSON body as the exact bytes it signed', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => commitJson(['status' => 'pending'])], 201)]);

    $commit = app(Client::class)->createCommit('ship it / "now" — ü', 'parent-1');

    expect($commit->isPending())->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        expect($request->body())->toBe('{"body":"ship it / \"now\" — ü","parent_id":"parent-1"}')
            ->and($request->header('Content-Type'))->toBe(['application/json'])
            ->and(receivedMessage($request))->toEndWith("\nPOST\n/api/v1/commits\n".'{"body":"ship it / \"now\" — ü","parent_id":"parent-1"}')
            ->and($request->header('X-SSH-Signature')[0])->toBe(testKey()->sign(receivedMessage($request), 'devnull-api'));

        return true;
    });
});

it('quotes a commit with its id next to the body, and no parent', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => commitJson(['id' => 'q1', 'quoted_commit_id' => 'c1'])], 201)]);

    $commit = app(Client::class)->createCommit('This, all of it.', quotedCommitId: 'c1');

    expect($commit->quotedCommitId)->toBe('c1');

    Http::assertSent(fn (Request $request): bool => $request->body() === '{"body":"This, all of it.","quoted_commit_id":"c1"}'
        && str_ends_with(receivedMessage($request), "\nPOST\n/api/v1/commits\n".'{"body":"This, all of it.","quoted_commit_id":"c1"}'));
});

it('starts a poll with a commit, its options trimmed and its length in minutes', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => commitJson(['id' => 'p1'])], 201)]);

    app(Client::class)->createCommit('Tabs or spaces?', poll: PollDraft::payload(['Tabs', ' Spaces '], '3 days'));

    Http::assertSent(fn (Request $request): bool => $request->body() === '{"body":"Tabs or spaces?","poll":{"options":["Tabs","Spaces"],"duration":4320}}'
        && $request->header('X-SSH-Signature')[0] === testKey()->sign(receivedMessage($request), 'devnull-api'));
});

it('signs a commit with images as multipart, with their SHA-256s on a line before the JSON body', function () {
    $first = new DraftImage(testPhoto('jpg'), 'image/jpeg', 40, 30, '  A cabin at sunrise.  ');
    $second = new DraftImage(testPhoto('png'), 'image/png', 40, 30);

    $request = app(Client::class)->signedCommitWithImages('Offsite photo dump', [$first, $second], quotedCommitId: 'c1');

    expect($request->url)->toBe('http://devnull.test/api/v1/commits')
        ->and($request->method)->toBe('POST')
        ->and($request->contentType)->toMatch('/^multipart\/form-data; boundary=devnull-[0-9a-f]{32}$/');

    $boundary = substr($request->contentType, strlen('multipart/form-data; boundary='));
    $parts = explode("--{$boundary}", $request->body);
    $payload = '{"body":"Offsite photo dump","quoted_commit_id":"c1","image_descriptions":["A cabin at sunrise.",null]}';

    expect(array_slice($parts, 1))->toBe([
        "\r\nContent-Disposition: form-data; name=\"payload\"; filename=\"payload.json\"\r\nContent-Type: application/json\r\n\r\n{$payload}\r\n",
        "\r\nContent-Disposition: form-data; name=\"images[]\"; filename=\"image-1.jpeg\"\r\nContent-Type: image/jpeg\r\n\r\n".file_get_contents($first->path)."\r\n",
        "\r\nContent-Disposition: form-data; name=\"images[]\"; filename=\"image-2.png\"\r\nContent-Type: image/png\r\n\r\n".file_get_contents($second->path)."\r\n",
        "--\r\n",
    ]);

    $message = RequestSigner::message(
        $request->headers['X-Devnull-Timestamp'],
        $request->headers['X-Devnull-Nonce'],
        'POST',
        '/api/v1/commits',
        hash_file('sha256', $first->path).','.hash_file('sha256', $second->path)."\n".$payload,
    );

    expect($request->headers['X-Devnull-Timestamp'])->toBe('1760000000')
        ->and($request->headers['X-SSH-Signature'])->toBe(testKey()->sign($message, 'devnull-api-images'));
});

it('sends a signed request with the content type it was signed with', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => commitJson()], 201)]);

    $request = app(Client::class)->signedCommitWithImages('Look', [new DraftImage(testPhoto(), 'image/png', 40, 30)], parentId: 'c1');

    expect(Client::sendSigned($request)->status())->toBe(201);

    Http::assertSent(fn (Request $request2): bool => $request2->header('Content-Type') === [$request->contentType]
        && $request2->body() === $request->body
        && $request2->header('X-SSH-Signature') === [$request->headers['X-SSH-Signature']]);
});

it('uses a fresh nonce for every request', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => userJson()])]);

    app(Client::class)->me();
    app(Client::class)->me();

    $nonces = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('X-Devnull-Nonce')[0]);

    expect($nonces->unique())->toHaveCount(2);
});

it('signs bodiless deletes and posts', function (string $call, string $method, string $target) {
    Http::fake(['devnull.test/*' => Http::response(null, 204)]);

    app(Client::class)->{$call}('local_dev');

    Http::assertSent(function (Request $request) use ($method, $target): bool {
        expect($request->method())->toBe($method)
            ->and($request->body())->toBe('')
            ->and($request->hasHeader('Content-Type'))->toBeFalse()
            ->and(receivedMessage($request))->toEndWith("\n{$method}\n{$target}\n");

        return true;
    });
})->with([
    ['follow', 'POST', '/api/v1/users/local_dev/follow'],
    ['unfollow', 'DELETE', '/api/v1/users/local_dev/follow'],
    ['deleteCommit', 'DELETE', '/api/v1/commits/local_dev'],
]);

it('pins and unpins your commit with a signed bodiless PUT and DELETE, reading is_pinned', function (string $call, string $method, bool $pinned) {
    Http::fake(['devnull.test/*' => Http::response(['data' => commitJson(['id' => 'c1', 'is_pinned' => $pinned])])]);

    $commit = app(Client::class)->{$call}('c1');

    expect($commit->isPinned)->toBe($pinned);

    Http::assertSent(function (Request $request) use ($method): bool {
        expect($request->method())->toBe($method)
            ->and($request->body())->toBe('')
            ->and(receivedMessage($request))->toEndWith("\n{$method}\n/api/v1/commits/c1/pin\n");

        return true;
    });
})->with([
    ['pin', 'PUT', true],
    ['unpin', 'DELETE', false],
]);

it('signs the path prefix when devnull lives under one', function () {
    config(['devnull.url' => 'https://example.test/devnull/']);
    Http::fake(['example.test/*' => Http::response(['data' => userJson()])]);

    app(Client::class)->me();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.test/devnull/api/v1/me'
        && str_contains(receivedMessage($request), "\nGET\n/devnull/api/v1/me\n"));
});

it('maps a 401 to Unauthenticated with the server message', function () {
    Http::fake(['devnull.test/*' => Http::response(['message' => 'This key is not on any devnull account.'], 401)]);

    expect(fn () => app(Client::class)->me())->toThrow(Unauthenticated::class, 'This key is not on any devnull account.');
});

it('tells a phone that only needs approving by the code devnull sends', function (?string $code, bool $needsApproval) {
    Http::fake(['devnull.test/*' => Http::response(array_filter(['message' => 'Not today.', 'code' => $code]), 401)]);

    try {
        app(Client::class)->me();
        $this->fail('Expected Unauthenticated.');
    } catch (Unauthenticated $exception) {
        expect($exception->needsApproval())->toBe($needsApproval)
            ->and($exception->errorCode)->toBe($code);
    }
})->with([
    'not approved' => ['device_not_approved', true],
    'key unknown' => ['key_not_on_github', false],
    'a bad signature' => ['invalid_signature', false],
    'no code at all' => [null, false],
]);

it('keeps the server message and code for other errors', function () {
    Http::fake(['devnull.test/*' => Http::response(['message' => 'No such commit.', 'code' => 'not_found'], 404)]);

    try {
        app(Client::class)->commit('c404');
        $this->fail('Expected an ApiException.');
    } catch (ApiException $exception) {
        expect($exception)->not->toBeInstanceOf(Unauthenticated::class)
            ->and($exception->status)->toBe(404)
            ->and($exception->errorCode)->toBe('not_found')
            ->and($exception->getMessage())->toBe('No such commit.');
    }
});

it('redeems a sign-in code with the verifier, signed, for the user', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => userWithCountsJson()])]);

    $user = app(Client::class)->redeemSignIn('the-code', 'the-verifier');

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->username)->toBe('local_dev');

    Http::assertSent(function (Request $request): bool {
        $body = '{"code":"the-code","code_verifier":"the-verifier"}';

        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('http://devnull.test/api/v1/'.Client::SignInPath)
            ->and($request->body())->toBe($body)
            ->and($request->hasHeader('Authorization'))->toBeFalse()
            ->and(receivedMessage($request))->toEndWith("\nPOST\n/api/v1/devices\n".$body)
            ->and($request->header('X-SSH-Signature')[0])->toBe(testKey()->sign(receivedMessage($request), 'devnull-api'));

        return true;
    });
});

it('reads how long a 429 asks to wait', function (string $retryAfter, ?int $seconds) {
    Http::fake(['devnull.test/*' => Http::response(['message' => 'Too Many Attempts.'], 429, $retryAfter === '' ? [] : ['Retry-After' => $retryAfter])]);

    try {
        app(Client::class)->me();
        $this->fail('Expected an ApiException.');
    } catch (ApiException $exception) {
        expect($exception->status)->toBe(429)
            ->and($exception->getMessage())->toBe('Too Many Attempts.')
            ->and($exception->retryAfter)->toBe($seconds);
    }
})->with([
    'in seconds' => ['42', 42],
    'as a date' => ['Thu, 09 Oct 2025 09:00:20 GMT', 420],
    'not at all' => ['', null],
]);

it('carries validation messages from a 422', function () {
    Http::fake(['devnull.test/*' => Http::response(['message' => 'The body field must be at least 3 characters.', 'errors' => ['body' => ['The body field must be at least 3 characters.']]], 422)]);

    try {
        app(Client::class)->createCommit('hi');
        $this->fail('Expected an ApiException.');
    } catch (ApiException $exception) {
        expect($exception->status)->toBe(422)
            ->and($exception->errors)->toBe(['body' => ['The body field must be at least 3 characters.']])
            ->and($exception->firstError())->toBe('The body field must be at least 3 characters.');
    }
});

it('speaks git when the server says nothing useful', function () {
    Http::fake(['devnull.test/*' => Http::response('<html>Bad Gateway</html>', 502)]);

    expect(fn () => app(Client::class)->me())->toThrow(ApiException::class, 'fatal: the remote end hung up unexpectedly (HTTP 502)');
});

it('reports an unreachable server', function () {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    expect(fn () => app(Client::class)->me())->toThrow(ApiException::class, "fatal: unable to access 'http://devnull.test': could not connect to the server");
});

it('makes the device key on first use and keeps it in the Keychain', function () {
    $bridge = fakeKeychain();
    Http::fake(['devnull.test/*' => Http::response(['data' => userJson()])]);

    app(Client::class)->me();
    $key = app(DeviceKey::class)->current();

    $bridge->assertCalledTimes('SecureStorage.Set', 1);
    $bridge->assertCalled('SecureStorage.Set', fn (array $params): bool => $params['key'] === DeviceKey::StorageKey
        && base64_decode($params['value']) === $key->seed());
});

it('reads the device key from the Keychain once, however many requests it signs', function () {
    $bridge = fakeKeychain(testSeed());
    Http::fake(['devnull.test/*' => Http::response(['data' => userJson()])]);

    app(Client::class)->me();
    app(Client::class)->me();
    app(Client::class)->profile('ada');

    // Android's Keystore takes about 150ms a read, so a screen's requests share one.
    $bridge->assertCalledTimes('SecureStorage.Get', 1);

    // Forgetting the key drops it too, so the next request makes and keeps a new one.
    app(DeviceKey::class)->forget();
    $fresh = app(DeviceKey::class)->current();

    expect($fresh->openSsh())->not->toBe(testKey()->openSsh());
    $bridge->assertCalledTimes('SecureStorage.Get', 2)->assertCalledTimes('SecureStorage.Set', 1);
});

it('never replaces a key it cannot read', function () {
    $bridge = Native::fakeBridge()->respondTo('SecureStorage.Get', ['status' => 'unavailable', 'code' => 'UNAVAILABLE', 'message' => 'Device is locked.']);

    expect(fn () => app(DeviceKey::class)->current())->toThrow(KeychainUnavailable::class);

    $bridge->assertNotCalled('SecureStorage.Set');
});

it('reads commits, threads, profiles and notifications into data objects', function () {
    Http::fake([
        'devnull.test/api/v1/commits/c1' => Http::response(commitPageJson(
            commitJson(['id' => 'c1', 'parent_id' => 'p1', 'author' => ['id' => 'u9', 'handle' => 'ada', 'name' => null]]),
            [commitJson(['id' => 'p1'])],
        )),
        'devnull.test/api/v1/users/ada' => Http::response([
            'data' => userWithCountsJson(['handle' => 'ada', 'lane' => 4], commits: 3, followers: 5, following: 1),
            'is_following' => true,
            'activity' => ['scale' => 'day', 'buckets' => [['date' => '2026-10-15T09:00:00Z', 'count' => 2]]],
            'streaks' => ['longest' => 4, 'current' => 1],
            'pinned_commit' => commitJson(['id' => 'p1', 'is_pinned' => true]),
        ]),
        'devnull.test/api/v1/notifications' => Http::response(pageJson([
            notificationJson('replied', ['commit' => notificationCommitJson(['id' => 'c2', 'parent_id' => 'c-yours', 'body' => 'Nice.', 'parent' => notificationCommitJson(['id' => 'c-yours', 'body' => 'Shipped.'], withParent: false)])]),
            notificationJson('followed', ['read_at' => now()->toIso8601ZuluString(), 'unread_count' => 0]),
        ], path: 'http://devnull.test/api/v1/notifications')),
    ]);

    $thread = app(Client::class)->commit('c1');
    $profile = app(Client::class)->profile('ada');
    $notifications = app(Client::class)->notifications();

    expect($thread->commit->id)->toBe('c1')
        ->and($thread->commit->author->id)->toBe('u9')
        ->and($thread->commit->author->name)->toBe('ada')
        ->and($thread->ancestors[0]->id)->toBe('p1')
        ->and($profile->user->username)->toBe('ada')
        ->and($profile->commitsCount)->toBe(3)
        ->and($profile->followersCount)->toBe(5)
        ->and($profile->isFollowing)->toBeTrue()
        ->and($profile->user->lane())->toBe(4)
        ->and($profile->activity->scale)->toBe('day')
        ->and($profile->activity->total())->toBe(2)
        ->and($profile->streaks->longest)->toBe(4)
        ->and($profile->streaks->current)->toBe(1)
        ->and($profile->pinnedCommit?->id)->toBe('p1')
        ->and($profile->pinnedCommit?->isPinned)->toBeTrue()
        ->and($notifications->items[0]->isReply())->toBeTrue()
        ->and($notifications->items[0]->isUnread)->toBeTrue()
        ->and($notifications->items[0]->parentBody)->toBe('Shipped.')
        ->and($notifications->items[1]->isReply())->toBeFalse()
        ->and($notifications->items[1]->isUnread)->toBeFalse();
});

it('tolerates missing optional fields', function () {
    $commit = Commit::fromArray(['id' => 'c1', 'body' => 'Hello', 'author' => ['handle' => 'ada']]);

    expect($commit->status)->toBe('approved')
        ->and($commit->createdAt)->toBeNull()
        ->and($commit->repliesCount)->toBe(0)
        ->and($commit->htmlUrl)->toBeNull()
        ->and($commit->shortHash)->toBe('c1')
        ->and($commit->author->avatarUrl)->toBeNull()
        ->and($commit->author->id)->toBe('ada')
        ->and($commit->author->htmlUrl)->toBeNull()
        ->and($commit->author->githubUrl)->toBeNull()
        ->and($commit->author->createdAt)->toBeNull()
        ->and($commit->isDeleted())->toBeFalse()
        ->and($commit->starsCount)->toBe(0)
        ->and($commit->starred)->toBeFalse()
        ->and(property_exists($commit, 'isSaved'))->toBeFalse()
        ->and($commit->isPinned)->toBeFalse()
        ->and($commit->mentions)->toBe([])
        ->and($commit->linkPreview)->toBeNull()
        ->and($commit->images)->toBe([])
        ->and($commit->parent)->toBeNull()
        ->and($commit->content)->toHaveCount(1);
});

it('reads a tombstone without a body or author', function () {
    $commit = Commit::fromArray(tombstoneJson(['id' => 'gone', 'parent_id' => 'p1', 'replies_count' => 2]));

    expect($commit->isDeleted())->toBeTrue()
        ->and($commit->id)->toBe('gone')
        ->and($commit->parentId)->toBe('p1')
        ->and($commit->repliesCount)->toBe(2)
        ->and($commit->body)->toBe('')
        ->and($commit->content)->toBe([])
        ->and($commit->lane())->toBe('rail');
});

it('reads what the web shows on a commit', function () {
    $commit = Commit::fromArray(commitJson([
        'stars_count' => 2,
        'starred' => true,
        'is_pinned' => true,
        'link_preview' => ['url' => 'https://www.laravel.com/docs', 'title' => 'Docs', 'description' => '', 'image_url' => null, 'site_name' => null],
        'images' => [['id' => 'i1', 'url' => 'https://img.test/1', 'width' => 1080, 'height' => 2400], ['url' => null]],
        'parent' => tombstoneJson(),
    ]));

    expect($commit->starsCount)->toBe(2)
        ->and($commit->starred)->toBeTrue()
        ->and($commit->isPinned)->toBeTrue()
        ->and($commit->linkPreview?->site())->toBe('laravel.com')
        ->and($commit->linkPreview?->description)->toBeNull()
        ->and($commit->images)->toHaveCount(1)
        // A tall screenshot is held at 3:4, as the web caps an image's height.
        ->and($commit->images[0]->ratio())->toBe(0.75)
        ->and($commit->parent?->isDeleted())->toBeTrue();
});

it('reads a commit\'s author, and its parent\'s, as full users', function () {
    $ada = userJson([
        'id' => 'u2',
        'handle' => 'ada',
        'name' => 'Ada Lovelace',
        'bio' => null,
        'company' => 'Analytical Engines',
        'location' => 'London',
        'website' => 'https://ada.example',
        'github_url' => 'https://github.com/ada',
        'html_url' => 'https://dev-null.sh/@ada',
        'feed_url' => 'https://dev-null.sh/@ada/feed.atom',
        'created_at' => '2026-09-01T08:00:00Z',
        'lane' => 4,
    ]);

    $commit = Commit::fromArray(commitJson([
        'id' => '0199e5c4-1111-7000-8000-00000000c0de',
        'short_hash' => 'abc1234',
        'html_url' => 'https://dev-null.sh/@local_dev/commits/0199e5c4-1111-7000-8000-00000000c0de',
        'parent_id' => 'p1',
        'parent' => commitJson(['id' => 'p1', 'author' => $ada]),
    ]));

    expect($commit->author->username)->toBe('local_dev')
        ->and($commit->author->name)->toBe('Local Dev')
        ->and($commit->author->lane())->toBe(3)
        ->and($commit->author->githubUrl)->toBe('https://github.com/local_dev')
        // The API's own short hash and link, not ones worked out here.
        ->and($commit->shortHash)->toBe('abc1234')
        ->and($commit->htmlUrl)->toBe('https://dev-null.sh/@local_dev/commits/0199e5c4-1111-7000-8000-00000000c0de')
        ->and($commit->parent?->author->id)->toBe('u2')
        ->and($commit->parent?->author->username)->toBe('ada')
        ->and($commit->parent?->author->bio)->toBeNull()
        ->and($commit->parent?->author->company)->toBe('Analytical Engines')
        ->and($commit->parent?->author->location)->toBe('London')
        ->and($commit->parent?->author->website)->toBe('https://ada.example')
        ->and($commit->parent?->author->githubUrl)->toBe('https://github.com/ada')
        ->and($commit->parent?->author->htmlUrl)->toBe('https://dev-null.sh/@ada')
        ->and($commit->parent?->author->feedUrl)->toBe('https://dev-null.sh/@ada/feed.atom')
        ->and($commit->parent?->author->createdAt?->toIso8601ZuluString())->toBe('2026-09-01T08:00:00Z')
        ->and($commit->parent?->author->lane())->toBe(4);
});

it('keeps you between launches with everything devnull said about you', function () {
    $user = User::fromArray(userJson(['company' => 'devnull', 'website' => 'https://local.dev']));

    expect(User::fromArray($user->toArray()))->toEqual($user);
});

it('reads who you are and how many notifications are unread', function () {
    Http::fake(['devnull.test/api/v1/me' => Http::response(['data' => userWithCountsJson(['lane' => 2]), 'unread_notifications_count' => 7])]);

    $current = app(Client::class)->me();

    expect($current->user->username)->toBe('local_dev')
        ->and($current->user->lane())->toBe(2)
        ->and($current->unreadNotificationsCount)->toBe(7);
});

it('reads what only you see in your profile settings from /me', function () {
    Http::fake(['devnull.test/api/v1/me' => Http::response(currentUserJson([
        'lane' => 1,
        'preferred_lane' => 1,
        'automatic_lane' => 4,
        'sponsors_url' => 'https://github.com/sponsors/local_dev',
        'github_synced_at' => '2026-10-01T09:30:00Z',
    ]))]);

    $current = app(Client::class)->me();

    expect($current->preferredLane)->toBe(1)
        ->and($current->automaticLane)->toBe(4)
        ->and($current->sponsorsUrl)->toBe('https://github.com/sponsors/local_dev')
        ->and($current->sponsorsLabel())->toBe('github.com/sponsors/local_dev')
        ->and($current->githubSyncedAt?->toIso8601ZuluString())->toBe('2026-10-01T09:30:00Z');
});

it('reads Automatic as no lane, and works it out when devnull is too old to say', function (array $data, ?int $preferred, int $automatic) {
    $current = CurrentUser::fromArray(['data' => [...userJson(['id' => '3a788e93-4cf7-4819-9377-94be2f329563']), ...$data]]);

    expect($current->preferredLane)->toBe($preferred)
        ->and($current->automaticLane)->toBe($automatic)
        ->and($current->sponsorsUrl)->toBeNull()
        ->and($current->githubSyncedAt)->toBeNull();
})->with([
    'Automatic' => [['lane' => 4, 'preferred_lane' => null, 'automatic_lane' => 4], null, 4],
    'an older server, on the automatic lane' => [['lane' => 4], null, 4],
    'an older server, on a lane you picked' => [['lane' => 1], 1, 4],
    'a lane out of range' => [['lane' => 4, 'preferred_lane' => 9, 'automatic_lane' => 12], null, 4],
    'a sponsors link that is not https' => [['lane' => 4, 'sponsors_url' => 'javascript:alert(1)'], null, 4],
]);

it('reads whether an Apple ID is linked from /me, and says no when devnull is silent', function (array $extra, bool $linked) {
    Http::fake(['devnull.test/api/v1/me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0, ...$extra])]);

    expect(app(Client::class)->me()->appleLinked)->toBe($linked);
})->with([
    'linked' => [['apple_linked' => true], true],
    'not linked' => [['apple_linked' => false], false],
    'an older server' => [[], false],
    'anything but true' => [['apple_linked' => 1], false],
]);

it('links an Apple ID with a signed PUT of the token and the raw nonce', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => userWithCountsJson(), 'apple_linked' => true])]);

    expect(app(Client::class)->linkApple('the.identity.token', 'raw-nonce'))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        $body = '{"identity_token":"the.identity.token","nonce":"raw-nonce"}';

        expect($request->method())->toBe('PUT')
            ->and($request->url())->toBe('http://devnull.test/api/v1/'.Client::ApplePath)
            ->and($request->body())->toBe($body)
            ->and(receivedMessage($request))->toEndWith("\nPUT\n/api/v1/me/apple\n".$body)
            ->and($request->header('X-SSH-Signature')[0])->toBe(testKey()->sign(receivedMessage($request), 'devnull-api'));

        return true;
    });
});

it('unlinks an Apple ID with a signed bodiless DELETE', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => userWithCountsJson(), 'apple_linked' => false])]);

    expect(app(Client::class)->unlinkApple())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('DELETE')
            ->and($request->url())->toBe('http://devnull.test/api/v1/me/apple')
            ->and($request->body())->toBe('')
            ->and($request->hasHeader('Content-Type'))->toBeFalse()
            ->and(receivedMessage($request))->toEndWith("\nDELETE\n/api/v1/me/apple\n")
            ->and($request->header('X-SSH-Signature')[0])->toBe(testKey()->sign(receivedMessage($request), 'devnull-api'));

        return true;
    });
});

it('reports a commit with a signed POST of its reason, and the details when there are some', function (string $details, string $body) {
    Http::fake(['devnull.test/*' => Http::response(null, 204)]);

    app(Client::class)->reportCommit('c1', ReportReason::Spam, $details);

    Http::assertSent(function (Request $request) use ($body): bool {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('http://devnull.test/api/v1/commits/c1/reports')
            ->and($request->body())->toBe($body)
            ->and(receivedMessage($request))->toEndWith("\nPOST\n/api/v1/commits/c1/reports\n".$body)
            ->and($request->header('X-SSH-Signature')[0])->toBe(testKey()->sign(receivedMessage($request), 'devnull-api'));

        return true;
    });
})->with([
    'with details' => ['Misleading link.', '{"reason":"spam","details":"Misleading link."}'],
    'without' => ['', '{"reason":"spam"}'],
]);

it('deletes your account with a signed bodiless DELETE of /me', function () {
    Http::fake(['devnull.test/*' => Http::response(null, 204)]);

    app(Client::class)->deleteAccount();

    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('DELETE')
            ->and($request->url())->toBe('http://devnull.test/api/v1/me')
            ->and($request->body())->toBe('')
            ->and($request->hasHeader('Content-Type'))->toBeFalse()
            ->and(receivedMessage($request))->toEndWith("\nDELETE\n/api/v1/me\n")
            ->and($request->header('X-SSH-Signature')[0])->toBe(testKey()->sign(receivedMessage($request), 'devnull-api'));

        return true;
    });
});

it('signs in with Apple on the sign-in endpoint, signed, for the user', function () {
    Http::fake(['devnull.test/*' => Http::response(['data' => userWithCountsJson()])]);

    $user = app(Client::class)->signInWithApple('the.identity.token', 'raw-nonce', 'devnull on iPhone');

    expect($user->username)->toBe('local_dev');

    Http::assertSent(function (Request $request): bool {
        $body = '{"apple_identity_token":"the.identity.token","nonce":"raw-nonce","device_name":"devnull on iPhone"}';

        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('http://devnull.test/api/v1/'.Client::SignInPath)
            ->and($request->body())->toBe($body)
            ->and(receivedMessage($request))->toEndWith("\nPOST\n/api/v1/devices\n".$body)
            ->and($request->header('X-SSH-Signature')[0])->toBe(testKey()->sign(receivedMessage($request), 'devnull-api'));

        return true;
    });
});

it("words devnull's Apple errors by their code, whatever the message says", function (string $call, int $status, ErrorCode $code) {
    Http::fake(['devnull.test/*' => Http::response(['message' => 'Something /dev/null says today.', 'code' => $code->value], $status)]);

    try {
        match ($call) {
            'link' => app(Client::class)->linkApple('token', 'nonce'),
            'sign in' => app(Client::class)->signInWithApple('token', 'nonce', 'devnull on iPhone'),
        };
        $this->fail('Expected an ApiException.');
    } catch (ApiException $exception) {
        expect($exception->status)->toBe($status)
            ->and($exception->errorCode)->toBe($code->value)
            ->and($exception->is($code))->toBeTrue()
            ->and($exception->firstError())->toBe($code->message())->not->toContain('/dev/null says');
    }
})->with([
    'a bad token on link' => ['link', 422, ErrorCode::InvalidAppleToken],
    'an Apple ID on another account' => ['link', 409, ErrorCode::AppleLinkedElsewhere],
    'a bad token on sign-in' => ['sign in', 422, ErrorCode::InvalidAppleToken],
    'an Apple ID on no account' => ['sign in', 404, ErrorCode::AppleNotLinked],
]);

it('shows the server message for a code it leaves to devnull, and for one it does not know', function (?string $code) {
    Http::fake(['devnull.test/*' => Http::response(array_filter(['message' => 'Slow down: 10 commits a minute. Even CI takes breaks.', 'code' => $code]), 429, ['Retry-After' => '12'])]);

    try {
        app(Client::class)->createCommit('Hello');
        $this->fail('Expected an ApiException.');
    } catch (ApiException $exception) {
        expect($exception->firstError())->toBe('Slow down: 10 commits a minute. Even CI takes breaks.')
            ->and($exception->retryAfter)->toBe(12);
    }
})->with([
    'rate limited' => ['rate_limited'],
    'a code from a newer server' => ['something_new'],
    'no code' => [null],
]);
