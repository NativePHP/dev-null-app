<?php

use App\Devnull\Account;
use App\Devnull\Display;
use App\Devnull\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
});

/**
 * A localtime link in a scratch folder, pointing where $target says.
 */
function localtimeLinkingTo(string $target): string
{
    $directory = sys_get_temp_dir().'/devnull-localtime-'.bin2hex(random_bytes(4));
    mkdir($directory);
    symlink($target, $directory.'/localtime');

    return $directory.'/localtime';
}

it("reads the phone's timezone from where localtime links, through any links on the way", function (string $target, ?string $expected) {
    expect((new Timezone(localtimeLinkingTo($target)))->phone())->toBe($expected);
})->with([
    'macOS and the simulator' => ['/var/db/timezone/zoneinfo/Atlantic/Canary', 'Atlantic/Canary'],
    'with a region' => ['/usr/share/zoneinfo/America/Argentina/Buenos_Aires', 'America/Argentina/Buenos_Aires'],
    'not a timezone PHP knows' => ['/var/db/timezone/zoneinfo/Mars/Olympus_Mons', null],
    'nowhere useful' => ['/tmp/somewhere-else', null],
]);

it('follows the link iOS puts in between, as phoneIn() does', function () {
    phoneIn('Asia/Kathmandu');

    expect(app(Timezone::class)->phone())->toBe('Asia/Kathmandu');
});

it('has no phone timezone without a localtime link, as on Android', function () {
    expect((new Timezone('/nonexistent'))->phone())->toBeNull();
});

it("shows your times in the timezone you chose, or else the phone's, as the web falls back to the browser's", function () {
    phoneIn('Europe/Lisbon');

    expect(app(Timezone::class)->yours())->toBe('Europe/Lisbon')
        ->and(app(Timezone::class)->here())->toBe('Europe/Lisbon');

    app(Account::class)->rememberTimezone('Asia/Tokyo');

    expect(app(Timezone::class)->yours())->toBe('Asia/Tokyo')
        // A time to schedule is still picked where the phone is.
        ->and(app(Timezone::class)->here())->toBe('Europe/Lisbon');
});

it("falls back to the timezone you chose, then UTC, when the phone's can't be told", function () {
    expect(app(Timezone::class)->here())->toBe('UTC')
        ->and(app(Timezone::class)->yours())->toBe('UTC');

    app(Account::class)->rememberTimezone('Asia/Tokyo');

    expect(app(Timezone::class)->here())->toBe('Asia/Tokyo')
        ->and(app(Timezone::class)->yours())->toBe('Asia/Tokyo');
});

it('forgets the timezone you chose when you sign out', function () {
    app(Account::class)->rememberTimezone('Asia/Tokyo');
    app(Account::class)->forget();

    expect(app(Account::class)->timezone())->toBeNull();
});

it("prints offsets and cities as the web's picker and tooltip do", function () {
    $summer = CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC');

    expect(Timezone::offset('Europe/Lisbon', $summer))->toBe('GMT+01:00')
        ->and(Timezone::offset('America/Sao_Paulo', $summer))->toBe('GMT-03:00')
        ->and(Timezone::offset('Asia/Kathmandu', $summer))->toBe('GMT+05:45')
        ->and(Timezone::offset('UTC', $summer))->toBe('GMT+00:00')
        ->and(Timezone::city('America/Sao_Paulo'))->toBe('Sao Paulo')
        ->and(Timezone::city('America/Argentina/Buenos_Aires'))->toBe('Buenos Aires')
        ->and(Timezone::city('UTC'))->toBe('UTC');
});

it("prints a commit's time in your timezone, and a scheduled one's where the phone is", function () {
    phoneIn('Europe/Lisbon');
    app(Account::class)->rememberTimezone('Asia/Tokyo');
    $date = CarbonImmutable::parse('2026-10-14T20:13:05Z');

    expect(Display::fullDateTime($date))->toBe('5:13 AM · Oct 15, 2026')
        ->and(Display::scheduleTime($date))->toBe('Oct 14, 2026, 9:13 PM');
});

it("counts the Log's days in the phone's timezone, as the web counts them in the browser's", function (?string $phone, string $firstOfYesterday) {
    if ($phone !== null) {
        phoneIn($phone);
    }

    signIn();
    $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));

    Http::fake(['devnull.test/*' => Http::response(pageJson([
        commitJson(['id' => 'c2', 'created_at' => '2026-10-15T11:00:00Z']),
        // 01:00 on the 15th in Tokyo, but still the 14th in UTC.
        commitJson(['id' => 'c1', 'created_at' => '2026-10-14T16:00:00Z']),
        commitJson(['id' => 'c0', 'created_at' => '2026-10-14T10:00:00Z']),
    ]))]);

    $json = (string) json_encode(Native::visit('/log')->tree());
    $yesterday = strpos($json, '"day-Yesterday"');

    expect($yesterday)->toBeLessThan(strpos($json, '"commit-'.$firstOfYesterday.'"'))
        ->and($yesterday)->toBeGreaterThan(strpos($json, '"commit-'.($firstOfYesterday === 'c1' ? 'c2' : 'c1').'"'));
})->with([
    'in Tokyo' => ['Asia/Tokyo', 'c0'],
    'where the phone can\'t be told, in UTC' => [null, 'c1'],
]);

it('picks up your timezone with the refresh of you that every tab makes', function () {
    signIn();
    Http::fake([
        'devnull.test/api/v1/me' => Http::response(currentUserJson(['timezone' => 'Asia/Tokyo'])),
        'devnull.test/*' => Http::response(pageJson([])),
    ]);

    Native::visit('/log');

    expect(app(Account::class)->timezone())->toBe('Asia/Tokyo');
});
