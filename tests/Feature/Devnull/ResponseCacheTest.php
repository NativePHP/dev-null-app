<?php

use App\Devnull\Account;
use App\Devnull\Data\User;
use App\Devnull\ResponseCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    signIn();
    $this->cache = app(ResponseCache::class);
});

it('keys an answer by user, method, path and query, whatever order the query comes in', function () {
    expect(ResponseCache::key('u1', 'users/ada/commits', ['replies' => '1']))->toBe('u1 GET /users/ada/commits?replies=1')
        ->and(ResponseCache::key('u1', 'log/following'))->toBe('u1 GET /log/following')
        ->and(ResponseCache::key('u1', 'log/following', ['cursor' => null, 'replies' => '']))->toBe('u1 GET /log/following')
        ->and(ResponseCache::key('u1', 'x', ['b' => '2', 'a' => '1']))->toBe(ResponseCache::key('u1', 'x', ['a' => '1', 'b' => '2']))
        ->and(ResponseCache::key('u1', 'log/following'))->not->toBe(ResponseCache::key('u2', 'log/following'));
});

it('keeps first pages of the GETs the screens show, and nothing else', function () {
    expect(ResponseCache::caches('log/following'))->toBeTrue()
        ->and(ResponseCache::caches('me'))->toBeTrue()
        ->and(ResponseCache::caches('notifications'))->toBeTrue()
        ->and(ResponseCache::caches('commits/c1'))->toBeTrue()
        ->and(ResponseCache::caches('commits/c1/replies'))->toBeTrue()
        ->and(ResponseCache::caches('users/ada'))->toBeTrue()
        ->and(ResponseCache::caches('users/ada/commits', ['replies' => '1']))->toBeTrue()
        ->and(ResponseCache::caches('starred'))->toBeTrue()
        ->and(ResponseCache::caches('starred', ['cursor' => 'page2']))->toBeFalse()
        ->and(ResponseCache::caches('users/ada/followers'))->toBeTrue()
        ->and(ResponseCache::caches('users/ada/following'))->toBeTrue()
        ->and(ResponseCache::caches('users/ada/followers', ['cursor' => 'page2']))->toBeFalse()
        ->and(ResponseCache::caches('log/following', ['cursor' => 'page2']))->toBeFalse()
        ->and(ResponseCache::caches('devices'))->toBeFalse()
        ->and(ResponseCache::caches('key-syncs'))->toBeFalse()
        ->and(ResponseCache::caches('me/apple'))->toBeFalse();

    $this->cache->put('log/following', ['cursor' => 'page2'], pageJson([commitJson()]));
    $this->cache->put('devices', [], ['data' => userJson()]);

    expect($this->cache->get('log/following', ['cursor' => 'page2']))->toBeNull()
        ->and($this->cache->get('devices'))->toBeNull();
});

it('gives back what devnull sent', function () {
    $json = pageJson([commitJson(['id' => 'c1', 'body' => 'Kept'])], 'page2');

    $this->cache->put('log/following', [], $json);

    expect($this->cache->get('log/following'))->toBe($json)
        ->and($this->cache->get('log/latest'))->toBeNull();
});

it('shows an answer for seven days and treats anything older as missing', function () {
    $this->cache->put('me', [], ['data' => userJson()]);

    $this->travel(7)->days();
    expect($this->cache->get('me'))->not->toBeNull();

    $this->travel(1)->minute();
    expect($this->cache->get('me'))->toBeNull();
});

it('keeps each user apart', function () {
    $this->cache->put('log/following', [], pageJson([commitJson(['body' => "Local Dev's log"])]));

    app(Account::class)->remember(User::fromArray(userJson(['id' => 'u2', 'handle' => 'ada'])));

    expect($this->cache->get('log/following'))->toBeNull();

    $this->cache->put('log/following', [], pageJson([commitJson(['body' => "Ada's log"])]));

    app(Account::class)->remember(User::fromArray(userJson()));

    expect($this->cache->get('log/following')['data'][0]['body'])->toBe("Local Dev's log");
});

