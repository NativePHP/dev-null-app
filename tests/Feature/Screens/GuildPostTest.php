<?php

use App\Devnull\Data\Commit;
use App\Devnull\Stash;
use App\Models\PendingWrite;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Victorycodedev\ToastKit\Events\ToastActionPressed;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->userId = userJson()['id'];
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/draft-images'));
});

/**
 * #rust's board with c1 on it, the Following log with c0, and devnull answering a commit with $answer.
 *
 * @param  array{0: mixed, 1?: int}|null  $answer
 */
function serveGuildAndLog(?array $answer = null): void
{
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c0'])])],
        'POST /api/v1/commits' => $answer ?? [['data' => commitJson(['id' => 'c9', 'body' => 'Crabs ahoy.', 'guild_handle' => 'rust'])], 201],
        'POST /api/v1/scheduled-commits' => [['data' => scheduledCommitJson()], 201],
    ]);
}

/**
 * #rust's page, loaded, with its composer open from the bar.
 */
function guildComposer(?array $answer = null, string $platform = 'ios'): TestableComponent
{
    serveGuildAndLog($answer);

    return finishRefreshes(Native::visit('/log/guilds/rust', platform: $platform))->press('composeForGuild');
}

/**
 * The commits the screen lists, by id.
 *
 * @return list<string>
 */
function listedIds(TestableComponent $screen): array
{
    return array_map(fn (Commit $commit): string => $commit->id, $screen->instance()->commits);
}

function pressEditOnToast(TestableComponent $screen, int $writeId): TestableComponent
{
    return $screen->emitNative(ToastActionPressed::class, ['toastId' => 'toast-1', 'actionId' => 'edit', 'data' => ['write' => $writeId]]);
}

it('offers New commit beside Share once the guild is loaded', function () {
    holdRefreshes();
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'])]]);
    $loading = Native::visit('/log/guilds/rust');

    expect(hasTopBarAction($loading, 'compose'))->toBeFalse();

    $loaded = finishRefreshes($loading);

    expect(hasTopBarAction($loaded, 'share'))->toBeTrue()
        ->and(hasTopBarAction($loaded, 'compose'))->toBeTrue()
        ->and(findNode($loaded->tree(), fn (array $node): bool => ($node['props']['id'] ?? null) === 'compose')['props']['a11y_label'] ?? null)->toBe('New commit');
});

it('offers no New commit on a paused guild, as the web has no composer there', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_paused' => true])])]]);

    $paused = finishRefreshes(Native::visit('/log/guilds/rust'))->call('composeForGuild');

    expect(hasTopBarAction($paused, 'share'))->toBeTrue()
        ->and(hasTopBarAction($paused, 'compose'))->toBeFalse()
        ->and($paused->instance()->sheetOpen)->toBeFalse();
});

it('opens a composer for the guild, saying where it posts, with its own draft', function () {
    $screen = guildComposer()
        ->assertSee('New commit')
        ->assertSee('Posted to this guild, not your profile log.')
        ->input('sheet-body', 'Crabs ahoy.');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeTrue()
        ->and(nodeByRef($screen, 'sheet-body')['props']['placeholder'])->toBe('What do you want to share with the guild?')
        // At the top, under the sheet's bar, with the same gap above it as between it and the field.
        ->and(childRefs($screen, 'sheet-above')[0] ?? null)->toBe('sheet-guild-hint')
        ->and(nodeByRef($screen, 'sheet-above')['layout']['padding'][0])->toEqual(nodeByRef($screen, 'sheet-field-slot')['layout']['padding'][0])
        ->and(nodeByRef($screen, 'sheet-above')['layout']['padding'][2] ?? 0)->toEqual(0)
        ->and(childRefs($screen, 'sheet-below'))->not->toContain('sheet-guild-hint')
        ->and(app(Stash::class)->get($this->userId, 'guild:rust')->body)->toBe('Crabs ahoy.')
        ->and(app(Stash::class)->get($this->userId, 'commit')->body)->toBe('');

    // The Log's composer has its own draft, and says nothing of guilds.
    $log = Native::visit('/log')->press('composeInSheet')->assertDontSee('Posted to this guild, not your profile log.');

    expect(nodeByRef($log, 'sheet-body')['props'])->toMatchArray(['placeholder' => 'What did you ship?', 'value' => '']);
});

