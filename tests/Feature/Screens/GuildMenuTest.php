<?php

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
 * The guild page's ⋯ menu: its own props, and its items' props by id. Null when the bar has no ⋯.
 *
 * @return array{menu: array<string, mixed>, items: array<string, array<string, mixed>>}|null
 */
function guildMenu(TestableComponent $screen): ?array
{
    $menu = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action' && ($node['props']['id'] ?? null) === 'more');

    if ($menu === null) {
        return null;
    }

    return [
        'menu' => $menu['props'],
        'items' => collect($menu['children'] ?? [])->mapWithKeys(fn (array $item): array => [$item['props']['id'] => $item['props']])->all(),
    ];
}

/**
 * The ids of the guild page's ⋯ menu items, top to bottom.
 *
 * @return list<string>
 */
function guildMenuIds(TestableComponent $screen): array
{
    return array_keys(guildMenu($screen)['items'] ?? []);
}

/**
 * Whether a request went to devnull with this method and API path.
 */
function guildSent(string $method, string $path): bool
{
    return Http::recorded(fn (Request $request): bool => $request->method() === $method && $request->url() === 'http://devnull.test/api/v1/'.$path)->isNotEmpty();
}

it('puts Share, then Mute, then Report in the ⋯ menu of a guild you have not joined', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'])]]);

    $menu = guildMenu(finishRefreshes(Native::visit('/log/guilds/rust')));

    expect(array_keys($menu['items']))->toBe(['share', 'mute', 'report'])
        ->and($menu['menu']['a11y_label'] ?? $menu['menu']['a11yLabel'] ?? null)->toBe('More options for #rust')
        ->and($menu['items']['share']['label'])->toBe('Share guild')
        ->and($menu['items']['mute']['label'])->toBe('Mute #rust');
});

it('leaves Mute out once you have joined', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_joined' => true])])]]);

    expect(guildMenuIds(finishRefreshes(Native::visit('/log/guilds/rust'))))->toBe(['share', 'report']);
});

it('offers Unmute on a muted guild', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_muted' => true])])]]);

    $menu = guildMenu(finishRefreshes(Native::visit('/log/guilds/rust')));

    expect(array_keys($menu['items']))->toBe(['share', 'unmute', 'report'])
        ->and($menu['items']['unmute']['label'])->toBe('Unmute #rust');
});

it('keeps the same menu on a paused guild', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_paused' => true])])]]);

    expect(guildMenuIds(finishRefreshes(Native::visit('/log/guilds/rust'))))->toBe(['share', 'mute', 'report']);
});

it('shares the guild\'s page on the web', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'])]]);

    finishRefreshes(Native::visit('/log/guilds/rust'))
        ->press('share')
        ->assertNativeCalled('Share.Url', fn (array $params): bool => $params['url'] === 'http://devnull.test/guilds/rust');
});

it('mutes once you confirm, and Cancel leaves it as it was', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'POST /api/v1/guilds/rust/mute' => [null, 204],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust', platform: 'ios'))
        ->press('confirmMute')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Mute #rust?'
            && $params['message'] === "You won't see its commits in your logs or search."
            && $params['buttons'] === [['label' => 'Cancel', 'style' => 'cancel'], ['label' => 'Mute', 'style' => 'default']])
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'mute:rust']);

    expect(guildSent('POST', 'guilds/rust/mute'))->toBeFalse()
        ->and($screen->instance()->guild->isMuted)->toBeFalse();

    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Mute', 'id' => 'mute:rust']);

    expect(guildSent('POST', 'guilds/rust/mute'))->toBeTrue()
        ->and($screen->instance()->guild->isMuted)->toBeTrue()
        ->and(kept('guilds/rust')['guild']['is_muted'])->toBeTrue()
        ->and(guildMenuIds($screen))->toBe(['share', 'unmute', 'report']);
});

it('unmutes once you confirm', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_muted' => true])])],
        'DELETE /api/v1/guilds/rust/mute' => [null, 204],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->press('confirmMute')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Unmute #rust?')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Unmute', 'id' => 'unmute:rust']);

    expect(guildSent('DELETE', 'guilds/rust/mute'))->toBeTrue()
        ->and($screen->instance()->guild->isMuted)->toBeFalse()
        ->and(kept('guilds/rust')['guild']['is_muted'])->toBeFalse()
        ->and(guildMenuIds($screen))->toBe(['share', 'mute', 'report']);
});

it('counts a mute in the kept directory', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'POST /api/v1/guilds/rust/mute' => [null, 204],
    ]);
    keep('guilds', ['data' => [guildJson(['handle' => 'go']), guildJson()], 'links' => ['next' => null]]);

    finishRefreshes(Native::visit('/log/guilds/rust'))->call('mute');

    expect(kept('guilds')['data'][1]['is_muted'])->toBeTrue()
        ->and(kept('guilds')['data'][0]['is_muted'])->toBeFalse();
});

it('puts the mute back, and says why, when devnull says no', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'POST /api/v1/guilds/rust/mute' => [['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->call('mute')
        ->assertSee('Too Many Attempts.');

    expect($screen->instance()->guild->isMuted)->toBeFalse()
        ->and(kept('guilds/rust')['guild']['is_muted'])->toBeFalse()
        ->and(guildMenuIds($screen))->toBe(['share', 'mute', 'report']);
});

it('clears a mute by joining after muting', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'POST /api/v1/guilds/rust/mute' => [null, 204],
        'POST /api/v1/guilds/rust/membership' => [null, 204],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))->call('mute')->tap('guild-join');

    expect($screen->instance()->guild->isMuted)->toBeFalse()
        ->and(guildMenuIds($screen))->toBe(['share', 'report']);
});

