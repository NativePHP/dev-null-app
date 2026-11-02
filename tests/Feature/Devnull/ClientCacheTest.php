<?php

use App\Devnull\Client;
use App\Devnull\Data\DraftImage;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\FetchInBackground;
use App\Devnull\RequestSigner;
use App\Devnull\ResponseCache;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->devnull = app(Client::class);
});

it('keeps the first page of what it fetches, and never a later one', function () {
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1'])], 'page2')],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson(['id' => 'a1'])])],
    ]);

    $this->devnull->log('following');
    $this->devnull->log('following', 'page2');
    $this->devnull->userCommits('ada', 'replies');

    expect(kept('log/following')['data'][0]['id'])->toBe('c1')
        ->and(kept('log/following', ['cursor' => 'page2']))->toBeNull()
        ->and(kept('users/ada/commits', ['replies' => '1'])['data'][0]['id'])->toBe('a1')
        ->and(kept('users/ada/commits'))->toBeNull();
});

it("keeps the first page of someone's followers and following", function () {
    serveApi([
        'GET /api/v1/users/ada/followers' => [pageJson([userJson(['id' => 'g1', 'handle' => 'grace', 'is_followed' => true, 'follows_you' => false])], 'page2')],
        'GET /api/v1/users/ada/following' => [pageJson([])],
    ]);

    $followers = $this->devnull->connections('ada', 'followers');
    $this->devnull->connections('ada', 'followers', 'page2');
    $this->devnull->connections('ada', 'following');

    expect($followers->items[0]->user->username)->toBe('grace')
        ->and($followers->items[0]->isFollowed)->toBeTrue()
        ->and($followers->items[0]->followsYou)->toBeFalse()
        ->and($followers->nextCursor)->toBe('page2')
        ->and(kept('users/ada/followers')['data'][0]['handle'])->toBe('grace')
        ->and(kept('users/ada/followers', ['cursor' => 'page2']))->toBeNull()
        ->and(kept('users/ada/following'))->toBe(pageJson([]));
});

it('signs a GET for the background to send, and the background sends it as signed', function () {
    $this->travelTo(now()->setTimestamp(1760000000));
    serveApi(['GET /api/v1/log/latest' => [pageJson([commitJson()])]]);

    $request = $this->devnull->signedGet('log/latest', ['cursor' => null]);

    expect($request->url)->toBe('http://devnull.test/api/v1/log/latest')
        ->and($request->headers['X-Devnull-Timestamp'])->toBe('1760000000')
        ->and($request->headers['X-SSH-Signature'])->toBe(testKey()->sign(RequestSigner::message('1760000000', $request->headers['X-Devnull-Nonce'], 'GET', '/api/v1/log/latest', ''), 'devnull-api'));

    Http::assertNothingSent();

    $result = (new FetchInBackground)->handle($request->url, $request->headers);

    expect($result['status'])->toBe(200)
        ->and($result['json']['data'][0]['id'])->toBe('0199e5c4-1111-7000-8000-00000000c0de');

    Http::assertSent(fn (Request $sent): bool => $sent->url() === $request->url
        && $sent->header('X-SSH-Signature') === [$request->headers['X-SSH-Signature']]
        && ! $sent->hasHeader('Content-Type'));
});

it('signs a write for the background to send, and the background sends its body as signed', function (?array $payload, string $body) {
    $this->travelTo(now()->setTimestamp(1760000000));
    serveApi(['PUT /api/v1/me/lane' => [currentUserJson(['preferred_lane' => 4])]]);

    $request = $this->devnull->signedWrite('PUT', Client::LanePath, $payload);

    expect($request->url)->toBe('http://devnull.test/api/v1/me/lane')
        ->and($request->method)->toBe('PUT')
        ->and($request->body)->toBe($body)
        ->and($request->headers['X-SSH-Signature'])->toBe(testKey()->sign(RequestSigner::message('1760000000', $request->headers['X-Devnull-Nonce'], 'PUT', '/api/v1/me/lane', $body), 'devnull-api'));

    Http::assertNothingSent();

    $result = (new FetchInBackground)->handle($request->url, $request->headers, $request->method, $request->body);

    expect($result['status'])->toBe(200)
        ->and($result['json']['data']['preferred_lane'])->toBe(4)
        // A write's answer isn't kept: the screen decides what it changes.
        ->and(kept('me'))->toBeNull();

    Http::assertSent(fn (Request $sent): bool => $sent->method() === 'PUT'
        && $sent->body() === $body
        && $sent->header('X-SSH-Signature') === [$request->headers['X-SSH-Signature']]
        && $sent->hasHeader('Content-Type') === ($body !== ''));
})->with([
    'a lane' => [['lane' => 4], '{"lane":4}'],
    'Automatic' => [['lane' => null], '{"lane":null}'],
    'no body' => [null, ''],
]);

