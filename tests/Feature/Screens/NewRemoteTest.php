<?php

use App\Devnull\Account;
use App\Devnull\Data\User;
use App\NativeComponents\NewOrg;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * @return array{user: array<string, mixed>, reason: string|null}
 */
function candidateJson(string $username, string $name, ?string $reason = null): array
{
    return ['user' => userJson(['id' => 'u-'.$username, 'handle' => $username, 'name' => $name]), 'reason' => $reason];
}

/**
 * Answers people searches with $candidates, and a new org with $org.
 *
 * @param  list<array<string, mixed>>  $candidates
 * @param  array{0: array<string, mixed>, 1: int}  $org
 */
function servePeople(array $candidates, array $org = [['org' => ['id' => 'org-new']], 201]): void
{
    serveApi(function (Request $request) use ($candidates, $org) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/v1/remotes/candidates' => Http::response(['candidates' => $candidates]),
            '/api/v1/remotes/orgs' => Http::response(...$org),
            default => Http::response(remotesInboxJson([])),
        };
    });
}

function navActionIds(TestableComponent $screen): array
{
    return array_map(
        fn (array $node): string => $node['props']['id'],
        findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action'),
    );
}

it('has the web inbox buttons on Remotes, New org then New remote, with its icons', function (string $platform) {
    fakeApi(['GET /api/v1/remotes' => [remotesInboxJson([])]]);

    $screen = finishRefreshes(Native::visit('/remotes', platform: $platform));

    expect(navActionIds($screen))->toBe(['new-org', 'new-remote']);

    $actions = array_column(findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action'), 'props');

    expect($actions[0])->toMatchArray(['icon' => 'custom:users-round', 'a11y_label' => 'New org'])
        ->and($actions[1])->toMatchArray(['icon' => 'custom:square-pen', 'a11y_label' => 'New remote']);

    $screen->press('newRemote')->assertNavigatedTo('/remotes/new');
    finishRefreshes(Native::visit('/remotes'))->press('newOrg')->assertNavigatedTo('/remotes/new-org');
})->with(['ios', 'android']);

it('has nothing to start while you have Remotes off', function () {
    app(Account::class)->rememberRemotes(null);
    fakeApi([]);

    expect(navActionIds(Native::visit('/remotes')))->toBe([]);
});

it('finds a developer to message as you type, the ones you can message first, as the web picker does', function () {
    servePeople([
        candidateJson('grace', 'Grace Hopper', "doesn't follow you"),
        candidateJson('ada', 'Ada Lovelace'),
    ]);

    $screen = Native::visit('/remotes/new')
        ->assertNavTitle('New remote')
        ->assertSee('Type a name or handle to find a developer')
        ->set('query', '@ad')
        ->assertSee('Ada Lovelace')
        ->assertSee("@grace · doesn't follow you");

    expect(childRefs($screen, 'candidates'))->toBe(['candidate-ada', 'candidate-grace']);

    $sent = collect(Http::recorded())->map(fn (array $call): string => $call[0]->url())->filter(fn (string $url): bool => str_contains($url, 'candidates'))->first();
    parse_str((string) parse_url($sent, PHP_URL_QUERY), $query);

    expect($query)->toBe(['q' => 'ad', 'for' => 'remote']);

    // Someone you can't message stays put; anyone else opens your conversation in the picker's place.
    $screen->tap('candidate-grace')->assertNavTitle('New remote');
    $screen->tap('candidate-ada')->assertReplacedWith('/remotes/conversations/ada');
});

it("answers a term nobody matches in git's voice", function () {
    servePeople([]);

    Native::visit('/remotes/new')->set('query', 'zz')->assertSee("fatal: nothing matches 'zz'");
});

it('picks people for a new org, counts them, and takes one back out from their chip', function () {
    servePeople([candidateJson('ada', 'Ada Lovelace'), candidateJson('grace', 'Grace Hopper', 'has remotes off')]);

    $screen = Native::visit('/remotes/new-org')
        ->assertNavTitle('New org')
        ->assertSee('nobody picked yet')
        ->assertSee('1/100')
        ->set('query', 'a');

    $sent = collect(Http::recorded())->map(fn (array $call): string => $call[0]->url())->filter(fn (string $url): bool => str_contains($url, 'candidates'))->first();

    expect($sent)->toContain('for=org');

    $screen->tap('candidate-ada')->assertSee('2/100')->assertDontSee('nobody picked yet');
    $screen->tap('candidate-grace')->assertSee('2/100');

    expect(childRefs($screen, 'picked'))->not->toBeEmpty();

    $screen->tap('picked-ada')->assertSee('1/100')->assertSee('nobody picked yet');
});

it('says the org is full beside everyone else once it holds the web limit', function () {
    servePeople([candidateJson('ada', 'Ada Lovelace')]);

    $people = array_map(fn (int $index): User => User::fromArray(userJson(['id' => "u{$index}", 'handle' => "dev{$index}", 'name' => "Dev {$index}"])), range(1, NewOrg::MaxMembers - 1));

    Native::visit('/remotes/new-org')
        ->set('picked', $people)
        ->set('query', 'ada')
        ->assertSee('100/100')
        ->assertSee('@ada · the org is full (100)')
        ->tap('candidate-ada')
        ->assertSee('100/100');
});

it('creates the org with its name and people, and opens it in the form’s place', function () {
    servePeople([candidateJson('ada', 'Ada Lovelace'), candidateJson('grace', 'Grace Hopper')]);

    Native::visit('/remotes/new-org')
        ->set('name', '  dev/null core ')
        ->set('query', 'a')
        ->tap('candidate-ada')
        ->tap('candidate-grace')
        ->tap('create-org')
        ->assertReplacedWith('/remotes/orgs/org-new');

    expect(json_decode(sentTo('POST', 'remotes/orgs')[0], true))->toBe(['name' => 'dev/null core', 'members' => ['ada', 'grace']]);
});

it("shows devnull's reason beside Create org when it won't create it, and stays", function () {
    servePeople([candidateJson('ada', 'Ada Lovelace')], [['message' => "@ada doesn't follow you.", 'errors' => ['members.0' => ["@ada doesn't follow you."]]], 422]);

    Native::visit('/remotes/new-org')
        ->set('query', 'ada')
        ->tap('candidate-ada')
        ->tap('create-org')
        ->assertSee("@ada doesn't follow you.")
        ->assertSet('creating', false)
        ->assertNavTitle('New org');

    expect(sentTo('POST', 'remotes/orgs'))->toHaveCount(1);
});

it('creates nothing until someone is picked', function () {
    servePeople([]);

    Native::visit('/remotes/new-org')->call('create');

    expect(sentTo('POST', 'remotes/orgs'))->toBe([]);
});
