package com.devnull.plugins.commit_row.ui

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawWithCache
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.layout.Measurable
import androidx.compose.ui.layout.MeasurePolicy
import androidx.compose.ui.layout.MeasureResult
import androidx.compose.ui.layout.MeasureScope
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.LinkAnnotation
import androidx.compose.ui.text.PlatformTextStyle
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextLinkStyles
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.LineHeightStyle
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withLink
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.IntRect
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.nativephp.mobile.ui.MaterialIcon
import com.nativephp.mobile.ui.nativerender.ColorParser
import com.nativephp.mobile.ui.nativerender.GenericProps
import com.nativephp.mobile.ui.nativerender.KeyboardFocusPolicy
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.ui.nativerender.NativeUIBridge
import com.nativephp.mobile.ui.nativerender.NativeUINode
import com.nativephp.mobile.ui.nativerender.NodeBorder
import com.nativephp.mobile.ui.nativerender.NodeStyle
import com.nativephp.mobile.ui.nativerender.argbToComposeColor
import com.nativephp.mobile.ui.nativerender.nodeBorder
import com.nativephp.plugins.native_ui.ui.ExpressiveMenu
import com.nativephp.plugins.native_ui.ui.ImageRenderer
import com.nativephp.plugins.native_ui.ui.NativeUIFontResolver
import com.nativephp.plugins.native_ui.ui.nuiThemeDefaultFontFamily
import com.nativephp.plugins.native_ui.ui.renderAttachedMenuItem
import kotlin.math.max
import kotlin.math.roundToInt

// The dev/null Log's commit row, drawn from one `commit_row` node's typed props
// (devnull/commit-row's CommitRow element) instead of the ~33 EDGE nodes the
// Blade row publishes.
//
// It's what a Blade-to-Compose compiler would emit for that template, written by
// hand: the Rows, Columns and Boxes the Android EDGE renderers build for the
// Blade row's nodes, each with the modifiers its node gets there, in the same
// order, and the same text, icon, image and menu calls, so it draws the same
// pixels. What it leaves out is EDGE's per-node machinery: a NodeView per node,
// a renderer looked up by type, buildChildModifier reading every child's layout,
// and the animation, style, gesture and layout passes on every node whether it
// uses them or not.
//
// ComposeFlexLayout composes its children through NodeView, so unlike iOS's
// FlexContainer it can't lay out views of ours. Each flex container here is the
// Row or Column FlexRow/FlexColumn would build, and each child carries what
// buildChildModifier would give it. That keeps Android's own quirks: flex-shrink
// is ignored, a negative margin only offsets the drawing, and `min-h` applies
// outside the padding.

object CommitRowRenderer {
    @Composable
    fun Render(node: NativeUINode, modifier: Modifier) {
        val row = remember(node) { CommitRowProps(node) }
        CommitRowView(row, isSystemInDarkTheme(), modifier)
    }
}

// MARK: - Props

/** A colour pair off the wire: a list of two hex strings, [light, dark]. */
@Immutable
private class CommitRowColor(list: List<String>) {
    val lightArgb = if (list.isNotEmpty()) ColorParser.parse(list[0], 0) else 0
    val darkArgb = if (list.size > 1) ColorParser.parse(list[1], 0) else 0
    private val light = argbToComposeColor(lightArgb)
    private val dark = argbToComposeColor(darkArgb)

    /** Dark mode takes the dark value when there is one, as NodeModifiers and TextRenderer resolve `dark_*`. */
    fun argb(isDark: Boolean): Int = if (isDark && darkArgb != 0) darkArgb else lightArgb

    fun color(isDark: Boolean): Color = if (isDark && darkArgb != 0) dark else light
}

/** One run of a paragraph, with "" or 0 meaning "the paragraph's". */
private class CommitRowRun(
    val text: String,
    val font: String,
    val weight: Int,
    val size: Float,
    val color: Int,
    val darkColor: Int,
    val background: Int,
    val darkBackground: Int,
    val press: Int,
    val tracking: Float,
)

/** Everything one row draws, decoded once per node. */
@Immutable
private class CommitRowProps(node: NativeUINode) {
    private val p = node.props

    val id = node.id

    val surface = color("surface_color")
    val gutter = p.getFloat("gutter")
    val contentMinHeight = p.getFloat("content_min_height")
    val contentPaddingTop = p.getFloat("content_padding_top")
    val contentPaddingBottom = p.getFloat("content_padding_bottom")
    val contentPaddingRight = p.getFloat("content_padding_right")
    val contentArrangement = vertical(p.getFloat("content_gap"))
    val hairline: NodeBorder? = p.getFloat("hairline_width").let { width ->
        if (p.getBool("hairline") && width > 0f) NodeBorder.from(borderSides(0, bottom = width), width, 0f) else null
    }
    val hairlineColor = color("hairline_color")

