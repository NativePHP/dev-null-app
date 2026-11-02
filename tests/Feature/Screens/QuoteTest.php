<?php

use App\Devnull\Data\Commit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Ada, someone else whose commits you can quote.
 *
 * @return array<string, mixed>
 */
function quoteAda(): array
{
    return userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']);
}

/**
 * Serves a Following log with these commits, and answers a new commit with $answer.
 *
 * @param  list<array<string, mixed>>  $commits
 * @param  array{0: mixed, 1?: int}  $answer
 */
function serveQuoteLog(array $commits, ?array $answer = null): void
{
    $answer ??= [['data' => commitJson(['id' => 'q1', 'body' => 'This, all of it.', 'quoted_commit_id' => 'c1', 'quoted_commit' => commitJson(['id' => 'c1', 'author' => quoteAda()])])], 201];

    serveApi(fn (Request $request) => $request->method() === 'POST'
        ? Http::response(...$answer)
        : Http::response(pageJson($commits)));
}

/**
 * A log with one commit of Ada's, its quote sheet open.
 *
 * @param  array{0: mixed, 1?: int}|null  $answer
 */
function quoteSheetOnLog(?array $answer = null): TestableComponent
{
    serveQuoteLog([commitJson(['id' => 'c1', 'body' => 'Required reading.', 'author' => quoteAda()])], $answer);

    return Native::visit('/log', platform: 'ios')->tap('quote-c1');
}

function quoteButtonDisabled(TestableComponent $screen): bool
{
    return ! sheetButtonEnabled($screen, 'sheet-commit');
}

it('makes Quote a button with a full target where the row had it', function () {
    serveQuoteLog([
        commitJson(['id' => 'c4', 'author' => quoteAda()]),
        commitJson(['id' => 'c3']),
        commitJson(['id' => 'c2', 'status' => 'pending']),
        commitJson(['id' => 'c1', 'status' => 'pending', 'author' => quoteAda()]),
    ]);

    $screen = Native::visit('/log', platform: 'ios');

    // Someone else's published commit, and any of your own, as on the web.
    foreach (['c4', 'c3', 'c2'] as $id) {
        $quote = nodeByRef($screen, 'quote-'.$id);

        expect($quote['type'])->toBe('pressable')
            ->and($quote['props']['a11y_label'])->toBe('Quote')
            ->and($quote['layout'])->toMatchArray(['min_width' => 44.0, 'height' => 36.0]);
    }

    // Not someone else's that isn't published, and nothing opens a sheet for it.
    $screen->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'quote-c1')
        ->call('quoteCommit', 'c1');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse();
});

it('opens a composer over the commit being quoted, drawn as the static quote card', function () {
    $screen = quoteSheetOnLog();

    expect(nodeByRef($screen, 'sheet')['props'])->toMatchArray(['visible' => true, 'detents' => 'large'])
        ->and(nodeByRef($screen, 'sheet-body')['props'])->toMatchArray([
            'placeholder' => 'Add your take',
            'multiline' => true,
            'max_length' => Commit::MaxRawLength,
            'autofocus' => true,
        ]);

    $card = nodeByRef($screen, 'quote-card');

    expect($card['type'])->toBe('column')
        ->and($card['on_press'] ?? null)->toBeNull()
        ->and($card['props']['a11y_label'])->toBe('Quoting Ada')
        ->and(treeShows($card, 'Required reading.'))->toBeTrue()
        ->and(treeShows($card, '@ada'))->toBeTrue();

    $screen->assertSee('Quote @ada');
});

