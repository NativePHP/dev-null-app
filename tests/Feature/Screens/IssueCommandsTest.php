<?php

use App\Devnull\Client;
use App\Devnull\Data\StashedDraft;
use App\Devnull\IssueCommand;
use App\Devnull\Stash;
use App\NativeComponents\Log;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Victorycodedev\ToastKit\Events\ToastActionPressed;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->userId = userJson()['id'];
});

/**
 * A Following log with one commit of Ada's, devnull answering a new issue with $answer, and the compose sheet open
 * from the nav bar.
 *
 * @param  array{0: mixed, 1?: int}|null  $answer
 */
function issueSheetOnLog(?array $answer = null): TestableComponent
{
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'An older commit', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])])],
        'POST /api/v1/issues' => $answer ?? [['number' => 123, 'url' => 'https://github.com/nunomaduro/dev-null.sh/issues/123'], 201],
    ]);

    return Native::visit('/log', platform: 'ios')->press('composeInSheet');
}

function issueRequestsSent(): int
{
    return collect(Http::recorded())->filter(fn (array $sent): bool => str_ends_with($sent[0]->url(), '/api/v1/issues'))->count();
}

function hasRef(TestableComponent $screen, string $ref): bool
{
    return findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === $ref) !== null;
}

it('turns a new commit into the issue its command names, without the command, as the web does', function (string $typed, string $command, string $body, string $placeholder) {
    $screen = issueSheetOnLog()->input('sheet-body', $typed);

    expect($screen->instance()->sheetIssue)->toBe(ltrim($command, '/'))
        ->and(nodeByRef($screen, 'sheet-body')['props'])->toMatchArray(['value' => $body, 'placeholder' => $placeholder])
        ->and(hasRef($screen, 'sheet-open-issue'))->toBeTrue()
        ->and(hasRef($screen, 'sheet-commit'))->toBeFalse();

    $screen->assertSee($command)->assertSee('Public issue on nunomaduro/dev-null.sh, crediting you');
})->with([
    'feature' => ['/feature Dark mode', '/feature', 'Dark mode', IssueCommand::Feature->placeholder()],
    'bug' => ['/bug ', '/bug', '', IssueCommand::Bug->placeholder()],
    'bug on a new line' => ["/bug\nSteps", '/bug', 'Steps', IssueCommand::Bug->placeholder()],
]);