    val name = p.getString("name")
    val nameSize = p.getFloat("name_size")
    val nameFont = p.getString("name_font")
    val nameColor = color("name_color")
    val handle = p.getString("handle")
    val time = p.getString("time")
    val separator = p.getString("separator")
    val metaSize = p.getFloat("meta_size")
    val metaFont = p.getString("meta_font")
    val metaColor = color("meta_color")
    val headerArrangement = horizontal(p.getFloat("header_gap"), centered = false)

    val replyingTo = p.getString("replying_to")
    val replyingToPress = p.getCallbackId("on_replying_to")
    val replyingToA11y = p.getString("replying_to_a11y")
    val replyingToIcon = p.getString("replying_to_icon")
    val replyingToIconSize = p.getFloat("replying_to_icon_size")
    val replyingToSize = p.getFloat("replying_to_size")
    val replyingToFont = p.getString("replying_to_font")
    val replyingToColor = color("replying_to_color")
    val replyingToArrangement = horizontal(p.getFloat("replying_to_gap"), centered = false)

    val paragraphs = decodeParagraphs()
    val bodySize = p.getFloat("body_size")
    val bodyFont = p.getString("body_font")
    val bodyColor = color("body_color")

    // TextRenderer's composed path: the leading is a multiple of the paragraph's size.
    val bodyStyle = TextStyle(
        lineHeight = p.getFloat("body_line_height").let { if (it > 0f) (it * bodySize).sp else TextUnit.Unspecified },
        platformStyle = NoFontPadding,
        lineHeightStyle = TrimmedLineHeight,
    )
    val showMorePress = p.getCallbackId("on_show_more")
    val showMoreLabel = p.getString("show_more_label")
    val showMoreFont = p.getString("show_more_font")
    val showMoreColor = color("show_more_color")

    // On Android the equal negative margins above and below net to no offset, so only the x reaches past the content.
    val actionsModifier = Modifier
        .edgeMargin(p.getFloat("actions_offset_y"), 0f, p.getFloat("actions_offset_y"), p.getFloat("actions_offset_x"))
        .fillMaxWidth()
    val actionMinWidth = p.getFloat("action_min_width")
    val actionHeight = p.getFloat("action_height")
    val actionPadding = p.getFloat("action_padding")
    val iconSize = p.getFloat("icon_size")
    val labelSize = p.getFloat("label_size")
    val countFont = p.getString("count_font")
    val actionColor = color("action_color")
    val accentColor = color("accent_color")

    val starPress = p.getCallbackId("on_star")
    val starIcon = p.getString("star_icon")
    val starOn = p.getBool("star_on")
    val starCount = p.getString("star_count")
    val starA11y = p.getString("star_a11y")
    val starArrangement = horizontal(p.getFloat("star_gap"), centered = false)

    val pastelPress = p.getCallbackId("on_pastel")
    val pastelSize = p.getFloat("pastel_size")
    val pastelCount = p.getString("pastel_count")
    val pastelA11y = p.getString("pastel_a11y")
    val pastelImage: NativeUINode? = p.getString("pastel_src").let { src ->
        if (src.isEmpty() || p.getString("pastel").isEmpty()) null else image(src, 1, NodeStyle.EMPTY)
    }

    val quotePress = p.getCallbackId("on_quote")
    val quoteIcon = p.getString("quote_icon")
    val quoteStash = p.getBool("quote_stash")
    val quoteA11y = p.getString("quote_a11y")
    val quoteArrangement = horizontal(p.getFloat("quote_gap"), centered = true)

    val replyPress = p.getCallbackId("on_reply")
    val replyIcon = p.getString("reply_icon")
    val replyLabel = p.getString("reply_label")
    val replyStash = p.getBool("reply_stash")
    val replyA11y = p.getString("reply_a11y")
    val replyArrangement = horizontal(p.getFloat("reply_gap"), centered = p.getBool("reply_centered"))
    val stashDotSize = p.getFloat("stash_dot_size")
    val replyStashOffset = p.getFloat("reply_stash_offset")

    val repliesPress = p.getCallbackId("on_replies")
    val repliesIcon = p.getString("replies_icon")
    val repliesLabel = p.getString("replies_label")
    val repliesA11y = p.getString("replies_a11y")
    val repliesIconSize = p.getFloat("replies_icon_size")
    val repliesArrangement = horizontal(p.getFloat("replies_gap"), centered = false)

    val moreIcon = p.getString("more_icon")
    val moreA11y = p.getString("more_a11y")
    val menu = decodeMenu()

