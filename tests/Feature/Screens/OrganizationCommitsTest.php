<?php

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\CurrentUser;
use App\Devnull\Data\Pasteis;
use App\Devnull\ResponseCache;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * NativePHP as `GET /me` lists an organization you're in.
 *
 * @return array<string, mixed>
 */
function nativephpMembershipJson(): array
{
    return [
        'type' => 'organization',
        'id' => 'o-nativephp',
        'role' => 'member',
        'handle' => 'nativephp',
        'name' => 'NativePHP',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/7?v=4',
        'github_url' => 'https://github.com/nativephp',
        'html_url' => 'http://devnull.test/@nativephp',
        'lane' => 5,
    ];
}

/**
 * A commit from NativePHP, posted by whichever member it was: devnull never says who.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nativephpCommitJson(array $overrides = []): array
{
    return commitJson(['author' => ['type' => 'organization', 'id' => 'o-nativephp', 'handle' => 'nativephp', 'name' => 'NativePHP', 'lane' => 5], ...$overrides]);
}

/**
 * Makes you a member of NativePHP, as the last `GET /me` said.
 */
function joinNativephp(): void
{
    app(Account::class)->rememberOrganizations(CurrentUser::fromArray(currentUserJson(['organizations' => [nativephpMembershipJson()]]))->organizations);
}

/**
 * The Log showing NativePHP's commit c1, with the API answering $routes besides.
 *
 * @param  array<string, mixed>  $commit
 * @param  array<string, array{0: mixed, 1?: int}>  $routes
 */
function nativephpCommitOnLog(array $commit = [], array $routes = []): TestableComponent
{
    serveApi([
        'GET /api/v1/log/following' => [pageJson([nativephpCommitJson(['id' => 'c1', 'body' => 'NativePHP ships', ...$commit])])],
        ...$routes,
    ]);

    return Native::visit('/log', platform: 'ios');
}

it('treats an organization\'s commit as yours when you\'re a member, whoever posted it', function () {
    joinNativephp();
    app(Account::class)->rememberPasteis(new Pasteis(Pasteis::Daily, CarbonImmutable::now()->addDay()));

    $screen = nativephpCommitOnLog(['stars_count' => 2]);

    // Delete, as for your own, but no Pin: devnull pins to a person's profile. No Report, as devnull won't take one.
    expect(menuItems($screen, 'more-c1'))->toBe([
        ['share-c1', 'Share link', 'custom:link'],
        'divider',
        ['delete-c1', 'Delete commit', 'custom:trash-2'],
    ])
        // You can't star it, so its star is the count, as on your own.
        ->and(rendersRef($screen, 'star-c1'))->toBeFalse()
        ->and(nodeByRef($screen, 'star-count-c1')['props']['a11y_label'])->toBe('2 stars, show who starred')
        // An organization's commit takes no pastéis from anyone, so the web shows none.
        ->and(rendersRef($screen, 'pastel-c1'))->toBeFalse()
        ->and(rendersRef($screen, 'pastel-count-c1'))->toBeFalse();

    $screen->call('toggleStar', 'c1')
        ->call('givePastel', 'c1')
        ->call('reportCommit', 'c1')
        ->call('togglePin', 'c1')
        ->assertSet('sheetOpen', false);

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('lets a member delete an organization\'s commit they didn\'t post', function () {
    joinNativephp();
    $deleted = false;

    serveApi(function (Request $request) use (&$deleted) {
        return match ($request->method().' '.parse_url($request->url(), PHP_URL_PATH)) {
            'DELETE /api/v1/commits/c1' => tap(Http::response(null, 204), function () use (&$deleted): void {
                $deleted = true;
            }),
            'GET /api/v1/log/following' => Http::response(pageJson([
                ...($deleted ? [] : [nativephpCommitJson(['id' => 'c1', 'body' => 'Delete me'])]),
                commitJson(['id' => 'c2', 'body' => 'Keep me']),
            ])),
            default => Http::response(currentUserJson(['organizations' => [nativephpMembershipJson()]])),
        };
    });

    Native::visit('/log', platform: 'ios')
        ->tap('delete-c1')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Delete this commit?')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit'])
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => str_starts_with($params['message'], 'rm '))
        ->assertDontSee('Delete me')
        ->assertSee('Keep me');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/commits/c1');
});

it('puts Delete in the nav bar of an organization\'s commit page for a member', function () {
    joinNativephp();
    fakeApi(['GET /api/v1/commits/c1' => [commitPageJson(nativephpCommitJson(['id' => 'c1']))]]);

    Native::visit('/log/commits/c1', platform: 'ios')
        ->assertElement('top_bar_action', fn (array $node): bool => ($node['props']['id'] ?? null) === 'delete');
});