it('keeps a draft that only looks like a command a commit', function (string $typed) {
    $screen = issueSheetOnLog()->input('sheet-body', $typed);

    expect($screen->instance()->sheetIssue)->toBeNull()
        ->and(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe($typed)
        ->and(hasRef($screen, 'issue-badge'))->toBeFalse()
        ->and(hasRef($screen, 'sheet-commit'))->toBeTrue();
})->with(['/bug', '/bugs are fixed', 'Fixed a /bug today']);

it('offers the commands a slash could become, and starts the one you tap empty', function () {
    $screen = issueSheetOnLog()->input('sheet-body', '/');

    expect(childRefs($screen, 'issue-commands'))->toBe(['issue-command-feature', 'issue-command-bug']);
    $screen->assertSee('Request a feature')->assertSee('Report a bug');

    $screen->input('sheet-body', '/f');
    expect(childRefs($screen, 'issue-commands'))->toBe(['issue-command-feature']);

    $screen->input('sheet-body', '/deploy');
    expect(hasRef($screen, 'issue-commands'))->toBeFalse();

    $screen->input('sheet-body', '/b')->tap('issue-command-bug');

    expect($screen->instance()->sheetIssue)->toBe('bug')
        ->and(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('')
        ->and(hasRef($screen, 'issue-commands'))->toBeFalse()
        ->and(app(Stash::class)->get($this->userId, 'commit')->body)->toBe('');
});

it('opens the menu for a restored slash only once you type, as the web opens it only while you type', function () {
    app(Stash::class)->put($this->userId, 'commit', new StashedDraft('/'));

    $screen = issueSheetOnLog();

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('/')
        ->and(hasRef($screen, 'issue-commands'))->toBeFalse();

    $screen->input('sheet-body', '/b');

    expect(childRefs($screen, 'issue-commands'))->toBe(['issue-command-bug']);
});

it('hints the command a plain draft mentions, and turns it into that issue as it is', function () {
    $screen = issueSheetOnLog()->input('sheet-body', 'The search has a bug');

    $screen->assertSee('Found a bug in /dev/null?')->assertSee('Use /bug');

    $screen->tap('issue-hint-use');

    expect($screen->instance()->sheetIssue)->toBe('bug')
        ->and(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('The search has a bug')
        ->and(hasRef($screen, 'issue-hint'))->toBeFalse();
});

it('stops the hints on this phone once dismissed', function () {
    $screen = issueSheetOnLog()->input('sheet-body', 'Feature idea: dark mode');

    $screen->assertSee('Have an idea for /dev/null?')->tap('issue-hint-dismiss');

    expect(hasRef($screen, 'issue-hint'))->toBeFalse()
        ->and(Cache::get(Log::IssueHintDismissedKey))->toBeTrue();

    $screen->dismissSheet('sheet')->press('composeInSheet');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('Feature idea: dark mode')
        ->and(hasRef($screen, 'issue-hint'))->toBeFalse();
});

it('goes back to a commit from the badge, keeping what you wrote', function () {
    $screen = issueSheetOnLog()->input('sheet-body', '/feature Dark mode')->tap('issue-cancel');

    expect($screen->instance()->sheetIssue)->toBeNull()
        ->and(nodeByRef($screen, 'sheet-body')['props'])->toMatchArray(['value' => 'Dark mode', 'placeholder' => 'What did you ship?'])
        ->and(hasRef($screen, 'sheet-commit'))->toBeTrue();
});

it('only turns a new commit into an issue, never a quote', function () {
    serveApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])])]]);

    $screen = Native::visit('/log', platform: 'ios')->tap('quote-c1')->input('sheet-body', '/bug ');

    expect($screen->instance()->sheetIssue)->toBeNull()
        ->and(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('/bug ')
        ->and(hasRef($screen, 'issue-commands'))->toBeFalse();

    $screen->input('sheet-body', '/')->input('sheet-body', 'Has a bug');

    expect(hasRef($screen, 'issue-commands'))->toBeFalse()
        ->and(hasRef($screen, 'issue-hint'))->toBeFalse();
});

