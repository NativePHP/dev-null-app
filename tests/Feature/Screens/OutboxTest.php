<?php

use App\Devnull\Account;
use App\Devnull\Outbox;
use App\Devnull\Stash;
use App\Models\PendingWrite;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Victorycodedev\ToastKit\Events\ToastActionPressed;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->userId = userJson()['id'];
});

/**
 * Someone else's commit in the Following log, with a star or two on it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adasCommit(array $overrides = []): array
{
    return commitJson(['id' => 'c1', 'body' => 'Shipped it.', 'stars_count' => 1, 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']), ...$overrides]);
}

/**
 * Something left in the outbox from when the app last closed, on its way to devnull from a task that has gone.
 *
 * @param  array<string, mixed>  $payload
 */
function leftover(string $kind, ?string $target, array $payload = []): PendingWrite
{
    return PendingWrite::query()->create([
        'user_id' => userJson()['id'],
        'kind' => $kind,
        'target' => $target,
        'client_id' => (string) Str::uuid(),
        'payload' => $payload,
        'status' => PendingWrite::Sending,
        'task_id' => 'a-task-from-before-the-app-closed',
        'attempts' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function pressToastAction(TestableComponent $screen, string $action, array $data): TestableComponent
{
    return $screen->emitNative(ToastActionPressed::class, ['toastId' => 'toast-1', 'actionId' => $action, 'data' => $data]);
}

/**
 * The methods of the writes sent to devnull so far, in the order they went.
 *
 * @return list<string>
 */
function writesSent(): array
{
    return collect(Http::recorded())->map(fn (array $sent): string => $sent[0]->method())->reject(fn (string $method): bool => $method === 'GET')->values()->all();
}

it('stars at once from the outbox, then takes the count devnull sends back', function () {
    holdRefreshes();
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([adasCommit()])],
        'POST /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => true, 'stars_count' => 7])]],
    ]);

    $screen = finishRefreshes(Native::visit('/log'))->tap('star-c1');

    // The star shows straight away, here and in the kept log, while it waits in the outbox.
    expect($screen->instance()->commits[0])->starred->toBeTrue()->starsCount->toBe(2)
        ->and(kept('log/following')['data'][0])->toMatchArray(['starred' => true, 'stars_count' => 2])
        ->and(PendingWrite::query()->sole())->kind->toBe(PendingWrite::Star)->target->toBe('c1')->status->toBe(PendingWrite::Sending)
        ->and(sentTo('POST', 'commits/c1/star'))->toBe([]);

    finishRefreshes($screen)->assertNativeNotCalled('ToastKit.Show');

    expect($screen->instance()->commits[0])->starred->toBeTrue()->starsCount->toBe(7)
        ->and(kept('log/following')['data'][0])->toMatchArray(['starred' => true, 'stars_count' => 7])
        ->and(PendingWrite::query()->count())->toBe(0)
        ->and(sentTo('POST', 'commits/c1/star'))->toHaveCount(1);
});