it('offers someone outside the organization a star, the pastel and Report, never Delete', function () {
    app(Account::class)->rememberPasteis(new Pasteis(Pasteis::Daily, CarbonImmutable::now()->addDay()));

    $screen = nativephpCommitOnLog();

    expect(menuItems($screen, 'more-c1'))->toBe([
        ['share-c1', 'Share link', 'custom:link'],
        'divider',
        ['report-c1', 'Report commit', 'custom:flag'],
    ])
        ->and(rendersRef($screen, 'star-c1'))->toBeTrue()
        // Still no pastel: it would go to whoever posted it.
        ->and(rendersRef($screen, 'pastel-c1'))->toBeFalse()
        ->and(rendersRef($screen, 'pastel-count-c1'))->toBeFalse();

    $screen->call('confirmDelete', 'c1')->call('deleteCommit', 'c1');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('lets a member quote an organization\'s commit still waiting for approval, as the web does', function () {
    $screen = nativephpCommitOnLog(['status' => 'pending']);

    expect(rendersRef($screen, 'quote-c1'))->toBeFalse();

    joinNativephp();
    $screen = nativephpCommitOnLog(['status' => 'pending']);

    expect(rendersRef($screen, 'quote-c1'))->toBeTrue();

    $screen->call('quoteCommit', 'c1')->assertSet('sheetOpen', true);
});

it('doesn\'t let a member vote on an organization\'s poll', function () {
    $poll = [
        'id' => 'poll1',
        'closes_at' => now()->addDay()->toIso8601ZuluString(),
        'is_closed' => false,
        'has_voted' => false,
        'total_votes' => null,
        'options' => [
            ['id' => 'o1', 'label' => 'Tabs', 'votes_count' => null, 'voted' => false, 'percentage' => null],
            ['id' => 'o2', 'label' => 'Spaces', 'votes_count' => null, 'voted' => false, 'percentage' => null],
        ],
    ];

    expect(rendersRef(nativephpCommitOnLog(['poll' => $poll]), 'poll-vote-c1'))->toBeTrue();

    joinNativephp();
    $screen = nativephpCommitOnLog(['poll' => $poll]);

    expect(rendersRef($screen, 'poll-vote-c1'))->toBeFalse();

    $screen->call('choosePollOption', 'c1', 'o1')->call('voteOnPoll', 'c1');

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), Client::pollVotesPath('c1')));
});

it('says devnull\'s no when you\'ve left the organization, and stops offering Delete once /me says so', function () {
    joinNativephp();
    $left = false;

    serveApi(function (Request $request) use (&$left) {
        return match ($request->method().' '.parse_url($request->url(), PHP_URL_PATH)) {
            'DELETE /api/v1/commits/c1' => tap(Http::response(['message' => 'This action is unauthorized.'], 403), function () use (&$left): void {
                $left = true;
            }),
            'GET /api/v1/log/following' => Http::response(pageJson([nativephpCommitJson(['id' => 'c1', 'body' => 'NativePHP ships'])])),
            default => Http::response(currentUserJson(['organizations' => $left ? [] : [nativephpMembershipJson()]])),
        };
    });

    $screen = Native::visit('/log', platform: 'ios');
    $asked = count(sentTo('GET', 'me'));

    $screen->tap('delete-c1')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit'])
        ->assertSee('This action is unauthorized.')
        ->assertSee('NativePHP ships');

    expect(sentTo('GET', 'me'))->toHaveCount($asked + 1)
        ->and(app(Account::class)->organizations())->toBe([])
        ->and(menuItems($screen, 'more-c1'))->toBe([
            ['share-c1', 'Share link', 'custom:link'],
            'divider',
            ['report-c1', 'Report commit', 'custom:flag'],
        ]);
});

it('lists who starred an organization\'s commit, or says only the member who posted it can', function () {
    joinNativephp();

    nativephpCommitOnLog(['stars_count' => 1], ['GET /api/v1/commits/c1/stars' => [pageJson([userJson(['id' => 'u-grace', 'handle' => 'grace', 'name' => 'Grace Hopper'])], path: 'http://devnull.test/api/v1/commits/c1/stars')]])
        ->tap('star-count-c1')
        ->assertSee('Grace Hopper')
        ->assertDontSee('Only the member who posted this commit can see who starred it here.');

    // The API answers someone who didn't post it as though the list isn't there, where the web shows it them.
    nativephpCommitOnLog(['stars_count' => 1], ['GET /api/v1/commits/c1/stars' => [['message' => 'Not found.'], 404]])
        ->tap('star-count-c1')
        ->assertSee('Only the member who posted this commit can see who starred it here.')
        ->assertDontSee('fatal: could not load stars')
        ->assertDontSee('Try again');
});

it('keeps an organization\'s commits off your profile\'s count, posted or deleted', function () {
    keep('users/local_dev', ['data' => userWithCountsJson(commits: 3)]);
    keep('users/local_dev/commits', pageJson([commitJson(['id' => 'c0'])]));
    keep('log/following', pageJson([nativephpCommitJson(['id' => 'c1']), commitJson(['id' => 'c0'])]));
    fakeApi(['DELETE /api/v1/commits/c1' => [null, 204]]);

    app(ResponseCache::class)->commitAdded(nativephpCommitJson(['id' => 'c2']));
    app(Client::class)->deleteCommit('c1');

    expect(kept('users/local_dev')['data']['commits_count'])->toBe(3)
        ->and(array_column(kept('users/local_dev/commits')['data'], 'id'))->toBe(['c0'])
        ->and(array_column(kept('log/following')['data'], 'id'))->toBe(['c2', 'c0']);
});