it('answers status 0 from the background when devnull cannot be reached', function () {
    fakeOffline();

    $request = $this->devnull->signedGet('me');

    expect((new FetchInBackground)->handle($request->url, $request->headers))->toBe(['status' => 0, 'json' => null, 'retry_after' => '', 'from_devnull' => false]);
});

it("fails a GET that gets a 200 with something other than devnull's answer, and keeps nothing from it", function (string $body) {
    keep('log/following', pageJson([commitJson(['id' => 'c1'])], 'page2'));
    $kept = kept('log/following');
    serveApi(['GET /api/v1/log/following' => [$body]]);

    expect(fn () => $this->devnull->log('following'))->toThrow(ApiException::class, "fatal: unable to access 'http://devnull.test': unexpected answer (HTTP 200)")
        ->and(kept('log/following'))->toBe($kept);

    $request = $this->devnull->signedGet('log/following');

    expect((new FetchInBackground)->handle($request->url, $request->headers))->toMatchArray(['status' => 200, 'from_devnull' => false]);
})->with([
    'a maintenance page' => ['<!DOCTYPE html><html><head><title>Maintenance - /dev/null</title></head><body>Back soon.</body></html>'],
    'no body' => [''],
    'an empty object' => ['{}'],
    'a list' => ['[{"id":"c1"}]'],
]);

it('fails a write that gets a maintenance page, and leaves the cache alone', function () {
    keep('users/ada', ['data' => userWithCountsJson(['handle' => 'ada'], followers: 1), 'is_following' => false]);
    serveApi(['POST /api/v1/users/ada/follow' => ['<html><title>Maintenance - /dev/null</title></html>']]);

    expect(fn () => $this->devnull->follow('ada'))->toThrow(ApiException::class, 'unexpected answer (HTTP 200)')
        ->and(kept('users/ada'))->toMatchArray(['is_following' => false])
        ->and(kept('users/ada')['data']['followers_count'])->toBe(1);
});

it('keeps stars on every kept copy', function () {
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'stars_count' => 1])]));
    keep('users/local_dev/commits', pageJson([commitJson(['id' => 'c1', 'stars_count' => 1])]));
    serveApi([
        'POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'starred' => true, 'stars_count' => 2])]],
        'DELETE /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'starred' => false, 'stars_count' => 1])]],
    ]);

    $this->devnull->star('c1');

    expect(kept('log/following')['data'][0])->toMatchArray(['starred' => true, 'stars_count' => 2])
        ->and(kept('users/local_dev/commits')['data'][0])->toMatchArray(['starred' => true, 'stars_count' => 2]);

    $this->devnull->unstar('c1');
    expect(kept('log/following')['data'][0])->toMatchArray(['starred' => false, 'stars_count' => 1]);
});

it('keeps starred commits in newest-star order as you star and unstar', function () {
    keep('starred', pageJson([commitJson(['id' => 'c2', 'starred' => true])], 'page2'));
    serveApi([
        'POST /api/v1/commits/c9/star' => [['data' => commitJson(['id' => 'c9', 'starred' => true, 'stars_count' => 1])]],
        'POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'starred' => true])]],
        'DELETE /api/v1/commits/c2/star' => [['data' => commitJson(['id' => 'c2', 'starred' => false])]],
    ]);

    $this->devnull->star('c9');
    expect(array_column(kept('starred')['data'], 'id'))->toBe(['c9', 'c2']);

    $this->devnull->star('c1');
    expect(array_column(kept('starred')['data'], 'id'))->toBe(['c1', 'c9', 'c2']);

    $this->devnull->unstar('c2');
    expect(array_column(kept('starred')['data'], 'id'))->toBe(['c1', 'c9'])
        ->and(app(ResponseCache::class)->unstarredIds())->toBe(['c2']);
});

it('leaves your starred commits in their order when you pin or unpin one', function () {
    keep('starred', pageJson([
        commitJson(['id' => 'c2', 'starred' => true]),
        commitJson(['id' => 'c1', 'starred' => true]),
    ]));
    serveApi([
        'PUT /api/v1/commits/c1/pin' => [['data' => commitJson(['id' => 'c1', 'starred' => true, 'is_pinned' => true])]],
        'DELETE /api/v1/commits/c1/pin' => [['data' => commitJson(['id' => 'c1', 'starred' => true, 'is_pinned' => false])]],
    ]);

    $this->devnull->pin('c1');
    expect(array_column(kept('starred')['data'], 'id'))->toBe(['c2', 'c1']);

    $this->devnull->unpin('c1');
    expect(array_column(kept('starred')['data'], 'id'))->toBe(['c2', 'c1']);
});

