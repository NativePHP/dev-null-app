<?php

namespace App\Devnull\NataNullers;

use Native\Mobile\Skia\Animation;
use Native\Mobile\Skia\Node;
use Native\Mobile\Skia\Paint;
use Native\Mobile\Skia\Scene;

/**
 * Everything Nata Nu//ers draws: Lisbon behind the street, you, the pastéis,
 * what comes along the pavement, the score, the controls, and the results
 * when you're knocked over. Where each goes is in Layout.
 *
 * The canvas gets it all once, and while a game is on only shared values
 * change: the screen moves things by animating them (see
 * NativeComponents\NataNullers), the slots for pastéis and obstacles are
 * reused, and an obstacle's slot holds every kind of obstacle and shows the
 * one it's sending. Nothing is swapped out mid-game, because replacing any
 * part of a scene makes the canvas let go of every finger it's holding: an
 * arrow held down would never come up. Only the results panel and your best
 * are replaced, once the game is over.
 *
 * Text is drawn in Geist Mono, which the scene carries, so it looks the same
 * on both platforms. Without its bytes, it's the platform's own type.
 */
final readonly class Scenery
{
    public const string Me = 'me';

    public const string Nuno = 'nuno';

    public const string Font = 'mono';

    /**
     * The shared value the touch surfaces drag, which nothing draws from.
     */
    public const string Scratch = 'nul';

    /**
     * Where a thumb on the rocker is along its length, kept by the canvas as
     * it slides. Moves never reach PHP, so the screen reads this each beat.
     */
    public const string Thumb = 'thumb';

    private const string Ink = '#F4F5F7';

    private const string Muted = '#AEB4C0';

    private const string Custard = '#F3B93A';

    private const string Panel = '#14161CEB';

    private const string Clear = '#FFFFFF01';

    private const string Night = '#0E1536A6';

    /**
     * The bus's windows, front last: [left, width].
     *
     * @var list<array{0: float, 1: float}>
     */
    private const array Windows = [[-28, 12], [-12, 12], [4, 12], [20, 10]];

    /**
     * Who sits at each window: [skin, hair, shirt].
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array Passengers = [
        ['#F1C9A5', '#4A3428', '#C8554B'],
        ['#8D5A3B', '#1E1A1A', '#3D7EA6'],
        ['#E8B890', '#B8B8BE', '#5C8A52'],
        ['#C68E63', '#2B2B33', '#8A5CA8'],
    ];

    /**
     * @param  ?string  $font  Geist Mono's bytes, or null for the platform's own type
     */
    public function __construct(
        private string $shirt,
        private ?string $font = null,
    ) {}

    /**
     * The whole scene, with your face and Nuno's where there are bytes for
     * them.
     */
    public function scene(?string $me = null, ?string $nuno = null, ?int $best = null): Scene
    {
        $scene = Scene::make(Layout::Width, Layout::Height)->background('#1F2430');

        foreach ([self::Me => $me, self::Nuno => $nuno] as $asset => $bytes) {
            if ($bytes !== null) {
                $scene->asset($asset, $bytes);
            }
        }

        if ($this->font !== null) {
            $scene->asset(self::Font, $this->font, 'font');
        }

        $this->values($scene);

        foreach ($this->background() as $node) {
            $scene->add($node);
        }

        $scene->add($this->pavement())->add($this->streetAtNight())->add($this->bus());

        for ($slot = 0; $slot < Simulation::ObstacleSlots; $slot++) {
            $scene->add($this->obstacleSlot($slot, $nuno !== null));
        }

        $scene->add($this->character($this->shirt, $me !== null ? self::Me : null, '#2B2B33')
            ->id('player')
            ->bind('translateX', 'px')->bind('translateY', 'py'));

        for ($slot = 0; $slot < Simulation::PastelSlots; $slot++) {
            $scene->add(Node::group([
                $this->splat()->id("p{$slot}splat")
                    ->bind('scaleX', "p{$slot}k")->bind('scaleY', "p{$slot}k")->bind('opacity', "s{$slot}o"),
                $this->pastel()->id("p{$slot}art")
                    ->animate('rotation', Animation::between(0, $slot % 2 === 0 ? 360 : -360, 1.4 + 0.15 * $slot))
                    ->bind('scaleX', "p{$slot}k")->bind('scaleY', "p{$slot}k")->bind('opacity', "p{$slot}o"),
            ])->id("p{$slot}")->bind('translateX', "p{$slot}x")->bind('translateY', "p{$slot}y"));
        }

        return $scene
            ->add($this->catchAll())
            ->add($this->score())
            ->add($this->best($best))
            ->add($this->close())
            ->add($this->controls())
            ->add($this->hint())
            ->add(self::noResults());
    }

    /**
     * An obstacle slot: one of each obstacle, all hidden, in a group that
     * moves across. Showing one is a matter of values (see obstacleValues()).
     */
    public function obstacleSlot(int $slot, bool $nunoFace = false): Node
    {
        $kinds = [];

        foreach (Obstacle::cases() as $obstacle) {
            $kinds[] = $this->obstacle($obstacle, $nunoFace)->id("o{$slot}{$obstacle->value}")->bind('opacity', "o{$slot}{$obstacle->value}");
        }

        return Node::group([Node::group($kinds)->bind('scaleX', "o{$slot}d")])
            ->id("o{$slot}")
            ->bind('translateX', "o{$slot}x")->bind('translateY', "o{$slot}y")->bind('opacity', "o{$slot}o");
    }

    /**
     * The values that put the bus somewhere, facing its way, with nobody on it.
     *
     * @return array<string, float>
     */
    public static function busValues(float $x, int $direction): array
    {
        $values = ['bx' => $x, 'bd' => $direction];

        for ($seat = 0; $seat < Bus::Seats; $seat++) {
            $values["bs{$seat}"] = 0;
        }

        return $values;
    }

    /**
     * The values that show $obstacle in a slot, facing the way it's going.
     *
     * @return array<string, float>
     */
    public static function obstacleValues(int $slot, Obstacle $obstacle, int $direction): array
    {
        $values = ["o{$slot}d" => $direction < 0 ? -1 : 1];

        foreach (Obstacle::cases() as $kind) {
            $values["o{$slot}{$kind->value}"] = $kind === $obstacle ? 1 : 0;
        }

        return $values;
    }

    /**
     * The values that show $score on the odometer: which digit each wheel
     * shows, which wheels and the minus sign are showing, and the whole
     * number moved to sit in the middle of its pill.
     *
     * @return array<string, float>
     */
    public static function scoreValues(int $score): array
    {
        [$cell, $height] = Layout::DigitCell;
        $score = max(-(10 ** (Layout::Digits - 1) - 1), min(10 ** Layout::Digits - 1, $score));
        $digits = (string) abs($score);
        $count = strlen($digits);
        $negative = $score < 0 ? 1 : 0;
        $values = [];

        for ($wheel = 0; $wheel < Layout::Digits; $wheel++) {
            $showing = $wheel < $count;
            $values["d{$wheel}"] = $showing ? -(int) $digits[$count - 1 - $wheel] * $height : 0;
            $values["d{$wheel}o"] = $showing ? 1 : 0;
        }

        $start = (Layout::Digits - $count - $negative) * $cell;
        [$x, , $width] = Layout::ScorePill;

        return $values + [
            'sm' => $negative,
            'smx' => (Layout::Digits - $count - 1) * $cell,
            'sx' => $x + $width / 2 - ($start + Layout::Digits * $cell) / 2,
        ];
    }

    /**
     * Your best, top right, or nothing before you have one.
     */
    public function best(?int $best): Node
    {
        if ($best === null) {
            return Node::group([])->id('best');
        }

        [$x, $y, $width, $height] = Layout::Best;

        return Node::group([
            Node::rect($x, $y, $width, $height, Paint::fill(self::Panel), $height / 2),
            Node::paragraph('best '.number_format($best), $x, $y + ($height - 16) / 2, $width, 13, Paint::fill(self::Muted), $this->font(), 'center', maxLines: 1),
        ])->id('best');
    }

    /**
     * The results panel: what happened, the score, sharing it when it's a new
     * best, and Play again or Quit. Its buttons are where Results says.
     */
    public function results(Results $results): Node
    {
        [$x, $y, $width, $height] = $results->panel();
        $inner = $width - Layout::PanelPadding * 2;
        $left = $x + Layout::PanelPadding;
        $buttons = $results->buttons();
        $lines = $results->lines();
        $best = $results->best !== null ? 'best '.number_format($results->best) : '';

        $nodes = [
            Node::rect($x, $y, $width, $height, Paint::fill(self::Panel)->shadow(0, 8, 18, '#00000066'), 22),
            Node::rect($x, $y, $width, $height, Paint::stroke('#FFFFFF1F', 1), 22),
            Node::paragraph($results->title(), $left, $y + 24, $inner, 20, Paint::fill(self::Ink), $this->font(), 'center', maxLines: 1)->id('results-title'),
            Node::paragraph((string) $results->score, $left, $y + 58, $inner, 60, Paint::fill(self::Custard), $this->font(), 'center', maxLines: 1)->id('results-score'),
            Node::paragraph($results->unit(), $left, $y + 130, $inner, 14, Paint::fill(self::Muted), $this->font(), 'center', maxLines: 1),
            Node::paragraph($best, $left, $y + 150, $inner, 13, Paint::fill(self::Muted), $this->font(), 'center', maxLines: 1),
        ];

        if ($lines['preview'] !== null) {
            [$px, $py, $pw, $ph] = $lines['preview'];
            $nodes[] = Node::rect($px, $py, $pw, $ph, Paint::fill('#FFFFFF14'), 12);
            $nodes[] = Node::paragraph($results->preview, $px + 12, $py + 12, $pw - 24, 13, Paint::fill(self::Ink), $this->font(), maxLines: 2)->id('share-preview');
            $nodes[] = Node::paragraph('+ your signed run, which the feed shows as a card', $px + 12, $py + 52, $pw - 24, 10, Paint::fill(self::Muted), $this->font(), maxLines: 2);
        }

        if ($lines['shared'] !== null) {
            $nodes[] = Node::paragraph('Shared. Let them try to beat it.', $left, $lines['shared'], $inner, 13, Paint::fill(self::Muted), $this->font(), 'center', maxLines: 1)->id('shared');
        }

        if ($lines['error'] !== null) {
            $nodes[] = Node::paragraph((string) $results->error, $left, $lines['error'], $inner, 11, Paint::fill('#FF7A7A'), $this->font(), 'center', maxLines: 3);
        }

        $labels = [
            'share' => ['Share to /dev/null', 'primary'],
            'post' => ['Post to /dev/null', 'primary'],
            'play-again' => ['Play again', 'secondary'],
            'quit' => ['Quit', 'ghost'],
        ];

        foreach ($buttons as $id => $rect) {
            [$label, $style] = $labels[$id];
            $nodes[] = $this->button($id, $rect, $label, $style);
        }

        return Node::group($nodes)->id('results');
    }

    /**
     * Where the results go while there aren't any.
     */
    public static function noResults(): Node
    {
        return Node::group([])->id('results');
    }

    /**
     * A pastel de nata from above, 13 across its middle: crimped pastry, the
     * custard, and the blistered caramel spots.
     */
    public function pastel(): Node
    {
        $crimp = 'M';

        for ($point = 0; $point <= 28; $point++) {
            $angle = M_PI * 2 * $point / 28;
            $radius = $point % 2 === 0 ? 13 : 11.6;
            $crimp .= sprintf('%s%.2F %.2F ', $point === 0 ? '' : 'L', cos($angle) * $radius, sin($angle) * $radius);
        }

        return Node::group([
            Node::circle(0, 2, 13, Paint::fill('#00000033')->blur(2)),
            Node::path($crimp.'Z', Paint::fill('#C98A3A')),
            Node::circle(0, 0, 10, Paint::fill()->radialGradient(-2, -2, 11, ['#FBE08A', '#F3B93A', '#D9922A'])),
            Node::circle(-3, -2, 3.2, Paint::fill('#5A2A0ACC')->blur(0.8)),
            Node::circle(4, 1, 2.4, Paint::fill('#6E3510CC')->blur(0.8)),
            Node::circle(-1, 5, 1.8, Paint::fill('#7A3B12B3')->blur(0.6)),
            Node::circle(3, -5, 1.4, Paint::fill('#7A3B12B3')->blur(0.6)),
        ]);
    }

    /**
     * Custard on the pavement, sitting where a pastel's bottom edge lands.
     */
    public function splat(): Node
    {
        return Node::group([
            Node::oval(-17, 9, 34, 7, Paint::fill('#F3C03F')),
            Node::oval(-9, 7, 14, 5, Paint::fill('#C98A3A')),
            Node::circle(-21, 8, 2, Paint::fill('#F3C03F')),
            Node::circle(20, 6, 1.6, Paint::fill('#F3C03F')),
            Node::circle(14, 11, 1.4, Paint::fill('#7A3B12')),
        ]);
    }

    /**
     * Every shared value the scene starts with: the player, the pastel and
     * obstacle slots, the score's odometer, and the controls.
     */
    private function values(Scene $scene): void
    {
        $scene->value('px', Simulation::Width / 2)
            ->value('py', Simulation::Ground)
            ->value(self::Scratch, 0)
            ->value(self::Thumb, Layout::RockerRight[0])
            ->value('cv', 1)
            ->value('lp', 0)->value('rp', 0)->value('jp', 0);

        for ($slot = 0; $slot < Simulation::PastelSlots; $slot++) {
            $scene->value("p{$slot}x", 0)->value("p{$slot}y", -100)->value("p{$slot}k", 1)
                ->value("p{$slot}o", 0)->value("s{$slot}o", 0);
        }

        for ($slot = 0; $slot < Simulation::ObstacleSlots; $slot++) {
            $scene->value("o{$slot}x", -100)->value("o{$slot}y", Simulation::Ground)->value("o{$slot}o", 0);

            foreach (self::obstacleValues($slot, Obstacle::Cyclist, 1) as $name => $value) {
                $scene->value($name, $value);
            }
        }

        foreach ([...self::scoreValues(0), ...self::busValues(Bus::StartX, 1)] as $name => $value) {
            $scene->value($name, $value);
        }
    }

    /**
     * An obstacle facing right, its feet or wheels at its origin.
     */
    private function obstacle(Obstacle $obstacle, bool $nunoFace): Node
    {
        return match ($obstacle) {
            Obstacle::Cyclist => $this->cyclist(),
            Obstacle::Gull => $this->gull(),
            Obstacle::Nuno => $this->character('#E0464E', $nunoFace ? self::Nuno : null, '#4A3428', wild: true),
            Obstacle::Octocat => $this->octocat(),
        };
    }

    /**
     * Over everything but the score and the controls, all but clear, so any
     * finger that lands on the canvas is held by it. On iOS, a first finger
     * that lands on nothing makes the canvas ignore every other finger until
     * it lifts, which would leave an arrow pressed after this one held nothing.
     */
    private function catchAll(): Node
    {
        return Node::rect(0, 0, Layout::Width, Layout::Height, Paint::fill(self::Clear))
            ->id('catch')
            ->draggable(self::Scratch)->dragLimits(0, 0, 0, 0);
    }

    /**
     * The score, top middle, as an odometer: a wheel of digits for each place,
     * each turned to its digit by a value, so the score changes without
     * replacing anything (see scoreValues()).
     */
    private function score(): Node
    {
        [$x, $y, $width, $height] = Layout::ScorePill;
        [$cell, $tall] = Layout::DigitCell;
        $paint = Paint::fill(self::Ink);
        $top = $y + ($height - $tall) / 2;
        $wheels = [];

        for ($wheel = 0; $wheel < Layout::Digits; $wheel++) {
            $digits = [];

            for ($digit = 0; $digit <= 9; $digit++) {
                $digits[] = Node::paragraph((string) $digit, 0, $digit * $tall, $cell, Layout::DigitSize, $paint, $this->font(), 'center', maxLines: 1);
            }

            $wheels[] = Node::group([Node::group($digits)->bind('translateY', "d{$wheel}")])
                ->transform((Layout::Digits - 1 - $wheel) * $cell)
                ->clip(sprintf('M0 0 H%.1F V%.1F H0 Z', $cell, $tall))
                ->bind('opacity', "d{$wheel}o");
        }

        $wheels[] = Node::paragraph('-', 0, 0, $cell, Layout::DigitSize, $paint, $this->font(), 'center', maxLines: 1)
            ->bind('translateX', 'smx')->bind('opacity', 'sm');

        return Node::group([
            Node::rect($x, $y, $width, $height, Paint::fill(self::Panel), $height / 2),
            Node::group($wheels)->transform(0, $top)->bind('translateX', 'sx')->id('score'),
        ]);
    }

    /**
     * Quit, top left.
     */
    private function close(): Node
    {
        [$x, $y, $radius] = Layout::Close;
        $cross = Paint::stroke(self::Ink, 2.4)->cap('round');

        return Node::group([
            Node::circle($x, $y, $radius, Paint::fill(self::Panel)),
            Node::line($x - 6, $y - 6, $x + 6, $y + 6, $cross),
            Node::line($x + 6, $y - 6, $x - 6, $y + 6, $cross),
            $this->hitCircle('close', [$x, $y, $radius + 6], 'Quit', 'Back to the app'),
        ]);
    }

    /**
     * The rocker, bottom left, and the jump button, bottom right. Each lights
     * up while it's held. They hide while the results are up.
     */
    private function controls(): Node
    {
        [$lx, $ly, $lw, $lh] = Layout::RockerLeft;
        [$rx, , $rw] = Layout::RockerRight;
        [$jx, $jy, $jr] = Layout::Jump;
        $middle = $ly + $lh / 2;
        $glyph = Paint::stroke(self::Ink, 4)->cap('round')->join('round');
        $base = Paint::fill('#1F2430D9');
        $pressed = Paint::fill('#FFFFFF33');

        return Node::group([
            Node::rect($lx, $ly, $lw + $rw, $lh, $base->shadow(0, 4, 10, '#00000040'), $lh / 2),
            Node::rect($lx, $ly, $lw, $lh, $pressed, $lh / 2)->bind('opacity', 'lp'),
            Node::rect($rx, $ly, $rw, $lh, $pressed, $lh / 2)->bind('opacity', 'rp'),
            Node::line($rx, $ly + 16, $rx, $ly + $lh - 16, Paint::stroke('#FFFFFF2E', 1.5)),
            Node::path(sprintf('M%.1F %.1F L%.1F %.1F L%.1F %.1F', $lx + $lw / 2 + 6, $middle - 12, $lx + $lw / 2 - 6, $middle, $lx + $lw / 2 + 6, $middle + 12), $glyph),
            Node::path(sprintf('M%.1F %.1F L%.1F %.1F L%.1F %.1F', $rx + $rw / 2 - 6, $middle - 12, $rx + $rw / 2 + 6, $middle, $rx + $rw / 2 - 6, $middle + 12), $glyph),
            $this->arrow('left', Layout::RockerLeft, 'Walk left'),
            $this->arrow('right', Layout::RockerRight, 'Walk right'),

            Node::circle($jx, $jy, $jr, Paint::fill(self::Custard)->shadow(0, 4, 10, '#00000040')),
            Node::circle($jx, $jy, $jr, Paint::fill('#FFFFFF59'))->bind('opacity', 'jp'),
            Node::path(sprintf('M%.1F %.1F L%.1F %.1F L%.1F %.1F', $jx - 13, $jy + 6, $jx, $jy - 8, $jx + 13, $jy + 6), Paint::stroke('#1F2430', 5)->cap('round')->join('round')),
            $this->hitCircle('jump', [$jx, $jy, $jr + 6], 'Jump', 'Hold to jump higher'),
        ])->bind('opacity', 'cv');
    }

    /**
     * How to play, for the first seconds on the canvas's clock, then gone.
     */
    private function hint(): Node
    {
        [$x, $y, $width] = Layout::Hint;

        return Node::group([
            Node::rect($x, $y - 8, $width, 40, Paint::fill(self::Panel), 20),
            Node::paragraph('Hold an arrow to walk. Tap jump to hop, hold it to jump higher.', $x + 12, $y - 2, $width - 24, 11, Paint::fill(self::Ink), $this->font(), 'center', maxLines: 2),
        ])
            ->animate('opacity', Animation::sequence(
                Animation::between(1, 1, 5, repeat: 'once'),
                Animation::between(1, 0, 1, repeat: 'once'),
            ));
    }

    /**
     * A button drawn in the canvas: [x, y, width, height], a label, and a style.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     */
    private function button(string $id, array $rect, string $label, string $style): Node
    {
        [$x, $y, $width, $height] = $rect;
        [$fill, $ink] = match ($style) {
            'primary' => [Paint::fill(self::Custard), '#1F2430'],
            'secondary' => [Paint::fill('#F4F5F7'), '#1F2430'],
            default => [Paint::stroke('#FFFFFF4D', 1.5), self::Ink],
        };

        return Node::group([
            Node::rect($x, $y, $width, $height, $fill, $height / 2),
            Node::paragraph($label, $x, $y + ($height - 19) / 2, $width, 15, Paint::fill($ink), $this->font(), 'center', maxLines: 1),
            $this->hitRect($id, $rect, $label),
        ]);
    }

    /**
     * One half of the rocker. Both halves write the thumb's place along the
     * whole rocker, so a slide across it counts wherever it started.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     */
    private function arrow(string $id, array $rect, string $label): Node
    {
        return Node::rect($rect[0], $rect[1], $rect[2], $rect[3], Paint::fill(self::Clear))
            ->id($id)
            ->draggable(self::Thumb)->dragLimits(Layout::RockerLeft[0], Layout::RockerRight[0] + Layout::RockerRight[2], 0, 0)
            ->accessible($label, 'button', 'Hold to walk, slide to turn');
    }

    /**
     * An all but clear rectangle over a control that holds the finger, so a
     * touch on its glyph or label still counts.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     */
    private function hitRect(string $id, array $rect, string $label, string $hint = ''): Node
    {
        return Node::rect($rect[0], $rect[1], $rect[2], $rect[3], Paint::fill(self::Clear))
            ->id($id)
            ->draggable(self::Scratch)->dragLimits(0, 0, 0, 0)
            ->accessible($label, 'button', $hint);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $circle
     */
    private function hitCircle(string $id, array $circle, string $label, string $hint = ''): Node
    {
        return Node::circle($circle[0], $circle[1], $circle[2], Paint::fill(self::Clear))
            ->id($id)
            ->draggable(self::Scratch)->dragLimits(0, 0, 0, 0)
            ->accessible($label, 'button', $hint);
    }

    /**
     * Someone small on two legs, their feet at the origin, with a round head:
     * a face when there's one, or a drawn one. You wear your lane's colour.
     */
    private function character(string $shirt, ?string $face, string $hair, bool $wild = false): Node
    {
        $skin = '#E8B48A';
        $arm = Paint::stroke($skin, 4)->cap('round');
        $arms = $wild
            ? [
                Node::line(-8, -31, -15, -42, $arm)->animate('rotation', Animation::between(-12, 12, 0.3, 'easeInOut', 'mirror')),
                Node::line(8, -31, 15, -42, $arm)->animate('rotation', Animation::between(12, -12, 0.3, 'easeInOut', 'mirror')),
            ]
            : [Node::line(-8, -31, -13, -20, $arm), Node::line(8, -31, 13, -20, $arm)];
        $head = $face !== null
            ? [
                Node::circle(0, -45, 14.5, Paint::fill('#FFFFFF')),
                Node::image($face, -13, -58, 26, 26, 'cover')->clip(self::circle(0, -45, 13)),
            ]
            : [
                Node::circle(0, -45, 13, Paint::fill($skin)),
                Node::path('M-13 -46 C-13 -60 13 -60 13 -46 C8 -52 -4 -53 -13 -46 Z', Paint::fill($hair)),
                Node::circle(-4.5, -44, 1.6, Paint::fill('#2B2B33')),
                Node::circle(4.5, -44, 1.6, Paint::fill('#2B2B33')),
                Node::path('M-4 -38 Q0 -35 4 -38', Paint::stroke('#2B2B33', 1.4)->cap('round')),
            ];

        return Node::group([
            Node::oval(-12, -2, 24, 5, Paint::fill('#00000026')),
            Node::rect(-7, -16, 5, 16, Paint::fill('#33415C'), 2),
            Node::rect(2, -16, 5, 16, Paint::fill('#33415C'), 2),
            Node::rect(-9, -34, 18, 20, Paint::fill($shirt), 5),
            ...$arms,
            ...$head,
        ]);
    }

    private function cyclist(): Node
    {
        $tyre = Paint::stroke('#1F2430', 2.6);
        $spokes = fn (float $x): Node => Node::group([
            Node::line(-7, 0, 7, 0, Paint::stroke('#9AA3B5', 1)),
            Node::line(0, -7, 0, 7, Paint::stroke('#9AA3B5', 1)),
        ])->transform($x, -9)->animate('rotation', Animation::between(0, 360, 0.5));
        $frame = Paint::stroke('#D7263D', 2.6)->cap('round')->join('round');

        return Node::group([
            Node::oval(-24, -2, 48, 5, Paint::fill('#00000026')),
            $spokes(-14),
            $spokes(14),
            Node::circle(-14, -9, 9, $tyre),
            Node::circle(14, -9, 9, $tyre),
            Node::path('M-14 -9 L-4 -9 L8 -22 L-6 -22 Z M-4 -9 L-7 -26 M14 -9 L9 -26 L13 -28', $frame),
            Node::line(-10, -26, -3, -26, Paint::stroke('#1F2430', 3)->cap('round')),
            Node::path('M-7 -27 L1 -36 L12 -27', Paint::stroke('#2F6FD0', 4.5)->cap('round')->join('round')),
            Node::line(-5, -27, -2, -14, Paint::stroke('#1F2430', 3.5)->cap('round')),
            Node::circle(4, -40, 5, Paint::fill('#E8B48A')),
            Node::path('M-2 -41 C-2 -48 10 -48 10 -41 Z', Paint::fill('#F2C230')),
        ]);
    }

    /**
     * A Lisbon gaivota, flapping.
     */
    private function gull(): Node
    {
        $outline = Paint::stroke('#4B5363', 1.4)->join('round');

        return Node::group([
            Node::oval(-15, -16, 30, 12, Paint::fill('#F4F5F7')),
            Node::oval(-15, -16, 30, 12, $outline),
            Node::path('M15 -12 L22 -10 L15 -8 Z', Paint::fill('#F2A93B')),
            Node::circle(10, -12, 1.5, Paint::fill('#1F2430')),
            Node::path('M-14 -11 L-20 -15 L-14 -7 Z', Paint::fill('#4B5363')),
            Node::group([
                Node::path('M-8 -12 L4 -12 L-2 -30 Z', Paint::fill('#C9CED8')),
                Node::path('M-8 -12 L4 -12 L-2 -30 Z', $outline),
                Node::path('M-4.7 -22 L-0.7 -22 L-2 -30 Z', Paint::fill('#2B2B33')),
            ])->animate('scaleY', Animation::between(1, -0.7, 0.22, 'easeInOut', 'mirror')),
        ]);
    }

    /**
     * Not GitHub's octocat: a round cat with ears and wriggling tentacles,
     * drawn for this.
     */
    private function octocat(): Node
    {
        $body = '#2BB3A3';
        $tentacles = [];

        foreach ([-13, -6.5, 0, 6.5, 13] as $index => $x) {
            $tentacles[] = Node::path(sprintf('M%.1F -12 Q%.1F -4 %.1F 0', $x, $x - 4, $x + 1), Paint::stroke($body, 4.5)->cap('round'))
                ->animate('rotation', Animation::between(-10, 10, 0.35 + 0.05 * $index, 'easeInOut', 'mirror'));
        }

        return Node::group([
            Node::oval(-18, -2, 36, 5, Paint::fill('#00000026')),
            ...$tentacles,
            Node::path('M-16 -20 L-14 -34 L-6 -27 Z M16 -20 L14 -34 L6 -27 Z', Paint::fill($body)),
            Node::oval(-17, -30, 34, 22, Paint::fill($body)),
            Node::oval(-11, -24, 8, 9, Paint::fill('#FFFFFF')),
            Node::oval(3, -24, 8, 9, Paint::fill('#FFFFFF')),
            Node::circle(-6, -19, 2.2, Paint::fill('#1F2430')),
            Node::circle(8, -19, 2.2, Paint::fill('#1F2430')),
            Node::path('M-2 -14 Q1 -12 4 -14', Paint::stroke('#1F2430', 1.4)->cap('round')),
        ]);
    }

    /**
     * Behind the street, Lisbon over a day: the sky, the sun and moon,
     * clouds, rain, stars, planes and the rooftops with their lights (see
     * Sky). It keeps the sun and moon below the score.
     *
     * @return list<Node>
     */
    private function background(): array
    {
        return (new Sky(Layout::Width, Layout::Ground, top: Layout::ScorePill[1] + Layout::ScorePill[3]))->nodes();
    }

    /**
     * The street: the black and white waves of a calçada from the kerb to the
     * bottom of the screen, with the controls on it. None of it moves, so it's
     * drawn once and kept.
     */
    private function pavement(): Node
    {
        $ground = Layout::Ground;
        $nodes = [
            Node::rect(0, $ground, Layout::Width, Layout::Height - $ground, Paint::fill('#E9E2D3')),
        ];

        foreach ([22, 70, 118, 166] as $offset) {
            $waves = 'M';

            for ($step = 0; $step <= 36; $step++) {
                $waves .= sprintf('%s%.1F %.1F ', $step === 0 ? '' : 'L', $step * 10, $ground + $offset + sin($step / 2) * 6);
            }

            $nodes[] = Node::path($waves, Paint::stroke('#2E2E33', 4)->cap('round')->join('round'));
        }

        $nodes[] = Node::line(0, $ground, Layout::Width, $ground, Paint::stroke('#00000026', 1));

        return Node::group($nodes)->cache();
    }

    /**
     * The pavement, dimmed with the night (see DayCycle), under whoever's on
     * it. The rooftops behind dim with the sky, and the bus carries its own.
     */
    private function streetAtNight(): Node
    {
        return DayCycle::fade(Node::rect(0, Layout::Ground, Layout::Width, Layout::Height - Layout::Ground, Paint::fill(self::Night)), DayCycle::Night);
    }

    /**
     * The bus (see Bus), facing right with its wheels at its origin and its
     * pole trailing behind it. It flips to go left. Seats are empty until a
     * passenger pops up from the sill, and the dark of the night falls over
     * the passengers as well as the paintwork.
     */
    private function bus(): Node
    {
        $seats = [];

        foreach (self::Windows as $seat => [$x, $width]) {
            [$skin, $hair, $shirt] = self::Passengers[$seat];
            $seats[] = Node::group([
                Node::rect(-4.5, -2.5, 9, 2.5, Paint::fill($shirt), 1),
                Node::circle(0, -6.6, 3.4, Paint::fill($hair)),
                Node::circle(0, -5.4, 2.9, Paint::fill($skin)),
            ])->id("bus-seat{$seat}")->transform($x + $width / 2, -22)->bind('scaleX', "bs{$seat}")->bind('scaleY', "bs{$seat}");
        }

        $windows = array_map(fn (array $window): Node => Node::rect($window[0], -34, $window[1], 12, Paint::fill('#CFE6F2'), 2), self::Windows);

        return Node::group([
            Node::group([
                Node::group([
                    Node::line(-2, -40, -30, -58, Paint::stroke('#2B2B33', 1.5)),
                    Node::rect(-34, -40, 68, 34, Paint::fill('#F2C230'), 6),
                    Node::rect(-34, -14, 68, 8, Paint::fill('#E9E2D0')),
                    ...$windows,
                    Node::circle(32, -10, 2, Paint::fill('#FFF3B0')),
                    Node::circle(-20, -4, 4, Paint::fill('#2B2B33')),
                    Node::circle(20, -4, 4, Paint::fill('#2B2B33')),
                ])->cache(),
                ...$seats,
                DayCycle::fade(Node::path('M-28 -40 H28 Q34 -40 34 -34 V-6 H-34 V-34 Q-34 -40 -28 -40 Z', Paint::fill(self::Night)), DayCycle::Night),
            ])->bind('scaleX', 'bd'),
        ])->id('bus')->transform(0, Layout::Ground)->bind('translateX', 'bx');
    }

    /**
     * The font text is drawn in: the scene's, when it has one.
     */
    private function font(): string
    {
        return $this->font !== null ? self::Font : '';
    }

    private static function circle(float $x, float $y, float $radius): string
    {
        return sprintf('M%.1F %.1F a%.1F %.1F 0 1 0 %.1F 0 a%.1F %.1F 0 1 0 %.1F 0 Z', $x - $radius, $y, $radius, $radius, $radius * 2, $radius, $radius, -$radius * 2);
    }
}
