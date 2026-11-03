<?php

use App\Devnull\DeviceKey;
use App\Devnull\NataNullers\Bus;
use App\Devnull\NataNullers\HighScore;
use App\Devnull\NataNullers\Layout;
use App\Devnull\NataNullers\Obstacle;
use App\Devnull\NataNullers\Run;
use App\Devnull\NataNullers\ScoreCommit;
use App\Devnull\NataNullers\Simulation;
use App\NativeComponents\NataNullers;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    // Faces are fetched into the image cache, so it's somewhere of the test's own. That comes first,
    // before anything that can skip or throw, because afterEach deletes whatever storage_path() is.
    app()->useStoragePath(sys_get_temp_dir().'/devnull-nata-nullers-'.bin2hex(random_bytes(4)));
    skipWithoutSkia();

    config(['devnull.url' => 'http://devnull.test']);
    $this->bridge = signIn();
    $this->clock = 10.0;
    $this->canvasAnswers = true;

    // The canvas answers every command with the time on its clock, which the test moves on, unless it's gone.
    $this->bridge->withSkiaControl(fn (string $canvasId, array $command): array => $this->canvasAnswers
        ? ['success' => true, 'seconds' => $this->clock, 'values' => isset($this->thumb) ? ['thumb' => $this->thumb] : []]
        : ['success' => false, 'error' => 'The canvas is not attached.']);

    // A game with nothing dropping or crossing but what the test sends.
    $this->game = new Simulation(10.0, seed: 1, spawning: false);
    app()->bind(Simulation::class, fn () => $this->game);

    $this->bus = new Bus(10.0, seed: 1);
    app()->bind(Bus::class, fn () => $this->bus);

    // No faces unless a test serves one, so the characters get drawn faces.
    $this->face = null;
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => match (true) {
        $request->url() === 'http://devnull.test/api/v1/commits' => Http::response(['data' => commitJson(['id' => '0199e5c4-2222-7000-8000-00000000cafe'])], 201),
        str_starts_with($request->url(), 'https://avatars.githubusercontent.com/') && $this->face !== null => Http::response($this->face),
        default => Http::response('', 404),
    });
});

afterEach(function () {
    File::deleteDirectory(storage_path());
});

/**
 * The game on screen, one beat in so it has started.
 */
function startedGame(): TestableComponent
{
    return Native::test(NataNullers::class)->call('tick');
}

/**
 * An empty Log for the logo to sit over.
 */
function serveLog(): void
{
    Http::swap(new Factory);
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);
}

/**
 * Catches one pastel, then rides a cyclist into you.
 */
function knockedOverWithOnePoint(TestableComponent $screen): TestableComponent
{
    test()->game->drop(Simulation::Width / 2, 10);
    test()->clock = 14.0;
    $screen->call('tick');

    test()->game->send(Obstacle::Cyclist, 1, 14);
    test()->clock = 17.0;

    return $screen->call('tick');
}

/**
 * Beats on, a few times a second, until $until says stop.
 *
 * @param  callable(): bool  $until
 */
function beatUntil(TestableComponent $screen, callable $until): TestableComponent
{
    for ($beats = 0; $beats < 4 * 600 && ! $until(); $beats++) {
        test()->clock += 0.25;
        $screen->call('tick');
    }

    return $screen;
}

/**
 * Every command the canvas was sent, decoded, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function canvasCommands(?string $action = null): array
{
    return collect(test()->bridge->callsTo('Skia.Control'))
        ->map(fn (array $call): array => json_decode($call['params']['command'], true))
        ->filter(fn (array $command): bool => $action === null || $command['action'] === $action)
        ->values()
        ->all();
}

/**
 * A node, found by its ID anywhere in a list of nodes.
 *
 * @param  list<array<string, mixed>>  $nodes
 * @return array<string, mixed>|null
 */
