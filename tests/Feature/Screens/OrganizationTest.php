<?php

use App\NativeComponents\Organization;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

function organizationJson(array $overrides = [], bool $isFollowing = false): array
{
    return [
        'data' => [
            'handle' => 'laravel',
            'name' => 'Laravel',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/958072',
            'bio' => 'The PHP framework for web artisans.',
            'location' => null,
            'website' => 'https://laravel.com',
            'github_url' => 'https://github.com/laravel',
            'html_url' => 'http://devnull.test/@laravel',
            'lane' => 0,
            'followers_count' => 45,
            'admins' => [
                userJson(['id' => 'u-taylor', 'handle' => 'taylorotwell', 'name' => 'Taylor Otwell']),
                userJson(['id' => 'u-nuno', 'handle' => 'nunomaduro', 'name' => 'Nuno Maduro']),
            ],
            ...$overrides,
        ],
        'is_following' => $isFollowing,
        'can_claim' => false,
        'settings_url' => null,
    ];
}

function organizationCommitJson(array $overrides = []): array
{
    return commitJson(['author' => ['type' => 'organization', 'id' => 'o-laravel', 'handle' => 'laravel', 'name' => 'Laravel', 'lane' => 0], ...$overrides]);
}

function tapOrganizationTab(TestableComponent $screen, string $tab): TestableComponent
{
    return $screen->changeTab($screen->get('tab') === $tab ? 'backToTop' : 'showPage', (int) array_search($tab, Organization::Tabs, true));
}

it('shows an organization as its page on the web does, and opens its links in the browser', function () {
    fakeApi([
        'GET /api/v1/organizations/laravel' => [organizationJson()],
        'GET /api/v1/organizations/laravel/commits' => [pageJson([organizationCommitJson(['id' => 'c1', 'body' => 'Laravel 13 is out'])])],
    ]);

    $screen = finishRefreshes(Native::visit('/log/organizations/laravel'));

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'profile-location'))->toBeNull();

    $screen->assertNavTitle('Laravel')
        ->assertSee('@laravel')
        ->assertSee('Organization')
        ->assertSee('The PHP framework for web artisans.')
        ->assertSee('laravel.com')
        ->assertSee('github.com/laravel')
        ->assertSee('45')->assertSee('followers')
        ->assertSee('Admins')->assertSee('@taylorotwell')->assertSee('@nunomaduro')
        ->assertElement('pager', fn (array $node): bool => ($node['props']['tabs'] ?? null) === ['Commits', 'Mentions'])
        ->assertSee('Laravel 13 is out')
        ->assertSee("That's @laravel's root commit.")
        ->tap('profile-website')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://laravel.com')
        ->tap('profile-github')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/laravel');
});

it('lists the commits that mention the organization on its Mentions tab', function () {
    fakeApi([
        'GET /api/v1/organizations/laravel' => [organizationJson()],
        'GET /api/v1/organizations/laravel/commits' => [pageJson([])],
        'GET /api/v1/organizations/laravel/mentions' => [pageJson([commitJson(['id' => 'm1', 'body' => 'Thanks @laravel'])])],
    ]);

    $screen = finishRefreshes(Native::visit('/log/organizations/laravel'))
        ->assertSee("@laravel hasn't committed anything yet.");

    finishRefreshes(tapOrganizationTab($screen, 'mentions'))
        ->assertSet('tab', 'mentions')
        ->assertSee('Thanks @laravel')
        ->assertSee("That's the first mention of @laravel.");
});

it('says a tab did not load without losing the header', function () {
    fakeApi([
        'GET /api/v1/organizations/laravel' => [organizationJson()],
        'GET /api/v1/organizations/laravel/commits' => [['message' => 'Not Found.'], 404],
    ]);

    finishRefreshes(Native::visit('/log/organizations/laravel'))
        ->assertSee('The PHP framework for web artisans.')
        ->assertSee("Its commits didn't load here.")
        ->assertDontSee('Not Found.')
        ->tap('empty-link')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/@laravel');
});