it('keeps Commit disabled until the quote is long enough, and short enough', function () {
    $screen = quoteSheetOnLog();

    expect(quoteButtonDisabled($screen))->toBeTrue();

    $screen->input('sheet-body', '  ');
    expect(quoteButtonDisabled($screen))->toBeTrue();

    $screen->input('sheet-body', 'a');
    expect(quoteButtonDisabled($screen))->toBeFalse();

    // The Log composer's counter, once few characters are left.
    $screen->input('sheet-body', str_repeat('a', Commit::MaxLength - 10));
    expect(quoteButtonDisabled($screen))->toBeFalse()
        ->and(nodeByRef($screen, 'sheet-budget')['props']['text'])->toBe('10');

    $screen->input('sheet-body', str_repeat('a', Commit::MaxLength + 1));
    expect(quoteButtonDisabled($screen))->toBeTrue();

    // Pressing it anyway sends nothing.
    $screen->call('commitFromSheet');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('commits the quote with the id of the commit it quotes, and puts it at the top of the log', function () {
    $screen = quoteSheetOnLog()
        ->input('sheet-body', '  This, all of it.  ')
        ->tap('sheet-commit');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://devnull.test/api/v1/commits'
        && $request->body() === '{"body":"This, all of it.","quoted_commit_id":"c1"}');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse()
        ->and(array_map(fn (Commit $commit): string => $commit->id, $screen->instance()->commits))->toBe(['q1', 'c1']);

    $json = json_encode($screen->tree());
    expect(strpos($json, '"commit-q1"'))->toBeLessThan(strpos($json, '"commit-c1"'));

    $screen->assertSee('This, all of it.')
        ->assertNativeNotCalled('ToastKit.Show')
        ->assertNoNavigation();

    // Once it's gone, the draft goes with it.
    $screen->dismissSheet('sheet');

    expect($screen->instance())
        ->sheetCommit->toBeNull()
        ->sheetBody->toBe('');
});

it('says why devnull said no in a toast, and opens the quote again on the draft', function () {
    $screen = quoteSheetOnLog([['message' => 'That commit is no longer available to quote.', 'errors' => ['quoted_commit_id' => ['That commit is no longer available to quote.']]], 422])
        ->input('sheet-body', 'This, all of it.')
        ->tap('sheet-commit')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'That commit is no longer available to quote.'
            && $params['action'] === ['id' => 'edit', 'label' => 'Edit']);

    // It waits for the sheet it was sent from to go.
    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse();

    $screen->dismissSheet('sheet');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('This, all of it.')
        ->and($screen->instance()->sheetView)->toBe('quote')
        ->and(quoteButtonDisabled($screen))->toBeFalse()
        ->and(nodeByRef($screen, 'sheet')['props']['visible'])->toBeTrue()
        ->and($screen->instance()->error)->toBeNull()
        ->and(array_map(fn (Commit $commit): string => $commit->id, $screen->instance()->commits))->toBe(['c1']);
});

it('keeps the draft when the sheet is swiped away, and opens on it again, marked as restored', function () {
    $screen = quoteSheetOnLog()->input('sheet-body', 'Half a thought');

    $screen->dismissSheet('sheet');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse()
        ->and($screen->instance())
        ->sheetCommit->toBeNull()
        ->sheetBody->toBe('');

    $screen->tap('quote-c1');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('Half a thought')
        ->and(nodeByRef($screen, 'sheet-stash'))->not->toBeEmpty()
        ->and(quoteButtonDisabled($screen))->toBeFalse();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('quotes from a commit page, where closing the sheet is all there is to do', function () {
    serveApi(fn (Request $request) => match ($request->method().' '.parse_url($request->url(), PHP_URL_PATH)) {
        'GET /api/v1/commits/c2' => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'author' => quoteAda()]))),
        'GET /api/v1/commits/c2/replies' => Http::response(pageJson([])),
        'POST /api/v1/commits' => Http::response(['data' => commitJson(['id' => 'q2', 'body' => 'Worth a read.', 'quoted_commit_id' => 'c2'])], 201),
        default => Http::response(['message' => 'No fake.'], 404),
    });

    $screen = Native::visit('/log/commits/c2', platform: 'ios')
        ->tap('quote-c2')
        ->assertSee('Quote @ada')
        ->input('sheet-body', 'Worth a read.')
        ->tap('sheet-commit')
        ->assertNoNavigation();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->body() === '{"body":"Worth a read.","quoted_commit_id":"c2"}');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse()
        ->and($screen->instance()->commit->id)->toBe('c2');
});
