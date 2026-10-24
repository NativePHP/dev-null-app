<?php

use App\Devnull\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo(now()->parse('2026-10-19T12:00:00Z'));
});

/**
 * A poll as devnull sends it to someone who hasn't voted, or with $results once they have.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function openPollJson(array $overrides = [], bool $results = false): array
{
    return [
        'id' => 'poll1',
        'closes_at' => now()->addDay()->toIso8601ZuluString(),
        'is_closed' => false,
        'has_voted' => $results,
        'total_votes' => $results ? 3 : null,
        'options' => [
            ['id' => 'o1', 'label' => 'Tabs', 'votes_count' => $results ? 2 : null, 'voted' => false, 'percentage' => $results ? 67 : null],
            ['id' => 'o2', 'label' => 'Spaces', 'votes_count' => $results ? 1 : null, 'voted' => $results, 'percentage' => $results ? 33 : null],
        ],
        ...$overrides,
    ];
}

/**
 * The Log with Ada's commit c1 and its poll, whose vote devnull answers with $answer.
 *
 * @param  array<string, mixed>  $commit
 * @param  array{0: mixed, 1?: int}|null  $answer
 */
function pollOnLog(array $commit = [], ?array $answer = null): TestableComponent
{
    $ada = userJson(['id' => 'u2', 'username' => 'ada']);

    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Tabs or spaces?', 'author' => $ada, 'poll' => openPollJson(), ...$commit])])],
        'POST /api/v1/'.Client::pollVotesPath('c1') => $answer ?? [['data' => commitJson(['id' => 'c1', 'body' => 'Tabs or spaces?', 'author' => $ada, 'poll' => openPollJson(results: true)])]],
    ]);

    return Native::visit('/log', platform: 'ios');
}

it('picks an option, then votes, and shows the results here and in what\'s kept', function () {
    $screen = pollOnLog();

    expect(nodeByRef($screen, 'poll-vote-c1')['props'])->toMatchArray(['label' => 'Vote', 'disabled' => true])
        ->and(nodeByRef($screen, 'poll-summary-c1')['props']['text'])->toBe('1d left');

    $screen->tap('poll-option-o2');

    expect(nodeByRef($screen, 'poll-picked-o2'))->not->toBeEmpty()
        ->and(nodeByRef($screen, 'poll-option-o2')['props']['a11y_label'])->toBe('Spaces, option 2 of 2, picked')
        ->and(nodeByRef($screen, 'poll-vote-c1')['props']['disabled'] ?? false)->toBeFalse();

    $screen->tap('poll-vote-c1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://devnull.test/api/v1/commits/c1/poll/votes'
        && $request->body() === '{"option":"o2"}');

    expect(nodeByRef($screen, 'poll-voted-o2'))->not->toBeEmpty()
        ->and(nodeByRef($screen, 'poll-summary-c1')['props']['text'])->toBe('3 votes · 1d left')
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'poll-vote-c1'))->toBeNull()
        ->and($screen->instance()->pollChoices)->toBe([])
        ->and(kept('log/following')['data'][0]['poll']['has_voted'])->toBeTrue();
});

it('spins Vote while the vote goes, and says why devnull turned it down, keeping the pick', function () {
    holdRefreshes();

    $screen = pollOnLog(answer: [['message' => 'This poll has closed.', 'errors' => ['option' => ['This poll has closed.']]], 422]);
    finishRefreshes($screen);

    $screen->tap('poll-option-o1')->tap('poll-vote-c1');

    expect(nodeByRef($screen, 'poll-vote-c1')['props'])->toMatchArray(['loading' => true, 'disabled' => true]);

    finishRefreshes($screen)->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'This poll has closed.');

    expect(nodeByRef($screen, 'poll-vote-c1')['props'])->not->toHaveKey('loading')
        ->and($screen->instance()->pollChoices)->toBe(['c1' => 'o1']);
});

it('offers no vote where the web offers none', function (array $commit) {
    $screen = pollOnLog($commit)->call('choosePollOption', 'c1', 'o1');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'poll-vote-c1'))->toBeNull()
        ->and(nodeByRef($screen, 'poll-option-o1')['type'])->not->toBe('pressable')
        ->and($screen->instance()->pollChoices)->toBe([]);

    $screen->call('voteOnPoll', 'c1');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with([
    'your own poll' => [['author' => userJson()]],
    'one awaiting approval' => [['status' => 'pending', 'poll' => openPollJson(['closes_at' => null])]],
    'a closed poll' => [['poll' => openPollJson(['closes_at' => '2026-10-19T11:59:00Z'])]],
    'one you voted on' => [['poll' => openPollJson(results: true)]],
]);
