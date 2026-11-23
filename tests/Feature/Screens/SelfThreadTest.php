<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * One of Ada's commits, $minutes ago, replying to $parent.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adaJson(string $id, ?string $parent = null, int $minutes = 30, array $overrides = []): array
{
    return commitJson([
        'id' => $id,
        'parent_id' => $parent,
        'body' => "Ada's {$id}",
        'author' => userJson(['handle' => 'ada', 'name' => 'Ada', 'lane' => 2]),
        'created_at' => now()->subMinutes($minutes)->toIso8601ZuluString(),
        ...$overrides,
    ]);
}

/**
 * Serves a commit page and the replies of every commit in $replies, by id, and 404s anything else.
 *
 * @param  array<string, mixed>  $commit
 * @param  list<array<string, mixed>>  $ancestors
 * @param  array<string, list<array<string, mixed>>>  $replies
 */
function serveSelfThread(array $commit, array $ancestors, array $replies): void
{
    Http::fake(function (Request $request) use ($commit, $ancestors, $replies) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/api/v1/commits/'.$commit['id']) {
            return Http::response(commitPageJson($commit, $ancestors));
        }

        if (preg_match('#^/api/v1/commits/([^/]+)/replies$#', $path, $match) === 1 && array_key_exists($match[1], $replies)) {
            return Http::response(pageJson($replies[$match[1]]));
        }

        if (str_ends_with($path, '/related')) {
            return Http::response(['data' => []]);
        }

        return Http::response(['message' => 'No fake.'], 404);
    });
}

/**
 * The refs of the rows down a commit page, top to bottom.
 *
 * @return list<string>
 */
function selfThreadRows(TestableComponent $screen): array
{
    return array_values(array_filter(childRefs($screen, 'thread-scroll'), fn (?string $ref): bool => $ref !== 'reply-bar-clearance'));
}

/**
 * The text of a commit's place in its thread.
 */
function threadPositionOf(TestableComponent $screen, string $id): string
{
    $text = findNode(nodeByRef($screen, 'thread-position-'.$id), fn (array $node): bool => ($node['type'] ?? null) === 'text');

    return $text['props']['text'] ?? '';
}

it("reads an author's replies to themselves in order under the commit, as the web's thread", function () {
    serveSelfThread(adaJson('c1', overrides: ['replies_count' => 2]), [], [
        'c1' => [
            replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'Grace answers', 'created_at' => now()->subMinutes(28)->toIso8601ZuluString()]),
            adaJson('p2', 'c1', 25),
            adaJson('later', 'c1', 5, ['body' => 'Ada again, later']),
        ],
        'p2' => [replyJson(['id' => 'x1', 'parent_id' => 'p2', 'body' => 'Under part two']), adaJson('p3', 'p2', 20)],
        'p3' => [replyJson(['id' => 'y1', 'parent_id' => 'p3', 'body' => 'Under the last part'])],
    ]);

    $screen = Native::visit('/log/commits/c1')
        ->assertSee("Ada's p2")
        ->assertSee("Ada's p3")
        ->assertSee('Under the last part')
        ->assertDontSee('Under part two')
        ->assertDontSee('Grace answers')
        ->assertDontSee('No replies yet.');

    expect(selfThreadRows($screen))->toBe(['focused-commit', 'commit-p2', 'commit-p3', 'thread-reply-y1'])
        ->and(threadPositionOf($screen, 'c1'))->toBe('1/3')
        ->and(threadPositionOf($screen, 'p2'))->toBe('2/3')
        ->and(threadPositionOf($screen, 'p3'))->toBe('3/3')
        ->and(railNode($screen, 'commit-p2'))->toBe('dot')
        // The last part's replies show under it, so its action row doesn't offer them again.
        ->and(findNode(nodeByRef($screen, 'commit-p3'), fn (array $node): bool => ($node['ref'] ?? null) === 'replies-p3'))->toBeNull();

    // The commit's own replies leave out the part that carries it on, and keep its later replies to itself.
    $screen->tap('replies-c1')->assertSee('Grace answers')->assertSee('Ada again, later');

    expect(selfThreadRows($screen))->toBe(['focused-commit', 'thread-reply-r1', 'thread-reply-later', 'commit-p2', 'commit-p3', 'thread-reply-y1']);
});

