<?php

namespace App\NativeComponents;

use App\Devnull\DeviceKey;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\NataNullers\Bus;
use App\Devnull\NataNullers\Faces;
use App\Devnull\NataNullers\Game;
use App\Devnull\NataNullers\HighScore;
use App\Devnull\NataNullers\Jump;
use App\Devnull\NataNullers\Layout;
use App\Devnull\NataNullers\Results;
use App\Devnull\NataNullers\Run;
use App\Devnull\NataNullers\Scenery;
use App\Devnull\NataNullers\ScoreCommit;
use App\Devnull\NataNullers\Simulation;
use App\Devnull\Outbox;
use App\Models\PendingWrite;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Device;
use Native\Mobile\Skia\Animation;
use Native\Mobile\Skia\Canvas;
use Native\Mobile\Skia\Events\CanvasAction;
use Native\Mobile\Skia\Events\CanvasPointer;
use Native\Mobile\Skia\Scene;
use RuntimeException;

/**
 * Nata Nu//ers, the Easter egg behind a long press on the Log's logo: catch
 * the pastéis de nata falling on Lisbon and jump whatever comes along the
 * pavement (see Simulation for the rules). It has the whole screen, with
 * everything on it drawn in the canvas (see Scenery and Layout).
 *
 * The canvas does all the moving, on its own clock, from the paths this hands
 * it: a pastel's fall, a cyclist's ride, your walk while an arrow is held and
 * your jump. This only checks in a few dozen times a second (see tick()), asks
 * the canvas what time it is, plays the rules forward to then, and starts or
 * stops whatever that changed. The two can't drift apart, since everything is
 * worked out against the canvas's clock, and it stops with the canvas when
 * the app goes to the background.
 *
 * While a game is on, the canvas only ever gets values and animations, never
 * a replaced shape: replacing one makes it let go of every finger, and the
 * arrow you're holding would never come up.
 *
 * The tram goes about its business too (see Bus), which is none of the game's.
 *
 * The phone buzzes when you jump, when you catch a pastel and when you're hit.
 * When something knocks you over, your score is signed with this phone's key
 * (see Run) and kept if it's your best. A new best can be shared as a commit,
 * which the feed shows as a card for others to beat.
 */
class NataNullers extends NativeComponent
{
    use SyncsOutbox;
    use TalksToDevnull;

    public const string CanvasId = 'nata-nullers';

    public int $score = 0;

    public ?int $best = null;

    public bool $over = false;

    public bool $newBest = false;

    public bool $confirmingShare = false;

    public bool $shared = false;

    public ?string $shareError = null;

    /**
     * Whether the canvas's clock runs. It stops with the game.
     */
    public bool $playing = true;

    protected bool $hidesNavBar = true;

    protected Scene $scene;

    protected Scenery $scenery;

    protected ?Simulation $game = null;

    /**
     * The bus going by, which has no part in the game but to make you look.
     */
    protected ?Bus $bus = null;

    protected ?Run $run = null;

    /**
     * The results panel on the canvas, while it's up.
     */
    protected ?Results $results = null;

    /**
     * The arrows held down, oldest first: the newest one walks.
     *
     * @var list<int>
     */
    protected array $held = [];

    /**
     * The way the rocker is walking you: -1, 1, or 0 with no thumb on it.
     */
    protected int $steering = 0;

    /**
     * Where the canvas last said the thumb is on the rocker.
     */
    protected ?float $thumb = null;

    /**
     * The last time the canvas told us, for when it can't be asked.
     */
    protected ?float $lastClock = null;

    public function mount(): void
    {
        if (! Game::available()) {
            $this->scene = Scene::make(1, 1);
            $this->back();

            return;
        }

        $faces = app(Faces::class);
        $font = resource_path('fonts/GeistMono-Medium.ttf');

        $this->best = $this->myBest()?->score;
        $this->scenery = new Scenery(TabsLayout::accent(), is_file($font) ? (string) file_get_contents($font) : null);
        $this->scene = $this->scenery->scene($faces->me($this->me()?->avatarUrl), $faces->nuno(), $this->best);
    }