it('does not mute a guild you have joined', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_joined' => true])])]]);

    finishRefreshes(Native::visit('/log/guilds/rust'))->call('mute');

    expect(guildSent('POST', 'guilds/rust/mute'))->toBeFalse();
});

it("still answers a commit's delete alert on the board", function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'DELETE /api/v1/commits/c1' => [null, 204],
    ]);

    finishRefreshes(Native::visit('/log/guilds/rust', platform: 'ios'))
        ->tap('delete-c1')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Delete this commit?')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit']);

    expect(guildSent('DELETE', 'commits/c1'))->toBeTrue();
});

it('adds Report guild to the menu, and leaves it out for the moderator', function (array $board, array $ids) {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: $board)]]);

    $menu = guildMenu(finishRefreshes(Native::visit('/log/guilds/rust')));

    expect(array_keys($menu['items']))->toBe($ids);

    if (in_array('report', $ids, true)) {
        expect($menu['items']['report']['label'])->toBe('Report guild');
    }
})->with([
    'someone else\'s guild' => [[], ['share', 'mute', 'report']],
    'a guild you moderate' => [['can_moderate' => true, 'guild' => guildJson(['is_joined' => true])], ['share']],
]);

it("reports the guild in the web's words", function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'POST /api/v1/guilds/rust/reports' => [null, 204],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->press('reportGuild')
        ->assertSee('Report Rust')
        ->assertSee('Your report is private. Admins will review the description and icon. Reporting does not hide the guild.');

    expect(nodeByRef($screen, 'guild-report-sheet')['props']['visible'])->toBeTrue()
        ->and(nodeByRef($screen, 'guild-report-details')['props']['label'] ?? null)->toBe('What looks wrong?')
        ->and(nodeByRef($screen, 'guild-send-report')['props']['disabled'] ?? false)->toBeTrue();

    $screen->input('guild-report-details', '  Spam  ');

    expect(nodeByRef($screen, 'guild-send-report')['props']['disabled'] ?? false)->toBeFalse();

    $screen->tap('guild-send-report')
        ->assertSee('Report received. Admins will review it privately.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://devnull.test/api/v1/guilds/rust/reports'
        && $request->body() === '{"details":"Spam"}');

    $screen->tap('guild-report-done');

    // It keeps saying so while it slides away, rather than going blank or growing.
    expect(nodeByRef($screen, 'guild-report-sheet')['props']['visible'])->toBeFalse()
        ->and(nodeByRef($screen, 'guild-report-sheet')['props']['detents'] ?? null)->toBe('0.3')
        ->and(nodeByRef($screen, 'guild-report-received'))->not->toBeNull();

    // Opened again, it starts over.
    $screen->press('reportGuild');

    expect(nodeByRef($screen, 'guild-report-details')['props']['value'] ?? '')->toBe('')
        ->and(nodeByRef($screen, 'guild-send-report')['props']['disabled'] ?? false)->toBeTrue();
});

it('keeps Send report off for details of only spaces', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'])]]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->press('reportGuild')
        ->input('guild-report-details', '   ');

    expect(nodeByRef($screen, 'guild-send-report')['props']['disabled'] ?? false)->toBeTrue();

    $screen->call('sendGuildReport');

    expect(guildSent('POST', 'guilds/rust/reports'))->toBeFalse();
});

it('shows why in the sheet when devnull says no, keeping what you wrote', function () {
    fakeApi([
        'GET /api/v1/guilds/rust' => [boardOf(['c1'])],
        'POST /api/v1/guilds/rust/reports' => [['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429],
    ]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->press('reportGuild')
        ->input('guild-report-details', 'Spam')
        ->tap('guild-send-report');

    expect(nodeByRef($screen, 'guild-report-error')['props']['text'])->toBe('Too Many Attempts.')
        ->and(nodeByRef($screen, 'guild-report-details')['props']['value'])->toBe('Spam')
        ->and(nodeByRef($screen, 'guild-report-sheet')['props']['visible'])->toBeTrue()
        ->and($screen->instance()->error)->toBeNull();

    $screen->assertDontSee('Report received');
});

it('forgets the report when the sheet is swiped away', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'])]]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))
        ->press('reportGuild')
        ->input('guild-report-details', 'Half a thought');

    $screen->dismissSheet('guild-report-sheet');

    expect(nodeByRef($screen, 'guild-report-sheet')['props']['visible'])->toBeFalse()
        ->and($screen->instance()->guildReportDetails)->toBe('');
});

it('opens nothing for the moderator', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['can_moderate' => true])]]);

    $screen = finishRefreshes(Native::visit('/log/guilds/rust'))->call('reportGuild');

    expect(nodeByRef($screen, 'guild-report-sheet')['props']['visible'] ?? false)->toBeFalse()
        ->and($screen->instance()->guildReportOpen)->toBeFalse();
});

it('shows the moderator Edit in the guild header, which opens the guild form', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['can_moderate' => true, 'guild' => guildJson(['is_joined' => true])])]]);

    finishRefreshes(Native::visit('/log/guilds/rust'))
        ->assertSee('Edit')
        ->tap('guild-edit')
        ->assertNavigatedTo('/log/guilds/rust/edit', ['name' => 'Rust', 'description' => 'All about rust.', 'emoji' => '💻', 'iconUrl' => null]);
});

it('leaves Edit out of the header for everyone else', function () {
    fakeApi(['GET /api/v1/guilds/rust' => [boardOf(['c1'], overrides: ['guild' => guildJson(['is_joined' => true])])]]);

    expect(findNode(finishRefreshes(Native::visit('/log/guilds/rust'))->tree(), fn (array $node): bool => ($node['props']['ref'] ?? $node['ref'] ?? null) === 'guild-edit'))->toBeNull();
});