it('keeps Open issue disabled until the issue is long enough, and short enough', function () {
    $screen = issueSheetOnLog()->input('sheet-body', '/bug ab');

    expect(sheetButtonEnabled($screen, 'sheet-open-issue'))->toBeFalse();

    $screen->input('sheet-body', 'abc');
    expect(sheetButtonEnabled($screen, 'sheet-open-issue'))->toBeTrue();

    $screen->input('sheet-body', str_repeat('a', 4001));
    expect(sheetButtonEnabled($screen, 'sheet-open-issue'))->toBeFalse();

    // Pressing it anyway, or Commit, sends nothing.
    $screen->call('openIssueFromSheet')->call('commitFromSheet');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('opens the issue while the button spins, then says so with View issue, as the web toast does', function () {
    $bridge = holdRefreshes();
    $screen = issueSheetOnLog()->input('sheet-body', "/bug Search loses its filters\n\nSteps: select PHP.");
    $list = nodeByRef($screen, 'log')['id'];

    // A spinner in the check's circle, which can't be tapped again. Asking again anyway sends nothing more.
    $screen->tap('sheet-open-issue')->call('openIssueFromSheet');

    expect(sheetButtonEnabled($screen, 'sheet-open-issue'))->toBeFalse()
        ->and(findNode(nodeByRef($screen, 'sheet-open-issue'), fn (array $node): bool => $node['type'] === 'activity_indicator'))->not->toBeNull()
        ->and(nodeByRef($screen, 'sheet-open-issue')['props']['a11y_label'])->toBe('Open issue')
        ->and(issueRequestsSent())->toBe(0);

    finishRefreshes($screen)->assertNoNavigation()->assertSee('Opened issue #123.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://devnull.test/api/v1/'.Client::IssuesPath
        && $request->body() === '{"command":"/bug","body":"Search loses its filters\n\nSteps: select PHP."}'
        && $request->hasHeader('X-SSH-Signature'));

    expect(issueRequestsSent())->toBe(1)
        ->and(nodeByRef($screen, 'sheet')['props'])->toMatchArray(['visible' => true, 'detents' => '0.3'])
        ->and(app(Stash::class)->get($this->userId, 'commit')->body)->toBe('')
        ->and(nodeByRef($screen, 'log')['id'])->toBe($list)
        ->and($screen->instance()->commits)->toHaveCount(1);
    $bridge->assertNotCalled('ToastKit.Show');

    $screen->tap('view-issue')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/nunomaduro/dev-null.sh/issues/123');

    $screen->tap('issue-done');
    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse();

    // Opened again, it's a new commit.
    $screen->dismissSheet('sheet')->press('composeInSheet');
    expect(nodeByRef($screen, 'sheet-body')['props'])->toMatchArray(['value' => '', 'placeholder' => 'What did you ship?'])
        ->and(hasRef($screen, 'sheet-commit'))->toBeTrue();
});

it('says so in a toast with View issue when the sheet was swiped away before the issue opened', function () {
    holdRefreshes();
    $screen = issueSheetOnLog()->input('sheet-body', '/feature Dark mode')->tap('sheet-open-issue')->dismissSheet('sheet');

    finishRefreshes($screen)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Opened issue #123.'
            && $params['variant'] === 'success'
            && $params['action'] === ['id' => 'view-issue', 'label' => 'View issue']
            && $params['data'] === ['url' => 'https://github.com/nunomaduro/dev-null.sh/issues/123']);

    expect(app(Stash::class)->get($this->userId, 'commit')->body)->toBe('')
        ->and(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse();

    $screen->emitNative(ToastActionPressed::class, ['toastId' => 'toast-1', 'actionId' => 'view-issue', 'data' => ['url' => 'https://github.com/nunomaduro/dev-null.sh/issues/123']])
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/nunomaduro/dev-null.sh/issues/123');
});

it('keeps the issue draft with why when devnull or GitHub says no', function (array $answer, string $message) {
    $screen = issueSheetOnLog($answer)->input('sheet-body', '/bug Broken.')->tap('sheet-open-issue');

    expect(nodeByRef($screen, 'sheet-error')['props']['text'])->toBe($message)
        ->and($screen->instance()->sheetIssue)->toBe('bug')
        ->and(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('Broken.')
        ->and(sheetButtonEnabled($screen, 'sheet-open-issue'))->toBeTrue()
        ->and(app(Stash::class)->get($this->userId, 'commit')->body)->toBe('Broken.');
})->with([
    'over the limit' => [[['message' => 'Issue limit reached. Try again in an hour.', 'code' => 'rate_limited'], 429], 'Issue limit reached. Try again in an hour.'],
    'GitHub said no' => [[['message' => 'GitHub did not create the issue. Your draft is safe.', 'code' => 'github_issue_failed'], 503], 'GitHub did not create the issue. Your draft is safe.'],
    'GitHub did not answer' => [[['message' => 'GitHub did not respond. Check the upstream issues before trying again. Your draft is safe.', 'code' => 'github_issue_failed'], 503], 'GitHub did not respond. Check the upstream issues before trying again. Your draft is safe.'],
    "a proxy's error page" => [['<html><title>502: Bad gateway</title></html>', 502], 'GitHub did not confirm the issue. Check upstream before retrying. Your draft is safe.'],
    'a maintenance page with a 200' => [['<html><title>Maintenance - /dev/null</title></html>', 200], "fatal: unable to access 'http://devnull.test': unexpected answer (HTTP 200)"],
    'invalid' => [[['message' => 'The body field must not be greater than 4000 characters.', 'errors' => ['body' => ['The body field must not be greater than 4000 characters.']]], 422], 'The body field must not be greater than 4000 characters.'],
]);

it('says to check upstream when the connection drops on the way', function () {
    $screen = issueSheetOnLog()->input('sheet-body', '/bug Broken.');

    serveApi(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $screen->tap('sheet-open-issue');

    expect(nodeByRef($screen, 'sheet-error')['props']['text'])->toBe('Connection lost. Check upstream before retrying. Your draft is safe.')
        ->and($screen->instance()->sheetIssue)->toBe('bug');
});