    /**
     * The game's heartbeat: plays it forward to the canvas's time and shows
     * what happened. It starts on the first beat the canvas answers.
     */
    #[Poll(50)]
    public function tick(): void
    {
        if ($this->over) {
            return;
        }

        $now = $this->clock();

        if ($now === null) {
            return;
        }

        $this->game ??= app()->make(Simulation::class, ['startedAt' => $now]);
        $this->bus ??= app()->make(Bus::class, ['startedAt' => $now]);

        if ($this->play($now)) {
            $this->steer($now);
        }
    }

    /**
     * A finger went down or came up on something that takes one. The arrows
     * walk while they're held, and the jump button jumps, higher the longer
     * it's held. A button on the results, or Quit, goes when the finger comes
     * up still on it. Moves stay on the canvas.
     */
    #[On(CanvasPointer::class)]
    public function pointer(string $canvasId = '', string $shapeId = '', string $phase = '', float $x = 0, float $y = 0): void
    {
        if ($canvasId !== self::CanvasId) {
            return;
        }

        $down = $phase === 'down';
        $released = $phase === 'up';

        if ($shapeId === 'close') {
            if ($released && Layout::inCircle([Layout::Close[0], Layout::Close[1], Layout::Close[2] + 8], $x, $y)) {
                $this->quit();
            }

            return;
        }

        if ($this->over) {
            $button = $this->results?->buttons()[$shapeId] ?? null;

            if ($released && $button !== null && Layout::inRect($button, $x, $y)) {
                $this->pressResult($shapeId);
            }

            return;
        }

        match ($shapeId) {
            'left', 'right' => $this->rocker($shapeId === 'left' ? -1 : 1, $down, $x),
            'jump' => $this->jumpButton($down),
            default => null,
        };
    }

    /**
     * VoiceOver and TalkBack activating a shape: the buttons press, and jump
     * hops.
     */
    #[On(CanvasAction::class)]
    public function action(string $canvasId = '', string $shapeId = '', string $action = ''): void
    {
        if ($canvasId !== self::CanvasId || $action !== 'activate') {
            return;
        }

        match (true) {
            $shapeId === 'close' => $this->quit(),
            $this->over && isset($this->results?->buttons()[$shapeId]) => $this->pressResult($shapeId),
            ! $this->over && $shapeId === 'jump' => $this->hop(),
            default => null,
        };
    }

    public function playAgain(): void
    {
        $values = ['px' => Simulation::Width / 2, 'py' => Simulation::Ground, 'cv' => 1, 'lp' => 0, 'rp' => 0, 'jp' => 0, ...Scenery::scoreValues(0), ...Scenery::busValues(Bus::StartX, 1)];

        for ($slot = 0; $slot < Simulation::PastelSlots; $slot++) {
            $values += ["p{$slot}o" => 0, "s{$slot}o" => 0, "p{$slot}y" => -100];
        }

        for ($slot = 0; $slot < Simulation::ObstacleSlots; $slot++) {
            $values += ["o{$slot}o" => 0, "o{$slot}x" => -100];
        }

        $this->command(fn () => Canvas::update(self::CanvasId, Scenery::noResults()));
        $this->command(fn () => Canvas::values(self::CanvasId, $values));

        $this->game = null;
        $this->bus = null;
        $this->run = null;
        $this->results = null;
        $this->held = [];
        $this->steering = 0;
        $this->score = 0;
        $this->over = false;
        $this->newBest = false;
        $this->confirmingShare = false;
        $this->shared = false;
        $this->shareError = null;
        $this->playing = true;
    }

    public function quit(): void
    {
        $this->back();
    }

    /**
     * Share on the results: shows the commit it would make, to post or not.
     */
    public function share(): void
    {
        $this->confirmingShare = $this->canShare();
        $this->showResults();
    }