function findSkiaNode(array $nodes, string $id): ?array
{
    foreach ($nodes as $node) {
        if (($node['id'] ?? null) === $id) {
            return $node;
        }

        if (($found = findSkiaNode($node['children'] ?? [], $id)) !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * The results panel as the canvas last had it.
 *
 * @return array<string, mixed>
 */
function shownResults(): array
{
    $patch = collect(canvasCommands('patch'))->last(fn (array $command): bool => findSkiaNode($command['nodes'], 'results') !== null);

    return findSkiaNode($patch['nodes'], 'results');
}

/**
 * Every piece of text in a node.
 *
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
function skiaText(array $node): array
{
    return [
        ...(isset($node['text']) ? [$node['text']] : []),
        ...collect($node['children'] ?? [])->flatMap(fn (array $child): array => skiaText($child))->all(),
    ];
}

/**
 * Taps a button on the results: down on it, and up again in its middle.
 */
function tapResult(TestableComponent $screen, string $button): TestableComponent
{
    [$left, $top, $width, $height] = findSkiaNode(shownResults()['children'], $button)['rect'];
    $x = $left + $width / 2;
    $y = $top + $height / 2;

    return $screen->pointerSkia('nata-nullers', $button, 'down', $x, $y)->pointerSkia('nata-nullers', $button, 'up', $x, $y);
}

it('takes the whole screen, with every slot, the controls and a place for the results', function () {
    $screen = Native::test(NataNullers::class)
        ->assertSkiaCanvas('nata-nullers', fn (array $props): bool => $props['animated'] === true)
        ->assertSkiaNode('nata-nullers', 'player')
        ->assertSkiaNode('nata-nullers', 'catch', fn (array $node): bool => $node['interaction']['kind'] === 'drag')
        ->assertSkiaNode('nata-nullers', 'left')
        ->assertSkiaNode('nata-nullers', 'right')
        ->assertSkiaNode('nata-nullers', 'jump', fn (array $node): bool => $node['type'] === 'circle')
        ->assertSkiaNode('nata-nullers', 'close')
        ->assertSkiaNode('nata-nullers', 'results', fn (array $node): bool => $node['children'] === [])
        ->assertSkiaScene('nata-nullers', fn (array $scene): bool => $scene['height'] == Layout::Height);

    foreach (range(0, Simulation::PastelSlots - 1) as $slot) {
        $screen->assertSkiaNode('nata-nullers', "p{$slot}");
    }

    foreach (range(0, Simulation::ObstacleSlots - 1) as $slot) {
        foreach (Obstacle::cases() as $obstacle) {
            $screen->assertSkiaNode('nata-nullers', "o{$slot}{$obstacle->value}");
        }
    }

    expect(json_encode($screen->tree()))->not->toContain('"top_bar"');
});

it('puts your avatar on the player when it can get it', function () {
    $this->face = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    Native::test(NataNullers::class)
        ->assertSkiaScene('nata-nullers', fn (array $scene): bool => isset($scene['assets']['me']) && ! isset($scene['assets']['nuno']));
});

it('starts the falling pastel from where it is now and lands it on the pavement', function () {
    $screen = startedGame();
    $pastel = $this->game->drop(100, 10);
    $this->clock = 10.5;
    $screen->call('tick');

    $this->bridge->assertSkiaControl('nata-nullers', 'animate', fn (array $command): bool => $command['value'] === "p{$pastel->slot}y"
        && abs($command['animation']['from'] - $pastel->y(10.5)) < 0.01
        && abs($command['animation']['to'] - $pastel->landing()) < 0.01);
});

it('walks while an arrow is held and stops when it comes up', function () {
    $screen = startedGame();

    $screen->pointerSkia('nata-nullers', 'left', 'down', 60, 700);

    $this->bridge->assertSkiaControl('nata-nullers', 'animate', fn (array $command): bool => $command['value'] === 'px' && $command['animation']['to'] == 22);

    $this->clock = 10.5;
    $screen->pointerSkia('nata-nullers', 'left', 'up', 60, 700);

    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => abs(($command['values']['px'] ?? 0) - (Simulation::Width / 2 - 105)) < 0.01
        && $command['values']['lp'] === 0);
});

it('turns when a held thumb slides across the rocker, without lifting', function () {
    $screen = startedGame()->pointerSkia('nata-nullers', 'left', 'down', 60, 700);

    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => ($command['values']['thumb'] ?? null) == 60 && $command['values']['lp'] === 1);

    $this->clock = 10.5;
    $this->thumb = 140.0;
    $screen->call('tick');

    expect($this->game->walk->direction)->toBe(1);
    $this->bridge->assertSkiaControl('nata-nullers', 'animate', fn (array $command): bool => $command['value'] === 'px' && $command['animation']['to'] > $command['animation']['from']);
    expect(collect(canvasCommands('values'))->last(fn (array $command): bool => isset($command['values']['lp']))['values'])->toMatchArray(['lp' => 0, 'rp' => 1]);

    $this->clock = 10.8;
    $screen->pointerSkia('nata-nullers', 'left', 'up', 140, 700);

    expect($this->game->walk->direction)->toBe(0);
});

it('keeps walking the same way while a thumb rests on the middle of the rocker', function () {
    $screen = startedGame()->pointerSkia('nata-nullers', 'left', 'down', 60, 700);

    $this->clock = 10.5;
    $this->thumb = Layout::RockerRight[0] + Layout::RockerDeadZone - 1;
    $screen->call('tick');

    expect($this->game->walk->direction)->toBe(-1);
});

it('does not move you when the thumb value changes with nothing held', function () {
    $screen = startedGame();

    $this->clock = 10.5;
    $this->thumb = 140.0;
    $screen->call('tick');

    expect($this->game->walk->direction)->toBe(0);
});

it('lets a second finger take over the walk and hands it back when it lifts', function () {
    $screen = startedGame()->pointerSkia('nata-nullers', 'left', 'down', 60, 700)->pointerSkia('nata-nullers', 'right', 'down', 140, 700);

    expect($this->game->walk->direction)->toBe(1);

    $this->clock = 10.5;
    $this->thumb = 60.0;
    $screen->call('tick');

    expect($this->game->walk->direction)->toBe(1);

    $screen->pointerSkia('nata-nullers', 'right', 'up', 140, 700);

    expect($this->game->walk->direction)->toBe(-1);
    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => ($command['values']['thumb'] ?? null) == Layout::rockerHalfMiddle(-1));
});

