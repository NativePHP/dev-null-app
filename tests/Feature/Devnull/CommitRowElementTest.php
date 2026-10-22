<?php

use App\Icons\Lucide;
use Devnull\CommitRow\ColorPair;
use Devnull\CommitRow\Elements\CommitRow;
use Devnull\CommitRow\MenuItem;
use Devnull\CommitRow\PlatformIcon;
use Devnull\CommitRow\Run;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Platform;

afterEach(fn () => Platform::set(null));

/**
 * The node a commit_row element sends.
 *
 * @return array<string, mixed>
 */
function commitRowNode(CommitRow $row, ?CallbackRegistry $registry = null): array
{
    return $row->toArray($registry ?? new CallbackRegistry);
}

function themePair(string $token): array
{
    return [strtoupper(config("native-ui.theme.light.{$token}")), strtoupper(config("native-ui.theme.dark.{$token}"))];
}

it('sends the feed row\'s values for anything the template leaves out', function () {
    $node = commitRowNode(CommitRow::make()->name('Ada'));

    expect($node['type'])->toBe('commit_row')
        ->and($node['layout'])->toBe(['width' => 'fill'])
        ->and($node)->not->toHaveKey('style')
        ->and($node['props'])->toMatchArray([
            'name' => 'Ada',
            'surface_color' => themePair('surface'),
            'name_color' => themePair('ink'),
            'meta_color' => themePair('ink-muted'),
            'hairline_color' => themePair('hairline'),
            'gutter' => 64.0,
            'content_min_height' => 64.0,
            'content_padding_top' => 12.0,
            'content_padding_bottom' => 13.0,
            'name_size' => 15.0,
            'name_font' => 'semibold',
            'meta_font' => 'mono',
            'separator' => '·',
            'body_line_height' => 1.625,
            'action_min_width' => 44.0,
            'action_height' => 36.0,
            'node' => 'avatar',
            'avatar_size' => 48.0,
        ]);
});

it('sends a colour pair as its light and dark hex, in wire order', function () {
    expect(ColorPair::theme('primary')->toWire())->toBe(themePair('primary'))
        ->and(ColorPair::of('#3b6fe0')->toWire())->toBe(['#3B6FE0', '#3B6FE0'])
        ->and(ColorPair::of('#fff', '#000')->toWire())->toBe(['#FFFFFF', '#000000'])
        // CSS #RRGGBBAA in, #AARRGGBB out, as TailwindParser sends alpha.
        ->and(ColorPair::of('#3B6FE080')->light)->toBe('#803B6FE0')
        ->and(ColorPair::of('red-500')->light)->toStartWith('#');
});

it('refuses a colour it cannot draw', function (Closure $make) {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'not a colour' => fn () => ColorPair::of('not-a-colour'),
    'not a theme token' => fn () => ColorPair::theme('nope'),
]);

it('sends each press as a callback id, and 0 for one it does not draw', function () {
    $registry = new CallbackRegistry;
    $props = commitRowNode(CommitRow::make()->starPress("toggleStar('c1')")->quotePress(null), $registry)['props'];

    expect($props['on_star'])->toBe($registry->register("toggleStar('c1')"))
        ->and($props['on_quote'])->toBe(0)
        ->and($props)->not->toHaveKey('on_reply');
});

it('sends the body as parallel string lists, with "" for the paragraph\'s own value', function () {
    $registry = new CallbackRegistry;
    $primary = ColorPair::theme('primary');
    $wash = ColorPair::theme('wash');

    $props = commitRowNode(CommitRow::make()->body([
        [new Run('Hi '), new Run('@ada', font: 'semibold', weight: 5, color: $primary, press: "openProfile('ada')")],
        [new Run('code', font: 'mono', size: 13, background: $wash), new Run('/', tracking: -0.025)],
    ]), $registry)['props'];

    expect($props['paragraph_runs'])->toBe(['2', '2'])
        ->and($props['run_text'])->toBe(['Hi ', '@ada', 'code', '/'])
        ->and($props['run_font'])->toBe(['', 'semibold', 'mono', ''])
        ->and($props['run_weight'])->toBe(['', '5', '', ''])
        ->and($props['run_size'])->toBe(['', '', '13', ''])
        ->and($props['run_color'])->toBe(['', $primary->light, '', ''])
        ->and($props['run_dark_color'])->toBe(['', $primary->dark, '', ''])
        ->and($props['run_bg'])->toBe(['', '', $wash->light, ''])
        ->and($props['run_dark_bg'])->toBe(['', '', $wash->dark, ''])
        ->and($props['run_press'])->toBe(['', (string) $registry->register("openProfile('ada')"), '', ''])
        ->and($props['run_tracking'])->toBe(['', '', '', '-0.025']);
});