    /**
     * Posts the new best as a commit, as the composer does (see SyncsOutbox),
     * with the signed run under the words for the feed to make a card of.
     */
    public function postScore(): void
    {
        if (! $this->canShare() || $this->run === null) {
            return;
        }

        $write = app(Outbox::class)->add(PendingWrite::Commit, null, [
            'body' => ScoreCommit::body($this->run),
            'parent_id' => null,
            'quoted_commit_id' => null,
            'poll' => null,
            'images' => [],
            'stash' => null,
            'composer' => 'compose',
        ]);

        if ($write === null) {
            return;
        }

        $this->shared = true;
        $this->confirmingShare = false;
        $this->showResults();
        $this->sendFromOutbox($write);
    }

    public function render(): View
    {
        return view('native.nata-nullers', [
            'scene' => $this->scene,
            'canvasId' => self::CanvasId,
        ]);
    }

    private function canShare(): bool
    {
        return $this->over && $this->newBest && $this->score > 0 && ! $this->shared;
    }

    private function pressResult(string $button): void
    {
        match ($button) {
            'play-again' => $this->playAgain(),
            'quit' => $this->quit(),
            'share' => $this->share(),
            'post' => $this->postScore(),
            default => null,
        };
    }

    /**
     * An arrow went down or came up. Letting go always stops the walk, even
     * if the canvas can't be asked the time just then. A second finger takes
     * over while it's down, and the first carries on when it comes up.
     */
    private function rocker(int $direction, bool $down, float $x): void
    {
        $this->held = array_values(array_diff($this->held, [$direction]));

        if ($down) {
            $this->held[] = $direction;
        }

        $this->steering = $this->held === [] ? 0 : $this->held[array_key_last($this->held)];
        $thumb = $this->held === [] ? [] : [Scenery::Thumb => $down ? $x : Layout::rockerHalfMiddle($this->steering)];
        $now = $this->clock() ?? $this->lastClock;

        if ($this->game === null || $now === null || ! $this->play($now)) {
            $this->command(fn () => Canvas::values(self::CanvasId, [...$this->litArrows(), ...$thumb]));

            return;
        }

        $this->walk($now, $thumb);
    }

    /**
     * A thumb slid across the rocker: walks the other way without it lifting.
     * Only with one thumb on it, since two would push the same value around.
     */
    private function steer(float $now): void
    {
        if (count($this->held) !== 1 || $this->thumb === null) {
            return;
        }

        $direction = Layout::rockerDirection($this->thumb, $this->steering);

        if ($direction !== $this->steering) {
            $this->steering = $direction;
            $this->walk($now);
        }
    }

    /**
     * Walks you the way the rocker says from where you are now.
     *
     * @param  array<string, float>  $values
     */
    private function walk(float $now, array $values = []): void
    {
        $walk = $this->game->walkTowards($this->steering, $now);

        if ($walk->seconds() <= 0) {
            $this->command(fn () => Canvas::values(self::CanvasId, ['px' => $walk->from, ...$this->litArrows(), ...$values]));

            return;
        }

        $this->command(fn () => Canvas::values(self::CanvasId, [...$this->litArrows(), ...$values]));
        $this->command(fn () => Canvas::animate(self::CanvasId, 'px', Animation::between($walk->from, $walk->to(), $walk->seconds(), repeat: 'once')));
    }

    /**
     * @return array{lp: int, rp: int}
     */
    private function litArrows(): array
    {
        return ['lp' => $this->steering === -1 ? 1 : 0, 'rp' => $this->steering === 1 ? 1 : 0];
    }

    /**
     * The jump button went down (a jump, which buzzes) or came up (the jump
     * cut short if it was a quick tap).
     */
    private function jumpButton(bool $down): void
    {
        $this->command(fn () => Canvas::values(self::CanvasId, ['jp' => $down ? 1 : 0]));

        $now = $this->clock() ?? $this->lastClock;

        if ($this->game === null || $now === null || ! $this->play($now)) {
            return;
        }

        $jump = $down ? $this->game->press($now) : $this->game->release($now);

        if ($down && $jump !== null) {
            Device::vibrate();
        }

        $this->jump($jump);
    }

    /**
     * A jump with no finger to hold it: the smallest hop.
     */
    private function hop(): void
    {
        $now = $this->clock();

        if ($this->game === null || $now === null || ! $this->play($now) || $this->game->press($now) === null) {
            return;
        }

        Device::vibrate();
        $this->jump($this->game->release($now));
    }

