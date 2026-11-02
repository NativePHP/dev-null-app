<?php

use App\Devnull\Client;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    phoneIn('Europe/Lisbon');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:30:00', 'UTC'));
});

/**
 * One waiting tomorrow, one due a minute ago and still waiting for the scheduler, with a poll, and one that was
 * submitted and approved.
 *
 * @return list<array<string, mixed>>
 */
function threeScheduled(): array
{
    return [
        scheduledCommitJson(['id' => 's-due', 'body' => 'Poll time.', 'poll_options' => ['Tabs', 'Spaces'], 'scheduled_at' => '2026-10-19T15:29:00Z']),
        scheduledCommitJson(['id' => 's-later', 'body' => 'Shipping this tomorrow.', 'image_count' => 2, 'scheduled_at' => '2026-10-20T08:00:00Z']),
        scheduledCommitJson(['id' => 's-done', 'body' => null, 'scheduled_at' => '2026-10-18T09:00:00Z', 'submitted_at' => '2026-10-18T09:00:12Z', 'status' => 'approved']),
    ];
}

function scheduledRows(TestableComponent $screen): array
{
    return array_values(array_filter(
        array_map(fn (array $node): string => (string) $node['ref'], findNodes($screen->tree(), fn (array $node): bool => preg_match('/^scheduled-s-[a-z]+$/', (string) ($node['ref'] ?? '')) === 1)),
    ));
}

it("lists your scheduled commits as the web does, with their times in the phone's timezone", function () {
    fakeApi(['GET /api/v1/scheduled-commits' => [scheduledPageJson(threeScheduled())]]);

    $screen = Native::visit('/log/scheduled')
        ->assertNavTitle('Scheduled commits')
        ->assertSee('Only you can see this list. At the scheduled time, each commit goes through the usual review process. Scheduling is not approval.')
        ->assertSee('Times in Europe/Lisbon')
        ->assertSee('Shipping this tomorrow.')
        ->assertSee('2 images')
        ->assertSee('Tabs')
        ->assertSee('Spaces')
        ->assertSee('Private until submitted. You can commit it now or cancel it before submission.')
        ->assertSee('Waiting for the scheduler. If submission fails, it is retried automatically.');

    expect(scheduledRows($screen))->toBe(['scheduled-s-due', 'scheduled-s-later', 'scheduled-s-done'])
        ->and(nodeByRef($screen, 'scheduled-status-s-due')['props']['text'] ?? null)->toBe('Waiting for submission')
        ->and(nodeByRef($screen, 'scheduled-status-s-later')['props']['text'] ?? null)->toBe('Scheduled')
        ->and(nodeByRef($screen, 'scheduled-status-s-done')['props']['text'] ?? null)->toBe('Approved')
        ->and(nodeByRef($screen, 'scheduled-at-s-later')['props']['text'] ?? null)->toBe('Oct 20, 2026, 9:00 AM')
        // Only those still waiting can be committed now or canceled.
        ->and(findNodes($screen->tree(), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'cancel-')))->toHaveCount(2)
        ->and(findNodes($screen->tree(), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'commit-now-')))->toHaveCount(2)
        ->and(findNodes($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['cancel-s-done', 'commit-now-s-done'], true)))->toBe([]);
});

it('says what became of each one submitted, as the web does', function (string $status, string $label) {
    fakeApi(['GET /api/v1/scheduled-commits' => [scheduledPageJson([scheduledCommitJson(['id' => 's-done', 'body' => null, 'submitted_at' => '2026-10-18T09:00:12Z', 'status' => $status])])]]);

    $screen = Native::visit('/log/scheduled');

    expect(nodeByRef($screen, 'scheduled-status-s-done')['props']['text'] ?? null)->toBe($label);
})->with([
    ['pending', 'Submitted for review'],
    ['rejected', 'Rejected'],
    ['deleted', 'Deleted'],
]);

it("says there are none, in the web's words", function () {
    fakeApi(['GET /api/v1/scheduled-commits' => [scheduledPageJson([])]]);

    Native::visit('/log/scheduled')
        ->assertSee('No scheduled commits')
        ->assertSee('Choose Schedule in the composer to submit later.');
});

it('opens on the list as it was last time, and asks devnull again behind it', function () {
    $bridge = holdRefreshes();
    keep(Client::ScheduledCommitsPath, scheduledPageJson([scheduledCommitJson(['id' => 's-later'])]));
    serveApi(['GET /api/v1/scheduled-commits' => [scheduledPageJson([])]]);

    $screen = Native::visit('/log/scheduled');

    expect(scheduledRows($screen))->toBe(['scheduled-s-later']);

    finishRefreshes($screen)->assertSee('No scheduled commits');
});

