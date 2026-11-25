<?php

use App\Devnull\Account;
use App\Devnull\Data\CurrentUser;
use App\Devnull\Data\User;
use App\Icons\Lucide;
use App\Models\PendingWrite;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Support\MenuSubtitle;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * An organization as `GET /me` lists one you're a member of.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function memberOrganizationJson(array $overrides = []): array
{
    return [
        'type' => 'organization',
        'id' => '0199e5c4-0000-7000-8000-0000000000aa',
        'role' => 'admin',
        'handle' => 'dev-null-sh',
        'name' => '/dev/null',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/9?v=4',
        'bio' => null,
        'location' => null,
        'website' => null,
        'github_url' => 'https://github.com/dev-null-sh',
        'html_url' => 'http://devnull.test/@dev-null-sh',
        'lane' => null,
        ...$overrides,
    ];
}

/**
 * Makes you a member of these organizations, as the last `GET /me` said.
 *
 * @param  list<array<string, mixed>>  $organizations
 */
function memberOf(array $organizations): void
{
    app(Account::class)->rememberOrganizations(CurrentUser::fromArray(currentUserJson(['organizations' => $organizations]))->organizations);
}

/**
 * The Log with its composer open in the sheet, devnull answering a new commit with $answer.
 *
 * @param  array{0: mixed, 1?: int}|null  $answer
 * @param  array<string, array{0: mixed, 1?: int}>  $extra
 */
function composeOnLogAs(?array $answer = null, array $extra = []): TestableComponent
{
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'An older commit', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])])],
        'POST /api/v1/commits' => $answer ?? [['data' => commitJson(['id' => '0199e5c4-2222-7000-8000-00000000cafe', 'body' => 'From the org.', 'author' => memberOrganizationJson()])], 201],
        ...$extra,
    ]);

    return Native::visit('/log', platform: 'ios')->press('composeInSheet');
}

/**
 * The Post as menu a pressable opens, as each row's ref, label and subtitle.
 *
 * @return list<array{0: string|null, 1: string|null, 2: string|null}>
 */
function postAsRows(TestableComponent $screen, string $ref): array
{
    $rows = array_filter(nodeByRef($screen, $ref)['children'] ?? [], fn (array $child): bool => ($child['type'] ?? null) === 'top_bar_action');

    return array_values(array_map(fn (array $row): array => [$row['ref'] ?? null, $row['props']['label'] ?? null, $row['props']['subtitle'] ?? null], $rows));
}

/**
 * The JSON of the commits sent to devnull.
 *
 * @return list<array<string, mixed>>
 */
function commitsSent(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $sent): bool => $sent[0]->method() === 'POST' && str_ends_with(parse_url($sent[0]->url(), PHP_URL_PATH), '/commits'))
        ->map(fn (array $sent): array => json_decode($sent[0]->body(), true))
        ->values()
        ->all();
}

it('reads the organizations you can post as from GET /me, oldest membership first', function () {
    $current = CurrentUser::fromArray(currentUserJson(['organizations' => [
        memberOrganizationJson(),
        memberOrganizationJson(['id' => 'o2', 'handle' => 'nativephp', 'name' => 'NativePHP', 'role' => 'member', 'lane' => 5, 'type' => 'user']),
        ['handle' => ''],
    ]]));

    expect(array_map(fn (User $organization): array => [$organization->username, $organization->isOrganization, $organization->lane()], $current->organizations))
        ->toBe([['dev-null-sh', true, 'primary'], ['nativephp', true, 5]])
        ->and(CurrentUser::fromArray(currentUserJson())->organizations)->toBeNull();
});

it('keeps the organizations GET /me lists, and what it had when devnull is too old to say', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([])],
        'GET /api/v1/me' => [currentUserJson(['organizations' => [memberOrganizationJson()]])],
    ]);

    Native::visit('/log');

    expect(array_map(fn (User $organization): string => $organization->username, app(Account::class)->organizations()))->toBe(['dev-null-sh']);

    app(Account::class)->rememberOrganizations(null);

    expect(app(Account::class)->organizations())->toHaveCount(1);

    app(Account::class)->forget();

    expect(app(Account::class)->organizations())->toBe([]);
});

it('shows no Post as to someone in no organization, as the web hides it', function () {
    composeOnLogAs()
        ->assertSee('New commit')
        ->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-post-as');
});