it('shows each obstacle by values, so an arrow held while one comes keeps walking and still stops', function () {
    $screen = startedGame()->pointerSkia('nata-nullers', 'right', 'down', 140, 700);

    $crossing = $this->game->send(Obstacle::Gull, -1, 10);
    $this->clock = 10.4;
    $screen->call('tick');

    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => ($command['values']["o{$crossing->slot}gull"] ?? null) === 1
        && $command['values']["o{$crossing->slot}cyclist"] === 0
        && $command['values']["o{$crossing->slot}d"] === -1);

    expect(canvasCommands('patch'))->toBe([]);

    $this->clock = 10.6;
    $screen->pointerSkia('nata-nullers', 'right', 'up', 140, 700);

    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => abs(($command['values']['px'] ?? 0) - (Simulation::Width / 2 + 0.6 * 210)) < 0.01);
});

it('stops walking when the arrow comes up even if the canvas cannot say the time', function () {
    $screen = startedGame()->pointerSkia('nata-nullers', 'left', 'down', 60, 700);

    $this->canvasAnswers = false;
    $screen->pointerSkia('nata-nullers', 'left', 'up', 60, 700);

    $this->canvasAnswers = true;
    $this->clock = 11.0;
    $screen->call('tick');

    expect($this->game->walk->direction)->toBe(0);
});

it('jumps from the jump button, as two arcs, with a buzz', function () {
    startedGame()->pointerSkia('nata-nullers', 'jump', 'down', 290, 698);

    $this->bridge->assertSkiaControl('nata-nullers', 'animate', fn (array $command): bool => $command['value'] === 'py'
        && $command['animation']['kind'] === 'sequence'
        && count($command['animation']['segments']) === 2);
    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => ($command['values']['jp'] ?? null) === 1);
    $this->bridge->assertCalled('Device.Vibrate');
});

it('buzzes on a catch and turns the score wheels to it', function () {
    $screen = startedGame();
    $this->game->drop(Simulation::Width / 2, 10);
    $this->bridge->assertNotCalled('Device.Vibrate');

    $this->clock = 14.0;
    $screen->call('tick')->assertSet('score', 1);

    $this->bridge->assertCalled('Device.Vibrate');
    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => ($command['values']['d0'] ?? null) === -Layout::DigitCell[1]
        && $command['values']['d0o'] === 1
        && $command['values']['d1o'] === 0);
});

