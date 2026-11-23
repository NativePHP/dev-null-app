<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

it('lists the members with their name, handle and bio, and marks the moderator', function () {
    fakeApi([
        'GET /api/v1/guilds/rust/members' => [guildMembersJson([
            'grace' => ['bio' => 'Found the first bug.', 'follows_you' => true],
            'linus' => [],
        ], moderatorId: 'id-grace')],
    ]);

    $screen = Native::visit('/log/guilds/rust/members', ['guildName' => 'Rustaceans'])
        ->assertSee('Grace')
        ->assertSee('@grace')
        ->assertSee('Found the first bug.')
        ->assertSee('Linus');

    expect(treeShows(nodeByRef($screen, 'person-grace'), '(moderator)'))->toBeTrue()
        ->and(treeShows(nodeByRef($screen, 'person-linus'), '(moderator)'))->toBeFalse()
        // Follow and Follows you are both off here, as on the web, even for someone who follows you.
        ->and(rendersRef($screen, 'follow-grace'))->toBeFalse()
        ->and(rendersRef($screen, 'follow-linus'))->toBeFalse()
        ->and(rendersRef($screen, 'follows-you-grace'))->toBeFalse()
        ->and(treeShows(nodeByRef($screen, 'person-grace'), 'Follows you'))->toBeFalse();
});

it('tells screen readers which member is the moderator', function () {
    fakeApi([
        'GET /api/v1/guilds/rust/members' => [guildMembersJson(['grace' => [], 'linus' => []], moderatorId: 'id-grace')],
    ]);

    $screen = Native::visit('/log/guilds/rust/members');

    expect(nodeByRef($screen, 'person-grace')['props']['a11y_label'])->toEndWith(', moderator')
        ->and(nodeByRef($screen, 'person-linus')['props']['a11y_label'])->not->toEndWith(', moderator');
});

it('marks nobody when the guild has no moderator', function () {
    fakeApi(['GET /api/v1/guilds/rust/members' => [guildMembersJson(['grace' => []])]]);

    $screen = Native::visit('/log/guilds/rust/members')->assertSee('Grace');

    expect(treeShows(nodeByRef($screen, 'person-grace'), '(moderator)'))->toBeFalse();
});

it('marks nobody when the moderator has left the guild', function () {
    fakeApi(['GET /api/v1/guilds/rust/members' => [guildMembersJson(['grace' => [], 'linus' => []], moderatorId: 'id-ada')]]);

    $screen = Native::visit('/log/guilds/rust/members')->assertSee('Grace');

    expect(treeShows(nodeByRef($screen, 'person-grace'), '(moderator)'))->toBeFalse()
        ->and(treeShows(nodeByRef($screen, 'person-linus'), '(moderator)'))->toBeFalse();
});

it('says when the guild is gone', function () {
    fakeApi([
        'GET /api/v1/guilds/rust/members' => [['message' => 'Not Found'], 404],
        'GET /api/v1/guilds' => [['data' => [], 'links' => ['next' => null]]],
    ]);

    finishRefreshes(Native::visit('/log/guilds/rust/members'))
        ->assertSee('fatal: no such guild #rust')
        ->assertSee('This guild is no longer available.');
});

it('says guilds are switched off when the directory is gone too', function () {
    fakeApi(['GET /api/v1/guilds/rust/members' => [['message' => 'Not Found'], 404]]);

    finishRefreshes(Native::visit('/log/guilds/rust/members'))
        ->assertSee('fatal: guilds are not available')
        ->assertSee("Guilds aren't open to you yet.");
});

it('opens their profile from a row', function () {
    fakeApi(['GET /api/v1/guilds/rust/members' => [guildMembersJson(['grace' => []])]]);

    Native::visit('/log/guilds/rust/members')->tap('person-grace')->assertNavigatedTo('/log/users/grace');
});

it('titles the screen with the passed guild name, else the kept guild, else the handle, under Members', function () {
    fakeApi(['GET /api/v1/guilds/rust/members' => [guildMembersJson(['grace' => []])]]);

    $passed = Native::visit('/log/guilds/rust/members', ['guildName' => 'Rustaceans'])->assertNavTitle('Rustaceans');

    expect(findNode($passed->tree(), fn (array $node): bool => ($node['props']['nav_subtitle'] ?? null) === 'Members'))->not->toBeNull();

    Native::visit('/log/guilds/rust/members')->assertNavTitle('#rust');

    keep('guilds/rust', boardJson(['guild' => guildJson(['name' => 'Kept Rust'])]));
    Native::visit('/log/guilds/rust/members')->assertNavTitle('Kept Rust');
});

it('speaks git when nobody has joined', function () {
    fakeApi(['GET /api/v1/guilds/rust/members' => [guildMembersJson([])]]);

    Native::visit('/log/guilds/rust/members')
        ->assertSee('fatal: no members yet')
        ->assertSee('Nobody has joined this guild yet.');
});

it('loads the next page without repeating anyone', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), 'page=2')
        ? Http::response(guildMembersJson(['grace' => [], 'linus' => []]))
        : Http::response(guildMembersJson(['ada' => [], 'grace' => []], nextPage: 2)));

    $screen = Native::visit('/log/guilds/rust/members')
        ->assertDontSee('Linus')
        ->call('loadMore')
        ->assertSee('Ada')
        ->assertSee('Linus');

    expect(findNodes($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'person-grace'))->toHaveCount(1)
        ->and($screen->get('nextCursor'))->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/guilds/rust/members?page=2');
});
