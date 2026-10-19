<?php

use App\Devnull\Account;
use App\Devnull\Data\Remotes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo('2026-10-19 15:30:00');
});

it('lists your conversations, the latest first, as the web inbox does', function () {
    fakeApi(['GET /api/v1/remotes' => [remotesInboxJson([
        conversationJson(['ahead' => 2, 'last_message' => remoteMessageJson(['id' => 7, 'body' => 'did you see the PR?'])]),
        conversationJson([
            'id' => 'conv-2',
            'partner' => userJson(['id' => 'u3', 'username' => 'grace', 'name' => 'Grace Hopper']),
            'last_message' => remoteMessageJson(['id' => 5, 'user_id' => '0199e5c4-0000-7000-8000-000000000001', 'body' => "thanks!\nsee you"]),
            'last_message_at' => '2026-10-19T13:30:00.000000Z',
            'partner_read_through_id' => 5,
        ]),
    ], unread: 2)]]);

    $screen = finishRefreshes(Native::visit('/remotes'))
        ->assertNavTitle('Remotes')
        ->assertTabActive('Remotes')
        ->assertSee('Ada Lovelace')
        ->assertSee('@ada')
        ->assertSee('did you see the PR?')
        ->assertSee('2 ahead')
        ->assertSee('Grace Hopper')
        ->assertSee('you: thanks! see you')
        ->assertSee('2h');

    expect(childRefs($screen, 'remotes'))->toBe(['remote-conv-1', 'remote-conv-2'])
        ->and(nodeByRef($screen, 'status-conv-2')['props']['a11y_label'] ?? null)->toBe('pulled')
        ->and(app(Account::class)->unreadRemotes())->toBe(2);
});