it('signs and keeps a new best, buzzes, and puts the results on the canvas with a way to share', function () {
    knockedOverWithOnePoint(startedGame())
        ->assertSet('over', true)
        ->assertSet('score', 1)
        ->assertSet('newBest', true)
        ->assertSkiaCanvas('nata-nullers', fn (array $props): bool => $props['animated'] === false);

    $best = app(HighScore::class)->best('0199e5c4-0000-7000-8000-000000000001');

    expect($best->score)->toBe(1)
        ->and($best->signer())->toBe(app(DeviceKey::class)->current()->fingerprint())
        ->and(skiaText(shownResults()))->toContain('A new best!', 'Share to /dev/null', 'Play again', 'Quit')
        ->and(findSkiaNode(shownResults()['children'], 'share'))->not->toBeNull();

    $this->bridge->assertCalled('Device.Vibrate');
    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => ($command['values']['cv'] ?? null) === 0);
});

it('shows the commit before posting it, then posts it', function () {
    $screen = tapResult(knockedOverWithOnePoint(startedGame()), 'share');

    $run = app(HighScore::class)->best('0199e5c4-0000-7000-8000-000000000001');

    expect(skiaText(shownResults()))->toContain('i got a new high score on Nata Nu//ers: 1', 'Post to /dev/null');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/commits'));

    tapResult($screen, 'post')->assertSet('shared', true);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/commits'
        && $request['body'] === ScoreCommit::body($run));
    expect(skiaText(shownResults()))->toContain('Shared. Let them try to beat it.')
        ->and(findSkiaNode(shownResults()['children'], 'post'))->toBeNull();
});

it('does nothing when a finger slides off a button before it comes up', function () {
    $screen = knockedOverWithOnePoint(startedGame());
    [$x, $y] = findSkiaNode(shownResults()['children'], 'share')['rect'];

    $screen->pointerSkia('nata-nullers', 'share', 'down', $x + 10, $y + 10)
        ->pointerSkia('nata-nullers', 'share', 'up', $x + 10, $y - 40)
        ->assertSet('confirmingShare', false);
});

it('does not offer to share a score that is not your best', function () {
    app(HighScore::class)->record(Run::sign(testKey(), '0199e5c4-0000-7000-8000-000000000001', 50));

    knockedOverWithOnePoint(startedGame())
        ->assertSet('newBest', false)
        ->assertSet('best', 50);

    expect(skiaText(shownResults()))->toContain('Knocked over', 'best 50')
        ->and(findSkiaNode(shownResults()['children'], 'share'))->toBeNull();
});

it('starts again from nothing', function () {
    tapResult(knockedOverWithOnePoint(startedGame()), 'play-again')
        ->assertSet('over', false)
        ->assertSet('score', 0)
        ->assertSkiaCanvas('nata-nullers', fn (array $props): bool => $props['animated'] === true);

    expect(shownResults()['children'])->toBe([]);
    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => ($command['values']['o0o'] ?? null) === 0
        && $command['values']['cv'] === 1);
});

it('draws the bus parked and empty, behind the player', function () {
    $screen = Native::test(NataNullers::class)
        ->assertSkiaNode('nata-nullers', 'bus', fn (array $node): bool => $node['bindings']['translateX'] === 'bx');

    foreach (range(0, Bus::Seats - 1) as $seat) {
        $screen->assertSkiaNode('nata-nullers', "bus-seat{$seat}", fn (array $node): bool => $node['bindings']['scaleX'] === "bs{$seat}");
    }

    $screen->assertSkiaScene('nata-nullers', function (array $scene): bool {
        $order = array_column($scene['nodes'], 'id');

        return $scene['values']['bx']['initial'] == Bus::StartX
            && $scene['values']['bs0']['initial'] == 0
            && array_search('bus', $order, true) < array_search('o0', $order, true)
            && array_search('o0', $order, true) < array_search('player', $order, true);
    });
});

