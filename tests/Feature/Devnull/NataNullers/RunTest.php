<?php

use App\Devnull\NataNullers\HighScore;
use App\Devnull\NataNullers\Run;
use App\Devnull\NataNullers\ScoreCommit;
use App\Devnull\SshKey;
use Illuminate\Support\Facades\Cache;

it('signs a run that verifies against the device key', function () {
    $run = Run::sign(testKey(), 'user-1', 42, 1760000000);

    expect($run->id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($run->signer())->toBe(testKey()->fingerprint());
});

it('round trips a run through a commit body', function () {
    $run = Run::sign(testKey(), 'user-1', 1234, 1760000000);
    $body = ScoreCommit::body($run);

    expect($body)->toStartWith("i got a new high score on Nata Nu//ers: 1234\n\n```nata-nullers\nv1.")
        ->and(ScoreCommit::parse($body, 'user-1'))->toEqual($run)
        ->and(ScoreCommit::parse($body, 'user-1')->signer())->toBe(testKey()->fingerprint());
});

it('does not verify a run lifted into someone else\'s commit or edited', function () {
    $body = ScoreCommit::body(Run::sign(testKey(), 'user-1', 10, 1760000000));

    expect(ScoreCommit::parse($body, 'user-2')->signer())->toBeNull()
        ->and(ScoreCommit::parse(str_replace('.10.', '.9999.', $body), 'user-1')->signer())->toBeNull();
});

it('finds no run in an ordinary commit', function () {
    expect(ScoreCommit::parse('shipped it', 'user-1'))->toBeNull()
        ->and(ScoreCommit::parse("```nata-nullers\nnope\n```", 'user-1'))->toBeNull();
});

it('keeps only a better run as the high score, per user', function () {
    $scores = new HighScore;

    expect($scores->record(Run::sign(testKey(), 'user-1', 10)))->toBeTrue()
        ->and($scores->record(Run::sign(testKey(), 'user-1', 10)))->toBeFalse()
        ->and($scores->record(Run::sign(testKey(), 'user-1', 3)))->toBeFalse()
        ->and($scores->record(Run::sign(SshKey::generate(), 'user-1', 11)))->toBeTrue()
        ->and($scores->best('user-1')->score)->toBe(11)
        ->and($scores->best('user-2'))->toBeNull();
});

it('keeps the high score when the cache is emptied, as Android does on every update', function () {
    $scores = new HighScore;
    $run = Run::sign(testKey(), 'user-1', 42, 1760000000);
    $scores->record($run);

    Cache::flush();

    expect($scores->best('user-1'))->toEqual($run)
        ->and($scores->record(Run::sign(testKey(), 'user-1', 7)))->toBeFalse()
        ->and($scores->best('user-1'))->toEqual($run);
});
