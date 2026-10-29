<?php

use App\NativeComponents\Log;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskRunner;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

/*
|--------------------------------------------------------------------------
| One Log tab tap: back to the top, and the log asked for again
|--------------------------------------------------------------------------
|
| A re-tap of the Log tab takes the log you're on back to its top and pulls
| it to refresh (Log::refreshLog, the callback Android's tab bar sends once
| the list is confirmed at its top — see LogBottomNavReselectTest). The tab
| bar's scrolling is Kotlin; what it ends in is a press at the published
| on_reselect id, which is what these tests fire, on a screen whose log is
| showing and loaded.
|
| The refresh says it's waiting: a chip under the pager's tabs (see
| partials/refreshing) from the moment of the press until the answer lands
| or fails, without disturbing the log, its list or where you are. The
| quiet asks — the poll, a resume, the next page, a pull to refresh, a
| switch of logs — never say so: the pull has the list's own spinner in
| your hand, and a switch's opening says itself.
|
| These are the PHP half of the story. What they cannot reproduce is the
| device's own: whether Android's tab bar still sends the reselect once the
| app has sat idle, and whether it scrolls the list to its top first, is
| Kotlin's to answer, on a device. What PHP can promise, and does here, is
| that whenever the press arrives — after any idle, with any refresh still
| out — the ask still goes out, the feedback still comes up, and every
| answer, however late, still settles it.
|
| The refresh itself is a background task (RefreshesInBackground), withheld
| by holdRefreshes() and landed by finishRefreshes(), as every screen test
| here does.
*/

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * The bottom navigation bar's tab for a section, as TabsLayout publishes it
 * in the active screen's chrome, as the reselect suite reads it.
 *
 * @return array<string, mixed>
 */
function feedbackTab(TestableComponent $screen, string $label): array
{
    $tab = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_nav_item'
        && ($node['props']['label'] ?? null) === $label);

    expect($tab)->not->toBeNull("The bottom navigation must publish a [{$label}] tab.");

    return $tab;
}

/**
 * The callback id the screen's registry resolves a method name to, as the
 * runloop does when a press lands from the device (the harness's registry
 * accessor is protected; the reselect suite binds into component internals
 * the same way).
 */
function feedbackCallbackId(TestableComponent $screen, string $method): ?int
{
    return Closure::bind(fn (): ?int => $this->callbacks()->lookup($method), $screen, TestableComponent::class)();
}

/**
 * The feedback a re-tap of the Log tab owes while devnull answers: something
 * on screen that says, to the eye and to a screen reader, that the log is
 * being fetched again — an activity indicator whose subtree carries an
 * accessibility label, or any node whose label says it is refreshing. The
 * skeleton rows a log with nothing kept to show draws, and the quiet
 * "couldn't refresh" banner after a failure, are not it: those answer
 * different questions.
 *
 * @return array<string, mixed>|null
 */
function refreshFeedback(TestableComponent $screen): ?array
{
    $labelled = fn (array $node): bool => findNode($node, fn (array $in): bool => (string) ($in['props']['a11y_label'] ?? '') !== '') !== null;

    return findNode($screen->tree(), function (array $node) use ($labelled): bool {
        return (in_array($node['type'] ?? null, ['activity_indicator'], true)
            || str_contains(mb_strtolower((string) ($node['props']['a11y_label'] ?? '')), 'refreshing'))
            && $labelled($node);
    });
}

/**
 * Serves the logs from a queue of answers, as devnull moving on between two
 * refreshes: each GET of a log's first page takes the next answer queued for
 * it, in the order asked, and `me` answers plainly. Next pages 404, so a
 * stray one shows up in the test.
 *
 * @param  array<string, list<array<string, mixed>>>  $answers
 */
function serveFeedbackFeeds(array $answers): void
{
    serveApi(function (Request $request) use (&$answers) {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1/'));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ($path === 'me') {
            return Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]);
        }

        if (isset($query['cursor']) || ($answers[$path] ?? []) === []) {
            return Http::response(['message' => "No fake for {$request->url()}."], 404);
        }

        return Http::response(array_shift($answers[$path]));
    });
}

