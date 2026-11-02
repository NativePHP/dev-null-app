<?php

use App\Devnull\Data\Commit;
use App\Devnull\Stash;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    $this->bridge = signIn();
    phoneIn('Europe/Lisbon');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:30:00', 'UTC'));
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/draft-images'));
});

/**
 * The Log, with its composer open in the sheet and a commit written in it, and devnull answering a schedule with $answer.
 *
 * @param  array{0: mixed, 1?: int}|null  $answer
 */
function composeToSchedule(?array $answer = null): TestableComponent
{
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1'])])],
        'POST /api/v1/scheduled-commits' => $answer ?? [['data' => scheduledCommitJson(['body' => 'Shipping this tomorrow.', 'scheduled_at' => '2026-10-20T08:00:00Z'])], 201],
    ]);

    return Native::visit('/log', platform: 'ios')
        ->press('composeInSheet')
        ->input('sheet-body', 'Shipping this tomorrow.');
}

function scheduleDisabled(TestableComponent $screen): bool
{
    return ! sheetButtonEnabled($screen, 'sheet-commit');
}

it("opens the web's Submit at under the field, with the system's picker in the phone's timezone", function () {
    $screen = composeToSchedule()
        ->assertMissingElement('date_picker')
        ->assertSee('Commit')
        ->tap('sheet-schedule')
        ->assertSet('scheduling', true)
        ->assertSee('Submit at')
        ->assertSee('Europe/Lisbon (GMT+01:00). Within 30 days. Private until submitted; then submitted for review, not published automatically.')
        ->assertSee('Scheduled commits →')
        ->assertSee('Schedule commit');

    expect(nodeByRef($screen, 'scheduled-at')['props'])->toMatchArray([
        'mode' => 'datetime',
        'picker_style' => 'compact',
        'placeholder' => 'Pick a date and time',
        'timezone' => 'Europe/Lisbon',
        'min' => '2026-10-19T16:30',
        'max' => '2026-11-18T16:30',
    ])
        // Schedule commit waits for a time.
        ->and(scheduleDisabled($screen))->toBeTrue()
        ->and(nodeByRef($screen, 'sheet-schedule')['props']['a11y_label'] ?? null)->toBe("Don't schedule");
});

it('schedules the commit for the time picked, in UTC, and says so as the web does', function () {
    $screen = composeToSchedule()
        ->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00');

    expect(scheduleDisabled($screen))->toBeFalse()
        ->and(nodeByRef($screen, 'schedule-help')['props']['text'] ?? null)->toStartWith('Europe/Lisbon (GMT+01:00).');

    $screen->tap('sheet-commit')
        ->assertSet('sheetOpen', false)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Commit scheduled. Only you can see it until it is submitted.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/scheduled-commits')
        && $request->body() === '{"body":"Shipping this tomorrow.","scheduled_at":"2026-10-20T08:00:00+00:00"}');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/commits'));

    // The draft is spent, and nothing new shows on the Log until it's submitted.
    expect(app(Stash::class)->get('0199e5c4-0000-7000-8000-000000000001', 'commit')->body)->toBe('')
        ->and(array_map(fn (Commit $commit): string => $commit->id, $screen->instance()->commits))->toBe(['c1']);
});

it('says when the phone skips the time picked, as on the night the clocks go forward', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-20 12:00:00', 'UTC'));

    $screen = composeToSchedule()
        ->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2027-03-28T01:30')
        ->assertSee('This time does not exist in your timezone. Choose another time.');

    expect(scheduleDisabled($screen))->toBeTrue();

    $screen->call('commitFromSheet');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

    $screen->pickDateTime('scheduled-at', '2027-03-28T02:30')
        ->assertDontSee('This time does not exist in your timezone.');

    expect(scheduleDisabled($screen))->toBeFalse();
});

it('keeps the draft and the time, and says why, when devnull does not schedule it', function () {
    $screen = composeToSchedule([['message' => 'You can have up to 10 scheduled commits. Cancel one before scheduling another.', 'errors' => ['scheduled_at' => ['You can have up to 10 scheduled commits. Cancel one before scheduling another.']]], 422])
        ->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00')
        ->tap('sheet-commit')
        ->assertSet('sheetOpen', true)
        ->assertSet('scheduledAt', '2026-10-20T09:00')
        ->assertSet('sheetBody', 'Shipping this tomorrow.')
        ->assertSee('You can have up to 10 scheduled commits. Cancel one before scheduling another.')
        ->assertNativeNotCalled('ToastKit.Show');
});

