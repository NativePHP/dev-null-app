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
    serveApi(['GET /api/v1/me' => [currentUserJson(['timezone' => null])]]);

    $screen = Native::visit('/profile/device')
        ->assertSee('Timezone')
        ->assertSee("Leave it unset to use this phone's timezone without sharing it.")
        ->assertSee('GMT+01:00 Atlantic/Canary')
        ->assertSee('Use Atlantic/Canary from this phone');

    expect(shownText($screen))->toContain('null')
        ->and(nodeByRef($screen, 'save-timezone')['props']['disabled'] ?? false)->toBeTrue()
        ->and(findNodes($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'unset-timezone'))->toBe([])
        ->and(array_column(findNodes($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['color', 'timezone', 'pasteis-mode'], true)), 'ref'))->toBe(['color', 'timezone', 'pasteis-mode']);
});

it("uses the phone's timezone, saves it, and remembers it for the times the app shows", function () {
    phoneIn('Atlantic/Canary');
    serveApi([
        'GET /api/v1/me' => [currentUserJson(['timezone' => null])],
        'PUT /api/v1/me/timezone' => [currentUserJson(['timezone' => 'Atlantic/Canary'])],
    ]);

    $screen = Native::visit('/profile/device')
        ->tap('use-phone-timezone')
        ->assertSet('chosenTimezone', 'Atlantic/Canary');

    expect(nodeByRef($screen, 'save-timezone')['props']['disabled'] ?? false)->toBeFalse();

    $screen->tap('save-timezone')
        ->assertSet('savingTimezone', false)
        ->assertSet('timezoneError', null)
        ->assertDontSee('Use Atlantic/Canary from this phone');

    expect(timezoneSaves())->toBe(['{"timezone":"Atlantic/Canary"}'])
        ->and(app(Account::class)->timezone())->toBe('Atlantic/Canary')
        ->and(nodeByRef($screen, 'save-timezone')['props']['disabled'] ?? false)->toBeTrue();
});

it("lists the timezones at the phone's offset first, with the one picked on top", function () {
    phoneIn('Europe/Lisbon');
    serveApi(['GET /api/v1/me' => [currentUserJson(['timezone' => 'Asia/Tokyo'])]]);

    $screen = Native::visit('/profile/device')->tap('choose-timezone')->assertSet('pickingTimezone', true);
    $rows = timezoneRows($screen);

    expect($rows[0])->toBe('Asia/Tokyo')
        ->and($rows)->toContain('Europe/Lisbon', 'Europe/London', 'Atlantic/Canary')
        ->and($rows)->not->toContain('Europe/Paris')
        ->and(findNode(nodeByRef($screen, 'timezone-Asia/Tokyo'), fn (array $node): bool => ($node['type'] ?? null) === 'icon'))->not->toBeNull();

    $screen->assertSee('Type a city or region to find another.');
});

it('finds a timezone by its name or city in the sheet, and picks it', function () {
    serveApi([
        'GET /api/v1/me' => [currentUserJson(['timezone' => 'Europe/Lisbon'])],
        'PUT /api/v1/me/timezone' => [currentUserJson(['timezone' => 'America/Sao_Paulo'])],
    ]);

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

    expect(timezoneSaves())->toBe(['{"timezone":"America/Sao_Paulo"}'])
        ->and(app(Account::class)->timezone())->toBe('America/Sao_Paulo');
});

it('lists at most 50 matches, by offset then name, and says how many more there are', function () {
    serveApi(['GET /api/v1/me' => [currentUserJson(['timezone' => null])]]);

    $screen = Native::visit('/profile/device')->tap('choose-timezone')->input('timezone-search', 'america');
    $all = array_values(array_filter(Timezone::all(), fn (string $name): bool => str_contains(strtolower($name), 'america')));
    $rows = timezoneRows($screen);

    expect($rows)->toHaveCount(ThisDevice::TimezonesShown)
        ->and($rows[0])->toBe('America/Adak')
        ->and(count($all))->toBeGreaterThan(ThisDevice::TimezonesShown);

    $screen->assertSee((count($all) - ThisDevice::TimezonesShown).' more. Keep typing to find yours.');
});

it('says when no timezone matches', function () {
    serveApi(['GET /api/v1/me' => [currentUserJson(['timezone' => null])]]);

    Native::visit('/profile/device')
        ->tap('choose-timezone')
        ->input('timezone-search', 'Mars/Olympus')
        ->assertSee('No timezone matches "Mars/Olympus".');
});

it('unsets the timezone, so the phone\'s is used again', function () {
    app(Account::class)->rememberTimezone('Europe/Lisbon');
    serveApi([
        'GET /api/v1/me' => [currentUserJson(['timezone' => 'Europe/Lisbon'])],
        'PUT /api/v1/me/timezone' => [currentUserJson(['timezone' => null])],
    ]);

    Native::visit('/profile/device')
        ->tap('unset-timezone')
        ->assertSet('chosenTimezone', null)
        ->tap('save-timezone');

    expect(timezoneSaves())->toBe(['{"timezone":null}'])
        ->and(app(Account::class)->timezone())->toBeNull();
});

it('keeps the timezone picked, and says why, when devnull does not take it', function () {
    serveApi([
        'GET /api/v1/me' => [currentUserJson(['timezone' => null])],
        'PUT /api/v1/me/timezone' => [['message' => 'The timezone field must be a valid timezone.', 'errors' => ['timezone' => ['The timezone field must be a valid timezone.']]], 422],
    ]);

    Native::visit('/profile/device')
        ->call('chooseTimezone', 'Europe/Kyiv')
        ->tap('save-timezone')
        ->assertSet('chosenTimezone', 'Europe/Kyiv')
        ->assertSee('The timezone field must be a valid timezone.');

    expect(app(Account::class)->timezone())->toBeNull();
});

it('picks up a timezone chosen on the web with any refresh of you', function () {
    serveApi(['GET /api/v1/me' => [currentUserJson(['timezone' => 'Asia/Tokyo'])]]);

    Native::visit('/profile/device')->assertSet('chosenTimezone', 'Asia/Tokyo');

    expect(app(Account::class)->timezone())->toBe('Asia/Tokyo');
});

it('ignores a timezone it does not know', function () {
    Native::visit('/profile/device')->call('chooseTimezone', 'Mars/Olympus_Mons')->assertSet('chosenTimezone', null);
});

it('keeps the list in the sheet while it slides away, and lets go of it once iOS says it is gone', function () {
    phoneIn('Europe/Lisbon');
    serveApi(['GET /api/v1/me' => [currentUserJson(['timezone' => null])]]);

    $screen = Native::visit('/profile/device')
        ->tap('choose-timezone')
        ->tap('timezone-Europe/Lisbon')
        ->assertSet('pickingTimezone', false)
        ->assertSet('listingTimezones', true);

    expect(timezoneRows($screen))->toContain('Europe/Lisbon');

    $screen->dismissSheet('timezone-sheet')->assertSet('listingTimezones', false);

    expect(timezoneRows($screen))->toBe([]);
});