it('cancels one still waiting, and says so as the web does', function () {
    serveApi([
        'GET /api/v1/scheduled-commits' => [scheduledPageJson(threeScheduled())],
        'DELETE /api/v1/scheduled-commits/s-later' => [null, 204],
    ]);

    $screen = Native::visit('/log/scheduled')
        ->tap('cancel-s-later')
        ->assertSet('busy', null)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Scheduled commit canceled.');

    expect(scheduledRows($screen))->toBe(['scheduled-s-due', 'scheduled-s-done'])
        ->and(nodeByRef($screen, 'cancel-s-due')['props']['disabled'] ?? false)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/v1/scheduled-commits/s-later'));
});

it('commits one now, keeping its row where it was, and says it went for review as the web does', function () {
    serveApi([
        'GET /api/v1/scheduled-commits' => [scheduledPageJson(threeScheduled())],
        'POST /api/v1/scheduled-commits/s-later/submission' => [['data' => scheduledCommitJson(['id' => 's-later', 'body' => 'Shipping this tomorrow.', 'image_count' => 2, 'submitted_at' => '2026-10-19T15:30:01Z', 'status' => 'pending'])]],
    ]);

    $screen = Native::visit('/log/scheduled')
        ->tap('commit-now-s-later')
        ->assertSet('busy', null)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Commit submitted. It goes through the usual review process.');

    expect(scheduledRows($screen))->toBe(['scheduled-s-due', 'scheduled-s-later', 'scheduled-s-done'])
        ->and(nodeByRef($screen, 'scheduled-status-s-later')['props']['text'] ?? null)->toBe('Submitted for review')
        ->and(findNodes($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['commit-now-s-later', 'cancel-s-later'], true)))->toBe([]);

    $screen->assertSee('Shipping this tomorrow.')->assertSee('2 images');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/scheduled-commits/s-later/submission'));
    // The list asks devnull again next time instead of showing it as still waiting.
    expect(kept(Client::ScheduledCommitsPath))->toBeNull();
});

it('says so, as the web does, when devnull got there first, and shows what became of it', function (string $tap, string $route) {
    serveApi([
        'GET /api/v1/scheduled-commits' => [scheduledPageJson(threeScheduled())],
    ]);

    $screen = Native::visit('/log/scheduled');

    serveApi([
        'GET /api/v1/scheduled-commits' => [scheduledPageJson([scheduledCommitJson(['id' => 's-due', 'body' => null, 'submitted_at' => '2026-10-19T15:30:02Z', 'status' => 'pending'])])],
        $route => [['message' => 'This commit was already submitted or canceled. Refresh to see its status.'], 409],
    ]);

    $screen->tap($tap)
        ->assertSet('busy', null)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'This commit was already submitted or canceled. Refresh to see its status.');

    expect(nodeByRef($screen, 'scheduled-status-s-due')['props']['text'] ?? null)->toBe('Submitted for review');
})->with([
    'canceling it' => ['cancel-s-due', 'DELETE /api/v1/scheduled-commits/s-due'],
    'committing it now' => ['commit-now-s-due', 'POST /api/v1/scheduled-commits/s-due/submission'],
]);

it('does one thing at a time, and nothing to one already submitted', function () {
    fakeApi(['GET /api/v1/scheduled-commits' => [scheduledPageJson(threeScheduled())]]);

    $screen = Native::visit('/log/scheduled')
        ->set('busy', 's-due')
        ->call('commitNow', 's-later')
        ->call('cancel', 's-later')
        ->set('busy', null)
        ->call('commitNow', 's-done')
        ->call('cancel', 's-done');

    expect(nodeByRef($screen, 'commit-now-s-later')['props']['disabled'] ?? false)->toBeFalse();

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('shows the next page under the first', function () {
    serveApi(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(($query['page'] ?? null) === '2'
            ? scheduledPageJson([scheduledCommitJson(['id' => 's-old', 'body' => null, 'submitted_at' => '2026-10-01T09:00:00Z', 'status' => 'approved'])])
            : scheduledPageJson([scheduledCommitJson(['id' => 's-later'])], nextPage: 2));
    });

    $screen = Native::visit('/log/scheduled')->tap('scheduled-more');

    expect(scheduledRows($screen))->toBe(['scheduled-s-later', 'scheduled-s-old'])
        ->and(findNodes($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'scheduled-more'))->toBe([]);
});