it('fills the bus and drives it off with values and animations alone, so a held arrow stays held', function () {
    $screen = startedGame()->pointerSkia('nata-nullers', 'right', 'down', 140, 700);

    beatUntil($screen, fn (): bool => $this->bus->phase === Bus::Leaving);

    $this->bridge->assertSkiaControl('nata-nullers', 'animate', fn (array $command): bool => $command['value'] === 'bs0' && $command['animation']['kind'] === 'spring');
    $this->bridge->assertSkiaControl('nata-nullers', 'animate', fn (array $command): bool => $command['value'] === 'bx'
        && $command['animation']['from'] == Bus::StartX
        && $command['animation']['to'] == Bus::OffRight
        && $command['animation']['easing'] === 'easeIn'
        && $command['animation']['repeat'] === 'once');

    expect(canvasCommands('patch'))->toBe([]);

    $screen->pointerSkia('nata-nullers', 'right', 'up', 140, 700);

    expect(collect(canvasCommands('values'))->last()['values'])->toHaveKey('px');
});

it('brings the bus back empty and facing the way it is now going', function () {
    $screen = startedGame();

    beatUntil($screen, fn (): bool => $this->bus->phase === Bus::Arriving);

    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => ($command['values']['bd'] ?? null) === -1
        && $command['values']['bx'] == Bus::OffRight
        && $command['values']['bs0'] === 0
        && $command['values']['bs3'] === 0);
    $this->bridge->assertSkiaControl('nata-nullers', 'animate', fn (array $command): bool => $command['value'] === 'bx'
        && $command['animation']['from'] == Bus::OffRight
        && $command['animation']['to'] == $this->bus->drive->to
        && $command['animation']['easing'] === 'easeOut');
});

it('cannot hit you, even driving straight through you', function () {
    $screen = startedGame();

    beatUntil($screen, fn (): bool => $this->bus->phase === Bus::Leaving);
    $crossesPlayerBy = $this->bus->drive->endsAt();
    beatUntil($screen, fn (): bool => $this->clock > $crossesPlayerBy);

    $screen->assertSet('over', false)->assertSet('score', 0);
    expect($this->game->over())->toBeFalse();
});

it('stops the bus where it is when you are knocked over, and starts it again from where it was parked', function () {
    $screen = startedGame();

    beatUntil($screen, fn (): bool => $this->bus->phase === Bus::Leaving);
    $this->clock += 2;
    $screen->call('tick');

    $this->game->send(Obstacle::Cyclist, 1, $this->clock);
    $this->clock += 3;
    $screen->call('tick')->assertSet('over', true);

    $x = $this->bus->x((float) $this->game->endedAt);

    $this->bridge->assertSkiaControl('nata-nullers', 'values', fn (array $command): bool => isset($command['values']['cv'], $command['values']['bx'])
        && $command['values']['cv'] === 0
        && abs($command['values']['bx'] - $x) < 0.01
        && $command['values']['bs0'] === 1);

    tapResult($screen, 'play-again');

    expect(collect(canvasCommands('values'))->last()['values'])
        ->toMatchArray(['bx' => Bus::StartX, 'bd' => 1, 'bs0' => 0, 'bs1' => 0, 'bs2' => 0, 'bs3' => 0]);
});

it('presses the results buttons from VoiceOver too', function () {
    knockedOverWithOnePoint(startedGame())
        ->actionSkia('nata-nullers', 'play-again', 'activate')
        ->assertSet('over', false);
});

it('goes back to the app from Quit', function () {
    startedGame()
        ->pointerSkia('nata-nullers', 'close', 'down', 36, 84)
        ->pointerSkia('nata-nullers', 'close', 'up', 36, 84)
        ->assertWentBack();
});

it('opens from a long press on the Log logo', function () {
    serveLog();

    $log = Native::visit('/log');

    expect(json_encode(nodeByRef($log, 'wordmark')))->toContain('long_press');

    $log->longPress('wordmark')->assertNavigatedTo('/nata-nullers');
});

it('has no long press on the logo when the game is not in the build', function () {
    config(['devnull.nata_nullers' => false]);
    serveLog();

    $logo = nodeByRef(Native::visit('/log'), 'wordmark');

    expect(json_encode($logo))->not->toContain('long_press');
});