it('has no /feature or /bug in the guild composer, as the web has none', function () {
    $screen = guildComposer()->input('sheet-body', '/b');

    expect(rendersRef($screen, 'issue-commands'))->toBeFalse();

    $screen->input('sheet-body', '/bug ');

    expect($screen->instance()->sheetIssue)->toBeNull()
        ->and(rendersRef($screen, 'issue-badge'))->toBeFalse();

    $screen->input('sheet-body', 'There is a bug in the borrow checker');

    expect(rendersRef($screen, 'issue-hint'))->toBeFalse();

    $screen->call('useIssueCommand', 'bug');

    expect($screen->instance()->sheetIssue)->toBeNull();
});

it('posts to the board, staged on top until devnull has it, then the real commit, never in the Log, counted on your profile', function () {
    holdRefreshes();
    serveGuildAndLog();
    keep('log/following', pageJson([commitJson(['id' => 'c0'])]));
    keep('users/local_dev', ['data' => userWithCountsJson(commits: 4), 'is_following' => false]);
    keep('users/local_dev/commits', pageJson([commitJson(['id' => 'c0'])]));
    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->press('composeForGuild')
        ->input('sheet-body', 'Crabs ahoy.')
        ->tap('sheet-commit');
    $write = PendingWrite::query()->sole();

    expect($write->guildHandle())->toBe('rust')
        ->and(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse()
        ->and(listedIds($screen))->toBe([PendingWrite::stagedId($write->client_id), 'c1'])
        ->and(app(Stash::class)->get($this->userId, 'guild:rust')->body)->toBe('');

    // On its way, it isn't in the Log, which isn't where it goes.
    $log = Native::visit('/log');

    expect(listedIds($log))->toBe(['c0']);

    finishRefreshes($screen);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://devnull.test/api/v1/commits'
        && $request->body() === '{"body":"Crabs ahoy.","guild_handle":"rust"}');

    expect(listedIds($screen))->toBe(['c9', 'c1'])
        ->and(PendingWrite::query()->count())->toBe(0)
        ->and(array_column(kept('log/following')['data'], 'id'))->toBe(['c0'])
        ->and(array_column(kept('guilds/rust')['data'], 'id'))->toBe(['c9', 'c1'])
        // devnull counts it among your commits, though your profile doesn't list it.
        ->and(kept('users/local_dev')['data']['commits_count'])->toBe(5)
        ->and(array_column(kept('users/local_dev/commits')['data'], 'id'))->toBe(['c0']);
});

it('posts photos to the board as multipart', function () {
    $screen = photosPicked(guildComposer(), [testPhoto('jpg')]);

    $screen->input('sheet-body', 'Crabs ahoy.')->tap('sheet-commit');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/commits'
        && multipartParts($request)[0]['contents'] === '{"body":"Crabs ahoy.","guild_handle":"rust"}');
});

it('schedules a commit for the board', function () {
    phoneIn('Europe/Lisbon');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:30:00', 'UTC'));

    guildComposer()
        ->input('sheet-body', 'Crabs tomorrow.')
        ->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00')
        ->tap('sheet-commit')
        ->assertSet('sheetOpen', false);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/scheduled-commits'
        && $request->body() === '{"body":"Crabs tomorrow.","scheduled_at":"2026-10-20T08:00:00+00:00","guild_handle":"rust"}');
});

it('takes a post devnull turned down off the board and opens it again in the guild composer', function () {
    $screen = guildComposer([['message' => 'Too Many Attempts.'], 429], platform: 'android')
        ->input('sheet-body', 'Crabs ahoy.')
        ->tap('sheet-commit')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Too Many Attempts.' && ! isset($params['action']));

    expect(listedIds($screen))->toBe(['c1'])
        ->and(nodeByRef($screen, 'sheet')['props']['visible'])->toBeTrue()
        ->and(nodeByRef($screen, 'sheet-body')['props'])->toMatchArray(['value' => 'Crabs ahoy.', 'placeholder' => 'What do you want to share with the guild?'])
        ->and(PendingWrite::query()->count())->toBe(0);
});