it('draws each account in the Post as menu with its avatar, round for you, square for an organization, and ticks who posts now', function () {
    memberOf([memberOrganizationJson(), memberOrganizationJson(['id' => 'o2', 'handle' => 'nativephp', 'name' => 'NativePHP', 'role' => 'member', 'avatar_url' => null])]);

    $screen = composeOnLogAs();

    $rows = fn (): array => array_map(fn (array $item): array => [
        $item['ref'],
        $item['props']['image'] ?? null,
        $item['props']['image_shape'],
        $item['props']['checked'],
        $item['props']['icon'],
    ], array_values(array_filter(nodeByRef($screen, 'sheet-post-as')['children'], fn (array $child): bool => $child['type'] === 'top_bar_action')));

    $me = app(Account::class)->user();
    $person = Lucide::UserRound->ios();
    $building = Lucide::Building2->ios();

    expect($me->avatarUrl)->not->toBeNull()
        ->and($rows())->toBe([
            ['post-as-local_dev', $me->avatarUrl, 'circle', true, $person],
            ['post-as-dev-null-sh', 'https://avatars.githubusercontent.com/u/9?v=4', 'rounded', false, $building],
            // No avatar: the building stands in.
            ['post-as-nativephp', null, 'rounded', false, $building],
        ]);

    $screen->call('postAsInSheet', 'dev-null-sh');

    expect(array_column($rows(), 3))->toBe([false, true, false]);
});

it('starts the composer as you, and lists you and your organizations under Post as', function () {
    memberOf([memberOrganizationJson(), memberOrganizationJson(['id' => 'o2', 'handle' => 'nativephp', 'name' => 'NativePHP', 'role' => 'member', 'lane' => 5])]);

    $screen = composeOnLogAs();

    expect(nodeByRef($screen, 'sheet-post-as-name')['props']['text'])->toBe('Local Dev')
        ->and(nodeByRef($screen, 'sheet-post-as-handle')['props']['text'])->toBe('@local_dev')
        ->and(nodeByRef($screen, 'sheet-post-as')['props']['a11y_label'])->toBe('Posting as @local_dev. Choose who to post as')
        ->and(array_column(menuItems($screen, 'sheet-post-as'), 0))->toBe(['post-as-local_dev', 'post-as-dev-null-sh', 'post-as-nativephp']);

    $screen->call('postAsInSheet', 'nativephp');

    expect(nodeByRef($screen, 'sheet-post-as-name')['props']['text'])->toBe('NativePHP')
        ->and(nodeByRef($screen, 'sheet-post-as-handle')['props']['text'])->toBe('@nativephp')
        ->and(nodeByRef($screen, 'sheet-post-as')['props']['a11y_label'])->toBe('Posting as @nativephp. Choose who to post as');

    $screen->input('sheet-body', 'a');
    $organizationFill = sheetButtonCircle($screen, 'sheet-commit')['style']['bg_color'];

    // Commit is filled with the organization's lane rather than yours.
    $screen->call('postAsInSheet', '');

    expect(sheetButtonCircle($screen, 'sheet-commit')['style']['bg_color'])->not->toBe($organizationFill);
});

it('names each account in the Post as menu, with its handle under the name', function () {
    MenuSubtitle::skipUnlessSupported();
    memberOf([memberOrganizationJson(), memberOrganizationJson(['id' => 'o2', 'handle' => 'nativephp', 'name' => 'NativePHP', 'role' => 'member'])]);

    expect(postAsRows(composeOnLogAs(), 'sheet-post-as'))->toBe([
        ['post-as-local_dev', 'Local Dev', '@local_dev'],
        ['post-as-dev-null-sh', '/dev/null', '@dev-null-sh'],
        ['post-as-nativephp', 'NativePHP', '@nativephp'],
    ]);
});

it('puts the handle in the label when core has no menu row subtitles', function () {
    MenuSubtitle::skipIfSupported();
    memberOf([memberOrganizationJson()]);

    expect(postAsRows(composeOnLogAs(), 'sheet-post-as'))->toBe([
        ['post-as-local_dev', 'Local Dev (@local_dev)', null],
        ['post-as-dev-null-sh', '/dev/null (@dev-null-sh)', null],
    ]);
});