it('sends More\'s items as parallel string lists, each icon named for the platform', function (string $platform, string $linkIcon) {
    Platform::set($platform);
    $registry = new CallbackRegistry;

    $props = commitRowNode(CommitRow::make()->menu([
        new MenuItem('share', 'Share link', new PlatformIcon(Lucide::Link->ios(), Lucide::Link->android()), "shareCommit('c1')"),
        MenuItem::divider(),
        new MenuItem('delete', 'Delete commit', null, "confirmDelete('c1')", destructive: true),
    ]), $registry)['props'];

    expect($props['menu_id'])->toBe(['share', 'divider', 'delete'])
        ->and($props['menu_label'])->toBe(['Share link', '', 'Delete commit'])
        ->and($props['menu_icon'])->toBe([$linkIcon, '', ''])
        ->and($props['menu_kind'])->toBe(['', 'divider', 'destructive'])
        ->and($props['menu_press'])->toBe([(string) $registry->register("shareCommit('c1')"), '', (string) $registry->register("confirmDelete('c1')")]);
})->with([
    'iOS' => ['ios', 'custom:link'],
    'Android' => ['android', 'custom:link'],
]);

it('names an icon for the platform, with Android\'s Material variant', function () {
    $row = fn () => CommitRow::make()->repliesIcon(new PlatformIcon(Lucide::ChevronRight->ios(), Lucide::ChevronRight->android()));

    Platform::set('ios');
    $ios = commitRowNode($row())['props'];
    Platform::set('android');
    $android = commitRowNode($row())['props'];

    expect([$ios['replies_icon'], $ios['replies_icon_variant']])->toBe(['chevron.right', ''])
        ->and([$android['replies_icon'], $android['replies_icon_variant']])->toBe(['chevron_right', 'outlined']);
});

it('takes Blade attributes by their kebab-case names', function () {
    $row = CommitRow::make();
    $row->applyAttributes([
        'name-size' => '14',
        'hairline' => false,
        'star-press' => "toggleStar('c1')",
        'name-color' => ColorPair::theme('ink-muted'),
        'previous-color' => null,
        'not-a-prop' => 'ignored',
    ]);

    $props = commitRowNode($row)['props'];

    expect($props['name_size'])->toBe(14.0)
        ->and($props['hairline'])->toBeFalse()
        ->and($props['on_star'])->toBeGreaterThan(0)
        ->and($props['name_color'])->toBe(themePair('ink-muted'))
        ->and($props)->not->toHaveKey('previous_color')
        ->and($props)->not->toHaveKey('not_a_prop');
});

it('refuses values the row cannot draw', function (Closure $set) {
    expect(fn () => $set(CommitRow::make()))->toThrow(InvalidArgumentException::class);
})->with([
    'a pastel mode' => fn (CommitRow $row) => $row->pastel('maybe'),
    'a rail node' => fn (CommitRow $row) => $row->node('star'),
    'a paragraph of strings' => fn (CommitRow $row) => $row->body([['text']]),
    'a menu of strings' => fn (CommitRow $row) => $row->menu(['Share']),
]);

it('stays inside the flat buffer\'s 255 props with every value set', function () {
    $icon = new PlatformIcon(Lucide::Star->ios(), Lucide::Star->android());
    $row = CommitRow::make()
        ->previousColor(ColorPair::theme('lane-1'))
        ->replyingTo('replying to @ada')->replyingToPress("openCommit('p')")->replyingToA11y('Replying to @ada')->replyingToIcon($icon)
        ->body([[new Run('a')]])->showMorePress("showMore('c1')")
        ->starPress('a()')->starIcon($icon)->starA11y('Star commit')
        ->pastel('give')->pastelPress('b()')->pastelSrc('images/p.png')->pastelCount('2')->pastelA11y('Give a pastel')
        ->quotePress('c()')->quoteIcon($icon)->replyPress('d()')->replyIcon($icon)->repliesPress('e()')->repliesIcon($icon)
        ->repliesLabel('2 replies')->repliesA11y('2 replies')->moreIcon($icon)->menu([MenuItem::divider()])
        ->laneColor(ColorPair::theme('lane-2'))->nodePress('f()')->nodeA11y("Ada's profile")
        ->avatarInitial('A')->avatarSrc('https://example.test/a.png')
        ->name('Ada')->handle('@ada')->time('5m');

    expect(count(commitRowNode($row)['props']))->toBeLessThan(255);
});