    val railWidth = p.getFloat("rail_width")
    val laneColor = color("lane_color")
    val previousColor: CommitRowColor? = if (p.has("previous_color")) color("previous_color") else null
    val previousHeight = p.getFloat("previous_height")
    val previousInset = Inset(top = 0f, left = inset(p.getFloat("rail_x")))
    val tail = p.getBool("tail")
    val tailInset = Inset(top = inset(p.getFloat("tail_top")), bottom = p.getFloat("tail_bottom"), left = inset(p.getFloat("rail_x")))
    val endDot = p.getBool("end_dot")
    val endDotInset = Inset(bottom = inset(p.getFloat("end_dot_bottom")), left = inset(p.getFloat("end_dot_left")))
    val replyTail = p.getBool("reply_tail")
    val replyTailWidth = p.getFloat("reply_tail_width")
    val replyTailHeight = p.getFloat("reply_tail_height")
    val replyTailInset = if (replyTailHeight > 0f) {
        Inset(bottom = 0f, left = inset(p.getFloat("reply_tail_x")))
    } else {
        Inset(top = inset(p.getFloat("reply_tail_top")), bottom = 0f, left = inset(p.getFloat("reply_tail_x")))
    }
    val replyTailBorder: NodeBorder? = NodeBorder.from(borderSides(1, left = replyTailWidth), replyTailWidth, 0f)

    val nodeIsDot = p.getString("node") == "dot"
    val nodeInset = Inset(top = inset(p.getFloat("node_top")), left = inset(p.getFloat("node_left")))
    val nodePress = p.getCallbackId("on_node")
    val nodeA11y = p.getString("node_a11y")
    val avatarSize = p.getFloat("avatar_size")
    val avatarRing = p.getFloat("avatar_ring")
    val avatarInner = p.getFloat("avatar_inner")
    val avatarInitial = p.getString("avatar_initial")
    val avatarInitialSize = p.getFloat("avatar_initial_size")
    val avatarInitialFont = p.getString("avatar_initial_font")
    val avatarInitialColor = color("avatar_initial_color")
    val avatarWash = color("avatar_wash")
    val avatarImage: NativeUINode? = p.getString("avatar_src").let { src ->
        if (src.isEmpty()) null else image(src, p.getInt("avatar_fit", 1), NodeStyle.EMPTY.copy(borderRadius = 9999f))
    }
    val dotSize = p.getFloat("dot_size")
    val dotInner = p.getFloat("dot_inner")
    val dotRing = p.getFloat("dot_ring")

    private fun color(key: String): CommitRowColor = CommitRowColor(p.getStringList(key))

    /** A node for ImageRenderer, so the avatar and pastel load, cache and clip as `<native:image>` does. */
    private fun image(src: String, fit: Int, style: NodeStyle): NativeUINode = NativeUINode(
        id = 0, type = "image", layout = null, style = style,
        props = GenericProps(mapOf("src" to src, "fit" to fit)),
        onPress = 0, onLongPress = 0, children = emptyList(),
    )

    /** The body's runs, from the parallel `run_*` lists, split into paragraphs by `paragraph_runs`. */
    private fun decodeParagraphs(): List<List<CommitRowRun>> {
        val texts = p.getStringList("run_text")
        val fonts = p.getStringList("run_font")
        val weights = p.getStringList("run_weight")
        val sizes = p.getStringList("run_size")
        val colors = p.getStringList("run_color")
        val darkColors = p.getStringList("run_dark_color")
        val backgrounds = p.getStringList("run_bg")
        val darkBackgrounds = p.getStringList("run_dark_bg")
        val presses = p.getStringList("run_press")
        val trackings = p.getStringList("run_tracking")
        fun at(list: List<String>, i: Int) = list.getOrElse(i) { "" }
        fun hex(s: String) = if (s.isEmpty()) 0 else ColorParser.parse(s, 0)

        var next = 0
        return p.getStringList("paragraph_runs").map { count ->
            val runs = ArrayList<CommitRowRun>()
            repeat(count.toIntOrNull() ?: 0) {
                if (next < texts.size) {
                    val i = next++
                    runs.add(
                        CommitRowRun(
                            text = texts[i], font = at(fonts, i), weight = at(weights, i).toIntOrNull() ?: 0,
                            size = at(sizes, i).toFloatOrNull() ?: 0f,
                            color = hex(at(colors, i)), darkColor = hex(at(darkColors, i)),
                            background = hex(at(backgrounds, i)), darkBackground = hex(at(darkBackgrounds, i)),
                            press = callback(at(presses, i)), tracking = at(trackings, i).toFloatOrNull() ?: 0f,
                        )
                    )
                }
            }
            runs
        }
    }

    /** More's items as the `top_bar_action` nodes renderAttachedMenuItem reads, pressing with the row's id. */
    private fun decodeMenu(): List<NativeUINode> {
        val labels = p.getStringList("menu_label")
        val icons = p.getStringList("menu_icon")
        val presses = p.getStringList("menu_press")
        val kinds = p.getStringList("menu_kind")
        return labels.indices.map { i ->
            val kind = kinds.getOrElse(i) { "" }
            val props = if (kind == "divider") {
                mapOf<String, Any>("divider" to true)
            } else {
                mapOf("label" to labels[i], "icon" to icons.getOrElse(i) { "" }, "destructive" to (kind == "destructive"))
            }
            NativeUINode(
                id = id, type = "top_bar_action", layout = null, style = null, props = GenericProps(props),
                onPress = callback(presses.getOrElse(i) { "" }), onLongPress = 0, children = emptyList(),
            )
        }
    }
}