it('commits as the organization picked, and shows it staged as the organization', function () {
    memberOf([memberOrganizationJson()]);
    holdRefreshes();

    $screen = composeOnLogAs()
        ->call('postAsInSheet', 'dev-null-sh')
        ->input('sheet-body', 'From the org.')
        ->tap('sheet-commit');

    $staged = $screen->instance()->commits[0];

    expect($staged->isStaged())->toBeTrue()
        ->and($staged->author->username)->toBe('dev-null-sh')
        ->and($staged->author->isOrganization)->toBeTrue()
        ->and(PendingWrite::query()->sole()->author())->toBe('dev-null-sh');

    finishRefreshes($screen);

    expect(commitsSent())->toBe([['body' => 'From the org.', 'author' => 'dev-null-sh']])
        ->and($screen->instance()->commits[0]->author->username)->toBe('dev-null-sh');
});

it('commits as you again once you pick yourself, and ignores an organization you are not in', function () {
    memberOf([memberOrganizationJson()]);

    composeOnLogAs()
        ->call('postAsInSheet', 'laravel')
        ->assertSet('sheetAuthor', null)
        ->call('postAsInSheet', 'dev-null-sh')
        ->call('postAsInSheet', '')
        ->input('sheet-body', 'From me.')
        ->tap('sheet-commit');

    expect(commitsSent())->toBe([['body' => 'From me.']]);
});

it('quotes as the organization picked', function () {
    memberOf([memberOrganizationJson()]);
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Quote me', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])])],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'q1', 'body' => 'Our take', 'quoted_commit_id' => 'c1', 'author' => memberOrganizationJson()])], 201],
    ]);

    $screen = Native::visit('/log', platform: 'ios')->call('quoteCommit', 'c1');

    expect(rendersRef($screen, 'sheet-post-as'))->toBeTrue();

    $screen->call('postAsInSheet', 'dev-null-sh')->input('sheet-body', 'Our take')->tap('sheet-commit');

    expect(commitsSent())->toBe([['body' => 'Our take', 'quoted_commit_id' => 'c1', 'author' => 'dev-null-sh']]);
});

it('replies from the commit page as the organization picked in the bar', function () {
    memberOf([memberOrganizationJson()]);
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit']))],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
        'POST /api/v1/commits' => [['data' => replyJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => 'From the org', 'author' => memberOrganizationJson()])], 201],
    ]);

    $screen = Native::visit('/log/commits/c2');

    // It leads the row under the field, ahead of the photo button.
    expect(array_slice(childRefs($screen, 'reply-actions'), 0, 2))->toBe(['reply-post-as', 'reply-add-photos'])
        ->and(array_column(menuItems($screen, 'reply-post-as'), 0))->toBe(['post-as-local_dev', 'post-as-dev-null-sh']);

    $screen->call('postAsInReply', 'dev-null-sh')
        ->input('reply-input', 'From the org')
        ->tap('send-reply')
        ->assertSee('From the org');

    expect(commitsSent())->toBe([['body' => 'From the org', 'parent_id' => 'c2', 'author' => 'dev-null-sh']])
        ->and($screen->instance()->replies[0]->author->username)->toBe('dev-null-sh');
});

it("fills the reply bar's send button with the lane of the organization it replies as", function () {
    memberOf([memberOrganizationJson(['handle' => 'nativephp', 'name' => 'NativePHP', 'lane' => 5])]);
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit']))],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
    ]);

    $screen = Native::visit('/log/commits/c2')->input('reply-input', 'Agreed');
    $mine = nodeByRef($screen, 'send-reply-circle')['style']['bg_color'];

    $screen->call('postAsInReply', 'nativephp');

    expect(strtolower(nodeByRef($screen, 'send-reply-circle')['style']['bg_color']))->toBe(strtolower(config('native-ui.theme.light.lane-5')))
        ->and(nodeByRef($screen, 'reply-post-as')['props']['a11y_label'])->toBe('Posting as @nativephp. Choose who to post as');

    $screen->call('postAsInReply', '');

    expect(nodeByRef($screen, 'send-reply-circle')['style']['bg_color'])->toBe($mine);
});

it('replies as the organization picked in the bar with photos', function () {
    memberOf([memberOrganizationJson()]);
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit']))],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
        'POST /api/v1/commits' => [['data' => replyJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => 'Look', 'author' => memberOrganizationJson()])], 201],
    ]);

    $screen = photosPicked(Native::visit('/log/commits/c2')->call('postAsInReply', 'dev-null-sh')->input('reply-input', 'Look'), [testPhoto()])
        ->tap('send-reply')
        ->assertSee('Look');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && multipartParts($request)[0]['contents'] === '{"body":"Look","parent_id":"c2","author":"dev-null-sh"}'
        && multipartParts($request)[1]['name'] === 'images[]');

    expect($screen->instance()->replies[0]->author->username)->toBe('dev-null-sh');
});