it('keeps nothing when nobody is signed in', function () {
    Cache::forget('devnull.account');

    $this->cache->put('me', [], ['data' => userJson()]);

    expect($this->cache->get('me'))->toBeNull();
});

it('empties the cache for everyone on sign-out', function () {
    $this->cache->put('log/following', [], pageJson([commitJson()]));
    app(Account::class)->remember(User::fromArray(userJson(['id' => 'u2', 'handle' => 'ada'])));
    $this->cache->put('log/following', [], pageJson([commitJson()]));

    app(Account::class)->forget();

    foreach (['u2', '0199e5c4-0000-7000-8000-000000000001'] as $userId) {
        expect(Cache::store(ResponseCache::Store)->get(ResponseCache::key($userId, 'log/following')))->toBeNull();
    }
});

it('lets the oldest answers go past the limit', function () {
    foreach (range(1, ResponseCache::MaxEntries + 1) as $n) {
        $this->cache->put("commits/c{$n}", [], commitPageJson(commitJson(['id' => "c{$n}"])));
    }

    expect($this->cache->get('commits/c1'))->toBeNull()
        ->and($this->cache->get('commits/c2'))->not->toBeNull()
        ->and($this->cache->get('commits/c'.(ResponseCache::MaxEntries + 1)))->not->toBeNull();
});

it('keeps answers on disk across launches, and empties the folder on sign-out', function () {
    $path = storage_path('framework/testing/responses-'.uniqid());
    config(['cache.stores.responses' => ['driver' => 'file', 'path' => $path]]);
    Cache::forgetDriver(ResponseCache::Store);

    $this->cache->put('me', [], ['data' => userJson()]);

    // A new launch is a new PHP process: nothing in memory, only what's on disk.
    Cache::forgetDriver(ResponseCache::Store);
    expect(app(ResponseCache::class)->get('me'))->toBe(['data' => userJson()])
        ->and(File::allFiles($path))->not->toBeEmpty();

    app(Account::class)->forget();

    expect(File::allFiles($path))->toBeEmpty();

    File::deleteDirectory($path);
});

it('takes a new star into every copy of the commit, wherever it sits', function () {
    $commit = commitJson(['id' => 'c1', 'stars_count' => 1]);

    $this->cache->put('log/following', [], pageJson([commitJson(['id' => 'c2', 'parent_id' => 'c1', 'parent' => $commit]), $commit]));
    $this->cache->put('users/local_dev', [], ['data' => userWithCountsJson(), 'pinned_commit' => $commit]);
    $this->cache->put('commits/c3', [], commitPageJson(commitJson(['id' => 'c3'])));

    $this->cache->commitReacted([...$commit, 'starred' => true, 'stars_count' => 2, 'body' => 'Ignored']);

    $log = $this->cache->get('log/following')['data'];

    expect($log[1])->toMatchArray(['starred' => true, 'stars_count' => 2, 'body' => 'Shipped the mobile app.'])
        ->and($log[0]['parent'])->toMatchArray(['starred' => true, 'stars_count' => 2])
        ->and($log[0]['starred'])->toBeFalse()
        ->and($this->cache->get('users/local_dev')['pinned_commit']['stars_count'])->toBe(2)
        ->and($this->cache->get('commits/c3')['data']['stars_count'])->toBe(0);
});

it('puts your new commit on top of your logs and profile, and counts it', function () {
    $this->cache->put('log/following', [], pageJson([commitJson(['id' => 'c1'])]));
    $this->cache->put('log/latest', [], pageJson([commitJson(['id' => 'c1'])]));
    $this->cache->put('log/trending', [], [...pageJson([commitJson(['id' => 'c2']), commitJson(['id' => 'c1'])]), 'is_ranked' => true, 'newest_commit_id' => 'c1']);
    $this->cache->put('users/local_dev/commits', [], pageJson([commitJson(['id' => 'c1'])]));
    $this->cache->put('users/local_dev', [], ['data' => userWithCountsJson(commits: 4)]);

    $this->cache->commitAdded(commitJson(['id' => 'c9', 'body' => 'New one']));

    expect(array_column($this->cache->get('log/following')['data'], 'id'))->toBe(['c9', 'c1'])
        ->and(array_column($this->cache->get('users/local_dev/commits')['data'], 'id'))->toBe(['c9', 'c1'])
        ->and($this->cache->get('users/local_dev')['data']['commits_count'])->toBe(5)
        ->and(array_column($this->cache->get('log/latest')['data'], 'id'))->toBe(['c9', 'c1'])
        // Trending is ranked, so a new commit has no place on top of it.
        ->and(array_column($this->cache->get('log/trending')['data'], 'id'))->toBe(['c2', 'c1']);
});