/**
 * An absolute child's insets, null where unset, placed as ComposeFlexLayout's
 * AbsolutePositionedChild places one in a box of the row's size: two opposing
 * insets stretch it between them, one anchors it, none leaves it at the origin.
 */
private class Inset(
    val top: Float? = null,
    val right: Float? = null,
    val bottom: Float? = null,
    val left: Float? = null,
) {
    fun constraints(density: Density, width: Int, height: Int): Constraints = with(density) {
        val l = left
        val t = top
        val r = right
        val b = bottom
        val stretchW = if (l != null && r != null) (width - l.dp.roundToPx() - r.dp.roundToPx()).coerceAtLeast(0) else null
        val stretchH = if (t != null && b != null) (height - t.dp.roundToPx() - b.dp.roundToPx()).coerceAtLeast(0) else null
        Constraints(
            minWidth = stretchW ?: 0, maxWidth = stretchW ?: width,
            minHeight = stretchH ?: 0, maxHeight = stretchH ?: height,
        )
    }

    fun offset(density: Density, width: Int, height: Int, childWidth: Int, childHeight: Int): IntOffset = with(density) {
        val l = left
        val t = top
        val r = right
        val b = bottom
        val x = when {
            l != null -> l.dp.roundToPx()
            r != null -> width - childWidth - r.dp.roundToPx()
            else -> 0
        }
        val y = when {
            t != null -> t.dp.roundToPx()
            b != null -> height - childHeight - b.dp.roundToPx()
            else -> 0
        }
        IntOffset(x, y)
    }

    /** Where a childless box sized by `width()` / `height()` (0 for none) lands. */
    fun rect(density: Density, width: Int, height: Int, childWidth: Float, childHeight: Float): IntRect {
        val c = constraints(density, width, height)
        val w = if (childWidth > 0f) with(density) { childWidth.dp.roundToPx() }.coerceIn(c.minWidth, c.maxWidth) else c.minWidth
        val h = if (childHeight > 0f) with(density) { childHeight.dp.roundToPx() }.coerceIn(c.minHeight, c.maxHeight) else c.minHeight
        val o = offset(density, width, height, w, h)
        return IntRect(o.x, o.y, o.x + w, o.y + h)
    }
}

// MARK: - Building

private val Round = RoundedCornerShape(9999.dp)

private val NoFontPadding = PlatformTextStyle(includeFontPadding = false)

private val TrimmedLineHeight = LineHeightStyle(
    alignment = LineHeightStyle.Alignment.Center,
    trim = LineHeightStyle.Trim.Both,
)

// A pressable run keeps its own decoration rather than a link's underline.
private val PlainLink = TextLinkStyles(style = SpanStyle(textDecoration = TextDecoration.None))

/** An inset PHP sends as a plain value: the device reads 0 as unset, as EDGE reads `top-[0]`. */
private fun inset(value: Float): Float? = if (value != 0f) value else null

/** A callback id sent as a string, wrapped to 32 bits as the binary node's ids are. */
private fun callback(id: String): Int = id.toLongOrNull()?.toInt() ?: 0

/** The props NodeBorder reads for a sided border: `border-b`, or `border-l border-dashed`. */
private fun borderSides(style: Int, bottom: Float = 0f, left: Float = 0f) = GenericProps(
    mapOf<String, Any>("border_style" to style, "border_t" to 0f, "border_r" to 0f, "border_b" to bottom, "border_l" to left)
)

/** FlexRow's arrangement for justify start or centre. */
private fun horizontal(gap: Float, centered: Boolean): Arrangement.Horizontal = when {
    centered -> if (gap > 0f) Arrangement.spacedBy(gap.dp, Alignment.CenterHorizontally) else Arrangement.Center
    else -> if (gap > 0f) Arrangement.spacedBy(gap.dp) else Arrangement.Start
}

/** FlexColumn's arrangement for justify start. */
private fun vertical(gap: Float): Arrangement.Vertical =
    if (gap > 0f) Arrangement.spacedBy(gap.dp) else Arrangement.Top

/** buildChildModifier's margin: the positive part is padding, the negative part only moves the drawing. */
private fun Modifier.edgeMargin(top: Float, right: Float, bottom: Float, left: Float): Modifier {
    var modifier = this
    if (top > 0f || right > 0f || bottom > 0f || left > 0f) {
        modifier = modifier.padding(
            start = left.coerceAtLeast(0f).dp, top = top.coerceAtLeast(0f).dp,
            end = right.coerceAtLeast(0f).dp, bottom = bottom.coerceAtLeast(0f).dp,
        )
    }
    val x = left.coerceAtMost(0f) - right.coerceAtMost(0f)
    val y = top.coerceAtMost(0f) - bottom.coerceAtMost(0f)
    if (x != 0f || y != 0f) {
        modifier = modifier.offset(x = x.dp, y = y.dp)
    }
    return modifier
}

