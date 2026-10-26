<?php

use App\Devnull\ReportReason;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Ada, someone else whose commits you can report.
 *
 * @return array<string, mixed>
 */
function sheetAda(): array
{
    return userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']);
}

/**
 * Serves a Following log with these commits, and answers reports as devnull would, or with $reportAnswer.
 *
 * @param  list<array<string, mixed>>  $commits
 * @param  array{0: mixed, 1?: int}  $reportAnswer
 */
function serveSheetLog(array $commits, array $reportAnswer = [null, 204]): void
{
    serveApi(fn (Request $request) => match (true) {
        $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/reports') => Http::response(...$reportAnswer),
        default => Http::response(pageJson($commits)),
    });
}

/**
 * The bottom sheet's props: whether it's up, and how tall it may be.
 *
 * @return array<string, mixed>
 */
function sheetProps(TestableComponent $screen): array
{
    return nodeByRef($screen, 'sheet')['props'];
}

/**
 * A log with one commit of Ada's, its sheet opened on the report form from More.
 */
function reportFormOnLog(array $reportAnswer = [null, 204]): TestableComponent
{
    serveSheetLog([commitJson(['id' => 'c1', 'body' => 'Buy cheap stars at example.com', 'author' => sheetAda()])], $reportAnswer);

    return Native::visit('/log', platform: 'ios')->tap('report-c1');
}

it('puts Report commit in More only on commits you can report', function () {
    serveSheetLog([
        commitJson(['id' => 'c3', 'author' => sheetAda()]),
        commitJson(['id' => 'c2']),
        commitJson(['id' => 'c1', 'status' => 'pending', 'author' => sheetAda()]),
    ]);

    $screen = Native::visit('/log', platform: 'ios');

    // Someone else's published commit: after a divider, the web's Report.
    expect(array_slice(menuItems($screen, 'more-c3'), -2))->toBe(['divider', ['report-c3', 'Report commit', 'custom:flag']]);

    // Your own commit, and someone else's that isn't published, can't be reported.
    foreach (['c2', 'c1'] as $id) {
        expect(array_column(array_filter(menuItems($screen, 'more-'.$id), is_array(...)), 0))->not->toContain('report-'.$id);
    }

    // The sheet is there, waiting, but down.
    expect(sheetProps($screen)['visible'])->toBeFalse();

    // Nor does anything open it for a commit More has no Report on.
    $screen->call('reportCommit', 'c2')->call('reportCommit', 'c1');

    expect(sheetProps($screen)['visible'])->toBeFalse();
});

it("turns the sheet into the web's report form", function () {
    $screen = reportFormOnLog();

    expect(sheetProps($screen))->toMatchArray(['visible' => true, 'detents' => 'large']);

    $screen->assertSee('Report commit')
        ->assertSee('Your report is private. Only moderators can review it. Reporting does not hide this commit.');

    $body = nodeByRef($screen, 'report-body');
    $reasons = nodeByRef($screen, 'report-reason');
    $details = nodeByRef($screen, 'report-details');

    expect($body['props']['text'])->toBe('Buy cheap stars at example.com')
        ->and($body['props']['max_lines'])->toBe(3)
        ->and($reasons['type'])->toBe('radio_group')
        ->and($reasons['props']['label'])->toBe('Why are you reporting this commit?')
        ->and(array_map(fn (array $radio): array => [$radio['props']['value'], $radio['props']['label']], $reasons['children']))->toBe([
            ['spam', 'Spam or misleading links'],
            ['harassment', 'Harassment or hate'],
            ['unsafe', 'Harmful or private information'],
            ['other', 'Something else'],
        ])
        ->and($details['props'])->toMatchArray([
            'label' => 'Details (optional)',
            'supporting' => 'Add context for moderators. Up to 500 characters.',
            'multiline' => true,
            'max_length' => ReportReason::DetailsMaxLength,
        ]);
});

it('keeps Send report disabled until a reason is picked', function () {
    $screen = reportFormOnLog();

    expect(nodeByRef($screen, 'send-report')['props'])->toMatchArray(['label' => 'Send report', 'disabled' => true]);

    // Pressing it anyway sends nothing.
    $screen->call('sendReport');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

    $screen->selectRadio('report-reason', 'harassment');

    expect(nodeByRef($screen, 'send-report')['props']['disabled'] ?? false)->toBeFalse();
});

