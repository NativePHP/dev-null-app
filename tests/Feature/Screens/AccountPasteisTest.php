<?php

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Pasteis;
use App\NativeComponents\ThisDevice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 15:30:00', 'UTC'));
});

/**
 * `GET /me` with pastéis mode as given.
 *
 * @return array<string, mixed>
 */
function meWithPasteis(bool $on, int $remaining = 8): array
{
    return currentUserJson(['pasteis_mode' => $on, 'pasteis_remaining' => $remaining, 'pasteis_resets_at' => '2026-10-18T00:00:00Z', 'pasteis_received' => 3]);
}

/**
 * The `PUT /me/pasteis-mode` requests sent, as their bodies.
 *
 * @return list<string>
 */
function pasteisModeSaves(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $sent): bool => $sent[0]->method() === 'PUT' && str_ends_with($sent[0]->url(), Client::PasteisModePath))
        ->map(fn (array $sent): string => $sent[0]->body())
        ->values()
        ->all();
}

it("has the web's pastéis mode section, word for word, with the system's switch", function (bool $on) {
    serveApi(['GET /api/v1/me' => [meWithPasteis($on)]]);

    $screen = Native::visit('/profile/device')
        ->assertSee('Pastéis mode')
        ->assertSee('A little appreciation, fresh daily. Get 10 pastéis to give to other people’s commits, one per click. Your allowance resets at 00:00 UTC.')
        ->assertSee('When off, all pastéis controls and counters are hidden. You still receive pastéis from others.');

    $switch = nodeByRef($screen, 'pasteis-mode-switch');

    expect($switch['type'])->toBe('toggle')
        ->and($switch['props']['value'] ?? false)->toBe($on)
        ->and($switch['props']['a11y_label'] ?? null)->toBe('Pastéis mode')
        // Never greyed out, even while a change is saving.
        ->and($switch['props']['disabled'] ?? false)->toBeFalse()
        // After Color, as on the web.
        ->and(array_column(findNodes($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['color', 'pasteis-mode', 'this-phone'], true)), 'ref'))->toBe(['color', 'pasteis-mode', 'this-phone']);
})->with(['on' => true, 'off' => false]);

it('turns pastéis mode on at once, and every screen gives pastéis from then on', function () {
    $bridge = holdRefreshes();
    keep('me', meWithPasteis(false));
    serveApi([
        'GET /api/v1/me' => [meWithPasteis(false)],
        'PUT /api/v1/me/pasteis-mode' => [meWithPasteis(true, remaining: 8)],
    ]);

    $screen = Native::visit('/profile/device')->toggle('pasteis-mode-switch', true);

    expect(nodeByRef($screen, 'pasteis-mode-switch')['props']['value'] ?? false)->toBeTrue()
        ->and(nodeByRef($screen, 'pasteis-mode-switch')['props']['disabled'] ?? false)->toBeFalse()
        ->and(app(Account::class)->pasteis())->not->toBeNull();

    finishRefreshes($screen)
        ->assertSet('savingPasteisMode', false)
        ->assertSet('pasteisModeError', null);

    // The screen's own GET /me, sent before the switch moved, lands first with the mode off, and changes nothing.
    expect(pasteisModeSaves())->toBe(['{"enabled":true}'])
        ->and(nodeByRef($screen, 'pasteis-mode-switch')['props']['value'] ?? false)->toBeTrue()
        ->and(app(Account::class)->pasteis()?->left())->toBe(8)
        ->and(kept('me')['data']['pasteis_mode'])->toBeTrue();

    $bridge->assertNotCalled('ToastKit.Show');
});

it('turns pastéis mode off, and the pastéis leave the rows', function () {
    app(Account::class)->rememberPasteis(new Pasteis);
    serveApi([
        'GET /api/v1/me' => [meWithPasteis(true)],
        'PUT /api/v1/me/pasteis-mode' => [meWithPasteis(false)],
    ]);

    Native::visit('/profile/device')->toggle('pasteis-mode-switch', false)->assertSet('pasteisModeError', null);

    expect(pasteisModeSaves())->toBe(['{"enabled":false}'])
        ->and(app(Account::class)->pasteis())->toBeNull();
});

it('puts the switch back when devnull says no, and says so in the web words', function () {
    serveApi([
        'GET /api/v1/me' => [meWithPasteis(false)],
        'PUT /api/v1/me/pasteis-mode' => [['message' => 'Server Error'], 500],
    ]);

    $screen = Native::visit('/profile/device')
        ->toggle('pasteis-mode-switch', true)
        ->assertSet('savingPasteisMode', false)
        ->assertSet('pasteisModeError', ThisDevice::PasteisModeFailed)
        ->assertSee('Could not save pastéis mode. Please try again.');

    expect(nodeByRef($screen, 'pasteis-mode-switch')['props']['value'] ?? false)->toBeFalse()
        ->and(app(Account::class)->pasteis())->toBeNull();
});

it('saves the last flip when the switch moves again before devnull answers', function (array $flips, array $saves, bool $on) {
    holdRefreshes();
    serveApi(function (Request $request) {
        if ($request->method() === 'PUT') {
            return Http::response(meWithPasteis(json_decode($request->body(), true)['enabled']));
        }

        return Http::response(meWithPasteis(false));
    });

    $screen = finishRefreshes(Native::visit('/profile/device'));

    foreach ($flips as $flip) {
        $screen->toggle('pasteis-mode-switch', $flip);
    }

    expect(nodeByRef($screen, 'pasteis-mode-switch')['props']['value'] ?? false)->toBe($on);

    finishRefreshes($screen)->assertSet('savingPasteisMode', false);

    expect(pasteisModeSaves())->toBe($saves)
        ->and($screen->instance()->current?->pasteisMode)->toBe($on)
        ->and(app(Account::class)->pasteis() !== null)->toBe($on)
        ->and(nodeByRef($screen, 'pasteis-mode-switch')['props']['value'] ?? false)->toBe($on);
})->with([
    'on, then off' => [[true, false], ['{"enabled":true}', '{"enabled":false}'], false],
    'on, off, on again' => [[true, false, true], ['{"enabled":true}'], true],
]);

it('sends nothing when the switch already says what devnull has', function () {
    serveApi(['GET /api/v1/me' => [meWithPasteis(true)]]);

    finishRefreshes(Native::visit('/profile/device'))->call('setPasteisMode', true);

    expect(pasteisModeSaves())->toBe([]);
});