it('never opens a guild post devnull turned down in the Log composer, and Edit takes you to its guild', function () {
    holdRefreshes();
    serveGuildAndLog([['message' => 'Too Many Attempts.'], 429]);
    finishRefreshes(Native::visit('/log/guilds/rust'))
        ->press('composeForGuild')
        ->input('sheet-body', 'Crabs ahoy.')
        ->tap('sheet-commit');
    $write = PendingWrite::query()->sole();

    // devnull answers once you're back on the Log.
    $log = finishRefreshes(Native::visit('/log', platform: 'android'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Too Many Attempts.'
            && $params['action'] === ['id' => 'edit', 'label' => 'Edit']);

    expect($log->instance()->sheetOpen)->toBeFalse()
        ->and(listedIds($log))->toBe(['c0']);

    pressEditOnToast($log, $write->id)->assertNavigatedTo('/log/guilds/rust');

    expect($log->instance()->sheetOpen)->toBeFalse();

    $guild = Native::visit('/log/guilds/rust', ['edit' => $write->id]);

    expect(nodeByRef($guild, 'sheet')['props']['visible'])->toBeTrue()
        ->and(nodeByRef($guild, 'sheet-body')['props'])->toMatchArray(['value' => 'Crabs ahoy.', 'placeholder' => 'What do you want to share with the guild?'])
        ->and(PendingWrite::query()->count())->toBe(0);
});

it("never opens the Log's new commit devnull turned down on a guild's page", function () {
    holdRefreshes();
    serveGuildAndLog([['message' => 'Too Many Attempts.'], 429]);
    finishRefreshes(Native::visit('/log'))
        ->press('composeInSheet')
        ->input('sheet-body', 'Shipped it.')
        ->tap('sheet-commit');

    // devnull answers once you're on a guild's page.
    $guild = finishRefreshes(Native::visit('/log/guilds/rust', platform: 'android'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Too Many Attempts.'
            && $params['action'] === ['id' => 'edit', 'label' => 'Edit']);

    expect($guild->instance()->sheetOpen)->toBeFalse()
        ->and(listedIds($guild))->toBe(['c1'])
        ->and(app(Stash::class)->get($this->userId, 'commit')->body)->toBe('Shipped it.');
});

it("never opens the Log's new commit devnull turned down on a paused guild's page, and Edit takes it to the Log", function () {
    holdRefreshes();
    serveApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_paused' => true])])],
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c0'])])],
        'POST /api/v1/commits' => [['message' => 'Too Many Attempts.'], 429],
    ]);
    finishRefreshes(Native::visit('/log'))
        ->press('composeInSheet')
        ->input('sheet-body', 'Shipped it.')
        ->tap('sheet-commit');
    $write = PendingWrite::query()->sole();

    $guild = finishRefreshes(Native::visit('/log/guilds/rust', platform: 'android'))
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Too Many Attempts.'
            && $params['action'] === ['id' => 'edit', 'label' => 'Edit']);

    expect($guild->instance()->guild?->isPaused)->toBeTrue()
        ->and($guild->instance()->sheetOpen)->toBeFalse();

    pressEditOnToast($guild, $write->id);

    expect(nodeByRef($guild, 'sheet')['props']['visible'])->toBeTrue()
        ->and(nodeByRef($guild, 'sheet-body')['props'])->toMatchArray(['value' => 'Shipped it.', 'placeholder' => 'What did you ship?']);
    $guild->assertDontSee('Posted to this guild, not your profile log.');
});

it('opens a Log draft in the normal composer from Edit on an open guild page', function () {
    holdRefreshes();
    serveGuildAndLog([['message' => 'Too Many Attempts.'], 429]);
    finishRefreshes(Native::visit('/log'))
        ->press('composeInSheet')
        ->input('sheet-body', 'Shipped it.')
        ->tap('sheet-commit');
    $write = PendingWrite::query()->sole();
    $guild = finishRefreshes(Native::visit('/log/guilds/rust', platform: 'android'));

    expect($guild->instance()->sheetOpen)->toBeFalse();

    pressEditOnToast($guild, $write->id);

    expect(nodeByRef($guild, 'sheet-body')['props'])->toMatchArray(['value' => 'Shipped it.', 'placeholder' => 'What did you ship?']);
    $guild->assertDontSee('Posted to this guild, not your profile log.');
});

