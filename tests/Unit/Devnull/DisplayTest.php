<?php

use App\Devnull\Display;
use Carbon\CarbonImmutable;

it('keeps the last reply clear of the link validation message', function () {
    $url = 'https://example.com/'.str_repeat('x', 2030);
    expect(Display::replyBarClearance($url))->toBe(Display::replyBarClearance(str_repeat('a', mb_strlen($url))) + 3 * Display::ReplyBarLineHeight);
});

it('prints compact counts from a thousand as the web does', function (int $count, string $expected) {
    expect(Display::compactCount($count))->toBe($expected);
})->with([
    [0, '0'], [999, '999'], [1000, '1k'], [1050, '1.1k'], [12500, '12.5k'],
    [999949, '999.9k'], [999950, '1m'], [1000000, '1m'], [1500000000, '1.5b'],
]);

it('prints relative times the way the web does', function (string $date, string $expected) {
    $now = CarbonImmutable::parse('2026-10-15T12:00:00Z');

    expect(Display::relativeTime(CarbonImmutable::parse($date), $now))->toBe($expected);
})->with([
    ['2026-10-15T11:59:30Z', 'now'],
    ['2026-10-15T11:45:00Z', '15m'],
    ['2026-10-15T09:00:00Z', '3h'],
    ['2026-10-12T12:00:00Z', '3d'],
    ['2026-09-01T12:00:00Z', 'Sep 1'],
    ['2025-12-31T12:00:00Z', 'Dec 31, 2025'],
]);

it('says how long ago in words, the way the web says when it last synced', function (string $date, string $expected) {
    $now = CarbonImmutable::parse('2026-10-15T12:00:00Z');

    expect(Display::ago(CarbonImmutable::parse($date), $now))->toBe($expected);
})->with([
    ['2026-10-15T11:59:30Z', 'just now'],
    ['2026-10-15T11:59:00Z', '1 minute ago'],
    ['2026-10-15T11:45:00Z', '15 minutes ago'],
    ['2026-10-15T11:00:00Z', '1 hour ago'],
    ['2026-10-15T09:00:00Z', '3 hours ago'],
    ['2026-10-14T11:00:00Z', 'yesterday'],
    ['2026-10-12T12:00:00Z', '3 days ago'],
    ['2026-07-17T12:00:00Z', '90 days ago'],
]);

it('shortens ids to the last seven characters without dashes', function () {
    expect(Display::shortHash('9b2f6c1e-1d3a-4e5f-8a7b-0c1d2e3f4a5b'))->toBe('e3f4a5b');
});

it('prints full dates the way git log does', function () {
    expect(Display::gitDate(CarbonImmutable::parse('2026-10-14T20:13:05Z')))->toBe('Wed Oct 14 20:13:05 2026 +0000')
        ->and(Display::gitDate(null))->toBe('');
});

it('labels days the way the web day markers do', function (string $date, string $expected) {
    $now = CarbonImmutable::parse('2026-10-15T12:00:00Z');

    expect(Display::dayLabel(CarbonImmutable::parse($date), $now))->toBe($expected);
})->with([
    ['2026-10-15T00:00:00Z', 'Today'],
    ['2026-10-15T23:59:59Z', 'Today'],
    ['2026-10-14T23:59:59Z', 'Yesterday'],
    ['2026-10-13T08:00:00Z', 'Tue, Oct 13'],
    ['2025-12-31T08:00:00Z', 'Wed, Dec 31, 2025'],
    // Counted in the app's time zone, whatever offset the API sent.
    ['2026-10-15T01:30:00+02:00', 'Yesterday'],
]);

it('masks a public key after the first 10 characters of the key itself', function () {
    expect(Display::maskedKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKuhlNtNELRJAJBUbtmUnZKdwgGf devnull-mobile'))
        ->toBe('ssh-ed25519 AAAAC3NzaC•••••••••••• devnull-mobile')
        ->and(Display::maskedKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKuhlNtNELRJAJBUbtmUnZKdwgGf'))
        ->toBe('ssh-ed25519 AAAAC3NzaC••••••••••••')
        ->and(Display::maskedKey('nothing'))->toBe('nothing');
});