/** nodeLayout's padding. */
private fun Modifier.edgePadding(top: Float, right: Float, bottom: Float, left: Float): Modifier =
    if (top > 0f || right > 0f || bottom > 0f || left > 0f) {
        padding(
            start = left.coerceAtLeast(0f).dp, top = top.coerceAtLeast(0f).dp,
            end = right.coerceAtLeast(0f).dp, bottom = bottom.coerceAtLeast(0f).dp,
        )
    } else {
        this
    }

// EDGE only sizes an axis whose fixed size is above 0.
private fun Modifier.fixedWidth(points: Float): Modifier = if (points > 0f) width(points.dp) else this

private fun Modifier.fixedHeight(points: Float): Modifier = if (points > 0f) height(points.dp) else this

private fun Modifier.widthAtLeast(points: Float): Modifier = if (points > 0f) widthIn(min = points.dp) else this

/**
 * A pressable's tap, sent as EDGE's nodeGestures sends it. EDGE's pressables
 * don't read `a11y_label` on Android; these are buttons read by their label.
 */
private fun Modifier.commitRowPress(callback: Int, node: Int, label: String): Modifier =
    clickable(role = Role.Button) {
        KeyboardFocusPolicy.dismissForInteractiveTap()
        NativeElementBridge.sendPressEvent(callback, node)
    }.commitRowLabel(label)

/** Read out by its label alone, its children's text and icon glyphs left out. Inside the click so that stays. */
private fun Modifier.commitRowLabel(label: String): Modifier =
    if (label.isEmpty()) this else clearAndSetSemantics { contentDescription = label }

/** TextRenderer's font: the named font or theme alias, else the theme's default, else the system's. */
private fun fontFamily(context: Context, name: String): FontFamily? =
    (if (name.isNotEmpty()) NativeUIFontResolver.resolve(context, name) else null) ?: nuiThemeDefaultFontFamily(context)

private fun fontWeight(weight: Int): FontWeight = when (weight) {
    1 -> FontWeight.Thin
    2 -> FontWeight.Light
    3 -> FontWeight.Normal
    4 -> FontWeight.Medium
    5 -> FontWeight.SemiBold
    6 -> FontWeight.Bold
    7 -> FontWeight.ExtraBold
    else -> FontWeight.Normal
}

private fun DrawScope.fill(rect: IntRect, color: Color) {
    drawRect(
        color = color,
        topLeft = Offset(rect.left.toFloat(), rect.top.toFloat()),
        size = Size(rect.width.toFloat(), rect.height.toFloat()),
    )
}

// MARK: - The row

@Composable
private fun CommitRowView(row: CommitRowProps, dark: Boolean, modifier: Modifier) {
    val policy = remember(row) { RootPolicy(row) }
    val surface = if (row.surface.argb(dark) != 0) modifier.background(row.surface.color(dark)) else modifier
    Layout(
        content = {
            CommitRowContent(row, dark)
            if (row.endDot) {
                CommitRowDot(row, dark, Modifier.fixedWidth(row.dotSize).fixedHeight(row.dotSize))
            }
            CommitRowNode(row, dark)
        },
        modifier = surface.commitRowRail(row, dark),
        measurePolicy = policy,
    )
}

/**
 * The Blade row's root: a pressable FlexContainer with absolute children, so a
 * Box holding a Row of its one flow child (the weighted content) with each
 * absolute child laid over the Box's bounds. The same sums in one layout.
 */
private class RootPolicy(private val row: CommitRowProps) : MeasurePolicy {
    override fun MeasureScope.measure(measurables: List<Measurable>, constraints: Constraints): MeasureResult {
        // The Box drops its minimums for the Row, and the Row hands its weighted child all of a bounded width.
        val contentWidth = if (constraints.hasBoundedWidth) constraints.maxWidth else 0
        val content = measurables[0].measure(Constraints(contentWidth, contentWidth, 0, constraints.maxHeight))
        val width = max(constraints.minWidth, content.width)
        val height = max(constraints.minHeight, content.height)

        val endDot = if (row.endDot) measurables[1].measure(row.endDotInset.constraints(this, width, height)) else null
        val endDotAt = endDot?.let { row.endDotInset.offset(this, width, height, it.width, it.height) }
        val node = measurables[measurables.size - 1].measure(row.nodeInset.constraints(this, width, height))
        val nodeAt = row.nodeInset.offset(this, width, height, node.width, node.height)

        return layout(width, height) {
            content.place(0, 0)
            if (endDot != null && endDotAt != null) {
                endDot.place(endDotAt)
            }
            node.place(nodeAt)
        }
    }
}

/**
 * The rail's segments and dashed reply line. In the Blade row they're childless
 * boxes with a fill or a dashed border, laid over the row; here they're drawn
 * on the pixels AbsolutePositionedChild would give those boxes, without a node
 * each. They go under the content rather than over it, which they never meet.
 */