    /**
     * Plays the game to $now and shows it. False once it's over.
     */
    private function play(float $now): bool
    {
        $events = $this->game->advance($now);
        $values = [];
        $animations = [];
        $caught = false;

        foreach ($events as ['event' => $event, 'slot' => $slot]) {
            $caught = $caught || $event === 'caught';

            match ($event) {
                'dropped' => $this->dropped($slot, $now, $values, $animations),
                'caught' => $values += ["p{$slot}o" => 0, "p{$slot}y" => -100],
                'splatted' => $this->splatted($slot, $values, $animations),
                'sent' => $this->sent($slot, $now, $values, $animations),
                'passed' => $values["o{$slot}o"] = 0,
                default => null,
            };
        }

        $this->moveBus($now, $values, $animations);

        if ($this->game->score !== $this->score) {
            $this->score = $this->game->score;
            $values += Scenery::scoreValues($this->score);
        }

        if ($caught) {
            Device::vibrate();
        }

        if ($this->game->over()) {
            $this->finish($values);

            return false;
        }

        if ($values !== []) {
            $this->command(fn () => Canvas::values(self::CanvasId, $values));
        }

        foreach ($animations as $value => $animation) {
            $this->command(fn () => Canvas::animate(self::CanvasId, $value, $animation));
        }

        return true;
    }

    /**
     * @param  array<string, float>  $values
     * @param  array<string, Animation>  $animations
     */
    private function dropped(int $slot, float $now, array &$values, array &$animations): void
    {
        $pastel = $this->game->pastels[$slot] ?? null;

        if ($pastel === null) {
            return;
        }

        $values += ["p{$slot}x" => $pastel->x, "p{$slot}k" => $pastel->big ? 2 : 1, "p{$slot}o" => 1, "s{$slot}o" => 0];
        $animations["p{$slot}y"] = Animation::between($pastel->y($now), $pastel->landing(), max(0.001, $pastel->landsAt() - $now), repeat: 'once');
    }

    /**
     * @param  array<string, float>  $values
     * @param  array<string, Animation>  $animations
     */
    private function splatted(int $slot, array &$values, array &$animations): void
    {
        $values["p{$slot}o"] = 0;
        $animations["s{$slot}o"] = Animation::between(1, 0, 0.9, 'easeIn', 'once');
    }

    /**
     * Shows the obstacle in its slot, facing its way, and sends it across.
     *
     * @param  array<string, float>  $values
     * @param  array<string, Animation>  $animations
     */
    private function sent(int $slot, float $now, array &$values, array &$animations): void
    {
        $crossing = $this->game->crossings[$slot] ?? null;

        if ($crossing === null) {
            return;
        }

        $values += Scenery::obstacleValues($slot, $crossing->obstacle, $crossing->direction)
            + ["o{$slot}y" => $crossing->bottom(), "o{$slot}o" => 1];
        $animations["o{$slot}x"] = Animation::between($crossing->x($now), $crossing->to(), max(0.001, $crossing->endsAt() - $now), repeat: 'once');
    }

    /**
     * Shows what the bus just did: a passenger popping up in their seat, or a
     * drive starting, and when it's coming back, empty and facing its new way.
     *
     * @param  array<string, float>  $values
     * @param  array<string, Animation>  $animations
     */
    private function moveBus(float $now, array &$values, array &$animations): void
    {
        $change = $this->bus?->advance($now);

        if ($change === null) {
            return;
        }

        if ($change['event'] === 'boarded') {
            $animations["bs{$change['slot']}"] = Animation::spring(0, 1, stiffness: 320, damping: 14, seconds: 1.2);

            return;
        }

        $drive = $this->bus->drive;

        if ($change['event'] === 'arriving') {
            $values += Scenery::busValues($drive->from, $this->bus->direction);
        }

        $animations['bx'] = Animation::between($drive->from, $drive->to, $drive->seconds, $drive->easing, 'once');
    }

