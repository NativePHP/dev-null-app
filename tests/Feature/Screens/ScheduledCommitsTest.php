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
        scheduledCommitJson(['id' => 's-done', 'body' => null, 'scheduled_at' => '2026-10-18T09:00:00Z', 'submitted_at' => '2026-10-18T09:00:12Z', 'status' => 'approved', 'commit_id' => 'c7']),
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
        ->assertSee('Private until submitted. You can cancel before submission.')
        ->assertSee('Waiting for the scheduler. If submission fails, it is retried automatically.');

    expect(scheduledRows($screen))->toBe(['scheduled-s-due', 'scheduled-s-later', 'scheduled-s-done'])
        ->and(nodeByRef($screen, 'scheduled-status-s-due')['props']['text'] ?? null)->toBe('Waiting for submission')
        ->and(nodeByRef($screen, 'scheduled-status-s-later')['props']['text'] ?? null)->toBe('Scheduled')
        ->and(nodeByRef($screen, 'scheduled-status-s-done')['props']['text'] ?? null)->toBe('Approved')
        ->and(nodeByRef($screen, 'scheduled-at-s-later')['props']['text'] ?? null)->toBe('Oct 20, 2026, 9:00 AM')
        // Only those still waiting can be edited or canceled. A submitted one opens its commit.
        ->and(findNodes($screen->tree(), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'cancel-')))->toHaveCount(2)
        ->and(findNodes($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'cancel-s-done'))->toBe([]);

    $screen->tap('open-commit-s-done')->assertNavigatedTo('/log/commits/c7');
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
        ->assertSet('canceling', null)
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Scheduled commit canceled.');

    expect(scheduledRows($screen))->toBe(['scheduled-s-due', 'scheduled-s-done'])
        ->and(nodeByRef($screen, 'cancel-s-due')['props']['disabled'] ?? false)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/v1/scheduled-commits/s-later'));
});

it('says so, as the web does, when it was submitted before it could be canceled, and shows what became of it', function () {
    serveApi([
        'GET /api/v1/scheduled-commits' => [scheduledPageJson(threeScheduled())],
        'DELETE /api/v1/scheduled-commits/s-due' => [['message' => 'This commit was already submitted or canceled. Refresh to see its status.', 'code' => 'scheduled_commit_submitted'], 409],
    ]);

    $screen = Native::visit('/log/scheduled');

    serveApi([
        'GET /api/v1/scheduled-commits' => [scheduledPageJson([scheduledCommitJson(['id' => 's-due', 'body' => null, 'submitted_at' => '2026-10-19T15:30:02Z', 'status' => 'pending', 'commit_id' => 'c8'])])],
        'DELETE /api/v1/scheduled-commits/s-due' => [['message' => 'This commit was already submitted or canceled. Refresh to see its status.', 'code' => 'scheduled_commit_submitted'], 409],
    ]);

    $screen->tap('cancel-s-due')
        ->assertNativeCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'This commit was already submitted or canceled. Refresh to see its status.');

    expect(nodeByRef($screen, 'scheduled-status-s-due')['props']['text'] ?? null)->toBe('Submitted for review');
});

it("edits one still waiting: its body and its time, in the phone's timezone", function () {
    serveApi([
        'GET /api/v1/scheduled-commits' => [scheduledPageJson(threeScheduled())],
        'PUT /api/v1/scheduled-commits/s-later' => [['data' => scheduledCommitJson(['id' => 's-later', 'body' => 'Shipping this on Wednesday.', 'image_count' => 2, 'scheduled_at' => '2026-10-21T13:15:00Z'])]],
    ]);

    $screen = Native::visit('/log/scheduled')
        ->tap('edit-s-later')
        ->assertSet('editOpen', true)
        ->assertSet('editBody', 'Shipping this tomorrow.')
        ->assertSet('editAt', '2026-10-20T09:00')
        ->assertSee('Edit scheduled commit')
        ->assertSee('Its images stay as they are.');

    expect(nodeByRef($screen, 'edit-at')['props'])->toMatchArray(['mode' => 'datetime', 'timezone' => 'Europe/Lisbon', 'value' => '2026-10-20T09:00']);

    $screen->input('edit-body', 'Shipping this on Wednesday.')
        ->pickDateTime('edit-at', '2026-10-21T14:15')
        ->tap('save-edit')
        ->assertSet('editOpen', false)
        ->assertSet('editError', null);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/api/v1/scheduled-commits/s-later')
        && $request->body() === '{"body":"Shipping this on Wednesday.","scheduled_at":"2026-10-21T13:15:00+00:00"}');

    expect(nodeByRef($screen, 'scheduled-at-s-later')['props']['text'] ?? null)->toBe('Oct 21, 2026, 2:15 PM');
});

it('keeps the edit, and says why, when devnull does not take it', function () {
    serveApi([
        'GET /api/v1/scheduled-commits' => [scheduledPageJson(threeScheduled())],
        'PUT /api/v1/scheduled-commits/s-later' => [['message' => 'The scheduled at field must be a date after now.', 'errors' => ['scheduled_at' => ['The scheduled at field must be a date after now.']]], 422],
    ]);

    Native::visit('/log/scheduled')
        ->tap('edit-s-later')
        ->pickDateTime('edit-at', '2026-10-19T16:31')
        ->tap('save-edit')
        ->assertSet('editOpen', true)
        ->assertSet('editAt', '2026-10-19T16:31')
        ->assertSee('The scheduled at field must be a date after now.')
        ->assertDontSee('fatal');
});

it('waits for a body and a time the phone has, before it saves', function () {
    fakeApi(['GET /api/v1/scheduled-commits' => [scheduledPageJson([scheduledCommitJson(['id' => 's-later', 'scheduled_at' => '2027-03-27T12:00:00Z'])])]]);

    $screen = Native::visit('/log/scheduled')->tap('edit-s-later')->input('edit-body', '   ');

    expect(nodeByRef($screen, 'save-edit')['props']['disabled'] ?? false)->toBeTrue();

    // One character is a commit now, on devnull as on the web.
    $screen->input('edit-body', 'k');
    expect(nodeByRef($screen, 'save-edit')['props']['disabled'] ?? false)->toBeFalse();

    $screen->pickDateTime('edit-at', '2027-03-28T01:30')
        ->assertSee('This time does not exist in your timezone. Choose another time.');

    expect(nodeByRef($screen, 'save-edit')['props']['disabled'] ?? false)->toBeTrue();

    $screen->call('saveEdit');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

it('drops an edit that was swiped away', function () {
    fakeApi(['GET /api/v1/scheduled-commits' => [scheduledPageJson(threeScheduled())]]);

    Native::visit('/log/scheduled')
        ->tap('edit-s-later')
        ->input('edit-body', 'Never mind.')
        ->dismissSheet('edit-sheet')
        ->assertSet('editOpen', false)
        ->assertSet('editing', null)
        ->assertSet('editBody', '');
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
