<?php

use App\Devnull\Data\Pasteis;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 23:59:59', 'UTC'));
    $this->midnight = CarbonImmutable::parse('2026-10-18T00:00:00Z');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('gives everyone ten again at 00:00 UTC, not 24 hours after the first one went', function () {
    $pasteis = new Pasteis(0, $this->midnight);

    expect($pasteis->left())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());

    expect($pasteis->left())->toBe(Pasteis::Daily)
        ->and($pasteis->afterGiving())->toEqual(new Pasteis(9, CarbonImmutable::parse('2026-10-19T00:00:00Z')));
});

it('counts one down per pastel and back up when one is handed back, never past ten or below none', function () {
    $pasteis = new Pasteis(1, $this->midnight);

    expect($pasteis->afterGiving()->left())->toBe(0)
        ->and($pasteis->afterGiving()->afterGiving()->left())->toBe(0)
        ->and($pasteis->afterTakingBack()->left())->toBe(2)
        ->and((new Pasteis(Pasteis::Daily, $this->midnight))->afterTakingBack()->left())->toBe(Pasteis::Daily)
        ->and($pasteis->exhausted())->toEqual(new Pasteis(0, $this->midnight));
});

it("takes devnull's word on what is left, unless more pastéis are still on their way, or devnull is on to another day", function () {
    $pasteis = new Pasteis(5, $this->midnight);

    expect($pasteis->settledWith(7, $this->midnight)->left())->toBe(5)
        ->and($pasteis->settledWith(3, $this->midnight)->left())->toBe(3)
        ->and($pasteis->settledWith(9, CarbonImmutable::parse('2026-10-19T00:00:00Z')))->toEqual(new Pasteis(9, CarbonImmutable::parse('2026-10-19T00:00:00Z')));
});

it('reads what it kept, keeping it between none and ten, and starts a day it was never told about', function (array $kept, int $left) {
    expect(Pasteis::fromKept($kept)->left())->toBe($left);
})->with([
    [['remaining' => 4, 'resets_at' => '2026-10-18T00:00:00Z'], 4],
    [['remaining' => 40], Pasteis::Daily],
    [['remaining' => -2], 0],
    [['remaining' => 'six'], Pasteis::Daily],
    [[], Pasteis::Daily],
]);

it('keeps itself as plain values', function () {
    expect((new Pasteis(3, $this->midnight))->toArray())->toBe(['remaining' => 3, 'resets_at' => '2026-10-18T00:00:00Z'])
        ->and(Pasteis::fromKept((new Pasteis(3, $this->midnight))->toArray()))->toEqual(new Pasteis(3, $this->midnight));
});