    /**
     * Draws a jump as its two arcs: easing out to the top, easing in to the ground.
     */
    private function jump(?Jump $jump): void
    {
        if ($jump === null) {
            return;
        }

        $ground = Simulation::Ground;
        $segments = [];

        if ($jump->rise > 0) {
            $segments[] = Animation::between($ground - $jump->from, $ground - $jump->apex, $jump->rise, 'easeOut', 'once');
        }

        $segments[] = Animation::between($ground - $jump->apex, $ground, max(0.001, $jump->fall), 'easeIn', 'once');

        $this->command(fn () => Canvas::animate(self::CanvasId, 'py', Animation::sequence(...$segments)));
    }

    /**
     * Knocked over: a buzz, everything stops where it was when you were hit,
     * the controls go, and the score is signed and kept if it's your best.
     * Then the results come up on the canvas.
     *
     * @param  array<string, float>  $values  what this beat had still to show
     */
    private function finish(array $values): void
    {
        $game = $this->game;
        $at = (float) $game->endedAt;
        $values = [...$values, 'px' => $game->x($at), 'py' => Simulation::Ground - $game->height($at), 'cv' => 0, 'lp' => 0, 'rp' => 0, 'jp' => 0];

        foreach ($game->pastels as $slot => $pastel) {
            $values["p{$slot}y"] = $pastel->y($at);
        }

        foreach ($game->crossings as $slot => $crossing) {
            $values["o{$slot}x"] = $crossing->x($at);
        }

        if ($this->bus !== null) {
            $values['bx'] = $this->bus->x($at);

            foreach ($this->bus->riders as $seat) {
                $values["bs{$seat}"] = 1;
            }
        }

        Device::vibrate();
        $this->command(fn () => Canvas::values(self::CanvasId, $values));

        $this->over = true;
        $this->playing = false;
        $this->held = [];
        $this->steering = 0;
        $this->record();
        $this->showResults();
    }

    /**
     * Signs the run with this phone's key and keeps it if it's your best.
     */
    private function record(): void
    {
        $me = $this->me();

        if ($me === null) {
            return;
        }

        try {
            $this->run = Run::sign(app(DeviceKey::class)->current(), $me->id, $this->score);
        } catch (KeychainUnavailable $exception) {
            $this->shareError = $exception->getMessage();

            return;
        }

        $this->newBest = app(HighScore::class)->record($this->run);
        $this->best = $this->myBest()?->score;
    }

    /**
     * Puts the results on the canvas as they stand, with your best beside
     * the score. The game is over, so nothing's held that replacing them
     * could drop.
     */
    private function showResults(): void
    {
        $this->results = new Results(
            score: $this->score,
            best: $this->best,
            newBest: $this->newBest,
            sharing: match (true) {
                $this->shared => Results::Shared,
                $this->confirmingShare => Results::Confirm,
                $this->canShare() => Results::Offer,
                default => null,
            },
            error: $this->shareError,
            preview: $this->run !== null ? explode("\n", ScoreCommit::body($this->run))[0] : '',
        );

        $this->command(fn () => Canvas::update(self::CanvasId, $this->scenery->results($this->results), $this->scenery->best($this->best)));
    }

    private function myBest(): ?Run
    {
        $me = $this->me();

        return $me === null ? null : app(HighScore::class)->best($me->id);
    }

    /**
     * The canvas's clock, which the game runs on, or null while the canvas
     * isn't on screen yet.
     */
    private function clock(): ?float
    {
        $state = $this->command(fn () => Canvas::state(self::CanvasId));

        if (! is_numeric($state['seconds'] ?? null)) {
            return null;
        }

        $thumb = $state['values'][Scenery::Thumb] ?? null;
        $this->thumb = is_numeric($thumb) ? (float) $thumb : null;

        return $this->lastClock = (float) $state['seconds'];
    }

    /**
     * Sends a command to the canvas. One that fails, because the canvas has
     * gone or isn't there yet, is dropped: the next beat puts things right.
     *
     * @param  callable(): array<string, mixed>  $command
     * @return array<string, mixed>|null
     */
    private function command(callable $command): ?array
    {
        try {
            return $command();
        } catch (RuntimeException) {
            return null;
        }
    }
}