it('puts your reply under its parent and counts it there', function () {
    $this->cache->put('log/following', [], pageJson([commitJson(['id' => 'c1', 'replies_count' => 1])]));
    $this->cache->put('commits/c1/replies', [], pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1'])]));
    $this->cache->put('users/local_dev/commits', ['replies' => '1'], pageJson([]));

    $this->cache->commitAdded(commitJson(['id' => 'r2', 'parent_id' => 'c1', 'body' => 'A reply']));

    expect(array_column($this->cache->get('commits/c1/replies')['data'], 'id'))->toBe(['r1', 'r2'])
        ->and(array_column($this->cache->get('users/local_dev/commits', ['replies' => '1'])['data'], 'id'))->toBe(['r2'])
        ->and($this->cache->get('log/following')['data'][0]['replies_count'])->toBe(2)
        ->and(array_column($this->cache->get('log/following')['data'], 'id'))->toBe(['c1']);
});

it('puts your reply under its parent in a list, as the newest one, with Show more for the rest', function () {
    $this->cache->put('log/following', [], pageJson([
        commitJson(['id' => 'c1', 'replies_count' => 1, 'reply_preview' => ['data' => [replyJson(['id' => 'r1', 'parent_id' => 'c1'])], 'next_cursor' => null]]),
        commitJson(['id' => 'c2', 'replies_count' => 0, 'reply_preview' => ['data' => [], 'next_cursor' => null]]),
    ]));

    $this->cache->commitAdded(commitJson(['id' => 'r2', 'parent_id' => 'c1', 'body' => 'Mine']));
    $this->cache->commitAdded(commitJson(['id' => 'r3', 'parent_id' => 'c2', 'body' => 'First']));

    $log = $this->cache->get('log/following')['data'];

    expect(array_column($log[0]['reply_preview']['data'], 'id'))->toBe(['r2'])
        ->and($log[0]['reply_preview']['next_cursor'])->not->toBeNull()
        ->and($log[0]['replies_count'])->toBe(2)
        ->and(array_column($log[1]['reply_preview']['data'], 'id'))->toBe(['r3'])
        ->and($log[1]['reply_preview']['next_cursor'])->toBeNull();
});

it('leaves a reply off a thread whose last page is not kept', function () {
    $this->cache->put('commits/c1/replies', [], pageJson([replyJson(['id' => 'r1'])], 'page2'));

    $this->cache->commitAdded(commitJson(['id' => 'r2', 'parent_id' => 'c1']));

    expect(array_column($this->cache->get('commits/c1/replies')['data'], 'id'))->toBe(['r1']);
});

it('takes a deleted commit out of every list and forgets its page', function () {
    $this->cache->put('log/following', [], pageJson([commitJson(['id' => 'r1', 'parent_id' => 'c1']), commitJson(['id' => 'c1', 'replies_count' => 1])]));
    $this->cache->put('commits/r1', [], commitPageJson(commitJson(['id' => 'r1', 'parent_id' => 'c1'])));
    $this->cache->put('commits/c1/replies', [], pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1'])]));

    $this->cache->commitDeleted('r1');

    expect(array_column($this->cache->get('log/following')['data'], 'id'))->toBe(['c1'])
        ->and($this->cache->get('log/following')['data'][0]['replies_count'])->toBe(0)
        ->and($this->cache->get('commits/c1/replies')['data'])->toBe([])
        ->and($this->cache->get('commits/r1'))->toBeNull();
});

it('remembers the commits deleted on this phone, newest first and only the last fifty', function () {
    foreach (range(1, 52) as $n) {
        $this->cache->commitDeleted("c{$n}");
    }

    expect($this->cache->deletedIds())->toHaveCount(50)
        ->and($this->cache->deletedIds()[0])->toBe('c52')
        ->and($this->cache->deletedIds())->not->toContain('c1');

    $this->cache->flush();

    expect($this->cache->deletedIds())->toBe([]);
});

it('puts a commit you starred on top and takes one you unstarred out', function () {
    $this->cache->put('starred', [], pageJson([commitJson(['id' => 'c2', 'starred' => true]), commitJson(['id' => 'c1', 'starred' => true])], 'page2'));

    $this->cache->commitStarred(commitJson(['id' => 'c3', 'body' => 'Worth keeping', 'starred' => true]));
    $this->cache->commitStarred(commitJson(['id' => 'c1', 'starred' => true]));

    expect(array_column($this->cache->get('starred')['data'], 'id'))->toBe(['c1', 'c3', 'c2'])
        ->and($this->cache->get('starred')['data'][1]['body'])->toBe('Worth keeping')
        ->and($this->cache->get('starred')['meta']['next_cursor'])->toBe('page2');

    $this->cache->commitStarred(commitJson(['id' => 'c3', 'starred' => false]));
    $this->cache->commitStarred(['id' => 'c2']);

    expect(array_column($this->cache->get('starred')['data'], 'id'))->toBe(['c1', 'c2']);
});

it('remembers unstarred commits until starred again newest first and only the last fifty', function () {
    foreach (range(1, 52) as $n) {
        $this->cache->commitStarred(commitJson(['id' => "c{$n}", 'starred' => false]));
    }

    $this->cache->commitStarred(commitJson(['id' => 'c51', 'starred' => true]));

    expect($this->cache->unstarredIds())->toHaveCount(49)
        ->and($this->cache->unstarredIds()[0])->toBe('c52')
        ->and($this->cache->unstarredIds())->not->toContain('c51')
        ->and($this->cache->unstarredIds())->not->toContain('c2');

    $this->cache->flush();

    expect($this->cache->unstarredIds())->toBe([]);
});

it('keeps a log the same whatever it was asked since, without what only ?since= answers', function () {
    $this->cache->put('log/trending', ['since' => 'c1'], [
        ...pageJson([commitJson(['id' => 'c2'])]),
        'is_ranked' => true,
        'newest_commit_id' => 'c3',
        'latest_commits' => [commitJson(['id' => 'c3'])],
        'new_commits_count' => 1,
    ]);

    expect($this->cache->get('log/trending'))->toMatchArray(['is_ranked' => true, 'newest_commit_id' => 'c3'])
        ->and($this->cache->get('log/trending'))->not->toHaveKeys(['latest_commits', 'new_commits_count'])
        ->and($this->cache->get('log/trending', ['since' => 'c9']))->toBe($this->cache->get('log/trending'));
});

it('keeps a quote of a commit you deleted, saying it was deleted', function () {
    $this->cache->put('log/latest', [], pageJson([
        commitJson(['id' => 'q1', 'quoted_commit_id' => 'c1', 'quoted_commit' => commitJson(['id' => 'c1'])]),
        commitJson(['id' => 'c1']),
    ]));

    $this->cache->commitDeleted('c1');

    $log = $this->cache->get('log/latest')['data'];

    expect(array_column($log, 'id'))->toBe(['q1'])
        ->and($log[0]['quoted_commit_id'])->toBe('c1')
        ->and($log[0]['quoted_commit'])->toHaveKey('deleted_at')
        ->and($log[0]['quoted_commit'])->not->toHaveKey('body');
});

it('follows and unfollows on the kept profiles', function () {
    $this->cache->put('users/ada', [], ['data' => userWithCountsJson(['handle' => 'ada'], followers: 3), 'is_following' => false]);
    $this->cache->put('users/local_dev', [], ['data' => userWithCountsJson(following: 2), 'is_following' => false]);

    $this->cache->followChanged('ada', true);
    $this->cache->followChanged('ada', true);

    expect($this->cache->get('users/ada'))->toMatchArray(['is_following' => true])
        ->and($this->cache->get('users/ada')['data']['followers_count'])->toBe(4)
        ->and($this->cache->get('users/local_dev')['data']['following_count'])->toBe(3);

    $this->cache->followChanged('ada', false);

    expect($this->cache->get('users/ada')['data']['followers_count'])->toBe(3)
        ->and($this->cache->get('users/local_dev')['data']['following_count'])->toBe(2);
});

it('counts a follow on your profile when theirs was never kept', function () {
    $this->cache->put('users/local_dev', [], ['data' => userWithCountsJson(following: 2), 'is_following' => false]);

    $this->cache->followChanged('grace', true);

    expect($this->cache->get('users/local_dev')['data']['following_count'])->toBe(3)
        ->and($this->cache->get('users/grace'))->toBeNull();

    $this->cache->followChanged('grace', false);

    expect($this->cache->get('users/local_dev')['data']['following_count'])->toBe(2);
});

it('marks them followed in every kept list of people that says so', function () {
    $grace = userJson(['id' => 'g1', 'handle' => 'grace', 'is_followed' => false, 'follows_you' => true]);
    $linus = userJson(['id' => 'l1', 'handle' => 'linus', 'is_followed' => false]);
    $this->cache->put('users/ada/followers', [], pageJson([$grace, $linus]));
    $this->cache->put('users/ada/following', [], pageJson([$grace]));
    $this->cache->put('notifications', [], pageJson([notificationJson('followed', ['actor' => $grace, 'actors' => [userJson(['id' => 'g1', 'handle' => 'grace'])]])]));
    $this->cache->put('log/following', [], pageJson([commitJson(['author' => userJson(['id' => 'g1', 'handle' => 'grace'])])]));

    $this->cache->followChanged('grace', true);

    expect(array_column($this->cache->get('users/ada/followers')['data'], 'is_followed'))->toBe([true, false])
        ->and($this->cache->get('users/ada/followers')['data'][0]['follows_you'])->toBeTrue()
        ->and($this->cache->get('users/ada/following')['data'][0]['is_followed'])->toBeTrue()
        ->and($this->cache->get('notifications')['data'][0]['actor']['is_followed'])->toBeTrue()
        ->and($this->cache->get('notifications')['data'][0]['actors'][0])->not->toHaveKey('is_followed')
        ->and($this->cache->get('log/following')['data'][0]['author'])->not->toHaveKey('is_followed');

    $this->cache->followChanged('grace', false);

    expect($this->cache->get('users/ada/followers')['data'][0]['is_followed'])->toBeFalse();
});

it('keeps the Apple link on the kept you', function () {
    $this->cache->put('me', [], ['data' => userJson(), 'apple_linked' => false]);

    $this->cache->appleLinkChanged(true);

    expect($this->cache->get('me')['apple_linked'])->toBeTrue();
});

it('takes a fresh you into the kept you, and your profile into every other kept copy of you', function () {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada', 'lane' => 2]);
    $this->cache->put('me', [], currentUserJson(['name' => 'Old Name', 'lane' => 3], ['apple_linked' => true, 'unread_notifications_count' => 2]));
    $this->cache->put('users/local_dev', [], ['data' => userWithCountsJson(['name' => 'Old Name', 'lane' => 3], commits: 7), 'is_following' => false]);
    $this->cache->put('log/following', [], pageJson([
        commitJson(['id' => 'c1', 'author' => userJson(['name' => 'Old Name', 'lane' => 3])]),
        commitJson(['id' => 'c2', 'author' => $ada]),
    ]));
    $this->cache->put('notifications', [], pageJson([notificationJson('followed', ['actor' => $ada, 'actors' => [$ada]])]));
    $before = $this->cache->get('notifications');

    $this->cache->meChanged(currentUserJson(['name' => 'Fresh Name', 'lane' => 5, 'preferred_lane' => 5, 'bio' => 'Fresh bio.']));

    $me = $this->cache->get('me');
    $profile = $this->cache->get('users/local_dev')['data'];
    $log = $this->cache->get('log/following')['data'];

    expect($me['data'])->toMatchArray(['name' => 'Fresh Name', 'lane' => 5, 'preferred_lane' => 5])
        ->and($me['apple_linked'])->toBeTrue()
        ->and($me['unread_notifications_count'])->toBe(0)
        ->and($profile)->toMatchArray(['name' => 'Fresh Name', 'lane' => 5, 'bio' => 'Fresh bio.', 'commits_count' => 7])
        ->and($profile)->not->toHaveKey('preferred_lane')
        ->and($log[0]['author'])->toMatchArray(['name' => 'Fresh Name', 'lane' => 5])
        ->and($log[0]['body'])->toBe('Shipped the mobile app.')
        ->and($log[1]['author'])->toBe($ada)
        ->and($this->cache->get('notifications'))->toBe($before);
});

it('leaves the other kept copies alone when only what the settings show changed', function () {
    $this->cache->put('me', [], currentUserJson(['name' => 'Local Dev']));
    $this->cache->put('users/local_dev', [], ['data' => userWithCountsJson(['name' => 'Stale Name']), 'is_following' => false]);

    $this->cache->meChanged(currentUserJson(['name' => 'Local Dev', 'github_synced_at' => '2026-10-17T12:00:00Z']));

    expect($this->cache->get('me')['data']['github_synced_at'])->toBe('2026-10-17T12:00:00Z')
        ->and($this->cache->get('users/local_dev')['data']['name'])->toBe('Stale Name');
});

it('leaves the kept copies alone for anyone but you', function () {
    $this->cache->put('users/local_dev', [], ['data' => userWithCountsJson(), 'is_following' => false]);

    $this->cache->meChanged(currentUserJson(['id' => 'someone-else', 'name' => 'Someone Else']));
    $this->cache->meChanged(['message' => 'Not JSON devnull would send.']);

    expect($this->cache->get('users/local_dev')['data']['name'])->toBe('Local Dev');
});

it('keeps a patched answer only as long as the one it came from', function () {
    $this->cache->put('me', [], ['data' => userJson(), 'apple_linked' => false]);

    $this->travel(6)->days();
    $this->cache->appleLinkChanged(true);
    $this->travel(1)->days();
    $this->travel(1)->minute();

    expect($this->cache->get('me'))->toBeNull();
});

it("keeps the Remotes inbox and a thread's first page, but never a thread's poll or an older page", function () {
    $this->cache->put('remotes', [], remotesInboxJson([conversationJson()]));
    $this->cache->put('remotes', ['tab' => 'stashed'], remotesInboxJson([]));
    $this->cache->put('remotes/@ada/messages', [], threadJson([remoteMessageJson()]));
    $this->cache->put('remotes/orgs/org-1/messages', [], threadJson([remoteMessageJson()]));
    $this->cache->put('remotes/@ada/messages', ['after' => '1', 'reactions_after' => '0'], threadJson([remoteMessageJson(['id' => 2])]));
    $this->cache->put('remotes', ['before' => 'conv-1'], remotesInboxJson([]));

    expect($this->cache->get('remotes'))->not->toBeNull()
        ->and($this->cache->get('remotes', ['tab' => 'stashed']))->not->toBeNull()
        ->and($this->cache->get('remotes/orgs/org-1/messages'))->not->toBeNull()
        ->and($this->cache->get('remotes/@ada/messages')['data']['messages'][0]['id'])->toBe(1)
        ->and(ResponseCache::caches('remotes/@ada/messages', ['after' => '1']))->toBeFalse()
        ->and(ResponseCache::caches('remotes', ['before' => 'conv-1']))->toBeFalse()
        ->and(ResponseCache::caches('remotes/reactions'))->toBeFalse();
});
