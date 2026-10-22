<?php

use App\Devnull\WhoToFollow;
use Carbon\CarbonImmutable;

it('shows who to follow until it is hidden, for each user apart', function () {
    $whoToFollow = app(WhoToFollow::class);

    $whoToFollow->show('u1', false);

    expect($whoToFollow->shows('u1'))->toBeFalse()
        ->and($whoToFollow->shows('u2'))->toBeTrue()
        ->and($whoToFollow->shows(null))->toBeFalse();

    $whoToFollow->show('u1', true);

    expect($whoToFollow->shows('u1'))->toBeTrue();
});

it('remembers the round of the day, and starts the next day at the first', function () {
    $whoToFollow = app(WhoToFollow::class);
    CarbonImmutable::setTestNow('2026-10-22T10:00:00Z');

    expect($whoToFollow->round('u1'))->toBe(0);

    $whoToFollow->rememberRound('u1', 2);
    $whoToFollow->rememberRound(null, 5);

    expect($whoToFollow->round('u1'))->toBe(2)
        ->and($whoToFollow->round('u2'))->toBe(0);

    CarbonImmutable::setTestNow('2026-10-23T00:30:00Z');

    expect($whoToFollow->round('u1'))->toBe(0);

    CarbonImmutable::setTestNow();
});
