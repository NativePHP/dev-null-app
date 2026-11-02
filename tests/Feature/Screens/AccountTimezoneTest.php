<?php

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Timezone;
use App\NativeComponents\ThisDevice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:30:00', 'UTC'));
});

/**
 * The `PUT /me/timezone` requests sent, as their bodies.
 *
 * @return list<string>
 */
function timezoneSaves(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $sent): bool => $sent[0]->method() === 'PUT' && str_ends_with($sent[0]->url(), Client::TimezonePath))
        ->map(fn (array $sent): string => $sent[0]->body())
        ->values()
        ->all();
}

/**
 * `GET /me` and `GET /me/settings` as devnull answers them, with the timezone and local time given, and saving one
 * answered as devnull answers it: like `GET /me`, which doesn't carry the timezone.
 *
 * @param  array{0: mixed, 1?: int}|null  $save
 */
function serveSettings(?string $timezone = null, bool $showLocalTime = false, ?array $save = null): void
{
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/me/settings' => [['data' => ['timezone' => $timezone, 'show_local_time' => $showLocalTime, 'translation_language' => null, 'automatic_translation' => true, 'translation_languages' => [], 'is_admin' => false]]],
        'PUT /api/v1/me/timezone' => $save ?? [currentUserJson()],
    ]);
}

/**
 * The timezones listed in the sheet, top to bottom.
 *
 * @return list<string>
 */
function timezoneRows(TestableComponent $screen): array
{
    return array_map(
        fn (array $node): string => substr($node['ref'], strlen('timezone-')),
        findNodes(nodeByRef($screen, 'timezone-list'), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'timezone-') && ! in_array($node['ref'], ['timezone-list', 'timezone-none', 'timezone-more', 'timezone-hint'], true)),
    );
}

it("has the web's Timezone section after Color, unset until you choose one, with the phone's beside it", function () {
    phoneIn('Atlantic/Canary');
    serveSettings();

    $screen = Native::visit('/profile/device')
        ->assertSee('Timezone')
        ->assertSee("Leave it unset to use this phone's timezone without sharing it. Your local time only shows on your profile if you turn that on below.")
        ->assertSee('GMT+01:00 Atlantic/Canary')
        ->assertSee('Use Atlantic/Canary from this phone')
        ->assertDontSee('Show my local time on my profile');

    expect(shownText($screen))->toContain('null')
        ->and(nodeByRef($screen, 'save-timezone')['props']['disabled'] ?? false)->toBeTrue()
        ->and(findNodes($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'unset-timezone'))->toBe([])
        ->and(array_column(findNodes($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['color', 'timezone', 'pasteis-mode'], true)), 'ref'))->toBe(['color', 'timezone', 'pasteis-mode']);
});

it('shows the timezone devnull has in your settings, and whether your profile shows your local time', function () {
    serveSettings('Asia/Tokyo', showLocalTime: true);

    $screen = Native::visit('/profile/device')
        ->assertSet('chosenTimezone', 'Asia/Tokyo')
        ->assertSet('showLocalTime', true)
        ->assertSee('GMT+09:00 Asia/Tokyo')
        ->assertSee('Show my local time on my profile');

    expect(nodeByRef($screen, 'show-local-time-switch')['props']['value'] ?? null)->toBeTrue()
        ->and(app(Account::class)->timezone())->toBe('Asia/Tokyo');
});

it("uses the phone's timezone, saves it, and remembers it for the times the app shows", function () {
    phoneIn('Atlantic/Canary');
    serveSettings();

    $screen = Native::visit('/profile/device')
        ->tap('use-phone-timezone')
        ->assertSet('chosenTimezone', 'Atlantic/Canary');

    expect(nodeByRef($screen, 'save-timezone')['props']['disabled'] ?? false)->toBeFalse();

    $screen->tap('save-timezone')
        ->assertSet('savingTimezone', false)
        ->assertSet('timezoneError', null)
        ->assertSet('savedTimezone', 'Atlantic/Canary')
        ->assertDontSee('Use Atlantic/Canary from this phone');

    // The web's form sends whether to show your local time with it, which devnull would otherwise turn off.
    expect(timezoneSaves())->toBe(['{"timezone":"Atlantic/Canary","show_local_time":false}'])
        ->and(app(Account::class)->timezone())->toBe('Atlantic/Canary')
        ->and(nodeByRef($screen, 'save-timezone')['props']['disabled'] ?? false)->toBeTrue();
});

it('saves showing your local time on your profile, with the timezone', function () {
    serveSettings('Asia/Tokyo');

    $screen = Native::visit('/profile/device')->toggle('show-local-time-switch', true);

    expect(nodeByRef($screen, 'save-timezone')['props']['disabled'] ?? false)->toBeFalse();

    $screen->tap('save-timezone')->assertSet('savedShowLocalTime', true);

    expect(timezoneSaves())->toBe(['{"timezone":"Asia/Tokyo","show_local_time":true}']);
});