it('keeps the first page of your starred commits', function () {
    serveApi(['GET /api/v1/starred' => [pageJson([commitJson(['id' => 'c1', 'starred' => true])], 'page2')]]);

    expect($this->devnull->starred()->items[0]->id)->toBe('c1')
        ->and($this->devnull->starred('page2')->nextCursor)->toBe('page2')
        ->and(array_column(kept('starred')['data'], 'id'))->toBe(['c1'])
        ->and(Http::recorded()[1][0]->url())->toBe('http://devnull.test/api/v1/starred?cursor=page2');
});

it('keeps a new commit and a new reply where they belong', function () {
    keep('log/following', pageJson([commitJson(['id' => 'c1'])]));
    keep('commits/c1/replies', pageJson([]));
    serveApi([
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'c2', 'body' => 'Fresh'])], 201],
    ]);

    $this->devnull->createCommit('Fresh');

    expect(array_column(kept('log/following')['data'], 'id'))->toBe(['c2', 'c1']);

    serveApi(['POST /api/v1/commits' => [['data' => commitJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'A reply'])], 201]]);

    $this->devnull->createCommit('A reply', 'c1');

    expect(array_column(kept('commits/c1/replies')['data'], 'id'))->toBe(['r1'])
        ->and(kept('log/following')['data'][1]['replies_count'])->toBe(1);
});

it('drops a deleted commit from every kept list', function () {
    keep('log/following', pageJson([commitJson(['id' => 'c2']), commitJson(['id' => 'c1'])]));
    keep('commits/c2', commitPageJson(commitJson(['id' => 'c2'])));
    serveApi(['DELETE /api/v1/commits/c2' => [null, 204]]);

    $this->devnull->deleteCommit('c2');

    expect(array_column(kept('log/following')['data'], 'id'))->toBe(['c1'])
        ->and(kept('commits/c2'))->toBeNull();
});

it('keeps follows and unfollows on the kept profile', function () {
    keep('users/ada', ['data' => userWithCountsJson(['handle' => 'ada'], followers: 1), 'is_following' => false]);
    serveApi([
        'POST /api/v1/users/ada/follow' => [null, 204],
        'DELETE /api/v1/users/ada/follow' => [null, 204],
    ]);

    $this->devnull->follow('ada');
    expect(kept('users/ada'))->toMatchArray(['is_following' => true])
        ->and(kept('users/ada')['data']['followers_count'])->toBe(2);

    $this->devnull->unfollow('ada');
    expect(kept('users/ada'))->toMatchArray(['is_following' => false])
        ->and(kept('users/ada')['data']['followers_count'])->toBe(1);
});

it('keeps the Apple link as it is linked and unlinked', function () {
    keep('me', ['data' => userWithCountsJson(), 'apple_linked' => false]);
    serveApi([
        'PUT /api/v1/me/apple' => [['apple_linked' => true]],
        'DELETE /api/v1/me/apple' => [['apple_linked' => false]],
    ]);

    $this->devnull->linkApple('token', 'nonce');
    expect(kept('me')['apple_linked'])->toBeTrue();

    $this->devnull->unlinkApple();
    expect(kept('me')['apple_linked'])->toBeFalse();
});

it('leaves the cache alone when a write fails', function () {
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'stars_count' => 1])]));
    serveApi(['POST /api/v1/commits/c1/star' => [['message' => 'Too Many Attempts.'], 429]]);

    expect(fn () => $this->devnull->star('c1'))->toThrow(ApiException::class);

    expect(kept('log/following')['data'][0]['stars_count'])->toBe(1);
});