it('sends the reason and details, then says the report was received, until Done closes the sheet', function () {
    $screen = reportFormOnLog()
        ->selectRadio('report-reason', 'spam')
        ->input('report-details', "  Misleading package link.\n ")
        ->tap('send-report');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://devnull.test/api/v1/commits/c1/reports'
        && $request->body() === '{"reason":"spam","details":"Misleading package link."}');

    $screen->assertSee('Report received. Moderators will review it privately.')
        ->assertDontSee('Send report')
        ->assertNativeNotCalled('ToastKit.Show');

    expect(sheetProps($screen))->toMatchArray(['visible' => true, 'detents' => '0.3']);

    $screen->tap('report-done');

    expect(sheetProps($screen)['visible'])->toBeFalse();

    $screen->dismissSheet('sheet');

    expect($screen->instance())
        ->sheetCommit->toBeNull()
        ->sheetView->toBe('report')
        ->reportReason->toBe('')
        ->reportDetails->toBe('');
});

it('sends a report without details as just its reason', function () {
    reportFormOnLog()->selectRadio('report-reason', 'other')->tap('send-report');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->body() === '{"reason":"other"}');
});

it('shows why devnull said no in the sheet, keeping the reason and details', function (array $answer, string $message) {
    $screen = reportFormOnLog($answer)
        ->selectRadio('report-reason', 'unsafe')
        ->input('report-details', 'Posts my address.')
        ->tap('send-report');

    expect(nodeByRef($screen, 'sheet-error')['props']['text'])->toBe($message)
        ->and(nodeByRef($screen, 'report-reason')['props']['value'])->toBe('unsafe')
        ->and(nodeByRef($screen, 'report-details')['props']['value'])->toBe('Posts my address.')
        ->and(nodeByRef($screen, 'send-report')['props']['disabled'] ?? false)->toBeFalse()
        ->and(sheetProps($screen)['visible'])->toBeTrue()
        // It's the sheet's to say, not the log's.
        ->and($screen->instance()->error)->toBeNull();

    $screen->assertDontSee('Report received');
})->with([
    'a commit devnull no longer shows' => [[['message' => "This commit isn't available.", 'code' => 'commit_unavailable'], 404], "fatal: this commit isn't available."],
    'too many reports' => [[['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429], 'Too Many Attempts.'],
]);

it('forgets the draft when the sheet is swiped away', function () {
    $screen = reportFormOnLog()
        ->selectRadio('report-reason', 'spam')
        ->input('report-details', 'Half a thought');

    $screen->dismissSheet('sheet');

    expect(sheetProps($screen)['visible'])->toBeFalse()
        ->and($screen->instance())
        ->sheetCommit->toBeNull()
        ->reportReason->toBe('')
        ->reportDetails->toBe('');

    // Opened again, it starts over.
    $screen->tap('report-c1');

    expect(nodeByRef($screen, 'report-reason')['props']['value'])->toBe('')
        ->and(nodeByRef($screen, 'report-details')['props']['value'])->toBe('')
        ->and(nodeByRef($screen, 'send-report')['props']['disabled'] ?? false)->toBeTrue();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('reports from a commit page and a profile too', function (string $uri, string $id) {
    serveApi(fn (Request $request) => match ($request->method().' '.parse_url($request->url(), PHP_URL_PATH)) {
        'GET /api/v1/commits/c2' => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'author' => sheetAda()]))),
        'GET /api/v1/commits/c2/replies' => Http::response(pageJson([])),
        'GET /api/v1/users/ada' => Http::response(['data' => userWithCountsJson(sheetAda()), 'is_following' => false, 'pinned_commit' => null]),
        'GET /api/v1/users/ada/commits' => Http::response(pageJson([commitJson(['id' => 'c3', 'body' => 'On the engine', 'author' => sheetAda()])])),
        "POST /api/v1/commits/{$id}/reports" => Http::response(null, 204),
        default => Http::response(['message' => 'No fake.'], 404),
    });

    Native::visit($uri, platform: 'ios')
        ->tap('report-'.$id)
        ->selectRadio('report-reason', 'harassment')
        ->tap('send-report')
        ->assertSee('Report received. Moderators will review it privately.');

    Http::assertSent(fn (Request $request): bool => $request->url() === "http://devnull.test/api/v1/commits/{$id}/reports" && $request->body() === '{"reason":"harassment"}');
})->with([
    'a commit page' => ['/log/commits/c2', 'c2'],
    'a profile' => ['/log/users/ada', 'c3'],
]);