/**
 * A log's first page as devnull sends it, with Trending's ranking shape.
 *
 * @param  list<array<string, mixed>>  $commits
 * @return array<string, mixed>
 */
function feedbackPage(string $log, array $commits): array
{
    $page = pageJson($commits, path: "http://devnull.test/api/v1/log/{$log}");

    return $log === Log::Ranked
        ? [...$page, 'log' => 'trending', 'is_ranked' => true, 'newest_commit_id' => $commits[0]['id'] ?? null, 'suggestions' => []]
        : $page;
}

/**
 * How many GETs have gone to one of the logs' first pages, not counting the
 * next pages a list asks for with a cursor.
 */
function feedbackAsks(string $log): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), "/api/v1/log/{$log}")
        && ! str_contains($request->url(), 'cursor='))->count();
}

/**
 * The payloads of the background refreshes still held, oldest first, by
 * their task id — read where they wait, without landing any of them.
 *
 * @return array<string, string>
 */
function heldRefreshPayloads(TestableComponent $screen): array
{
    $held = [];

    foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
        $id = $call['params']['id'];
        $path = AsyncTaskTransport::directory('payload').DIRECTORY_SEPARATOR.$id.'.task';

        if (is_file($path)) {
            $sealed = (string) file_get_contents($path);
            $payload = AsyncTaskTransport::readPayload($id);

            if ($payload !== null) {
                file_put_contents($path, $sealed);
                $held[$id] = $payload;
            }
        }
    }

    return $held;
}

/**
 * Lands a held background refresh, exactly as the background lane lands one:
 * runs the task its payload names and delivers its answer to the screen the
 * way the lane does. finishRefreshes() runs them all; the lane answers one
 * at a time and not in order, so $url lands the oldest held task asking for
 * it (or the newest, with $last) — a stale answer landing alone is what some
 * of these tests need.
 */
function landOneHeldRefresh(TestableComponent $screen, ?string $url = null, bool $last = false): void
{
    $matching = array_keys(array_filter(heldRefreshPayloads($screen), fn (string $payload): bool => $url === null || str_contains($payload, $url)));
    $id = $matching === [] ? null : ($last ? $matching[count($matching) - 1] : $matching[0]);
    $payload = $id === null ? null : AsyncTaskTransport::readPayload($id);
    $what = $url ?? 'anything';

    expect($payload)->not->toBeNull("No held background refresh for [{$what}] was left to land.");

    try {
        $result = AsyncTaskRunner::normalizeResult(AsyncTaskRunner::invoke(unserialize($payload)));
        $screen->emitNative(AsyncTaskFinished::class, ['id' => $id, 'result' => $result]);
    } catch (Throwable $exception) {
        $screen->emitNative(AsyncTaskFailed::class, ['id' => $id, 'exceptionClass' => $exception::class, 'message' => $exception->getMessage()]);
    }
}

/**
 * Forgets every held background refresh but the oldest, as a screen going
 * under another has all but its first ask answered and dropped — and keeps
 * that one, to land it late.
 */
function keepOldestHeldRefresh(TestableComponent $screen): void
{
    $held = array_keys(heldRefreshPayloads($screen));

    expect($held)->not->toBeEmpty('No held background refresh was left to keep.');

    array_shift($held);

    foreach ($held as $id) {
        AsyncTaskTransport::forgetPayload($id);
    }
}

