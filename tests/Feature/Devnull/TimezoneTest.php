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

it("asks NativePHP for the phone's timezone before reading localtime", function () {
    Native::fakeBridge()->respondTo('Device.GetInfo', ['info' => json_encode(['platform' => 'ios', 'timezone' => 'Asia/Kathmandu'])]);

    expect((new Timezone(localtimeLinkingTo('/var/db/timezone/zoneinfo/Atlantic/Canary')))->phone())->toBe('Asia/Kathmandu');
});

it("gets the phone's timezone from NativePHP where there's no localtime, as on Android", function () {
    Native::fakeBridge()->respondTo('Device.GetInfo', ['info' => json_encode(['platform' => 'android', 'timezone' => 'America/Sao_Paulo'])]);

    expect((new Timezone('/nonexistent'))->phone())->toBe('America/Sao_Paulo');
});

it("reads localtime when NativePHP's device info doesn't say, as older builds send it", function (?array $info) {
    $bridge = Native::fakeBridge();

    if ($info !== null) {
        $bridge->respondTo('Device.GetInfo', ['info' => json_encode($info)]);
    }

    expect((new Timezone(localtimeLinkingTo('/var/db/timezone/zoneinfo/Atlantic/Canary')))->phone())->toBe('Atlantic/Canary');
})->with([
    'no timezone' => [['platform' => 'ios', 'language' => 'en-US']],
    'not a timezone PHP knows' => [['timezone' => 'Mars/Olympus_Mons']],
    'no answer' => [null],
]);

it('has no phone timezone when neither NativePHP nor localtime tells, as on Android with older builds', function () {
    Native::fakeBridge();

    expect((new Timezone('/nonexistent'))->phone())->toBeNull();
});

it("keeps the phone's timezone for a minute, so a list doesn't ask NativePHP once a row", function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
    $bridge = Native::fakeBridge()->respondTo('Device.GetInfo', ['info' => json_encode(['timezone' => 'Europe/Lisbon'])]);
    $timezone = new Timezone('/nonexistent');

    expect($timezone->phone())->toBe('Europe/Lisbon')
        ->and($timezone->phone())->toBe('Europe/Lisbon');

    // The phone lands in Tokyo.
    $bridge->respondTo('Device.GetInfo', ['info' => json_encode(['timezone' => 'Asia/Tokyo'])]);
    $this->travel(Timezone::PhoneSeconds - 1)->seconds();

    expect($timezone->phone())->toBe('Europe/Lisbon');

    $this->travel(1)->seconds();

    expect($timezone->phone())->toBe('Asia/Tokyo')
        ->and($bridge->callsTo('Device.GetInfo'))->toHaveCount(2);
});

it('is the same Timezone everywhere, so what it keeps is shared', function () {
    app()->forgetInstance(Timezone::class);

    expect(app(Timezone::class))->toBe(app(Timezone::class));
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

it('picks up the timezone you chose on the web from your settings, when the Log opens', function () {
    signIn();
    app(Account::class)->rememberTimezone('Asia/Tokyo');
    Http::fake([
        'devnull.test/api/v1/me/settings' => Http::response(['data' => ['timezone' => 'Europe/Lisbon', 'show_local_time' => true]]),
        'devnull.test/*' => Http::response(pageJson([])),
    ]);

    Native::visit('/log');

    expect(app(Account::class)->timezone())->toBe('Europe/Lisbon');
});

it('forgets the timezone once your settings say it is unset, and keeps it when they cannot be read', function (int $status, ?string $kept) {
    signIn();
    app(Account::class)->rememberTimezone('Asia/Tokyo');
    Http::fake([
        'devnull.test/api/v1/me/settings' => Http::response($status === 200 ? ['data' => ['timezone' => null, 'show_local_time' => false]] : ['message' => 'Server Error'], $status),
        'devnull.test/*' => Http::response(pageJson([])),
    ]);

    Native::visit('/log');

    expect(app(Account::class)->timezone())->toBe($kept);
})->with([
    'unset' => [200, null],
    'unreadable' => [500, 'Asia/Tokyo'],
]);