it('posts as the organization picked in a reply sheet, which starts as you, apart from the bar', function () {
    memberOf([memberOrganizationJson()]);
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit']))],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
        'POST /api/v1/commits' => [['data' => replyJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => 'Sheet reply'])], 201],
    ]);

    // The bar no longer opens the sheet, but a reply written there before it took over comes back to it if devnull
    // turned it down.
    $screen = Native::visit('/log/commits/c2', platform: 'ios')
        ->call('postAsInReply', 'dev-null-sh')
        ->call('replyInSheet', 'c2')
        ->assertSet('sheetAuthor', null);

    expect(rendersRef($screen, 'sheet-post-as'))->toBeTrue();

    $screen->call('postAsInSheet', 'dev-null-sh')->input('sheet-body', 'Sheet reply')->tap('sheet-commit');

    expect(commitsSent())->toBe([['body' => 'Sheet reply', 'parent_id' => 'c2', 'author' => 'dev-null-sh']]);
});

it('puts a commit turned down for leaving the organization back in the sheet, asks devnull again, and then posts as you', function () {
    memberOf([memberOrganizationJson()]);
    $refused = 'You can only post as an organization you are a member of.';
    $routes = [
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1'])])],
        'GET /api/v1/me' => [currentUserJson(['organizations' => [memberOrganizationJson()]])],
    ];
    serveApi($routes);

    $screen = Native::visit('/log', platform: 'ios')
        ->press('composeInSheet')
        ->call('postAsInSheet', 'dev-null-sh')
        ->input('sheet-body', 'From the org.');

    // You were taken out of the organization since the app last asked.
    serveApi([
        ...$routes,
        'POST /api/v1/commits' => [['message' => $refused, 'errors' => ['author' => [$refused]]], 422],
        'GET /api/v1/me' => [currentUserJson(['organizations' => []])],
    ]);

    $screen->tap('sheet-commit')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === $refused && $params['variant'] === 'error');

    expect(commitsSent())->toBe([['body' => 'From the org.', 'author' => 'dev-null-sh']])
        ->and(app(Account::class)->organizations())->toBe([]);

    // The sheet opens on the draft again, as the organization picked, which now posts as you.
    $screen->dismissSheet('sheet');

    expect($screen->instance())
        ->sheetOpen->toBeTrue()
        ->sheetBody->toBe('From the org.')
        ->sheetAuthor->toBe('dev-null-sh');
    expect(nodeByRef($screen, 'sheet-post-as-handle')['props']['text'])->toBe('@local_dev')
        ->and(menuItems($screen, 'sheet-post-as'))->toHaveCount(1);

    serveApi([...$routes, 'POST /api/v1/commits' => [['data' => commitJson(['id' => 'c9', 'body' => 'From the org.'])], 201]]);
    $screen->tap('sheet-commit');

    expect(commitsSent())->toBe([['body' => 'From the org.', 'author' => 'dev-null-sh'], ['body' => 'From the org.']]);
});

it('schedules a commit as the organization picked, and lists it as the organization', function () {
    phoneIn('Europe/Lisbon');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:30:00', 'UTC'));
    memberOf([memberOrganizationJson()]);

    composeOnLogAs(extra: [
        'POST /api/v1/scheduled-commits' => [['data' => scheduledCommitJson(['author' => memberOrganizationJson()])], 201],
        'GET /api/v1/scheduled-commits' => [scheduledPageJson([scheduledCommitJson(['author' => memberOrganizationJson()]), scheduledCommitJson(['id' => 's2'])])],
    ])
        ->call('postAsInSheet', 'dev-null-sh')
        ->input('sheet-body', 'Shipping this tomorrow.')
        ->tap('sheet-schedule')
        ->pickDateTime('scheduled-at', '2026-10-20T09:00')
        ->tap('sheet-commit');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/scheduled-commits')
        && $request['author'] === 'dev-null-sh');

    $list = Native::visit('/log/scheduled');

    expect(rendersRef($list, 'scheduled-author-0199e5c4-3333-7000-8000-00000000da7e'))->toBeTrue()
        ->and(rendersRef($list, 'scheduled-author-s2'))->toBeFalse();
    $list->assertSee('As @dev-null-sh');
});
