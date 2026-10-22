<?php

namespace Devnull\CommitRow\Elements;

use Devnull\CommitRow\ColorPair;
use Devnull\CommitRow\MenuItem;
use Devnull\CommitRow\PlatformIcon;
use Devnull\CommitRow\Run;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * The dev/null Log's commit row as one native element: what a Blade-to-native
 * compiler would emit for the feed branch of commit-row.blade.php, written by
 * hand so it can be measured on the phones against the same row drawn as ~33
 * EDGE nodes.
 *
 * It takes no Tailwind classes. Every value it draws is a typed prop of its
 * own (texts, sizes, light/dark colour pairs, paddings, flags, callbacks, a11y
 * labels), and the native view only draws the values it's given. Whatever
 * picks a value (muted, thread below, lane, continues run) stays in the
 * template that uses the element. Values the feed row never changes (the 64pt
 * gutter, the 44x36 targets) are props too, with the feed row's values as
 * defaults, so a template can change them without touching native code.
 *
 * The node only carries a layout of its own (full width), which the list it
 * sits in reads. The row's tap is the node's own press (`@press`), so the
 * row behaves like the EDGE pressable it replaces.
 *
 * Wire format: scalars go as they are, a colour pair as a two-string list
 * [light, dark], callbacks as `on_*` ids. The flat buffer only carries lists of
 * strings, so the body's runs and More's menu items go as parallel string
 * lists (`run_*`, `menu_*`), numbers written as strings and "" for "take the
 * paragraph's value". `paragraph_runs` says how many runs each paragraph has.
 */
class CommitRow extends Element
{
    protected string $type = 'commit_row';

    /**
     * The setters a Blade attribute may call, by their camel-case name
     * (`name-size` calls nameSize()).
     */
    private const array Setters = [
        'surface', 'gutter', 'contentMinHeight', 'contentPaddingTop', 'contentPaddingBottom', 'contentPaddingRight', 'contentGap',
        'hairline', 'hairlineWidth', 'hairlineColor',
        'name', 'nameSize', 'nameFont', 'nameColor', 'handle', 'time', 'separator', 'metaSize', 'metaFont', 'metaColor', 'headerGap',
        'replyingTo', 'replyingToPress', 'replyingToA11y', 'replyingToIcon', 'replyingToIconSize', 'replyingToSize', 'replyingToFont', 'replyingToColor', 'replyingToGap',
        'body', 'bodySize', 'bodyLineHeight', 'bodyFont', 'bodyColor', 'showMorePress', 'showMoreLabel', 'showMoreFont', 'showMoreColor',
        'actionsOffsetX', 'actionsOffsetY', 'actionMinWidth', 'actionHeight', 'actionPadding', 'iconSize', 'labelSize', 'countFont', 'actionColor', 'accentColor',
        'starPress', 'starIcon', 'starOn', 'starCount', 'starA11y', 'starGap',
        'pastel', 'pastelPress', 'pastelSrc', 'pastelSize', 'pastelCount', 'pastelA11y',
        'quotePress', 'quoteIcon', 'quoteStash', 'quoteA11y', 'quoteGap',
        'replyPress', 'replyIcon', 'replyLabel', 'replyStash', 'replyA11y', 'replyCentered', 'replyGap', 'stashDotSize', 'replyStashOffset',
        'repliesPress', 'repliesIcon', 'repliesLabel', 'repliesA11y', 'repliesIconSize', 'repliesGap',
        'moreIcon', 'moreA11y', 'menu',
        'railX', 'railWidth', 'laneColor', 'previousColor', 'previousHeight', 'tail', 'tailTop', 'tailBottom',
        'endDot', 'endDotBottom', 'endDotLeft', 'replyTail', 'replyTailTop', 'replyTailHeight', 'replyTailX', 'replyTailWidth',
        'node', 'nodeTop', 'nodeLeft', 'nodePress', 'nodeA11y',
        'avatarSize', 'avatarRing', 'avatarInner', 'avatarInitial', 'avatarInitialSize', 'avatarInitialFont', 'avatarInitialColor', 'avatarWash', 'avatarSrc', 'avatarFit',
        'dotSize', 'dotInner', 'dotRing',
    ];