it('lets the last of several quick taps on a star have the last word', function () {
    holdRefreshes();
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([adasCommit()])],
        'POST /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => true, 'stars_count' => 9])]],
        'DELETE /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => false, 'stars_count' => 8])]],
    ]);

    $screen = finishRefreshes(Native::visit('/log'))->tap('star-c1')->tap('star-c1');

    expect($screen->instance()->commits[0])->starred->toBeFalse()->starsCount->toBe(1);

    finishRefreshes($screen);

    expect($screen->instance()->commits[0])->starred->toBeFalse()->starsCount->toBe(8)
        ->and(kept('log/following')['data'][0])->toMatchArray(['starred' => false, 'stars_count' => 8])
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('sends a quick unstar only once the star before it has gone, so devnull ends up where the last tap left it', function () {
    holdRefreshes();
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([adasCommit()])],
        'POST /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => true, 'stars_count' => 2])]],
        'DELETE /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => false, 'stars_count' => 1])]],
    ]);

    $screen = finishRefreshes(Native::visit('/log'))->tap('star-c1')->tap('star-c1');

    finishRefreshes($screen, newestFirst: true);

    expect($screen->instance()->commits[0])->starred->toBeFalse()->starsCount->toBe(1)
        ->and(kept('log/following')['data'][0])->toMatchArray(['starred' => false, 'stars_count' => 1])
        ->and(writesSent())->toBe(['POST', 'DELETE'])
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('still sends a quick unstar when devnull turns down the star before it', function () {
    holdRefreshes();
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([adasCommit()])],
        'POST /api/v1/commits/c1/star' => [['message' => 'Server Error'], 500],
        'DELETE /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => false, 'stars_count' => 1])]],
    ]);

    $screen = finishRefreshes(finishRefreshes(Native::visit('/log'))->tap('star-c1')->tap('star-c1'));

    expect($screen->instance()->commits[0])->starred->toBeFalse()->starsCount->toBe(1)
        ->and(sentTo('DELETE', 'commits/c1/star'))->toHaveCount(1)
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('puts the star back when devnull took it but turned down the quick unstar after it', function () {
    holdRefreshes();
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([adasCommit()])],
        'POST /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => true, 'stars_count' => 2])]],
        'DELETE /api/v1/commits/c1/star' => [['message' => 'Server Error'], 500],
    ]);

    $screen = finishRefreshes(finishRefreshes(Native::visit('/log'))->tap('star-c1')->tap('star-c1'));

    expect($screen->instance()->commits[0])->starred->toBeTrue()->starsCount->toBe(2)
        ->and(kept('log/following')['data'][0])->toMatchArray(['starred' => true, 'stars_count' => 2])
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('says nothing when devnull turns down a quick unstar and the star after it, as it still has the star', function () {
    holdRefreshes();
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([adasCommit(['starred' => true, 'stars_count' => 2])])],
        'POST /api/v1/commits/c1/star' => [['message' => 'Server Error'], 500],
        'DELETE /api/v1/commits/c1/star' => [['message' => 'Server Error'], 500],
    ]);

    $screen = finishRefreshes(finishRefreshes(Native::visit('/log'))->tap('star-c1')->tap('star-c1'))->assertNativeNotCalled('ToastKit.Show');

    expect($screen->instance()->commits[0])->starred->toBeTrue()->starsCount->toBe(2)
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('leaves a commit as devnull has it when a quick star and the unstar after it are both turned down', function () {
    holdRefreshes();
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([adasCommit()])],
        'POST /api/v1/commits/c1/star' => [['message' => 'Server Error'], 500],
        'DELETE /api/v1/commits/c1/star' => [['message' => 'Server Error'], 500],
    ]);

    $screen = finishRefreshes(finishRefreshes(Native::visit('/log'))->tap('star-c1')->tap('star-c1'));

    expect($screen->instance()->commits[0])->starred->toBeFalse()->starsCount->toBe(1)
        ->and(kept('log/following')['data'][0])->toMatchArray(['starred' => false, 'stars_count' => 1])
        ->and(writesSent())->toBe(['POST', 'DELETE'])
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('sends a star and an unstar of one commit left over from when the app closed one after the other', function () {
    leftover(PendingWrite::Star, 'c1');
    leftover(PendingWrite::Unstar, 'c1');
    holdRefreshes();
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([adasCommit()])],
        'POST /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => true, 'stars_count' => 2])]],
        'DELETE /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => false, 'stars_count' => 1])]],
    ]);

    finishRefreshes(Native::visit('/log'), newestFirst: true);

    expect(writesSent())->toBe(['POST', 'DELETE'])
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('puts a star devnull turned down back, and stars again from Retry on its toast', function () {
    serveApi([
        'GET /api/v1/log/following' => [pageJson([adasCommit()])],
        'POST /api/v1/commits/c1/star' => [['message' => 'Server Error'], 500],
    ]);

    $screen = Native::visit('/log')->tap('star-c1');

    expect($screen->instance()->commits[0])->starred->toBeFalse()->starsCount->toBe(1)
        ->and(PendingWrite::query()->count())->toBe(0);

    serveApi(['POST /api/v1/commits/c1/star' => [['data' => adasCommit(['starred' => true, 'stars_count' => 2])]]]);
    pressToastAction($screen, 'retry-star', ['commit' => 'c1', 'starred' => true]);

    expect($screen->instance()->commits[0])->starred->toBeTrue()->starsCount->toBe(2)
        ->and(sentTo('POST', 'commits/c1/star'))->toHaveCount(2);
});

it('sends the stars and messages left over from when the app closed, since devnull cannot do them twice', function () {
    $star = leftover(PendingWrite::Star, 'c1');
    $message = leftover(PendingWrite::Message, 'remotes/@ada', ['body' => 'still there?']);
    serveApi(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/v1/commits/c1/star' => Http::response(['data' => adasCommit(['starred' => true, 'stars_count' => 2])]),
            $path === '/api/v1/remotes/@ada/messages' => Http::response(['data' => remoteMessageJson(['id' => 7, 'body' => 'still there?'])]),
            default => Http::response(pageJson([adasCommit()])),
        };
    });

    Native::visit('/log')->assertNativeNotCalled('ToastKit.Show');

    expect(sentTo('POST', 'commits/c1/star'))->toHaveCount(1)
        ->and(json_decode(sentTo('POST', 'remotes/@ada/messages')[0], true))->toBe(['body' => 'still there?', 'client_id' => $message->client_id])
        ->and(PendingWrite::query()->whereKey([$star->id, $message->id])->count())->toBe(0);
});

