<?php

use App\Devnull\Account;
use App\Devnull\Data\Remotes;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * `GET /me` with Remotes as given, or off with null.
 *
 * @param  array<string, mixed>|null  $remotes
 * @return array<string, mixed>
 */
function meWithRemotes(?array $remotes = []): array
{
    return currentUserJson(['remotes' => $remotes === null ? null : ['from' => 'following', 'pull_receipts' => true, 'requests' => false, 'everyone' => false, 'unread_count' => 0, ...$remotes]]);
}

it("has the web's Remotes switch, word for word, with Share pulls under it", function () {
    serveApi(['GET /api/v1/me' => [meWithRemotes()]]);

    $screen = Native::visit('/profile/device')
        ->assertSee('Remotes')
        ->assertSee('One-to-one messages with other developers. People you follow can push to you, and you can push to people who follow you. Whether you share pulls (read receipts) is up to you, from Remotes.')
        ->assertSee('When off, nobody can message you and Remotes is hidden. Your conversations are kept for when you turn it back on.')
        ->assertSee('Share pulls')
        ->assertSee("When off, nobody sees when you pull their messages, and you don't see when they pull yours.")
        ->assertDontSee('who can push to you');

    expect(nodeByRef($screen, 'remotes-switch')['props']['value'] ?? null)->toBeTrue()
        ->and(nodeByRef($screen, 'share-pulls-switch')['props']['value'] ?? null)->toBeTrue()
        // After pastéis mode, before Safety.
        ->and(array_column(findNodes($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['pasteis-mode', 'remotes', 'safety'], true)), 'ref'))->toBe(['pasteis-mode', 'remotes', 'safety']);
});

it('turns Remotes off, hiding their settings, and the Remotes tab takes it at once', function () {
    serveApi([
        'GET /api/v1/me' => [meWithRemotes()],
        'PUT /api/v1/me/remotes' => [meWithRemotes(null)],
    ]);

    $screen = Native::visit('/profile/device')->toggle('remotes-switch', false)->assertDontSee('Share pulls');

    expect(app(Account::class)->remotes())->toBeNull()
        ->and(sentTo('PUT', 'me/remotes'))->toBe(['{"enabled":false}'])
        ->and(nodeByRef($screen, 'remotes-switch')['props']['value'] ?? null)->toBeFalse();
});

it('turns Remotes back on', function () {
    app(Account::class)->rememberRemotes(null);
    serveApi([
        'GET /api/v1/me' => [meWithRemotes(null)],
        'PUT /api/v1/me/remotes' => [meWithRemotes()],
    ]);

    Native::visit('/profile/device')->toggle('remotes-switch', true)->assertSee('Share pulls');

    expect(app(Account::class)->remotes())->not->toBeNull()
        ->and(sentTo('PUT', 'me/remotes'))->toBe(['{"enabled":true}']);
});

it("puts the switch back, in the web's words, when devnull can't save it", function () {
    serveApi([
        'GET /api/v1/me' => [meWithRemotes()],
        'PUT /api/v1/me/remotes' => [['message' => 'Server Error'], 500],
    ]);

    $screen = Native::visit('/profile/device')->toggle('remotes-switch', false)->assertSee('Could not save remotes. Please try again.');

    expect(nodeByRef($screen, 'remotes-switch')['props']['value'] ?? null)->toBeTrue()
        ->and(app(Account::class)->remotes())->not->toBeNull();
});

it('saves Share pulls', function () {
    serveApi([
        'GET /api/v1/me' => [meWithRemotes()],
        'PUT /api/v1/me/remote-preferences' => [meWithRemotes(['pull_receipts' => false])],
    ]);

    $screen = Native::visit('/profile/device')->toggle('share-pulls-switch', false);

    expect(sentTo('PUT', 'me/remote-preferences'))->toBe(['{"from":"following","pull_receipts":false}'])
        ->and(nodeByRef($screen, 'share-pulls-switch')['props']['value'] ?? null)->toBeFalse()
        ->and(app(Account::class)->remotes()?->pullReceipts)->toBeFalse();
});

it("puts Share pulls back, in the web's words, when devnull can't save it", function () {
    serveApi([
        'GET /api/v1/me' => [meWithRemotes()],
        'PUT /api/v1/me/remote-preferences' => [['message' => 'Server Error'], 500],
    ]);

    $screen = Native::visit('/profile/device')->toggle('share-pulls-switch', false)->assertSee('Could not save your remote settings. Try again.');

    expect(nodeByRef($screen, 'share-pulls-switch')['props']['value'] ?? null)->toBeTrue();
});

it('offers who can push to you only while devnull offers everyone', function (bool $requests, string $help) {
    serveApi([
        'GET /api/v1/me' => [meWithRemotes(['everyone' => true, 'requests' => $requests])],
        'PUT /api/v1/me/remote-preferences' => [meWithRemotes(['everyone' => true, 'requests' => $requests, 'from' => 'everyone'])],
    ]);

    $screen = Native::visit('/profile/device')
        ->assertSee('who can push to you')
        ->assertSee('People you follow')
        ->assertSee('Everyone')
        ->assertSee($help)
        ->tap('remotes-from-everyone');

    expect(sentTo('PUT', 'me/remote-preferences'))->toBe(['{"from":"everyone","pull_receipts":true}'])
        ->and(app(Account::class)->remotes()?->from)->toBe(Remotes::Everyone);
})->with([
    'requests on' => [true, 'Anyone else lands in Fetched until you merge them. Requests already fetched stay there.'],
    'requests off' => [false, 'Anyone else can push to you once you follow them.'],
]);

it('remembers your Remotes whenever devnull says who you are', function () {
    fakeApi([
        'GET /api/v1/me' => [meWithRemotes(['unread_count' => 4, 'requests' => true])],
        'GET /api/v1/starred' => [pageJson([])],
    ]);

    finishRefreshes(Native::visit('/profile/starred'));

    expect(app(Account::class)->remotes())->toEqual(new Remotes(requests: true, unreadCount: 4));
});

it('keeps Remotes on when devnull is too old to say', function () {
    fakeApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/starred' => [pageJson([])],
    ]);

    finishRefreshes(Native::visit('/profile/starred'));

    expect(app(Account::class)->remotes())->toEqual(new Remotes);
});
