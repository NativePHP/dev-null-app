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
 * Ada's profile, then devnull answering a report as it would, or with $answer.
 *
 * @param  array{0: mixed, 1?: int}  $answer
 */
function serveReportableProfile(array $answer = [null, 204]): void
{
    serveApi(fn (Request $request) => match (true) {
        $request->method() === 'POST' => Http::response(...$answer),
        str_ends_with($request->url(), '/commits') || str_contains($request->url(), '/commits?') => Http::response(pageJson([])),
        default => Http::response([
            'data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace'], commits: 1, followers: 5, following: 2),
            'is_following' => true,
            'follows_you' => false,
            'is_muting' => false,
            'activity' => ['scale' => 'week', 'buckets' => []],
            'streaks' => ['longest' => 0, 'current' => 0],
            'pinned_commit' => null,
        ]),
    });
}

/**
 * Ada's profile with the report sheet up.
 *
 * @param  array{0: mixed, 1?: int}  $answer
 */
function personReportForm(array $answer = [null, 204]): TestableComponent
{
    serveReportableProfile($answer);

    return Native::visit('/log/users/ada', platform: 'ios')->call('reportPerson');
}

/**
 * The requests that reported someone.
 *
 * @return list<Request>
 */
function reportsSent(): array
{
    return collect(Http::recorded())
        ->map(fn (array $sent): Request => $sent[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/reports'))
        ->values()
        ->all();
}

it("opens the web's report form from a profile's menu", function () {
    $screen = personReportForm();

    expect(nodeByRef($screen, 'person-report-sheet')['props'])->toMatchArray(['visible' => true, 'detents' => 'large']);

    $screen->assertSee('Report @ada')
        ->assertSee("Your report is private and they won't be told. Moderators see your reason and details, not your messages. Reporting doesn't block them.");

    $reasons = nodeByRef($screen, 'report-reason');

    expect($reasons['props']['label'])->toBe('Why are you reporting this person?')
        ->and(array_map(fn (array $radio): string => $radio['props']['value'], $reasons['children']))->toBe(['spam', 'harassment', 'unsafe', 'child_safety', 'other'])
        ->and($reasons['children'][3]['props']['label'])->toBe('Child safety, such as grooming or sexual content involving a minor')
        ->and(nodeByRef($screen, 'send-report')['props']['disabled'])->toBeTrue();

    // Pressing Send report anyway sends nothing.
    $screen->call('sendPersonReport');

    expect(reportsSent())->toBe([]);
});

it('sends the reason and details, then says it was received, until Done closes the sheet', function () {
    $screen = personReportForm()
        ->selectRadio('report-reason', 'child_safety')
        ->input('report-details', "  They asked how old I am.\n ")
        ->tap('send-report');

    $reports = reportsSent();

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->url())->toBe('http://devnull.test/api/v1/users/ada/reports')
        ->and($reports[0]->body())->toBe('{"reason":"child_safety","details":"They asked how old I am."}')
        ->and($reports[0]->hasHeader('X-SSH-Signature'))->toBeTrue();

    $screen->assertSee('Report received. Moderators will review it privately.')->assertDontSee('Send report');

    expect(nodeByRef($screen, 'person-report-sheet')['props'])->toMatchArray(['visible' => true, 'detents' => '0.3']);

    $screen->tap('report-done');

    expect(nodeByRef($screen, 'person-report-sheet')['props']['visible'])->toBeFalse();

    // Android never says a sheet closed from here has gone, so the next one starts over without being told.
    $screen->call('reportPerson');

    expect(nodeByRef($screen, 'report-reason')['props']['value'])->toBe('')
        ->and(nodeByRef($screen, 'report-details')['props']['value'])->toBe('');
});

it('sends a report without details as just its reason', function () {
    personReportForm()->selectRadio('report-reason', 'other')->tap('send-report');

    expect(reportsSent()[0]->body())->toBe('{"reason":"other"}');
});

it('shows why devnull said no in the sheet, keeping the reason and details', function (array $answer, string $message) {
    $screen = personReportForm($answer)
        ->selectRadio('report-reason', 'harassment')
        ->input('report-details', 'Keeps messaging me.')
        ->tap('send-report');

    expect(nodeByRef($screen, 'sheet-error')['props']['text'])->toBe($message)
        ->and(nodeByRef($screen, 'report-reason')['props']['value'])->toBe('harassment')
        ->and(nodeByRef($screen, 'report-details')['props']['value'])->toBe('Keeps messaging me.')
        ->and(nodeByRef($screen, 'person-report-sheet')['props']['visible'])->toBeTrue()
        // It's the sheet's to say, not the profile's.
        ->and($screen->instance()->error)->toBeNull();

    $screen->assertDontSee('Report received');
})->with([
    'your own profile' => [[['message' => 'You cannot report yourself.', 'code' => 'own_profile'], 403], 'You cannot report yourself.'],
    'a missing handle' => [[['message' => "This profile isn't available.", 'code' => 'user_unavailable'], 403], "fatal: this profile isn't available."],
    'details too long' => [[['message' => 'Invalid.', 'errors' => ['details' => ['The details field must not be greater than 500 characters.']]], 422], 'The details field must not be greater than 500 characters.'],
    'too many reports' => [[['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429], 'Too many reports. Please wait a minute and try again.'],
]);

it('signs out when devnull no longer takes the key', function () {
    personReportForm([['message' => 'Invalid signature.', 'code' => 'invalid_signature'], 401])
        ->selectRadio('report-reason', 'spam')
        ->tap('send-report')
        ->assertReplacedWith('/onboarding');
});

it('clears the form when the sheet is swiped away', function () {
    $screen = personReportForm()
        ->selectRadio('report-reason', 'spam')
        ->input('report-details', 'Half a thought')
        ->dismissSheet('person-report-sheet');

    expect(nodeByRef($screen, 'person-report-sheet')['props']['visible'])->toBeFalse()
        ->and($screen->instance())
        ->reportedUsername->toBe('')
        ->reportReason->toBe('')
        ->reportDetails->toBe('');
});

it('offers no report on your own profile, even if asked', function () {
    serveApi(fn (Request $request) => str_contains($request->url(), '/commits')
        ? Http::response(pageJson([]))
        : Http::response([
            'data' => userWithCountsJson(['handle' => 'local_dev'], commits: 1, followers: 5, following: 2),
            'is_following' => false,
            'follows_you' => false,
            'is_muting' => false,
            'activity' => ['scale' => 'week', 'buckets' => []],
            'streaks' => ['longest' => 0, 'current' => 0],
            'pinned_commit' => null,
        ]));

    $screen = Native::visit('/profile', platform: 'ios')->call('reportPerson');

    expect(nodeByRef($screen, 'person-report-sheet')['props']['visible'])->toBeFalse();
});

it('reports someone you blocked, and from a profile that is not available', function () {
    serveApi(fn (Request $request) => match (true) {
        $request->method() === 'POST' => Http::response(null, 204),
        str_contains($request->url(), '/users/grace') => Http::response(['message' => "This profile isn't available.", 'code' => 'user_unavailable'], 403),
        default => Http::response(['message' => 'You blocked Ada.', 'code' => 'user_blocked', 'user' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace'])], 403),
    });

    $blocked = Native::visit('/log/users/ada', platform: 'ios')->call('reportPerson');

    expect(nodeByRef($blocked, 'person-report-sheet')['props']['visible'])->toBeTrue();

    $blocked->selectRadio('report-reason', 'harassment')->tap('send-report')->assertSee('Report received.');

    expect(reportsSent()[0]->url())->toBe('http://devnull.test/api/v1/users/ada/reports');

    $unavailable = Native::visit('/log/users/grace', platform: 'ios')
        ->assertSee("This profile isn't available.")
        ->call('reportPerson');

    expect(nodeByRef($unavailable, 'person-report-sheet')['props']['visible'])->toBeTrue();

    $unavailable->assertSee('Report @grace')
        ->selectRadio('report-reason', 'child_safety')
        ->tap('send-report')
        ->assertSee('Report received.');

    expect(reportsSent()[1]->url())->toBe('http://devnull.test/api/v1/users/grace/reports')
        ->and(reportsSent()[1]->body())->toBe('{"reason":"child_safety"}');
});

it('says only that the profile is not available when devnull refuses a report from that screen', function () {
    serveApi(fn (Request $request) => Http::response(['message' => "This profile isn't available.", 'code' => 'user_unavailable'], 403));

    $screen = Native::visit('/log/users/ghost', platform: 'ios')
        ->call('reportPerson')
        ->selectRadio('report-reason', 'spam')
        ->tap('send-report');

    expect(nodeByRef($screen, 'sheet-error')['props']['text'])->toBe("fatal: this profile isn't available.")
        ->and($screen->instance()->unavailable)->toBe('user_unavailable');

    $screen->assertDontSee('Report received')->assertDontSee('blocked');
});

it('reports the person from a private chat, with nothing from the chat', function () {
    serveApi(fn (Request $request) => match (true) {
        $request->method() === 'POST' => Http::response(null, 204),
        str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/remotes/@ada/messages') => Http::response(threadJson([remoteMessageJson(['body' => 'A private message'])], ['read_through_id' => 1])),
        default => Http::response(['usage' => []]),
    });

    $screen = finishRefreshes(Native::visit('/remotes/conversations/ada', platform: 'ios'));

    $menu = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action' && ($node['props']['id'] ?? null) === 'conversation-actions');

    expect(collect($menu['children'])->firstWhere('props.id', 'report')['props'])
        ->toMatchArray(['label' => 'Report @ada', 'icon' => 'custom:flag']);

    $screen->call('reportPerson')
        ->assertSee('Report @ada')
        ->selectRadio('report-reason', 'child_safety')
        ->tap('send-report')
        ->assertSee('Report received.');

    $reports = reportsSent();

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->url())->toBe('http://devnull.test/api/v1/users/ada/reports')
        ->and($reports[0]->body())->toBe('{"reason":"child_safety"}');
});

it('has no report in an org chat', function () {
    serveApi(fn (Request $request) => match (true) {
        str_contains($request->url(), 'orgs/org-1/messages') => Http::response(['data' => [
            'org' => ['id' => 'org-1', 'name' => 'Testers'],
            'messages' => [remoteMessageJson()],
            'has_older' => false,
            'reactions_version' => 0,
            'access' => 'open',
            'members' => [['user' => userJson(), 'role' => 'maintainer']],
        ]]),
        default => Http::response(['usage' => []]),
    });

    $screen = finishRefreshes(Native::visit('/remotes/orgs/org-1', platform: 'ios'));
    $menu = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action' && ($node['props']['id'] ?? null) === 'conversation-actions');

    expect(collect($menu['children'])->pluck('props.id')->all())->not->toContain('report');

    $screen->call('reportPerson');

    expect(nodeByRef($screen, 'person-report-sheet')['props']['visible'])->toBeFalse();
});

it('reports the partner from a row of the inbox, and there is no report on an org row', function () {
    serveApi(fn (Request $request) => $request->method() === 'POST'
        ? Http::response(null, 204)
        : Http::response(remotesInboxJson([conversationJson(), conversationJson(['id' => 'org-1', 'kind' => 'org', 'name' => 'Testers', 'partner' => null, 'members' => []])])));

    $screen = finishRefreshes(Native::visit('/remotes', platform: 'ios'));

    expect(nodeByRef($screen, 'report-conv-1')['props']['label'])->toBe('Report @ada')
        ->and(array_column(array_filter(menuItems($screen, 'remote-org-1'), is_array(...)), 0))->not->toContain('report-org-1');

    $screen->call('reportPartner', 'conv-1')
        ->selectRadio('report-reason', 'spam')
        ->tap('send-report')
        ->assertSee('Report received.');

    expect(reportsSent()[0]->url())->toBe('http://devnull.test/api/v1/users/ada/reports');
});
