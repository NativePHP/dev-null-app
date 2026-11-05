<?php

use App\Devnull\NataNullers\Run;
use App\Devnull\NataNullers\ScoreCommit;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo(now()->setDateTime(2026, 10, 16, 12, 0));
    $this->freezeTime();
});

/**
 * The Log with one shared high score in it, by Ada, signed for $signedFor.
 */
function logWithScore(int $score = 1234, string $signedFor = 'u2'): TestableComponent
{
    $body = ScoreCommit::body(Run::sign(testKey(), $signedFor, $score, 1760000000));
    [$line] = explode("\n", $body);

    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'body' => $body,
        'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']),
        'body_segments' => [['type' => 'text', 'text' => $line."\n\n"], ['type' => 'block', 'language' => 'nata-nullers']],
    ])]))]);

    return Native::visit('/log', platform: 'ios');
}

it('draws a shared high score as a card, signed, with its run kept out of the body', function () {
    skipWithoutSkia();

    $screen = logWithScore()
        ->assertSee('i got a new high score on Nata Nu//ers: 1234')
        ->assertSee('Beat 1,234')
        ->assertSee('Signed by their device');

    expect(nodeByRef($screen, 'score-card-c1')['props']['a11y_label'])->toBe('Nata Nullers high score, 1,234, signed by their device')
        ->and(rendersRef($screen, 'score-signed-c1'))->toBeTrue()
        ->and(collect(shownText($screen))->contains(fn (string $text): bool => str_contains($text, 'v1.') || str_contains($text, 'nata-nullers')))->toBeFalse();
});

it('shows a run signed for someone else without the signed line', function () {
    skipWithoutSkia();

    $screen = logWithScore(signedFor: 'u9')->assertSee('Beat 1,234')->assertDontSee('Signed by their device');

    expect(rendersRef($screen, 'score-signed-c1'))->toBeFalse()
        ->and(nodeByRef($screen, 'score-card-c1')['props']['a11y_label'])->toBe('Nata Nullers high score, 1,234');
});

it('opens the game from the card', function () {
    skipWithoutSkia();

    logWithScore()->tap('score-card-c1')->assertNavigatedTo('/nata-nullers');
});

it('shows the card with nothing to open when the game is not in the build', function () {
    config(['devnull.nata_nullers' => false]);

    $screen = logWithScore()->assertSee('1,234')->assertDontSee('Beat 1,234');

    expect(nodeByRef($screen, 'score-card-c1')['type'])->not->toBe('pressable');
});