it('keeps mutes on the kept profile and the people you mute, and takes their commits out of the kept logs', function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']);
    keep('users/ada', ['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada']), 'is_muting' => false]);
    keep('users/ada/commits', pageJson([commitJson(['id' => 'c2', 'author' => $ada])]));
    keep('me/muted', pageJson([userJson(['id' => 'u3', 'handle' => 'grace'])]));
    keep('log/following', [...pageJson([commitJson(['id' => 'c2', 'author' => $ada]), commitJson(['id' => 'c1'])]), 'suggestions' => [commitJson(['id' => 'c4', 'author' => $ada])]]);
    keep('log/trending', [...pageJson([commitJson(['id' => 'c3', 'author' => $ada])]), 'is_ranked' => true]);
    serveApi([
        'POST /api/v1/users/ada/mute' => [['data' => $ada, 'is_muting' => true]],
        'DELETE /api/v1/users/ada/mute' => [['data' => $ada, 'is_muting' => false]],
    ]);

    $this->devnull->mute('ada');

    expect(kept('users/ada')['is_muting'])->toBeTrue()
        ->and(array_column(kept('me/muted')['data'], 'handle'))->toBe(['ada', 'grace'])
        ->and(array_column(kept('log/following')['data'], 'id'))->toBe(['c1'])
        ->and(kept('log/following')['suggestions'])->toBe([])
        ->and(kept('log/trending')['data'])->toBe([])
        // Their own profile still shows their commits, as on the web.
        ->and(array_column(kept('users/ada/commits')['data'], 'id'))->toBe(['c2']);

    $this->devnull->unmute('ada');

    expect(kept('users/ada')['is_muting'])->toBeFalse()
        ->and(array_column(kept('me/muted')['data'], 'handle'))->toBe(['grace']);
});

it('keeps blocks on the people you block, and drops their profile, their commits and the follows a block ends', function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']);
    keep('users/ada', ['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada']), 'is_following' => true, 'follows_you' => true]);
    keep('users/ada/commits', pageJson([commitJson(['id' => 'c2', 'author' => $ada])]));
    keep('users/ada/commits', pageJson([commitJson(['id' => 'r1', 'author' => $ada])]), ['replies' => '1']);
    keep('users/local_dev', ['data' => userWithCountsJson(['handle' => 'local_dev'], followers: 3, following: 2)]);
    keep('me/blocked', pageJson([]));
    keep('log/latest', pageJson([commitJson(['id' => 'c2', 'author' => $ada]), commitJson(['id' => 'c1'])]));
    serveApi([
        'POST /api/v1/users/ada/block' => [['data' => $ada, 'is_blocking' => true]],
        'DELETE /api/v1/users/ada/block' => [['data' => $ada, 'is_blocking' => false]],
    ]);

    $this->devnull->block('ada');

    expect(kept('users/ada'))->toBeNull()
        ->and(kept('users/ada/commits'))->toBeNull()
        ->and(kept('users/ada/commits', ['replies' => '1']))->toBeNull()
        ->and(kept('users/local_dev')['data'])->toMatchArray(['followers_count' => 2, 'following_count' => 1])
        ->and(array_column(kept('me/blocked')['data'], 'handle'))->toBe(['ada'])
        ->and(array_column(kept('log/latest')['data'], 'id'))->toBe(['c1']);

    $this->devnull->unblock('ada');

    // Unblocking doesn't bring back the follows.
    expect(kept('me/blocked')['data'])->toBe([])
        ->and(kept('users/local_dev')['data'])->toMatchArray(['followers_count' => 2, 'following_count' => 1]);
});

it('keeps your counts when you block someone with no follows between you', function () {
    keep('users/ada', ['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada']), 'is_following' => false, 'follows_you' => false]);
    keep('users/local_dev', ['data' => userWithCountsJson(['handle' => 'local_dev'], followers: 3, following: 2)]);
    serveApi(['POST /api/v1/users/ada/block' => [['data' => userJson(['id' => 'u2', 'handle' => 'ada']), 'is_blocking' => true]]]);

    $this->devnull->block('ada');

    expect(kept('users/local_dev')['data'])->toMatchArray(['followers_count' => 3, 'following_count' => 2]);
});