private fun Modifier.commitRowRail(row: CommitRowProps, dark: Boolean): Modifier {
    val previousColor = row.previousColor
    if (previousColor == null && !row.tail && !row.replyTail) {
        return this
    }
    return drawWithCache {
        val width = size.width.roundToInt()
        val height = size.height.roundToInt()
        val previousArgb = previousColor?.argb(dark) ?: 0
        val laneArgb = row.laneColor.argb(dark)
        val previous = if (previousArgb != 0) row.previousInset.rect(this, width, height, row.railWidth, row.previousHeight) else null
        val tail = if (row.tail && laneArgb != 0) row.tailInset.rect(this, width, height, row.railWidth, 0f) else null
        val replyTail = if (row.replyTail && laneArgb != 0) {
            row.replyTailInset.rect(this, width, height, row.replyTailWidth, row.replyTailHeight)
        } else {
            null
        }
        val dashes = replyTail?.let { box ->
            row.replyTailBorder?.runs(Size(box.width.toFloat(), box.height.toFloat()), density)
        }.orEmpty()
        val previousFill = argbToComposeColor(previousArgb)
        val lane = argbToComposeColor(laneArgb)

        onDrawBehind {
            if (previous != null) fill(previous, previousFill)
            if (tail != null) fill(tail, lane)
            if (replyTail != null) {
                translate(replyTail.left.toFloat(), replyTail.top.toFloat()) {
                    dashes.forEach { drawPath(it.path, lane, style = it.stroke) }
                }
            }
        }
    }
}

@Composable
private fun CommitRowContent(row: CommitRowProps, dark: Boolean) {
    var modifier = Modifier.edgeMargin(0f, 0f, 0f, row.gutter)
    if (row.contentMinHeight > 0f) {
        // Before the padding, as on Android EDGE: the minimum counts the padding in.
        modifier = modifier.heightIn(min = row.contentMinHeight.dp)
    }
    val hairline = row.hairline
    if (hairline != null && row.hairlineColor.argb(dark) != 0) {
        modifier = modifier.nodeBorder(hairline, row.hairlineColor.color(dark))
    }
    modifier = modifier.edgePadding(row.contentPaddingTop, row.contentPaddingRight, row.contentPaddingBottom, 0f)

    Column(modifier, verticalArrangement = row.contentArrangement, horizontalAlignment = Alignment.Start) {
        CommitRowHeader(row, dark)

        if (row.replyingTo.isNotEmpty()) {
            CommitRowReplyingTo(row, dark)
        }

        for (index in row.paragraphs.indices) {
            CommitRowParagraph(row, index, dark)
        }

        if (row.showMorePress != 0) {
            CommitRowText(
                text = row.showMoreLabel, size = row.bodySize, font = row.showMoreFont,
                color = row.showMoreColor.color(dark),
                modifier = Modifier.commitRowPress(row.showMorePress, row.id, row.showMoreLabel),
            )
        }

        CommitRowActions(row, dark)
    }
}

@Composable
private fun CommitRowHeader(row: CommitRowProps, dark: Boolean) {
    val meta = row.metaColor.color(dark)
    Row(Modifier.fillMaxWidth(), horizontalArrangement = row.headerArrangement, verticalAlignment = Alignment.CenterVertically) {
        CommitRowText(row.name, row.nameSize, row.nameFont, row.nameColor.color(dark), maxLines = 1)
        CommitRowText(row.handle, row.metaSize, row.metaFont, meta, maxLines = 1)
        CommitRowText(row.separator, row.metaSize, "", meta)
        CommitRowText(row.time, row.metaSize, row.metaFont, meta)
    }
}

@Composable
private fun CommitRowReplyingTo(row: CommitRowProps, dark: Boolean) {
    val color = row.replyingToColor.color(dark)
    // No press is a deleted parent: plain text, as the Blade row's plain row.
    val modifier = if (row.replyingToPress != 0) {
        Modifier.commitRowPress(row.replyingToPress, row.id, row.replyingToA11y)
    } else {
        Modifier
    }
    Row(modifier, horizontalArrangement = row.replyingToArrangement, verticalAlignment = Alignment.CenterVertically) {
        CommitRowIcon(row.replyingToIcon, row.replyingToIconSize, color)
        CommitRowText(row.replyingTo, row.replyingToSize, row.replyingToFont, color)
    }
}

/** A leaf text: TextRenderer's leaf path with the values this row sets. */
@Composable
private fun CommitRowText(
    text: String,
    size: Float,
    font: String,
    color: Color,
    maxLines: Int = 0,
    modifier: Modifier = Modifier,
) {
    Text(
        text = text,
        modifier = modifier,
        color = color,
        fontSize = size.sp,
        fontWeight = FontWeight.Normal,
        fontStyle = FontStyle.Normal,
        textAlign = TextAlign.Start,
        maxLines = if (maxLines > 0) maxLines else Int.MAX_VALUE,
        overflow = TextOverflow.Ellipsis,
        style = TextStyle(
            fontFamily = fontFamily(LocalContext.current, font),
            platformStyle = NoFontPadding,
            lineHeightStyle = TrimmedLineHeight,
        ),
    )
}