it('marks the earlier parts above a commit in the middle of its thread', function () {
    serveSelfThread(adaJson('p2', 'c1', 20), [adaJson('c1', minutes: 30)], [
        'c1' => [adaJson('p2', 'c1', 20)],
        'p2' => [],
    ]);

    $screen = Native::visit('/log/commits/p2');

    expect(threadPositionOf($screen, 'c1'))->toBe('1/2')
        ->and(threadPositionOf($screen, 'p2'))->toBe('2/2');
});

it("doesn't mark a commit its author replied to some other way first", function () {
    serveSelfThread(adaJson('p2', 'c1', 10), [adaJson('c1', minutes: 30)], [
        'c1' => [adaJson('first', 'c1', 20), adaJson('p2', 'c1', 10)],
        'p2' => [],
    ]);

    $screen = Native::visit('/log/commits/p2')->assertSee("Ada's c1");

    expect(findNode($screen->tree(), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'thread-position-')))->toBeNull();
});

it('shows ten parts at a time, and the rest from Show more of this thread', function () {
    $replies = ['c1' => [adaJson('p2', 'c1', 29)]];

    foreach (range(2, 11) as $part) {
        $replies['p'.$part] = [adaJson('p'.($part + 1), 'p'.$part, 29 - $part)];
    }

    $replies['p12'] = [];
    serveSelfThread(adaJson('c1', minutes: 30), [], $replies);

    $screen = Native::visit('/log/commits/c1')
        ->assertSee("Ada's p11")
        ->assertDontSee("Ada's p12");

    expect(threadPositionOf($screen, 'c1'))->toBe('1/12+');

    $screen->tap('more-thread')->assertSee("Ada's p12");

    expect(threadPositionOf($screen, 'c1'))->toBe('1/12')
        ->and(threadPositionOf($screen, 'p12'))->toBe('12/12')
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'more-thread'))->toBeNull();
});

it("leaves the author's next part out of a thread opened on the Log, and links to the thread instead", function () {
    Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/v1/log/following' => Http::response(pageJson([adaJson('c1', overrides: ['replies_count' => 1])])),
        '/api/v1/commits/c1/replies' => Http::response(pageJson([adaJson('p2', 'c1', 25), replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'Grace answers'])])),
        default => Http::response(['message' => 'No fake.'], 404),
    });

    $screen = Native::visit('/log')->assertDontSee('Show thread')->tap('replies-c1')
        ->assertSee('Grace answers')
        ->assertDontSee("Ada's p2")
        ->assertSee('Show thread');

    expect(array_map(fn ($reply): string => $reply->id, $screen->instance()->threads['c1']))->toBe(['r1']);

    $screen->tap('show-thread-c1')->assertNavigatedTo('/log/commits/c1');
});

it("leaves a reply's next part out of its replies opened in place on the Log, and links that reply to its thread", function () {
    $grace = userJson(['handle' => 'grace', 'name' => 'Grace', 'lane' => 4]);

    Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/v1/log/following' => Http::response(pageJson([adaJson('c1', overrides: ['replies_count' => 1])])),
        '/api/v1/commits/c1/replies' => Http::response(pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c1', 'body' => 'Grace answers', 'author' => $grace, 'replies_count' => 2])])),
        '/api/v1/commits/r1/replies' => Http::response(pageJson([
            replyJson(['id' => 'g2', 'parent_id' => 'r1', 'body' => 'Grace carries on', 'author' => $grace, 'created_at' => now()->subMinutes(4)->toIso8601ZuluString()]),
            replyJson(['id' => 'n1', 'parent_id' => 'r1', 'body' => 'Someone else answers Grace']),
        ])),
        default => Http::response(['message' => 'No fake.'], 404),
    });

    $screen = Native::visit('/log')->tap('replies-c1')->assertSee('Grace answers')->assertDontSee('Show thread')
        ->tap('replies-r1')
        ->assertSee('Someone else answers Grace')
        ->assertDontSee('Grace carries on')
        ->assertSee('Show thread');

    expect(array_map(fn ($reply): string => $reply->id, $screen->instance()->threads['r1']))->toBe(['n1'])
        ->and($screen->instance()->threadsContinued)->toBe(['r1']);

    $screen->tap('show-thread-r1')->assertNavigatedTo('/log/commits/r1');
});

it('links to the thread when devnull says which reply carries it on', function () {
    Http::fake(['devnull.test/api/v1/log/following*' => Http::response(pageJson([adaJson('c1', overrides: ['continuation_id' => 'p2'])]))]);

    Native::visit('/log')->assertSee('Show thread')->tap('show-thread-c1')->assertNavigatedTo('/log/commits/c1');
});