it('commits now again once the clock is turned off', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1'])])],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'c9', 'body' => 'Shipping this now.'])], 201],
    ]);

    Native::visit('/log', platform: 'ios')
        ->press('composeInSheet')
        ->input('sheet-body', 'Shipping this now.')
        ->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00')
        ->tap('sheet-schedule')
        ->assertSet('scheduling', false)
        ->assertSet('scheduledAt', '')
        ->assertMissingElement('date_picker')
        ->tap('sheet-commit');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/commits'));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/scheduled-commits'));
});

it('starts unscheduled every time the composer opens', function () {
    composeToSchedule()
        ->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00')
        ->call('commitSheetClosed')
        ->press('composeInSheet')
        ->assertSet('scheduling', false)
        ->assertSet('scheduledAt', '');
});

it('opens your scheduled commits beneath the Log, from Submit at, once the sheet has gone', function () {
    $screen = composeToSchedule()
        ->tap('sheet-schedule')
        ->tap('open-scheduled')
        ->assertSet('sheetOpen', false)
        ->assertNoNavigation();

    $screen->dismissSheet('sheet')->assertNavigatedTo('/log/scheduled');
});

it('opens nothing when the sheet is closed any other way', function () {
    composeToSchedule()
        ->tap('sheet-schedule')
        ->dismissSheet('sheet')
        ->assertNoNavigation();
});

it('has no clock in a quote, which the web does not schedule', function () {
    fakeApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'username' => 'ada'])])])]]);

    $screen = Native::visit('/log', platform: 'ios')->call('quoteCommit', 'c1');

    expect(findNodes($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-schedule'))->toBe([]);

    $screen->call('toggleSchedule')->assertSet('scheduling', false);
});

it('schedules a commit of one character, as devnull now takes one', function () {
    $screen = composeToSchedule()
        ->input('sheet-body', 'k')
        ->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00');

    expect(scheduleDisabled($screen))->toBeFalse();

    $screen->tap('sheet-commit');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/scheduled-commits')
        && $request->body() === '{"body":"k","scheduled_at":"2026-10-20T08:00:00+00:00"}');
});

it('keeps scheduling and a /feature or /bug draft apart, as the web does', function () {
    // Scheduling, a command stays text.
    $screen = composeToSchedule()->tap('sheet-schedule')->input('sheet-body', '/bug The scheduler');

    expect($screen->instance()->sheetIssue)->toBeNull();

    // An issue has no clock, and can't be scheduled.
    $screen->tap('sheet-schedule')->input('sheet-body', '/bug The scheduler');

    expect($screen->instance()->sheetIssue)->toBe('bug')
        ->and(findNodes($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-schedule'))->toBe([]);

    $screen->call('toggleSchedule')->assertSet('scheduling', false);
});

it('schedules a poll with the commit, as the web does', function () {
    composeToSchedule()
        ->input('sheet-body', 'Tabs or spaces?')
        ->tap('sheet-poll')
        ->input('poll-option-0', 'Tabs')
        ->input('poll-option-1', 'Spaces')
        ->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00')
        ->tap('sheet-commit')
        ->assertSet('sheetOpen', false);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/scheduled-commits')
        && $request->body() === '{"body":"Tabs or spaces?","scheduled_at":"2026-10-20T08:00:00+00:00","poll":{"options":["Tabs","Spaces"],"duration":1440}}');
});

it('schedules photos with the commit as multipart, then lets the photos go', function () {
    $screen = photosPicked(composeToSchedule(), [testPhoto('jpg')]);
    $path = $screen->instance()->sheetImages[0]->path;
    $contents = file_get_contents($path);

    $screen->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00')
        ->tap('sheet-commit')
        ->assertSet('sheetOpen', false)
        ->assertSet('sheetImages', []);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/scheduled-commits' && multipartParts($request) === [
        ['name' => 'payload', 'type' => 'application/json', 'contents' => '{"body":"Shipping this tomorrow.","scheduled_at":"2026-10-20T08:00:00+00:00"}'],
        ['name' => 'images[]', 'type' => 'image/jpeg', 'contents' => $contents],
    ]);

    expect(file_exists($path))->toBeFalse();
});