it("lists the timezones at the phone's offset first, with the one picked on top", function () {
    phoneIn('Europe/Lisbon');
    serveSettings('Asia/Tokyo');

    $screen = Native::visit('/profile/device')->tap('choose-timezone')->assertSet('pickingTimezone', true);
    $rows = timezoneRows($screen);

    expect($rows[0])->toBe('Asia/Tokyo')
        ->and($rows)->toContain('Europe/Lisbon', 'Europe/London', 'Atlantic/Canary')
        ->and($rows)->not->toContain('Europe/Paris')
        ->and(findNode(nodeByRef($screen, 'timezone-Asia/Tokyo'), fn (array $node): bool => ($node['type'] ?? null) === 'icon'))->not->toBeNull();

    $screen->assertSee('Type a city or region to find another.');
});

it('finds a timezone by its name or city in the sheet, and picks it', function () {
    serveSettings('Europe/Lisbon');

    $screen = Native::visit('/profile/device')
        ->assertSee('GMT+01:00 Europe/Lisbon')
        ->tap('choose-timezone')
        ->input('timezone-search', 'sao paulo');

    expect(timezoneRows($screen))->toBe(['America/Sao_Paulo']);

    $screen->tap('timezone-America/Sao_Paulo')
        ->assertSet('pickingTimezone', false)
        ->assertSet('chosenTimezone', 'America/Sao_Paulo')
        ->assertSee('GMT-03:00 America/Sao_Paulo')
        ->tap('save-timezone');

    expect(timezoneSaves())->toBe(['{"timezone":"America/Sao_Paulo","show_local_time":false}'])
        ->and(app(Account::class)->timezone())->toBe('America/Sao_Paulo');
});

it('lists at most 50 matches, by offset then name, and says how many more there are', function () {
    serveSettings();

    $screen = Native::visit('/profile/device')->tap('choose-timezone')->input('timezone-search', 'america');
    $all = array_values(array_filter(Timezone::all(), fn (string $name): bool => str_contains(strtolower($name), 'america')));
    $rows = timezoneRows($screen);

    expect($rows)->toHaveCount(ThisDevice::TimezonesShown)
        ->and($rows[0])->toBe('America/Adak')
        ->and(count($all))->toBeGreaterThan(ThisDevice::TimezonesShown);

    $screen->assertSee((count($all) - ThisDevice::TimezonesShown).' more. Keep typing to find yours.');
});

it('says when no timezone matches', function () {
    serveSettings();

    Native::visit('/profile/device')
        ->tap('choose-timezone')
        ->input('timezone-search', 'Mars/Olympus')
        ->assertSee('No timezone matches "Mars/Olympus".');
});

it("unsets the timezone, and showing your local time with it, so the phone's is used again", function () {
    app(Account::class)->rememberTimezone('Europe/Lisbon');
    serveSettings('Europe/Lisbon', showLocalTime: true);

    Native::visit('/profile/device')
        ->tap('unset-timezone')
        ->assertSet('chosenTimezone', null)
        ->assertSet('showLocalTime', false)
        ->assertDontSee('Show my local time on my profile')
        ->tap('save-timezone');

    expect(timezoneSaves())->toBe(['{"timezone":null,"show_local_time":false}'])
        ->and(app(Account::class)->timezone())->toBeNull();
});

it('keeps the timezone picked, and says why, when devnull does not take it', function () {
    serveSettings(save: [['message' => 'The timezone field must be a valid timezone.', 'errors' => ['timezone' => ['The timezone field must be a valid timezone.']]], 422]);

    Native::visit('/profile/device')
        ->call('chooseTimezone', 'Europe/Kyiv')
        ->tap('save-timezone')
        ->assertSet('chosenTimezone', 'Europe/Kyiv')
        ->assertSee('The timezone field must be a valid timezone.');

    expect(app(Account::class)->timezone())->toBeNull();
});

it('keeps a timezone picked and not saved yet when your settings come in', function () {
    $bridge = holdRefreshes();
    serveSettings('Asia/Tokyo');

    $screen = Native::visit('/profile/device')->call('chooseTimezone', 'Europe/Lisbon');

    finishRefreshes($screen)
        ->assertSet('chosenTimezone', 'Europe/Lisbon')
        ->assertSet('savedTimezone', 'Asia/Tokyo');
});

it('ignores a timezone it does not know', function () {
    serveSettings();

    Native::visit('/profile/device')->call('chooseTimezone', 'Mars/Olympus_Mons')->assertSet('chosenTimezone', null);
});

it('keeps the list in the sheet while it slides away, and lets go of it once iOS says it is gone', function () {
    phoneIn('Europe/Lisbon');
    serveSettings();

    $screen = Native::visit('/profile/device')
        ->tap('choose-timezone')
        ->tap('timezone-Europe/Lisbon')
        ->assertSet('pickingTimezone', false)
        ->assertSet('listingTimezones', true);

    expect(timezoneRows($screen))->toContain('Europe/Lisbon');

    $screen->dismissSheet('timezone-sheet')->assertSet('listingTimezones', false);

    expect(timezoneRows($screen))->toBe([]);
});