it('lists the people you mute and block, and keeps their first pages', function () {
    serveApi([
        'GET /api/v1/me/muted' => [pageJson([userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace'])], 'next')],
        'GET /api/v1/me/blocked' => [pageJson([userJson(['id' => 'u3', 'handle' => 'grace'])])],
    ]);

    $muted = $this->devnull->muted();
    $blocked = $this->devnull->blocked();

    expect(array_map(fn ($user): string => $user->username, $muted->items))->toBe(['ada'])
        ->and($muted->items[0]->name)->toBe('Ada Lovelace')
        ->and($muted->nextCursor)->toBe('next')
        ->and(array_map(fn ($user): string => $user->username, $blocked->items))->toBe(['grace'])
        ->and($blocked->nextCursor)->toBeNull()
        ->and(kept('me/muted')['data'][0]['handle'])->toBe('ada')
        ->and(kept('me/blocked')['data'][0]['handle'])->toBe('grace');

    $this->devnull->muted('next');

    expect(kept('me/muted', ['cursor' => 'next']))->toBeNull();
});

it('leaves the cache alone when a mute or block fails, or devnull answers without the user', function (string $route, Closure $call) {
    keep('users/ada', ['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada']), 'is_muting' => false]);
    keep('me/blocked', pageJson([]));
    keep('log/latest', pageJson([commitJson(['id' => 'c2', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])]));
    serveApi([
        $route => [['message' => 'Too Many Attempts.'], 429],
        'DELETE /api/v1/users/ada/mute' => [['is_muting' => false]],
    ]);

    try {
        $call($this->devnull);
    } catch (ApiException) {
    }

    expect(kept('users/ada')['is_muting'])->toBeFalse()
        ->and(kept('me/blocked')['data'])->toBe([])
        ->and(kept('log/latest')['data'])->toHaveCount(1);
})->with([
    'a mute' => ['POST /api/v1/users/ada/mute', fn (Client $devnull) => $devnull->mute('ada')],
    'a block' => ['POST /api/v1/users/ada/block', fn (Client $devnull) => $devnull->block('ada')],
    'an answer without the user' => ['POST /api/v1/users/ada/mute', fn (Client $devnull) => $devnull->unmute('ada')],
]);

it('keeps the first page of your scheduled commits, paged by number, and lets it go when you change one', function (Closure $change) {
    serveApi(function (Request $request) {
        return match ($request->method().' '.$request->url()) {
            'GET http://devnull.test/api/v1/scheduled-commits' => Http::response(scheduledPageJson([scheduledCommitJson(['id' => 's1'])], nextPage: 2)),
            'GET http://devnull.test/api/v1/scheduled-commits?page=2' => Http::response(scheduledPageJson([scheduledCommitJson(['id' => 's0'])])),
            'POST http://devnull.test/api/v1/scheduled-commits/s1/submission' => Http::response(['data' => scheduledCommitJson(['id' => 's1', 'body' => null, 'submitted_at' => '2026-10-19T15:30:00+00:00', 'status' => 'pending'])]),
            'DELETE http://devnull.test/api/v1/scheduled-commits/s1' => Http::response(null, 204),
            default => Http::response(['message' => 'No fake.'], 404),
        };
    });

    $first = $this->devnull->scheduledCommits();

    expect($first->nextCursor)->toBe('2')
        ->and($this->devnull->scheduledCommits($first->nextCursor)->items[0]->id)->toBe('s0')
        ->and(array_column(kept(Client::ScheduledCommitsPath)['data'], 'id'))->toBe(['s1'])
        ->and(kept(Client::ScheduledCommitsPath, ['page' => '2']))->toBeNull();

    $change($this->devnull);

    expect(kept(Client::ScheduledCommitsPath))->toBeNull();
})->with([
    'committing one now' => [fn (Client $devnull) => expect($devnull->submitScheduledCommit('s1')->status)->toBe('pending')],
    'canceling one' => [fn (Client $devnull) => $devnull->cancelScheduledCommit('s1')],
]);

it('signs a commit to schedule as JSON, in UTC with its offset, and with its poll', function () {
    $request = $this->devnull->signedScheduledCommit('Tabs or spaces?', CarbonImmutable::parse('2026-10-20 09:00', 'Europe/Lisbon'), poll: ['options' => ['Tabs', 'Spaces'], 'duration' => 4320]);

    expect($request->url)->toBe('http://devnull.test/api/v1/scheduled-commits')
        ->and($request->method)->toBe('POST')
        ->and($request->body)->toBe('{"body":"Tabs or spaces?","scheduled_at":"2026-10-20T08:00:00+00:00","poll":{"options":["Tabs","Spaces"],"duration":4320}}');
});

it('signs a commit to schedule with photos as multipart, as a commit with photos', function () {
    $photo = new DraftImage(testPhoto('png'), 'image/png', 40, 30, 'A cabin.');

    $request = $this->devnull->signedScheduledCommit('', CarbonImmutable::parse('2026-10-20T08:00:00Z'), [$photo]);

    expect($request->url)->toBe('http://devnull.test/api/v1/scheduled-commits')
        ->and($request->contentType)->toStartWith('multipart/form-data; boundary=devnull-')
        ->and($request->body)->toContain('{"body":"","scheduled_at":"2026-10-20T08:00:00+00:00","image_descriptions":["A cabin."]}')
        ->and($request->body)->toContain('name="images[]"; filename="image-1.png"');
});