it('follows and unfollows the organization', function () {
    fakeApi([
        'GET /api/v1/organizations/laravel' => [organizationJson()],
        'GET /api/v1/organizations/laravel/commits' => [pageJson([])],
        'POST /api/v1/organizations/laravel/follow' => [null, 204],
        'DELETE /api/v1/organizations/laravel/follow' => [null, 204],
    ]);

    $screen = finishRefreshes(Native::visit('/log/organizations/laravel'))
        ->tap('follow')
        ->assertSee('Unfollow')
        ->assertSee('46');

    expect($screen->instance()->organization->isFollowing)->toBeTrue();

    $screen->tap('follow');

    expect($screen->instance()->organization->isFollowing)->toBeFalse()
        ->and($screen->instance()->organization->followersCount)->toBe(45);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://devnull.test/api/v1/organizations/laravel/follow');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/organizations/laravel/follow');
});

it('puts the follow back when devnull refuses it', function () {
    fakeApi([
        'GET /api/v1/organizations/laravel' => [organizationJson()],
        'GET /api/v1/organizations/laravel/commits' => [pageJson([])],
        'POST /api/v1/organizations/laravel/follow' => [['message' => 'Too Many Attempts.'], 429],
    ]);

    $screen = finishRefreshes(Native::visit('/log/organizations/laravel'))->tap('follow');

    expect($screen->instance()->organization->isFollowing)->toBeFalse()
        ->and($screen->instance()->organization->followersCount)->toBe(45);
});

it('shares the organization page and opens its admins', function () {
    fakeApi([
        'GET /api/v1/organizations/laravel' => [organizationJson()],
        'GET /api/v1/organizations/laravel/commits' => [pageJson([])],
    ]);

    finishRefreshes(Native::visit('/log/organizations/laravel'))
        ->tap('share')
        ->assertNativeCalled('Share.Url', fn (array $params): bool => $params['url'] === 'http://devnull.test/@laravel')
        ->tap('admin-nunomaduro')
        ->assertNavigatedTo('/log/users/nunomaduro');
});

it("says the page didn't load when devnull can't send the organization, and offers its page on the web", function () {
    Http::fake(['devnull.test/api/v1/organizations/*' => Http::response(['message' => 'Not Found.'], 404)]);

    $screen = finishRefreshes(Native::visit('/log/organizations/nobody'))->assertNavTitle('nobody');

    $screen->assertSee("This organization's page didn't load here.");
    expect(findNode($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['error', 'organization-header'], true)))->toBeNull();

    $screen->tap('empty-link')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/@nobody');
});

it('draws an organization without a lane in Commit Blue on its page and its commits, as the web does', function () {
    fakeApi([
        'GET /api/v1/organizations/laravel' => [organizationJson(['lane' => null])],
        'GET /api/v1/organizations/laravel/commits' => [pageJson([organizationCommitJson([
            'id' => 'c1',
            'author' => ['type' => 'organization', 'id' => '01a19c71-70d7-73e4-801c-09e5c315ad53', 'handle' => 'laravel', 'name' => 'Laravel', 'lane' => null],
        ])])],
    ]);

    $screen = finishRefreshes(Native::visit('/log/organizations/laravel'));
    $primary = strtolower((string) config('native-ui.theme.light.primary'));
    $ring = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'stack' && (float) ($node['layout']['width'] ?? 0) === 92.0);
    $lanes = array_map(fn (int $lane): string => strtolower((string) config("native-ui.theme.light.lane-{$lane}")), range(0, 5));
    $laneBorders = findNodes($screen->tree(), fn (array $node): bool => in_array(strtolower((string) ($node['style']['border_color'] ?? '')), $lanes, true));

    expect(strtolower($ring['style']['bg_color']))->toBe($primary)
        ->and(findNodes($screen->tree(), fn (array $node): bool => strtolower((string) ($node['style']['border_color'] ?? '')) === $primary))->not->toBeEmpty()
        ->and($laneBorders)->toBeEmpty();
});

it('opens only the GitHub handles its bio mentions', function () {
    fakeApi([
        'GET /api/v1/organizations/laravel' => [organizationJson(['bio' => 'Maintained by @taylorotwell'])],
        'GET /api/v1/organizations/laravel/commits' => [pageJson([])],
    ]);

    $screen = finishRefreshes(Native::visit('/log/organizations/laravel'))
        ->call('openGitHubMention', 'someone-else')
        ->assertNativeNotCalled('Browser.OpenInApp');

    $screen->call('openGitHubMention', 'taylorotwell')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/taylorotwell');
});
