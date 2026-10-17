<?php

use App\Devnull\Client;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\FetchInBackground;
use App\Devnull\RequestSigner;
use App\Devnull\ResponseCache;
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

    expect((new FetchInBackground)->handle($request->url, $request->headers))->toBe(['status' => 0, 'json' => null, 'retry_after' => '']);
});

it('keeps stars and bookmarks on every kept copy', function () {
    keep('log/following', pageJson([commitJson(['id' => 'c1', 'stars_count' => 1])]));
    keep('users/local_dev/commits', pageJson([commitJson(['id' => 'c1', 'stars_count' => 1])]));
    serveApi([
        'POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'starred' => true, 'stars_count' => 2])]],
        'DELETE /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'starred' => false, 'stars_count' => 1])]],
        'PUT /api/v1/commits/c1/bookmark' => [['data' => commitJson(['id' => 'c1', 'starred' => true, 'stars_count' => 2, 'is_saved' => true])]],
        'DELETE /api/v1/commits/c1/bookmark' => [['data' => commitJson(['id' => 'c1', 'starred' => true, 'stars_count' => 2, 'is_saved' => false])]],
    ]);

    $this->devnull->star('c1');

    expect(kept('log/following')['data'][0])->toMatchArray(['starred' => true, 'stars_count' => 2])
        ->and(kept('users/local_dev/commits')['data'][0])->toMatchArray(['starred' => true, 'stars_count' => 2]);

    $this->devnull->bookmark('c1');
    expect(kept('log/following')['data'][0]['is_saved'])->toBeTrue();

    $this->devnull->unbookmark('c1');
    expect(kept('log/following')['data'][0]['is_saved'])->toBeFalse();

    $this->devnull->unstar('c1');
    expect(kept('log/following')['data'][0])->toMatchArray(['starred' => false, 'stars_count' => 1]);
});

it('keeps the kept saved commits up to date as you save and unsave, but not as you star', function () {
    keep('saved', pageJson([commitJson(['id' => 'c2', 'is_saved' => true])], 'page2'));
    serveApi([
        'POST /api/v1/commits/c9/star' => [['data' => commitJson(['id' => 'c9', 'starred' => true, 'stars_count' => 1, 'is_saved' => true])]],
        'PUT /api/v1/commits/c1/bookmark' => [['data' => commitJson(['id' => 'c1', 'is_saved' => true])]],
        'DELETE /api/v1/commits/c2/bookmark' => [['data' => commitJson(['id' => 'c2', 'is_saved' => false])]],
    ]);

    // c9 was saved long ago, past the kept page: a star doesn't make it the last saved.
    $this->devnull->star('c9');
    expect(array_column(kept('saved')['data'], 'id'))->toBe(['c2']);

    $this->devnull->bookmark('c1');
    expect(array_column(kept('saved')['data'], 'id'))->toBe(['c1', 'c2']);

    $this->devnull->unbookmark('c2');
    expect(array_column(kept('saved')['data'], 'id'))->toBe(['c1'])
        ->and(app(ResponseCache::class)->unsavedIds())->toBe(['c2']);
});

it('keeps the first page of your saved commits', function () {
    serveApi(['GET /api/v1/saved' => [pageJson([commitJson(['id' => 'c1', 'is_saved' => true])], 'page2')]]);

    expect($this->devnull->saved()->items[0]->id)->toBe('c1')
        ->and($this->devnull->saved('page2')->nextCursor)->toBe('page2')
        ->and(array_column(kept('saved')['data'], 'id'))->toBe(['c1'])
        ->and(Http::recorded()[1][0]->url())->toBe('http://devnull.test/api/v1/saved?cursor=page2');
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
    keep('users/ada', ['data' => userWithCountsJson(['username' => 'ada'], followers: 1), 'is_following' => false]);
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
