<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Ada's commit c1, with Grace's reply r1 on it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function graceRepliedTo(array $overrides = []): array
{
    return commitJson(['id' => 'c1', 'body' => 'What should I build next?', 'replies_count' => 1, 'stars_count' => 3, 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace']), ...$overrides]);
}

function serveThread(): void
{
    serveApi([
        'GET /api/v1/commits/c1' => [commitPageJson(graceRepliedTo(), [commitJson(['id' => 'c0', 'body' => 'The commit it replies to'])])],
        'GET /api/v1/commits/c1/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'A compiler', 'author' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace'])])])],
        'GET /api/v1/log/following' => [pageJson([graceRepliedTo()])],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'r2', 'parent_id' => 'c1', 'body' => 'A spreadsheet'])], 201],
    ]);
}

it('opens on the commit and replies kept last time, with no request and no spinner', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(graceRepliedTo(), [commitJson(['id' => 'c0', 'body' => 'The commit it replies to'])]));
    keep('commits/c1/replies', pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'A compiler'])]));
    serveThread();

    $screen = unfolded(Native::visit('/log/commits/c1'))
        ->assertSee('The commit it replies to')
        ->assertSee('What should I build next?')
        ->assertSee('A compiler');

    expect(showsLoading($screen->tree()))->toBeFalse()
        ->and(count(Http::recorded()))->toBe(0);

    finishRefreshes($screen)->assertSee('A compiler');

    expect(count(Http::recorded()))->toBe(2);
});

it('opens from a row on what the row knew, then fills in the replies and what it replies to', function () {
    holdRefreshes();
    keep('log/following', pageJson([graceRepliedTo(['parent_id' => 'c0'])]));
    serveThread();

    $commit = Native::visit('/log')->tap('commit-c1')->follow()
        ->assertSee('What should I build next?')
        ->assertSee('Ada Lovelace')
        ->assertSee('3')
        ->assertSee('1 reply')
        ->assertMissingElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-replies')
        ->assertElement('bare_text_input', fn (array $node): bool => ($node['ref'] ?? null) === 'reply-input')
        ->assertDontSee('No replies yet.')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-commit');

    // Opened before devnull answers, the replies say they're on their way.
    $commit->tap('replies-c1')
        ->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-replies');

    expect(count(Http::recorded()))->toBe(0);

    finishRefreshes($commit)
        ->assertSee('A compiler')
        ->assertMissingElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-replies')
        ->assertSee('The commit it replies to');
});

it('shows a spinner only the first time, when nothing is known of the commit', function () {
    holdRefreshes();
    serveThread();

    $screen = Native::visit('/log/commits/c1')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-commit');

    finishRefreshes($screen)->assertSee('What should I build next?')->tap('replies-c1')->assertSee('A compiler');

    $again = Native::visit('/log/commits/c1');

    expect(showsLoading($again->tree()))->toBeFalse();
});

it('keeps your reply under the commit and counts it in the log', function () {
    keep('log/following', pageJson([graceRepliedTo()]));
    serveThread();

    Native::visit('/log/commits/c1')
        ->input('reply-input', 'A spreadsheet')
        ->tap('send-reply')
        ->assertSee('A spreadsheet');

    expect(array_column(kept('commits/c1/replies')['data'], 'id'))->toBe(['r1', 'r2'])
        ->and(kept('log/following')['data'][0]['replies_count'])->toBe(2);

    holdRefreshes();

    Native::visit('/log/commits/c1')->assertSee('2 replies')->tap('replies-c1')->assertSee('A spreadsheet');
});

it('keeps the commit and says it could not refresh when offline', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(graceRepliedTo()));
    keep('commits/c1/replies', pageJson([]));
    fakeOffline();

    finishRefreshes(Native::visit('/log/commits/c1'))
        ->assertSee('What should I build next?')
        ->assertSee("warning: couldn't refresh")
        ->assertSet('error', null);
});

it('says so and forgets it when a kept commit has gone from devnull', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(graceRepliedTo()));
    serveApi([]);

    finishRefreshes(Native::visit('/log/commits/c1'))
        ->assertNotSet('error', null)
        ->assertDontSee("warning: couldn't refresh");

    expect(kept('commits/c1'))->toBeNull();
});

it('asks for the replies of a deleted reply opened in place again', function () {
    serveApi([
        'GET /api/v1/commits/c1' => [commitPageJson(graceRepliedTo())],
        'GET /api/v1/commits/c1/replies' => [pageJson([tombstoneJson(['id' => 'd1', 'parent_id' => 'c1'])])],
        'GET /api/v1/commits/d1/replies' => [pageJson([replyJson(['id' => 'r9', 'parent_id' => 'd1', 'body' => 'Under a deleted reply'])])],
    ]);

    $screen = Native::visit('/log/commits/c1')->tap('replies-c1')->call('toggleTombstone', 'd1')->assertSee('Under a deleted reply');

    $screen->call('load');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/commits/d1/replies?limit=5');
    $screen->assertSee('Under a deleted reply');
});