    /**
     * The feed row's values, on the wire, worked out once.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $defaultProps = null;

    /**
     * What's been set, by wire key, before it's resolved for the wire.
     *
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * @var list<list<Run>>
     */
    private array $paragraphs = [];

    /**
     * @var list<MenuItem>
     */
    private array $menuItems = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        foreach ($attrs as $attribute => $value) {
            $setter = Str::camel((string) $attribute);

            if (in_array($setter, self::Setters, true)) {
                $this->{$setter}($value);
            }
        }
    }

    // ── The row ─────────────────────────────────────────

    /** The row's background. */
    public function surface(ColorPair $color): static
    {
        return $this->set('surface_color', $color);
    }

    /** The rail's gutter: the content column's left margin, in points. */
    public function gutter(float $points): static
    {
        return $this->set('gutter', $points);
    }

    public function contentMinHeight(float $points): static
    {
        return $this->set('content_min_height', $points);
    }

    public function contentPaddingTop(float $points): static
    {
        return $this->set('content_padding_top', $points);
    }

    /** Includes the hairline's width when there is one: a border draws inside the box. */
    public function contentPaddingBottom(float $points): static
    {
        return $this->set('content_padding_bottom', $points);
    }

    public function contentPaddingRight(float $points): static
    {
        return $this->set('content_padding_right', $points);
    }

    /** The gap between the header, the replying-to line, each paragraph, Show more and the actions. */
    public function contentGap(float $points): static
    {
        return $this->set('content_gap', $points);
    }

    /** Whether the content draws a hairline along its bottom edge. */
    public function hairline(bool $draws): static
    {
        return $this->set('hairline', $draws);
    }

    public function hairlineWidth(float $points): static
    {
        return $this->set('hairline_width', $points);
    }

    public function hairlineColor(ColorPair $color): static
    {
        return $this->set('hairline_color', $color);
    }

    // ── Header: name, handle, separator, time ───────────

    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    public function nameSize(float $points): static
    {
        return $this->set('name_size', $points);
    }

    /** A font token or name, as `font="…"` takes it. */
    public function nameFont(string $font): static
    {
        return $this->set('name_font', $font);
    }

    public function nameColor(ColorPair $color): static
    {
        return $this->set('name_color', $color);
    }

    public function handle(string $handle): static
    {
        return $this->set('handle', $handle);
    }

    public function time(string $time): static
    {
        return $this->set('time', $time);
    }

    public function separator(string $separator): static
    {
        return $this->set('separator', $separator);
    }

    /** The handle, separator and time's size. */
    public function metaSize(float $points): static
    {
        return $this->set('meta_size', $points);
    }

    /** The handle and time's font. The separator takes the theme's. */
    public function metaFont(string $font): static
    {
        return $this->set('meta_font', $font);
    }

    public function metaColor(ColorPair $color): static
    {
        return $this->set('meta_color', $color);
    }

    public function headerGap(float $points): static
    {
        return $this->set('header_gap', $points);
    }

    // ── Replying to ─────────────────────────────────────

    /** The line under the header ("replying to @ada"). Empty draws none. */
    public function replyingTo(string $text): static
    {
        return $this->set('replying_to', $text);
    }

    /** What a tap on the replying-to line calls. None leaves it plain text (a deleted parent). */
    public function replyingToPress(?string $method): static
    {
        return $this->set('on_replying_to', $method);
    }

    public function replyingToA11y(string $label): static
    {
        return $this->set('replying_to_a11y', $label);
    }

    public function replyingToIcon(PlatformIcon $icon): static
    {
        return $this->set('replying_to_icon', $icon);
    }

    public function replyingToIconSize(float $points): static
    {
        return $this->set('replying_to_icon_size', $points);
    }

    public function replyingToSize(float $points): static
    {
        return $this->set('replying_to_size', $points);
    }

    public function replyingToFont(string $font): static
    {
        return $this->set('replying_to_font', $font);
    }

    public function replyingToColor(ColorPair $color): static
    {
        return $this->set('replying_to_color', $color);
    }

    public function replyingToGap(float $points): static
    {
        return $this->set('replying_to_gap', $points);
    }

    // ── Body ────────────────────────────────────────────

    /**
     * The body's paragraphs, each a list of runs drawn as one wrapping text.
     *
     * @param  list<list<Run>>  $paragraphs
     */
    public function body(array $paragraphs): static
    {
        foreach ($paragraphs as $paragraph) {
            foreach ($paragraph as $run) {
                if (! $run instanceof Run) {
                    throw new InvalidArgumentException('A paragraph is a list of '.Run::class.'.');
                }
            }
        }

        $this->paragraphs = array_values(array_map(array_values(...), $paragraphs));

        return $this;
    }

    public function bodySize(float $points): static
    {
        return $this->set('body_size', $points);
    }

    /** A multiple of the size, as `leading-*` sends it. */
    public function bodyLineHeight(float $multiple): static
    {
        return $this->set('body_line_height', $multiple);
    }

    /** "" takes the theme's font. */
    public function bodyFont(string $font): static
    {
        return $this->set('body_font', $font);
    }

    public function bodyColor(ColorPair $color): static
    {
        return $this->set('body_color', $color);
    }

    /** What "Show more" under a folded body calls. None draws no Show more. */
    public function showMorePress(?string $method): static
    {
        return $this->set('on_show_more', $method);
    }

    public function showMoreLabel(string $label): static
    {
        return $this->set('show_more_label', $label);
    }

    public function showMoreFont(string $font): static
    {
        return $this->set('show_more_font', $font);
    }

    public function showMoreColor(ColorPair $color): static
    {
        return $this->set('show_more_color', $color);
    }

    // ── Actions ─────────────────────────────────────────

    /** How far the action row reaches left past the content, so each target's padding lines its icon up with the text. */
    public function actionsOffsetX(float $points): static
    {
        return $this->set('actions_offset_x', $points);
    }

    /** How far the action row reaches up and down past its slot, so 36pt targets keep the commit's rhythm. */
    public function actionsOffsetY(float $points): static
    {
        return $this->set('actions_offset_y', $points);
    }

    public function actionMinWidth(float $points): static
    {
        return $this->set('action_min_width', $points);
    }

    public function actionHeight(float $points): static
    {
        return $this->set('action_height', $points);
    }

    /** Each action's padding left and right. */
    public function actionPadding(float $points): static
    {
        return $this->set('action_padding', $points);
    }

    public function iconSize(float $points): static
    {
        return $this->set('icon_size', $points);
    }

    /** The star count's, the pastel count's and the Reply label's size. */
    public function labelSize(float $points): static
    {
        return $this->set('label_size', $points);
    }

    /** The star and pastel counts' font. The labels take the theme's. */
    public function countFont(string $font): static
    {
        return $this->set('count_font', $font);
    }

    /** The colour of the actions at rest: icons, counts, labels, stash dots. */
    public function actionColor(ColorPair $color): static
    {
        return $this->set('action_color', $color);
    }

    /** A starred star and its count, and the replies link. */
    public function accentColor(ColorPair $color): static
    {
        return $this->set('accent_color', $color);
    }

    /** What the star calls. None draws no star. */
    public function starPress(?string $method): static
    {
        return $this->set('on_star', $method);
    }

    public function starIcon(PlatformIcon $icon): static
    {
        return $this->set('star_icon', $icon);
    }

    /** Whether the star draws in the accent colour. */
    public function starOn(bool $on): static
    {
        return $this->set('star_on', $on);
    }

    /** "" draws no count. */
    public function starCount(string $count): static
    {
        return $this->set('star_count', $count);
    }

    public function starA11y(string $label): static
    {
        return $this->set('star_a11y', $label);
    }

    public function starGap(float $points): static
    {
        return $this->set('star_gap', $points);
    }

    /** "give" draws the pastel as a button, "count" as its count only, "" not at all. */
    public function pastel(string $mode): static
    {
        if (! in_array($mode, ['', 'give', 'count'], true)) {
            throw new InvalidArgumentException("The pastel is drawn as give, count or not at all, not [{$mode}].");
        }

        return $this->set('pastel', $mode);
    }

    public function pastelPress(?string $method): static
    {
        return $this->set('on_pastel', $method);
    }

    /** The pastel's image, as `<native:image :src>` takes it. */
    public function pastelSrc(string $src): static
    {
        return $this->set('pastel_src', $src);
    }

    public function pastelSize(float $points): static
    {
        return $this->set('pastel_size', $points);
    }

    public function pastelCount(string $count): static
    {
        return $this->set('pastel_count', $count);
    }

    public function pastelA11y(string $label): static
    {
        return $this->set('pastel_a11y', $label);
    }

    /** What Quote calls. None draws no Quote. */
    public function quotePress(?string $method): static
    {
        return $this->set('on_quote', $method);
    }

    public function quoteIcon(PlatformIcon $icon): static
    {
        return $this->set('quote_icon', $icon);
    }

    /** Whether Quote wears the dot for an unsent quote. */
    public function quoteStash(bool $stashed): static
    {
        return $this->set('quote_stash', $stashed);
    }

    public function quoteA11y(string $label): static
    {
        return $this->set('quote_a11y', $label);
    }

    public function quoteGap(float $points): static
    {
        return $this->set('quote_gap', $points);
    }

    /** What Reply calls. None draws no Reply. */
    public function replyPress(?string $method): static
    {
        return $this->set('on_reply', $method);
    }

    public function replyIcon(PlatformIcon $icon): static
    {
        return $this->set('reply_icon', $icon);
    }

    /** "" draws Reply as its icon only. */
    public function replyLabel(string $label): static
    {
        return $this->set('reply_label', $label);
    }

    /** Whether Reply wears the dot for an unsent reply. */
    public function replyStash(bool $stashed): static
    {
        return $this->set('reply_stash', $stashed);
    }

    public function replyA11y(string $label): static
    {
        return $this->set('reply_a11y', $label);
    }

    /** Whether Reply centres its content in its target (it does when it's only an icon). */
    public function replyCentered(bool $centered): static
    {
        return $this->set('reply_centered', $centered);
    }

    public function replyGap(float $points): static
    {
        return $this->set('reply_gap', $points);
    }

    public function stashDotSize(float $points): static
    {
        return $this->set('stash_dot_size', $points);
    }

    /** Reply's dot sits this far from its label or icon (negative pulls it in). */
    public function replyStashOffset(float $points): static
    {
        return $this->set('reply_stash_offset', $points);
    }

    /** What the replies link calls. None draws no replies link. */
    public function repliesPress(?string $method): static
    {
        return $this->set('on_replies', $method);
    }

    public function repliesIcon(PlatformIcon $icon): static
    {
        return $this->set('replies_icon', $icon);
    }

    public function repliesLabel(string $label): static
    {
        return $this->set('replies_label', $label);
    }

    public function repliesA11y(string $label): static
    {
        return $this->set('replies_a11y', $label);
    }

    public function repliesIconSize(float $points): static
    {
        return $this->set('replies_icon_size', $points);
    }

    public function repliesGap(float $points): static
    {
        return $this->set('replies_gap', $points);
    }

    public function moreIcon(PlatformIcon $icon): static
    {
        return $this->set('more_icon', $icon);
    }

    public function moreA11y(string $label): static
    {
        return $this->set('more_a11y', $label);
    }

    /**
     * More's menu, top to bottom.
     *
     * @param  list<MenuItem>  $items
     */
    public function menu(array $items): static
    {
        foreach ($items as $item) {
            if (! $item instanceof MenuItem) {
                throw new InvalidArgumentException('The menu is a list of '.MenuItem::class.'.');
            }
        }

        $this->menuItems = array_values($items);

        return $this;
    }

    // ── The rail ────────────────────────────────────────

    /** The x of the rail's 2pt segments. */
    public function railX(float $points): static
    {
        return $this->set('rail_x', $points);
    }

    public function railWidth(float $points): static
    {
        return $this->set('rail_width', $points);
    }

    /** This commit's lane: its node, the segment below it, its end dot and reply line. */
    public function laneColor(ColorPair $color): static
    {
        return $this->set('lane_color', $color);
    }

    /** The previous commit's lane, for the segment above the node. None draws no segment above. */
    public function previousColor(?ColorPair $color): static
    {
        return $this->set('previous_color', $color);
    }

    /** The segment above the node runs from the top this far down. */
    public function previousHeight(float $points): static
    {
        return $this->set('previous_height', $points);
    }

    /** Whether a segment runs on below the node. */
    public function tail(bool $draws): static
    {
        return $this->set('tail', $draws);
    }

    public function tailTop(float $points): static
    {
        return $this->set('tail_top', $points);
    }

    /** How far above the row's bottom the segment below stops. */
    public function tailBottom(float $points): static
    {
        return $this->set('tail_bottom', $points);
    }

    /** Whether the segment below ends in a lane dot. */
    public function endDot(bool $draws): static
    {
        return $this->set('end_dot', $draws);
    }

    public function endDotBottom(float $points): static
    {
        return $this->set('end_dot_bottom', $points);
    }

    public function endDotLeft(float $points): static
    {
        return $this->set('end_dot_left', $points);
    }

    /** Whether a dashed line in the lane runs down to the replies under the row. */
    public function replyTail(bool $draws): static
    {
        return $this->set('reply_tail', $draws);
    }

    /** Where the dashed line starts, running to the bottom. Ignored when it has a height. */
    public function replyTailTop(float $points): static
    {
        return $this->set('reply_tail_top', $points);
    }

    /** A height above 0 draws the dashed line as the row's last this-many points instead. */
    public function replyTailHeight(float $points): static
    {
        return $this->set('reply_tail_height', $points);
    }

    public function replyTailX(float $points): static
    {
        return $this->set('reply_tail_x', $points);
    }

    public function replyTailWidth(float $points): static
    {
        return $this->set('reply_tail_width', $points);
    }

    /** What sits on the rail: "avatar", or "dot" for a commit carrying on its author's run. */
    public function node(string $node): static
    {
        if (! in_array($node, ['avatar', 'dot'], true)) {
            throw new InvalidArgumentException("The rail's node is an avatar or a dot, not [{$node}].");
        }

        return $this->set('node', $node);
    }

    public function nodeTop(float $points): static
    {
        return $this->set('node_top', $points);
    }

    public function nodeLeft(float $points): static
    {
        return $this->set('node_left', $points);
    }

    /** What a tap on the node calls (the author's profile). */
    public function nodePress(?string $method): static
    {
        return $this->set('on_node', $method);
    }

    public function nodeA11y(string $label): static
    {
        return $this->set('node_a11y', $label);
    }

    /** The avatar's ring, outside to outside. */
    public function avatarSize(float $points): static
    {
        return $this->set('avatar_size', $points);
    }

    /** The ring's width. The gap inside it is as wide again, in the surface colour. */
    public function avatarRing(float $points): static
    {
        return $this->set('avatar_ring', $points);
    }

    /** The avatar's own size inside the ring and gap. */
    public function avatarInner(float $points): static
    {
        return $this->set('avatar_inner', $points);
    }

    /** The letter drawn while the picture loads, or when there's none. */
    public function avatarInitial(string $initial): static
    {
        return $this->set('avatar_initial', $initial);
    }

    public function avatarInitialSize(float $points): static
    {
        return $this->set('avatar_initial_size', $points);
    }

    public function avatarInitialFont(string $font): static
    {
        return $this->set('avatar_initial_font', $font);
    }

    public function avatarInitialColor(ColorPair $color): static
    {
        return $this->set('avatar_initial_color', $color);
    }

    /** The disc behind the initial and the picture. */
    public function avatarWash(ColorPair $color): static
    {
        return $this->set('avatar_wash', $color);
    }

    /** The picture, as `<native:image :src>` takes it. None draws the initial only. */
    public function avatarSrc(?string $src): static
    {
        return $this->set('avatar_src', $src);
    }

    /** As `<native:image :fit>` takes it. */
    public function avatarFit(int $fit): static
    {
        return $this->set('avatar_fit', $fit);
    }

    public function dotSize(float $points): static
    {
        return $this->set('dot_size', $points);
    }

    /** The dot's centre. The ring round it is the gap between the two, in the surface colour. */
    public function dotInner(float $points): static
    {
        return $this->set('dot_inner', $points);
    }

    public function dotRing(float $points): static
    {
        return $this->set('dot_ring', $points);
    }

    // ── Wire ────────────────────────────────────────────

    protected function layoutDefaults(): array
    {
        // The list reads a row's width off its node, as it did the pressable's `w-full`.
        return ['width' => 'fill'];
    }

    /**
     * The feed row's values, for anything the template doesn't set.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return self::$defaultProps ??= self::wire([
            'surface_color' => ColorPair::theme('surface'),
            'gutter' => 64.0,
            'content_min_height' => 64.0,
            'content_padding_top' => 12.0,
            'content_padding_bottom' => 13.0,
            'content_padding_right' => 16.0,
            'content_gap' => 4.0,
            'hairline' => true,
            'hairline_width' => 1.0,
            'hairline_color' => ColorPair::theme('hairline'),
            'name_size' => 15.0,
            'name_font' => 'semibold',
            'name_color' => ColorPair::theme('ink'),
            'separator' => '·',
            'meta_size' => 13.0,
            'meta_font' => 'mono',
            'meta_color' => ColorPair::theme('ink-muted'),
            'header_gap' => 6.0,
            'replying_to_icon_size' => 12.0,
            'replying_to_size' => 12.0,
            'replying_to_font' => 'mono',
            'replying_to_color' => ColorPair::theme('ink-muted'),
            'replying_to_gap' => 4.0,
            'body_size' => 15.0,
            'body_line_height' => 1.625,
            'body_font' => '',
            'body_color' => ColorPair::theme('ink'),
            'show_more_label' => 'Show more',
            'show_more_font' => 'medium',
            'show_more_color' => ColorPair::theme('primary'),
            'actions_offset_x' => -8.0,
            'actions_offset_y' => -6.0,
            'action_min_width' => 44.0,
            'action_height' => 36.0,
            'action_padding' => 8.0,
            'icon_size' => 16.0,
            'label_size' => 13.0,
            'count_font' => 'mono',
            'action_color' => ColorPair::theme('ink-muted'),
            'accent_color' => ColorPair::theme('primary'),
            'star_on' => false,
            'star_count' => '',
            'star_gap' => 4.0,
            'pastel' => '',
            'pastel_size' => 20.0,
            'quote_stash' => false,
            'quote_a11y' => 'Quote',
            'quote_gap' => 3.0,
            'reply_label' => 'Reply',
            'reply_stash' => false,
            'reply_a11y' => 'Reply',
            'reply_centered' => false,
            'reply_gap' => 6.0,
            'stash_dot_size' => 5.0,
            'reply_stash_offset' => -3.0,
            'replies_icon_size' => 14.0,
            'replies_gap' => 4.0,
            'more_a11y' => 'More',
            'rail_x' => 31.0,
            'rail_width' => 2.0,
            'previous_height' => 32.0,
            'tail' => false,
            'tail_top' => 32.0,
            'tail_bottom' => 0.0,
            'end_dot' => false,
            'end_dot_bottom' => 11.0,
            'end_dot_left' => 23.0,
            'reply_tail' => false,
            'reply_tail_top' => 32.0,
            'reply_tail_height' => 0.0,
            'reply_tail_x' => 31.5,
            'reply_tail_width' => 1.0,
            'node' => 'avatar',
            'node_top' => 8.0,
            'node_left' => 8.0,
            'avatar_size' => 48.0,
            'avatar_ring' => 2.0,
            'avatar_inner' => 40.0,
            'avatar_initial_size' => 16.0,
            'avatar_initial_font' => 'semibold',
            'avatar_initial_color' => ColorPair::theme('ink-muted'),
            'avatar_wash' => ColorPair::theme('wash'),
            'avatar_fit' => 2,
            'dot_size' => 18.0,
            'dot_inner' => 14.0,
            'dot_ring' => 3.0,
        ]);
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = self::wire($this->values, $registry);

        foreach (['replying_to_icon', 'star_icon', 'quote_icon', 'reply_icon', 'replies_icon', 'more_icon'] as $key) {
            if (isset($props[$key]) && is_array($props[$key])) {
                $props[$key.'_variant'] = $props[$key]['variant'];
                $props[$key] = $props[$key]['name'];
            }
        }

        return $props + $this->bodyProps($registry) + $this->menuProps($registry);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function wire(array $values, ?CallbackRegistry $registry = null): array
    {
        $props = [];

        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'on_')) {
                // Callbacks are ids. None is 0, which the renderer reads as "not drawn".
                $props[$key] = $value === null || $registry === null ? 0 : $registry->register($value);

                continue;
            }

            if ($value === null) {
                continue;
            }

            $props[$key] = match (true) {
                $value instanceof ColorPair => $value->toWire(),
                $value instanceof PlatformIcon => $value->resolve(),
                default => $value,
            };
        }

        return $props;
    }

    private function set(string $key, mixed $value): static
    {
        $this->values[$key] = $value;

        return $this;
    }

    /**
     * The body's runs as parallel string lists.
     *
     * @return array<string, list<string>>
     */
    private function bodyProps(CallbackRegistry $registry): array
    {
        $props = [
            'paragraph_runs' => [], 'run_text' => [], 'run_font' => [], 'run_weight' => [], 'run_size' => [],
            'run_color' => [], 'run_dark_color' => [], 'run_bg' => [], 'run_dark_bg' => [], 'run_press' => [], 'run_tracking' => [],
        ];

        foreach ($this->paragraphs as $paragraph) {
            $props['paragraph_runs'][] = (string) count($paragraph);

            foreach ($paragraph as $run) {
                $props['run_text'][] = $run->text;
                $props['run_font'][] = $run->font ?? '';
                $props['run_weight'][] = $run->weight === null ? '' : (string) $run->weight;
                $props['run_size'][] = $run->size === null ? '' : self::number($run->size);
                $props['run_color'][] = $run->color?->light ?? '';
                $props['run_dark_color'][] = $run->color?->dark ?? '';
                $props['run_bg'][] = $run->background?->light ?? '';
                $props['run_dark_bg'][] = $run->background?->dark ?? '';
                $props['run_press'][] = $run->press === null ? '' : (string) $registry->register($run->press);
                $props['run_tracking'][] = $run->tracking === null ? '' : self::number($run->tracking);
            }
        }

        return $props;
    }

    /**
     * More's items as parallel string lists. A kind is "", "divider" or "destructive".
     *
     * @return array<string, list<string>>
     */
    private function menuProps(CallbackRegistry $registry): array
    {
        $props = ['menu_id' => [], 'menu_label' => [], 'menu_icon' => [], 'menu_icon_variant' => [], 'menu_press' => [], 'menu_kind' => []];

        foreach ($this->menuItems as $item) {
            $icon = $item->icon?->resolve();
            $props['menu_id'][] = $item->id;
            $props['menu_label'][] = $item->label;
            $props['menu_icon'][] = $icon['name'] ?? '';
            $props['menu_icon_variant'][] = $icon['variant'] ?? '';
            $props['menu_press'][] = $item->press === null ? '' : (string) $registry->register($item->press);
            $props['menu_kind'][] = $item->divider ? 'divider' : ($item->destructive ? 'destructive' : '');
        }

        return $props;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