it('never sends a commit left over from when the app closed, and offers it back to edit instead', function () {
    $commit = leftover(PendingWrite::Commit, null, ['body' => 'Lost in transit', 'stash' => 'commit', 'composer' => 'compose']);
    fakeApi(['GET /api/v1/log/following' => [pageJson([adasCommit()])]]);

    $screen = Native::visit('/log')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === Outbox::ClosedBeforeAnswer
            && $params['variant'] === 'error'
            && $params['action'] === ['id' => 'edit', 'label' => 'Edit']
            && $params['data'] === ['write' => $commit->id]);

    // It isn't opened on you as the app opens. It waits for Edit, with its draft back in the stash.
    expect(sentTo('POST', 'commits'))->toBe([])
        ->and($commit->fresh())->status->toBe(PendingWrite::Failed)->error->toBe(Outbox::ClosedBeforeAnswer)
        ->and(app(Stash::class)->get($this->userId, 'commit')->body)->toBe('Lost in transit')
        ->and($screen->instance()->sheetOpen)->toBeFalse()
        ->and(array_map(fn ($shown): string => $shown->id, $screen->instance()->commits))->toBe(['c1']);

    pressToastAction($screen, 'edit', ['write' => $commit->id]);

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeTrue()
        ->and($screen->instance()->sheetView)->toBe('compose')
        ->and(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('Lost in transit')
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('brings back the photos and poll of a commit devnull turned down when its composer opens again', function () {
    $commit = leftover(PendingWrite::Commit, null, [
        'body' => 'Which one?',
        'poll' => ['options' => ['Tabs', 'Spaces'], 'duration' => 4320],
        'stash' => 'commit',
        'composer' => 'compose',
    ]);
    app(Outbox::class)->failed($commit, 'Too Many Attempts.');
    fakeApi(['GET /api/v1/log/following' => [pageJson([adasCommit()])]]);

    $screen = Native::visit('/log')->press('composeInSheet');

    expect($screen->instance())
        ->sheetBody->toBe('Which one?')
        ->sheetPoll->toBe(['Tabs', 'Spaces'])
        ->sheetPollLength->toBe('3 days')
        ->sheetRestored->toBeTrue()
        ->and(PendingWrite::query()->count())->toBe(0);
});

it("hears a reply devnull turned down after you've left its commit, and Edit takes you back to it", function () {
    holdRefreshes();
    keep('commits/c2', commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])));
    keep('commits/c2/replies', pageJson([]));
    fakeApi(['POST /api/v1/commits' => [['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429]]);

    Native::visit('/log/commits/c2')->input('reply-input', 'Hold on')->tap('send-reply');
    $write = PendingWrite::query()->sole();

    // devnull answers once you're on another tab, which hears it.
    $elsewhere = finishRefreshes(Native::visit('/notifications'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Too Many Attempts.'
            && $params['action'] === ['id' => 'edit', 'label' => 'Edit']
            && $params['data'] === ['write' => $write->id]);

    expect($write->fresh()->status)->toBe(PendingWrite::Failed)
        ->and(app(Stash::class)->get($this->userId, 'reply-c2')->body)->toBe('Hold on');

    pressToastAction($elsewhere, 'edit', ['write' => $write->id])
        ->assertNavigatedTo('/notifications/commits/c2');

    $back = Native::visit('/notifications/commits/c2', ['edit' => $write->id])
        ->assertSet('reply', 'Hold on')
        ->assertSet('replyRestored', true)
        ->assertSet('focusReply', true);

    expect($back->instance()->replies)->toBe([])
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('keeps a pushed message staged in its thread when you come back before devnull answers', function () {
    holdRefreshes();
    serveApi([
        'GET /api/v1/remotes/@ada/messages' => [threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1])],
        'POST /api/v1/remotes/@ada/messages' => [['message' => 'Nope.'], 403],
    ]);

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada'))->set('draft', 'on it')->call('send');
    $clientId = $screen->instance()->pending[0]->clientId;

    // A fresh copy of the thread, as after leaving it, still has it staged.
    $again = finishRefreshes(Native::visit('/remotes/conversations/ada'));

    expect($again->instance()->pending)->toHaveCount(1)
        ->and($again->instance()->pending[0])->clientId->toBe($clientId)->status->toBe('rejected');

    $again->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Nope.'
        && $params['action'] === ['id' => 'retry-message', 'label' => 'Retry']);

    $write = PendingWrite::query()->sole();
    expect($write->status)->toBe(PendingWrite::Failed);

    serveApi([
        'GET /api/v1/remotes/@ada/messages' => [threadJson([remoteMessageJson(['id' => 1])], ['read_through_id' => 1])],
        'POST /api/v1/remotes/@ada/messages' => [['data' => remoteMessageJson(['id' => 9, 'client_id' => $clientId, 'body' => 'on it'])], 201],
    ]);

    finishRefreshes(pressToastAction($again, 'retry-message', ['write' => $write->id]))->assertSet('pending', []);

    expect(array_map(fn (string $body): string => json_decode($body, true)['client_id'], sentTo('POST', 'remotes/@ada/messages')))->toBe([$clientId, $clientId])
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('empties the outbox when you sign out', function () {
    leftover(PendingWrite::Star, 'c1');

    app(Account::class)->forget();

    expect(PendingWrite::query()->count())->toBe(0);
});