it('opens a post devnull turned down because the guild was paused meanwhile in the guild composer, with why', function () {
    holdRefreshes();
    $paused = false;
    serveApi(function (Request $request) use (&$paused) {
        return match ($request->method().' '.parse_url($request->url(), PHP_URL_PATH)) {
            'GET /api/v1/guilds/rust' => Http::response(boardOf(['c1'], overrides: ['guild' => guildJson(['is_paused' => $paused])])),
            'POST /api/v1/commits' => Http::response(['message' => 'This guild is paused.', 'code' => 'guild_paused'], 422),
            default => Http::response(['message' => 'Not Found'], 404),
        };
    });
    $screen = finishRefreshes(Native::visit('/log/guilds/rust', platform: 'android'))
        ->press('composeForGuild')
        ->input('sheet-body', 'Crabs ahoy.')
        ->tap('sheet-commit');
    $paused = true;

    // The board learns the guild is paused before devnull answers the post.
    finishRefreshes($screen->call('load'), newestFirst: true)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'This guild is paused.' && ! isset($params['action']));

    expect($screen->instance()->guild?->isPaused)->toBeTrue()
        ->and(listedIds($screen))->toBe(['c1'])
        ->and(nodeByRef($screen, 'sheet')['props']['visible'])->toBeTrue()
        ->and(nodeByRef($screen, 'sheet-body')['props'])->toMatchArray(['value' => 'Crabs ahoy.', 'placeholder' => 'What do you want to share with the guild?']);
});

it('quotes a commit on the board without posting the quote to the guild', function () {
    $screen = guildComposer()->input('sheet-body', 'Half a guild post');

    $screen->call('hideCommitSheet')
        ->call('quoteCommit', 'c1')
        ->input('sheet-body', 'Good one.')
        ->tap('sheet-commit');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://devnull.test/api/v1/commits'
        && $request->body() === '{"body":"Good one.","quoted_commit_id":"c1"}');
    expect(app(Stash::class)->get($this->userId, 'guild:rust')->body)->toBe('Half a guild post');
});

it('offers a guild post left over from when the app closed back to its guild, which posts it there', function () {
    $leftover = PendingWrite::query()->create([
        'user_id' => $this->userId,
        'kind' => PendingWrite::Commit,
        'target' => null,
        'client_id' => 'left-over',
        'payload' => ['body' => 'Crabs ahoy.', 'stash' => 'guild:rust', 'composer' => 'compose', 'guild_handle' => 'rust'],
        'status' => PendingWrite::Sending,
        'task_id' => 'a-task-from-before-the-app-closed',
        'attempts' => 1,
    ]);
    serveGuildAndLog();

    // A commit is never sent again on its own, guild post or not: it waits for Edit.
    $log = Native::visit('/log')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['action'] === ['id' => 'edit', 'label' => 'Edit']);

    expect(sentTo('POST', 'commits'))->toBe([])
        ->and($log->instance()->sheetOpen)->toBeFalse();

    pressEditOnToast($log, $leftover->id)->assertNavigatedTo('/log/guilds/rust');

    finishRefreshes(Native::visit('/log/guilds/rust', ['edit' => $leftover->id]))->tap('sheet-commit');

    expect(sentTo('POST', 'commits'))->toBe(['{"body":"Crabs ahoy.","guild_handle":"rust"}']);
});

it('schedules photos for the board as multipart', function () {
    phoneIn('Europe/Lisbon');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:30:00', 'UTC'));
    $screen = photosPicked(guildComposer(), [testPhoto('jpg')])->input('sheet-body', 'Crabs tomorrow.');

    $screen->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00')
        ->tap('sheet-commit')
        ->assertSet('sheetOpen', false);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/scheduled-commits'
        && multipartParts($request)[0]['contents'] === '{"body":"Crabs tomorrow.","scheduled_at":"2026-10-20T08:00:00+00:00","guild_handle":"rust"}');
});