/** A paragraph: TextRenderer's composed path, one AnnotatedString of its runs. */
@Composable
private fun CommitRowParagraph(row: CommitRowProps, index: Int, dark: Boolean) {
    val context = LocalContext.current
    val themeFont = nuiThemeDefaultFontFamily(context)
    val text = buildAnnotatedString {
        for (run in row.paragraphs[index]) {
            if (run.text.isEmpty()) continue

            val light = if (run.color != 0) run.color else row.bodyColor.lightArgb
            val darkColor = if (run.darkColor != 0) run.darkColor else row.bodyColor.darkArgb
            val foreground = if (dark && darkColor != 0) darkColor else light
            val background = if (dark && run.darkBackground != 0) run.darkBackground else run.background
            val font = run.font.ifEmpty { row.bodyFont }
            val span = SpanStyle(
                color = argbToComposeColor(foreground),
                fontSize = (if (run.size > 0f) run.size else row.bodySize).sp,
                fontWeight = fontWeight(run.weight),
                fontStyle = FontStyle.Normal,
                fontFamily = (if (font.isNotEmpty()) NativeUIFontResolver.resolve(context, font) else null) ?: themeFont,
                letterSpacing = if (run.tracking != 0f) run.tracking.em else TextUnit.Unspecified,
                background = if (background != 0) argbToComposeColor(background) else Color.Unspecified,
            )

            if (run.press != 0) {
                val callback = run.press
                val node = row.id
                val link = LinkAnnotation.Clickable(tag = "nativephp-press", styles = PlainLink, linkInteractionListener = {
                    // The link consumes the tap, so it drops the keyboard as the Blade row's runs do.
                    KeyboardFocusPolicy.dismissForInteractiveTap()
                    NativeUIBridge.sendPressEvent(callback, node)
                })
                withLink(link) { withStyle(span) { append(run.text) } }
            } else {
                withStyle(span) { append(run.text) }
            }
        }
    }

    Text(
        text = text,
        modifier = Modifier.fillMaxWidth(),
        textAlign = TextAlign.Start,
        maxLines = Int.MAX_VALUE,
        overflow = TextOverflow.Ellipsis,
        style = row.bodyStyle,
    )
}

/** IconRenderer's call. It reads no Material variant on Android, so neither does this. */
@Composable
private fun CommitRowIcon(name: String, size: Float, color: Color) {
    MaterialIcon(name = name, contentDescription = null, size = size.dp, tint = color)
}

// MARK: - Actions

@Composable
private fun CommitRowActions(row: CommitRowProps, dark: Boolean) {
    val muted = row.actionColor.color(dark)
    val accent = row.accentColor.color(dark)
    Row(row.actionsModifier, horizontalArrangement = Arrangement.Start, verticalAlignment = Alignment.CenterVertically) {
        if (row.starPress != 0) {
            val color = if (row.starOn) accent else muted
            CommitRowTarget(row, row.starArrangement, row.starPress, row.starA11y) {
                CommitRowIcon(row.starIcon, row.iconSize, color)
                if (row.starCount.isNotEmpty()) {
                    CommitRowText(row.starCount, row.labelSize, row.countFont, color)
                }
            }
        }

        val pastel = row.pastelImage
        if (pastel != null) {
            // The pastel takes the star's gap, as the Blade row's does.
            CommitRowTarget(row, row.starArrangement, row.pastelPress, row.pastelA11y) {
                ImageRenderer.Render(pastel, Modifier.fixedWidth(row.pastelSize).fixedHeight(row.pastelSize))
                CommitRowText(row.pastelCount, row.labelSize, row.countFont, muted)
            }
        }

        if (row.quotePress != 0) {
            CommitRowTarget(row, row.quoteArrangement, row.quotePress, row.quoteA11y) {
                CommitRowIcon(row.quoteIcon, row.iconSize, muted)
                if (row.quoteStash) {
                    CommitRowStashDot(row.stashDotSize, 0f, muted)
                }
            }
        }

        if (row.replyPress != 0) {
            CommitRowTarget(row, row.replyArrangement, row.replyPress, row.replyA11y) {
                CommitRowIcon(row.replyIcon, row.iconSize, muted)
                if (row.replyLabel.isNotEmpty()) {
                    CommitRowText(row.replyLabel, row.labelSize, "", muted)
                }
                if (row.replyStash) {
                    CommitRowStashDot(row.stashDotSize, row.replyStashOffset, muted)
                }
            }
        }

        if (row.repliesPress != 0) {
            CommitRowTarget(row, row.repliesArrangement, row.repliesPress, row.repliesA11y, hasMinWidth = false) {
                CommitRowIcon(row.repliesIcon, row.repliesIconSize, accent)
                CommitRowText(row.repliesLabel, row.labelSize, "", accent)
            }
        }

        Spacer(Modifier.weight(1f))
        CommitRowMore(row, muted)
    }
}