it('says the log is being refreshed, to the eye and to a screen reader, while a re-tap of the Log tab is answered', function (string $log) {
    holdRefreshes();
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'f1', 'body' => 'Kept row'])]),
            feedbackPage('following', [commitJson(['id' => 'f2', 'body' => 'Retapped in']), commitJson(['id' => 'f1', 'body' => 'Kept row'])]),
        ],
        'log/latest' => [
            feedbackPage('latest', [commitJson(['id' => 'l1', 'body' => 'Kept row'])]),
            feedbackPage('latest', [commitJson(['id' => 'l2', 'body' => 'Retapped in']), commitJson(['id' => 'l1', 'body' => 'Kept row'])]),
        ],
        'log/trending' => [
            feedbackPage('trending', [commitJson(['id' => 't1', 'body' => 'Kept row'])]),
            feedbackPage('trending', [commitJson(['id' => 't2', 'body' => 'Retapped in']), commitJson(['id' => 't1', 'body' => 'Kept row'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'));
    $screen = finishRefreshes(tapLogTab($screen, $log))->assertSee('Kept row');

    // The confirmed-top press the tab bar sends, at the published on_reselect id.
    $onReselect = feedbackTab($screen, 'Log')['props']['on_reselect'] ?? null;

    expect($onReselect)->toBeInt()
        ->and(feedbackCallbackId($screen, 'refreshLog'))->toBe($onReselect);

    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    // Immediately, before anything lands.
    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    $screen->assertSee('Kept row');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull('The feedback outlived the refresh it was for.');

    $screen->assertSee('Retapped in')->assertSee('Kept row');
})->with(Log::Logs);

it('keeps a switch of logs quiet, its opening saying itself and never the re-tap chip', function (string $log) {
    holdRefreshes();
    keep("log/{$log}", feedbackPage($log, [commitJson(['id' => 'k1', 'body' => 'Kept row'])]));
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept on Following'])]),
        ],
        "log/{$log}" => [
            feedbackPage($log, [commitJson(['id' => 'n1', 'body' => 'Switched in']), commitJson(['id' => 'k1', 'body' => 'Kept row'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'))->assertSee('Kept on Following');

    $asks = feedbackAsks($log);

    // The pager settling on another log: what was kept for it opens at once
    // and devnull is asked again — quietly, the kept rows saying the opening
    // as the re-tap chip never may.
    $screen = tapLogTab($screen, $log);

    expect(refreshFeedback($screen))->toBeNull('A switch of logs put up the re-tap refresh chip.')
        ->and($screen->get('log'))->toBe($log);

    $screen->assertSee('Kept row');

    finishRefreshes($screen);

    // The ask went out and landed quietly. Trending is ranked afresh, so its
    // rows go straight in; a feed's newer rows wait for the pill, as ever.
    expect(refreshFeedback($screen))->toBeNull()
        ->and(feedbackAsks($log))->toBe($asks + 1)
        ->and($screen->get('log'))->toBe($log);

    $screen->assertSee('Kept row');
})->with(['latest', 'trending']);

it('keeps a pull to refresh quiet, the list\'s own spinner saying it instead', function (string $log) {
    holdRefreshes();
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
            feedbackPage('following', [commitJson(['id' => 'c2', 'body' => 'Pulled in']), commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
        ],
        'log/latest' => [
            feedbackPage('latest', [commitJson(['id' => 'l1', 'body' => 'Kept row'])]),
            feedbackPage('latest', [commitJson(['id' => 'l2', 'body' => 'Pulled in']), commitJson(['id' => 'l1', 'body' => 'Kept row'])]),
        ],
        'log/trending' => [
            feedbackPage('trending', [commitJson(['id' => 't1', 'body' => 'Kept row'])]),
            feedbackPage('trending', [commitJson(['id' => 't2', 'body' => 'Pulled in']), commitJson(['id' => 't1', 'body' => 'Kept row'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'));
    $screen = finishRefreshes(tapLogTab($screen, $log))->assertSee('Kept row');

    $asks = feedbackAsks($log);

    // The pull, as the list sends it with its own spinner in your hand: the
    // ask still goes out, but the chip is the re-tap's alone.
    $screen->call('load');

    expect(refreshFeedback($screen))->toBeNull('A pull to refresh put up the re-tap refresh chip.');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull()
        ->and(feedbackAsks($log))->toBe($asks + 1);

    $screen->assertSee('Pulled in')->assertSee('Kept row');
})->with(Log::Logs);

it('keeps the asks quiet on iOS, where the tab bar never sends a re-tap at all', function () {
    holdRefreshes();
    keep('log/latest', feedbackPage('latest', [commitJson(['id' => 'l1', 'body' => 'Kept on Latest'])]));
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept on Following'])]),
        ],
        'log/latest' => [
            feedbackPage('latest', [commitJson(['id' => 'l2', 'body' => 'Switched in']), commitJson(['id' => 'l1', 'body' => 'Kept on Latest'])]),
            feedbackPage('latest', [commitJson(['id' => 'l3', 'body' => 'Pulled in']), commitJson(['id' => 'l2', 'body' => 'Switched in'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'ios'))->assertSee('Kept on Following');

    // A switch and a pull, the same asks as Android's: on the platform with
    // no reselect to carry the chip, nothing may put it up.
    $screen = tapLogTab($screen, 'latest');

    expect(refreshFeedback($screen))->toBeNull('A switch of logs put up the re-tap refresh chip.');

    finishRefreshes($screen);

    // The feed's newer rows wait for the pill, as ever — quietly either way.
    expect(refreshFeedback($screen))->toBeNull();

    $screen->assertSee('Kept on Latest');

    $screen->call('load');

    expect(refreshFeedback($screen))->toBeNull('A pull to refresh put up the re-tap refresh chip.');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull();

    $screen->assertSee('Pulled in');
});

it('keeps the log, its list and its rows where they are while the re-tap refresh is answered, and lays the fresh rows in place', function () {
    holdRefreshes();
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
            feedbackPage('following', [commitJson(['id' => 'c2', 'body' => 'Retapped in']), commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'))->assertSee('Kept row');

    $listKey = $screen->get('listKey');
    $list = showingLog($screen)['id'];

    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    expect($screen->get('log'))->toBe('following')
        ->and($screen->get('listKey'))->toBe($listKey)
        ->and(showingLog($screen)['id'])->toBe($list)
        ->and($screen->navigationIntent())->toBeNull();

    $screen->assertSee('Kept row');

    finishRefreshes($screen);

    expect($screen->get('log'))->toBe('following')
        ->and($screen->get('listKey'))->toBe($listKey)
        ->and(showingLog($screen)['id'])->toBe($list)
        ->and($screen->navigationIntent())->toBeNull();

    $screen->assertSee('Retapped in')->assertSee('Kept row');
});

it('clears the feedback when the refresh cannot be done, saying so the quiet way instead', function (string $log) {
    holdRefreshes();

    $offline = false;
    $page = fn (): array => feedbackPage($log, [commitJson(['id' => 'k1', 'body' => 'Kept row'])]);
    serveApi(function (Request $request) use (&$offline, $log, $page) {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1/'));

        if ($offline && in_array($path, ['me', "log/{$log}"], true)) {
            throw new ConnectionException('cURL error 6: Could not resolve host: devnull.test');
        }

        return match ($path) {
            'me' => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
            "log/{$log}" => Http::response($page()),
            default => Http::response(['message' => "No fake for {$path}."], 404),
        };
    });

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'));
    $screen = finishRefreshes(tapLogTab($screen, $log))->assertSee('Kept row');

    $offline = true;

    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    finishRefreshes($screen);

    // Waiting is over, one way or another: the feedback goes and the quiet
    // banner takes its turn, with what was showing kept where it was.
    expect(refreshFeedback($screen))->toBeNull('The feedback outlived a refresh that failed.')
        ->and($screen->get('refreshFailed'))->toBeTrue()
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'refresh-failed'))->not->toBeNull();

    $screen->assertSee('Kept row');
})->with(['following', 'trending']);

it('keeps the feedback up while a newer refresh of the same log is still out, when an older answer lands', function () {
    holdRefreshes();
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
            feedbackPage('following', [commitJson(['id' => 'c2', 'body' => 'First answer'])]),
            feedbackPage('following', [commitJson(['id' => 'c3', 'body' => 'Second answer'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'))->assertSee('Kept row');

    // Two refreshes of the same log out at once, as the reselect suite sends.
    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);
    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    // The older one's answer lands first, as the lane answers one at a time.
    landOneHeldRefresh($screen);

    expect(refreshFeedback($screen))->not->toBeNull('An older answer cleared the feedback while a newer refresh is still out.');

    $screen->assertSee('First answer');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull();

    $screen->assertSee('Second answer');
});

it('keeps the feedback for the log you switched to while the log you left is still being answered', function () {
    holdRefreshes();
    keep('log/latest', feedbackPage('latest', [commitJson(['id' => 'l1', 'body' => 'Kept on Latest'])]));
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept on Following'])]),
            feedbackPage('following', [commitJson(['id' => 'c2', 'body' => 'Late from following'])]),
        ],
        'log/latest' => [
            feedbackPage('latest', [commitJson(['id' => 'l1', 'body' => 'Kept on Latest'])]),
            feedbackPage('latest', [commitJson(['id' => 'l2', 'body' => 'Latest landed']), commitJson(['id' => 'l1', 'body' => 'Kept on Latest'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'))->assertSee('Kept on Following');

    // A re-tap refresh of Following, then away to Latest before it answers.
    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    // The switch stays quiet; a re-tap on Latest owns its own feedback while
    // Following's earlier answer is still out.
    tapLogTab($screen, 'latest')->assertSee('Kept on Latest');

    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    // Following's answer lands first, stale now that you've left: an answer
    // for a log nobody is on must not settle the refresh that's showing.
    landOneHeldRefresh($screen);

    expect(refreshFeedback($screen))->not->toBeNull('A stale answer for the log you left cleared the feedback for the log you\'re on.');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull()
        ->and($screen->get('log'))->toBe('latest');

    $screen->assertSee('Latest landed');
});

it('still asks, and still says so, once the log has sat idle past its refresh windows', function () {
    holdRefreshes();
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Polled in'])]),
            feedbackPage('following', [commitJson(['id' => 'c2', 'body' => 'Retapped in']), commitJson(['id' => 'c1', 'body' => 'Polled in'])]),
            feedbackPage('following', [commitJson(['id' => 'c3', 'body' => 'Retapped again']), commitJson(['id' => 'c2', 'body' => 'Retapped in'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'))->assertSee('Kept row');

    // The screen sits idle: its poll asks and is answered, and time moves on
    // past every thirty-second window a refresh is remembered by. This is as
    // close as PHP comes to the user coming back to an idle phone; whether
    // the tab bar still sends the reselect then is the device's half.
    finishRefreshes($screen->firePolls())->assertSee('Polled in');

    $this->travel(31)->seconds();

    $asks = feedbackAsks('following');
    $sent = refreshesSent($screen);

    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    // Still asked — the press still puts its ask in the background — and
    // still said: nothing about having been idle may hold either back.
    expect(refreshesSent($screen))->toBeGreaterThan($sent)
        ->and(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    // The answer is late, later than every window, and the feedback waits
    // for it all the same: no clock takes it down, only the answer.
    $this->travel(35)->seconds();

    expect(refreshFeedback($screen))->not->toBeNull('The feedback gave up on a refresh still out.');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull()
        ->and(feedbackAsks('following'))->toBe($asks + 1);

    $screen->assertSee('Retapped in');

    // And once more after that: whatever the feedback ran on has let go.
    $sent = refreshesSent($screen);

    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    expect(refreshesSent($screen))->toBeGreaterThan($sent)
        ->and(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull();

    $screen->assertSee('Retapped again');
});

it('lets the feedback go when the screen goes under another, whose answers were dropped', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'A commit'])));
    keep('commits/c1/replies', pageJson([], path: 'http://devnull.test/api/v1/commits/c1/replies'));
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
            feedbackPage('following', [commitJson(['id' => 'c2', 'body' => 'Landed while away'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log'))->assertSee('Kept row');

    // The reselect refresh, sent as the tab bar sends it (see the rest of
    // this suite for the wiring).
    $screen->call('refreshLog');

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    // Under a commit, with the refresh still out: its answer is dropped, as
    // NativePHP drops the answers a buried screen asked for.
    $child = $screen->tap('commit-c1')->assertNavigatedTo('/log/commits/c1')->follow();

    foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
        AsyncTaskTransport::forgetPayload($call['params']['id']);
    }

    // Back: the refresh it never heard the end of is forgotten with its
    // dropped answer, and the one the resume makes is quiet.
    $screen = $child->goBack();

    expect(refreshFeedback($screen))->toBeNull('The feedback outlived the screen going under another.');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull();

    $screen->assertSee('Kept row');
});

it('says only the log that is showing is being refreshed, so a slower other log cannot keep its chip up', function () {
    holdRefreshes();
    keep('log/latest', feedbackPage('latest', [commitJson(['id' => 'l1', 'body' => 'Kept on Latest'])]));
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept on Following'])]),
            feedbackPage('following', [commitJson(['id' => 'c2', 'body' => 'Late from following'])]),
        ],
        'log/latest' => [
            feedbackPage('latest', [commitJson(['id' => 'l1', 'body' => 'Latest landed'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'android'))->assertSee('Kept on Following');

    // A re-tap refresh of Following, then away to Latest before it answers.
    $screen->fireEvent('refreshLog', TestableComponent::EVENT_PRESS);

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    tapLogTab($screen, 'latest')->assertSee('Kept on Latest');

    // Latest's own answer lands first, as the lane can answer out of order:
    // the log you're on is no longer being asked for, so its chip goes, though
    // Following's ask is still out beneath it.
    landOneHeldRefresh($screen, 'log/latest');

    expect(refreshFeedback($screen))->toBeNull('An ask for a log you are not on kept the chip up after the log you are on was answered.');

    $screen->assertSee('Latest landed');

    // Following's answer landing late changes nothing on screen.
    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull()
        ->and($screen->get('log'))->toBe('latest');
});

it('keeps the chip for a new re-tap refresh when an answer from before the screen went under lands late', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'A commit'])));
    keep('commits/c1/replies', pageJson([], path: 'http://devnull.test/api/v1/commits/c1/replies'));
    serveFeedbackFeeds([
        'log/following' => [
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
            feedbackPage('following', [commitJson(['id' => 'c2', 'body' => 'Answer from before'])]),
            feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
            feedbackPage('following', [commitJson(['id' => 'c3', 'body' => 'New answer'])]),
        ],
    ]);

    $screen = finishRefreshes(Native::visit('/log'))->assertSee('Kept row');

    $screen->call('refreshLog');

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    // Under a commit and back: the refresh it asked for is forgotten, its
    // answer dropped — its payload is kept, to land it late below.
    $child = $screen->tap('commit-c1')->assertNavigatedTo('/log/commits/c1')->follow();
    $screen = $child->goBack();

    expect(refreshFeedback($screen))->toBeNull('The feedback outlived the screen going under another.');

    // A new re-tap forces a fresh ask of its own.
    $screen->call('refreshLog');

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    // The old answer lands now, out of its time. What it brought still shows
    // — stale-while-revalidate, as ever — but it owns nothing: the chip stays
    // up for the ask that is still out, and that ask's marker with it.
    landOneHeldRefresh($screen, 'log/following');

    expect(refreshFeedback($screen))->not->toBeNull('An answer from before the screen went under cleared the chip for the refresh that is still out.');

    $screen->assertSee('Answer from before');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull();

    $screen->assertSee('New answer');
});

it('keeps the chip for a new re-tap refresh when a failure from before the screen went under lands late', function () {
    holdRefreshes();
    keep('commits/c1', commitPageJson(commitJson(['id' => 'c1', 'body' => 'A commit'])));
    keep('commits/c1/replies', pageJson([], path: 'http://devnull.test/api/v1/commits/c1/replies'));

    $offline = false;
    $answers = [
        feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
        feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
        feedbackPage('following', [commitJson(['id' => 'c3', 'body' => 'New answer'])]),
    ];
    serveApi(function (Request $request) use (&$offline, &$answers) {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1/'));

        if ($path === 'me') {
            return Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]);
        }

        if ($offline && $path === 'log/following') {
            throw new ConnectionException('cURL error 6: Could not resolve host: devnull.test');
        }

        return $path === 'log/following' && $answers !== []
            ? Http::response(array_shift($answers))
            : Http::response(['message' => "No fake for {$path}."], 404);
    });

    $screen = finishRefreshes(Native::visit('/log'))->assertSee('Kept row');

    $screen->call('refreshLog');

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    $child = $screen->tap('commit-c1')->assertNavigatedTo('/log/commits/c1')->follow();
    $screen = $child->goBack();

    $screen->call('refreshLog');

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    // The old ask fails now, out of its time, offline. It says so the quiet
    // way, as any failed refresh does — but it owns nothing: the chip stays
    // up for the ask that is still out.
    $offline = true;

    landOneHeldRefresh($screen, 'log/following');

    $offline = false;

    expect(refreshFeedback($screen))->not->toBeNull('A failure from before the screen went under cleared the chip for the refresh that is still out.');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull();

    $screen->assertSee('New answer');
});

it('keeps the chip across a server error retry, whose late twin from before the screen went under owns nothing', function () {
    holdRefreshes();
    keep('commits/c3', commitPageJson(commitJson(['id' => 'c3', 'body' => 'A commit'])));
    keep('commits/c3/replies', pageJson([], path: 'http://devnull.test/api/v1/commits/c3/replies'));

    // The mount's ask answers plainly; the next ask of the log meets a server
    // error, and every one after answers.
    $answers = [
        feedbackPage('following', [commitJson(['id' => 'c1', 'body' => 'Kept row'])]),
        'error',
        feedbackPage('following', [commitJson(['id' => 'c3', 'body' => 'Answered'])]),
        'error',
        feedbackPage('following', [commitJson(['id' => 'c3', 'body' => 'Answered'])]),
        feedbackPage('following', [commitJson(['id' => 'c3', 'body' => 'Answered'])]),
    ];
    serveApi(function (Request $request) use (&$answers) {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1/'));

        if ($path === 'me') {
            return Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]);
        }

        if ($path === 'log/following' && ($answers[0] ?? null) === 'error') {
            array_shift($answers);

            return Http::response(['message' => 'Server Error'], 500);
        }

        return $path === 'log/following' && $answers !== []
            ? Http::response(array_shift($answers))
            : Http::response(['message' => "No fake for {$path}."], 404);
    });

    $screen = finishRefreshes(Native::visit('/log'))->assertSee('Kept row');

    // A re-tap whose answer is a server error: the ask is made once more,
    // signed afresh, and the chip holds through both, as one refresh.
    $screen->call('refreshLog');

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    landOneHeldRefresh($screen, 'log/following');

    expect(refreshFeedback($screen))->not->toBeNull('The chip left while a server error retry was still out.');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull();

    $screen->assertSee('Answered');

    // The same error, but from an ask made before the screen went under a
    // child: its retry is asked for and answered, and owns nothing.
    $screen->call('refreshLog');

    $child = $screen->tap('commit-c3')->assertNavigatedTo('/log/commits/c3')->follow();
    $screen = $child->goBack();

    keepOldestHeldRefresh($screen);

    $screen->call('refreshLog');

    expect(refreshFeedback($screen))->not->toBeNull('Nothing on the Log says a re-tap refresh is under way.');

    // The old ask's server error, landing late, sends its retry.
    landOneHeldRefresh($screen, 'log/following');

    // The retry lands before the new ask does: it owns nothing, so the chip
    // is still up, waiting on the new ask.
    landOneHeldRefresh($screen, 'log/following', last: true);

    expect(refreshFeedback($screen))->not->toBeNull('A retry from before the screen went under took the chip from the refresh that is still out.');

    finishRefreshes($screen);

    expect(refreshFeedback($screen))->toBeNull();

    $screen->assertSee('Answered');
});