it('lists an org by its name, with how many are in it', function () {
    fakeApi(['GET /api/v1/remotes' => [remotesInboxJson([conversationJson([
        'id' => 'org-1',
        'kind' => 'org',
        'name' => null,
        'partner' => null,
        'members' => [
            ['user' => userJson(), 'role' => 'maintainer'],
            ['user' => userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada Lovelace']), 'role' => 'contributor'],
            ['user' => userJson(['id' => 'u3', 'username' => 'grace', 'name' => 'Grace Hopper']), 'role' => 'contributor'],
        ],
        'last_message' => remoteMessageJson(['user' => userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada Lovelace'])]),
    ])])]]);

    finishRefreshes(Native::visit('/remotes'))
        ->assertSee('Ada, Grace')
        ->assertSee('org · 3')
        ->assertSee('Ada: Shipped it.')
        ->tap('remote-org-1')
        ->assertNavigatedTo('/remotes/orgs/org-1');
});

it('opens a conversation in the Remotes tab', function () {
    fakeApi(['GET /api/v1/remotes' => [remotesInboxJson([conversationJson()])]]);

    finishRefreshes(Native::visit('/remotes'))->tap('remote-conv-1')->assertNavigatedTo('/remotes/conversations/ada');
});

it('opens on the inbox kept last time, with no skeleton, then refreshes behind it', function () {
    holdRefreshes();
    keep('remotes', remotesInboxJson([conversationJson()]));
    serveApi(['GET /api/v1/remotes' => [remotesInboxJson([conversationJson(['id' => 'conv-2', 'partner' => userJson(['id' => 'u3', 'username' => 'grace', 'name' => 'Grace Hopper'])]), conversationJson()])]]);

    $screen = Native::visit('/remotes')->assertSee('Ada Lovelace')->assertDontSee('Grace Hopper');

    expect(showsLoading($screen->tree()))->toBeFalse();

    finishRefreshes($screen)->assertSee('Grace Hopper');

    expect(childRefs($screen, 'remotes'))->toBe(['remote-conv-2', 'remote-conv-1']);
});

it('shows skeleton rows until devnull first answers', function () {
    holdRefreshes();
    serveApi(['GET /api/v1/remotes' => [remotesInboxJson([])]]);

    $screen = Native::visit('/remotes');

    expect(showsLoading($screen->tree()))->toBeTrue();

    finishRefreshes($screen)->assertSee('no conversations yet');
});

it('polls the inbox every 15 seconds, as the web does', function () {
    serveApi(['GET /api/v1/remotes' => [remotesInboxJson([conversationJson()])]]);

    $screen = finishRefreshes(Native::visit('/remotes'));

    serveApi(['GET /api/v1/remotes' => [remotesInboxJson([conversationJson(['ahead' => 1, 'last_message' => remoteMessageJson(['id' => 2, 'body' => 'still there?'])])], unread: 1)]]);

    finishRefreshes($screen->call('pollInbox'))->assertSee('still there?')->assertSee('1 ahead');

    expect(app(Account::class)->unreadRemotes())->toBe(1);
});

it('has the web tabs, with Fetched only while devnull takes requests, counting them', function (bool $requests) {
    app(Account::class)->rememberRemotes(new Remotes(requests: $requests));
    fakeApi(['GET /api/v1/remotes' => [remotesInboxJson([], requests: 2)]]);

    $screen = finishRefreshes(Native::visit('/remotes'));

    expect(childRefs($screen, 'remotes-tabs'))->toBe($requests ? ['tab-open', 'tab-fetched', 'tab-stashed'] : ['tab-open', 'tab-stashed']);

    if ($requests) {
        expect(nodeByRef($screen, 'requests-count'))->not->toBeEmpty();
    }
})->with(['requests on' => true, 'requests off' => false]);

it('lists the stashed conversations and the fetched requests on their tabs, with their own empty states', function () {
    app(Account::class)->rememberRemotes(new Remotes(requests: true));
    serveApi(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(remotesInboxJson(($query['tab'] ?? 'open') === 'stashed' ? [conversationJson(['is_archived' => true])] : []));
    });

    $screen = finishRefreshes(Native::visit('/remotes'))->assertSee('no conversations yet');

    finishRefreshes($screen->tap('tab-stashed'))->assertSee('Ada Lovelace')->assertSet('tab', 'stashed');
    finishRefreshes($screen->tap('tab-fetched'))->assertSee('no requests fetched');

    expect(collect(Http::recorded())->map(fn (array $sent): string => (string) parse_url($sent[0]->url(), PHP_URL_QUERY))->filter()->values()->all())
        ->toContain('tab=stashed', 'tab=fetched');
});

it('loads older conversations before the last one showing', function () {
    serveApi(function (Request $request) {
        $before = str_contains($request->url(), 'before=conv-1');

        return Http::response(remotesInboxJson($before
            ? [conversationJson(['id' => 'conv-0', 'partner' => userJson(['id' => 'u4', 'username' => 'linus', 'name' => 'Linus'])])]
            : [conversationJson()], hasMore: ! $before));
    });

    $screen = finishRefreshes(Native::visit('/remotes'))->call('loadMore')->assertSee('Linus');

    expect(childRefs($screen, 'remotes'))->toBe(['remote-conv-1', 'remote-conv-0'])
        ->and($screen->get('hasMore'))->toBeFalse();
});

it("shows the web's page for /remotes while you have Remotes off", function () {
    app(Account::class)->rememberRemotes(null);
    fakeApi(['GET /api/v1/remotes' => [['message' => 'Not Found.'], 404]]);

    finishRefreshes(Native::visit('/remotes'))
        ->assertSee("fatal: path '/remotes' does not exist in 'HEAD'")
        ->assertSee("There's nothing here.")
        ->assertSee('The link may be mistyped, or whatever it pointed to has been deleted.')
        ->assertSee('Back to your log')
        ->tap('back-to-log')
        ->assertReplacedWith('/log');
});

it('takes a 404 from devnull as Remotes turned off on the web', function () {
    fakeApi(['GET /api/v1/remotes' => [['message' => 'Not Found.'], 404]]);

    finishRefreshes(Native::visit('/remotes'))->assertSee("There's nothing here.");

    expect(app(Account::class)->remotes())->toBeNull();
});

it('wears the unread count on the Remotes tab', function () {
    app(Account::class)->rememberRemotes(new Remotes(unreadCount: 3));
    fakeApi(['GET /api/v1/log/following' => [pageJson([])]]);

    $tab = findNode(Native::visit('/log')->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'bottom_nav_item' && ($node['props']['label'] ?? null) === 'Remotes');

    expect($tab['props']['badge'] ?? null)->toBe('3');
});