/** An action: a pressable laid out as a row, its whole box the target. */
@Composable
private fun CommitRowTarget(
    row: CommitRowProps,
    arrangement: Arrangement.Horizontal,
    press: Int,
    a11y: String,
    hasMinWidth: Boolean = true,
    content: @Composable RowScope.() -> Unit,
) {
    var modifier: Modifier = if (hasMinWidth) Modifier.widthAtLeast(row.actionMinWidth) else Modifier
    modifier = modifier.fixedHeight(row.actionHeight)
    modifier = if (press != 0) {
        modifier.commitRowPress(press, row.id, a11y)
    } else {
        // The pastel's count only is a plain row, read out by its label.
        modifier.semantics(mergeDescendants = true) {}.commitRowLabel(a11y)
    }
    modifier = modifier.edgePadding(0f, row.actionPadding, 0f, row.actionPadding)
    Row(modifier, horizontalArrangement = arrangement, verticalAlignment = Alignment.CenterVertically, content = content)
}

/** A stash dot. Reply's has a negative left margin, which on Android only moves it. */
@Composable
private fun CommitRowStashDot(size: Float, marginLeft: Float, color: Color) {
    Box(Modifier.edgeMargin(0f, 0f, 0f, marginLeft).fixedWidth(size).fixedHeight(size).background(color, Round))
}

/**
 * More: PressableRenderer's menu branch, the tap opening EDGE's own menu. The
 * label column that centres the icon is this Box, the same size as the Box
 * EDGE wraps round it, so the menu anchors to the same bounds.
 */
@Composable
private fun CommitRowMore(row: CommitRowProps, color: Color) {
    var expanded by remember { mutableStateOf(false) }
    val modifier = Modifier
        .clickable(role = Role.Button) { expanded = true }
        .commitRowLabel(row.moreA11y)
        .widthAtLeast(row.actionMinWidth)
        .fixedHeight(row.actionHeight)
    Box(modifier, contentAlignment = Alignment.Center) {
        CommitRowIcon(row.moreIcon, row.iconSize, color)
        ExpressiveMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            row.menu.forEach { item ->
                renderAttachedMenuItem(item) { expanded = false }
            }
        }
    }
}

// MARK: - The rail's node

@Composable
private fun CommitRowNode(row: CommitRowProps, dark: Boolean) {
    // The pressable's click sits outside the node's size, as the Blade row's pressable wraps it.
    val modifier = if (row.nodePress != 0) {
        Modifier.commitRowPress(row.nodePress, row.id, row.nodeA11y)
    } else {
        Modifier.commitRowLabel(row.nodeA11y)
    }
    if (row.nodeIsDot) {
        CommitRowDot(row, dark, modifier.fixedHeight(row.dotSize).fixedWidth(row.dotSize))
    } else {
        CommitRowAvatar(row, dark, modifier)
    }
}

/** A lane dot: a disc in the lane with a centre ringed in the surface colour. */
@Composable
private fun CommitRowDot(row: CommitRowProps, dark: Boolean, modifier: Modifier) {
    val lane = row.laneColor.color(dark)
    var centre = Modifier.fixedWidth(row.dotInner).fixedHeight(row.dotInner).background(lane, Round)
    if (row.dotRing > 0f) {
        // A 0dp border is a hairline, not nothing, so a ring of 0 gets no border at all, as in EDGE.
        centre = centre.border(row.dotRing.dp, row.surface.color(dark), Round)
    }
    Box(modifier.background(lane, Round), contentAlignment = Alignment.Center) {
        Box(centre)
    }
}

/** The author's avatar ringed in their lane, with a gap in the surface colour. */
@Composable
private fun CommitRowAvatar(row: CommitRowProps, dark: Boolean, modifier: Modifier) {
    var ring = modifier.fixedHeight(row.avatarSize).fixedWidth(row.avatarSize).background(row.surface.color(dark), Round)
    if (row.avatarRing > 0f) {
        ring = ring.border(row.avatarRing.dp, row.laneColor.color(dark), Round)
    }
    Box(ring, contentAlignment = Alignment.Center) {
        Box(
            Modifier.fixedWidth(row.avatarInner).fixedHeight(row.avatarInner).background(row.avatarWash.color(dark), Round),
            contentAlignment = Alignment.Center,
        ) {
            CommitRowText(row.avatarInitial, row.avatarInitialSize, row.avatarInitialFont, row.avatarInitialColor.color(dark))
            val image = row.avatarImage
            if (image != null) {
                ImageRenderer.Render(image, Modifier.fixedWidth(row.avatarInner).fixedHeight(row.avatarInner))
            }
        }
    }
}
