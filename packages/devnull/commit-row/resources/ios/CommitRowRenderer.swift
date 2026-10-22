import ObjectiveC
import SwiftUI

// The dev/null Log's commit row, drawn from one `commit_row` node's typed props
// (devnull/commit-row's CommitRow element) instead of the ~33 EDGE nodes the
// Blade row publishes.
//
// It's what a Blade-to-Swift compiler would emit for that template, written by
// hand: one view, the same layout code EDGE uses (FlexContainer,
// NativeUIStackLayout) and the same text, icon and image calls, so it draws
// the same pixels. What it leaves out is EDGE's per-node machinery: a
// NodeView, a renderer looked up by type and the full modifier chain on every
// node, whether the node uses those modifiers or not. Each view here carries
// only what its node in the Blade row actually draws.
//
// FlexContainer and NativeUIStackLayout read each child's layout off a node, so
// the row hands them layout-only carrier nodes, built with the values the
// device's decoder gives the Blade row's nodes (an unset size wraps, an unset
// flex-shrink is 1, and an inset authored as 0 is -0.0).

struct CommitRowRenderer: View {
    let node: NativeUINode

    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        CommitRowView(row: CommitRowProps.of(node), dark: colorScheme == .dark)
    }
}

// MARK: - Props

/// A colour pair off the wire: a list of two hex strings, [light, dark].
struct CommitRowColor: Equatable {
    let light: Int
    let dark: Int

    init(_ list: [String]) {
        light = list.count > 0 ? ColorParser.parse(list[0], default: 0) : 0
        dark = list.count > 1 ? ColorParser.parse(list[1], default: 0) : 0
    }

    /// Dark mode takes the dark value when there is one, as EDGE resolves `dark_*` props.
    func argb(_ dark: Bool) -> Int {
        dark && self.dark != 0 ? self.dark : light
    }

    var isSet: Bool { light != 0 || dark != 0 }
}

/// One run of a paragraph, with "" or 0 meaning "the paragraph's".
struct CommitRowRun {
    let text: String
    let font: String
    let weight: Int
    let size: Float
    let color: Int
    let darkColor: Int
    let background: Int
    let darkBackground: Int
    let press: Int
    let tracking: Float
}

/// Everything one row draws, decoded once per node and kept on it.
final class CommitRowProps {
    let id: Int

    let surface: CommitRowColor
    let gutter: Float
    let contentMinHeight: Float
    let contentPaddingTop: Float
    let contentPaddingBottom: Float
    let contentPaddingRight: Float
    let contentGap: Float
    let hairline: NodeBorder?
    let hairlineColor: CommitRowColor

    let name: String
    let nameSize: CGFloat
    let nameFont: String
    let nameColor: CommitRowColor
    let handle: String
    let time: String
    let separator: String
    let metaSize: CGFloat
    let metaFont: String
    let metaColor: CommitRowColor
    let headerGap: CGFloat

    let replyingTo: String
    let replyingToPress: Int
    let replyingToA11y: String
    let replyingToIcon: String
    let replyingToIconSize: CGFloat
    let replyingToSize: CGFloat
    let replyingToFont: String
    let replyingToColor: CommitRowColor
    let replyingToGap: Float

    let paragraphs: [[CommitRowRun]]
    let bodySize: Float
    let bodyLineHeight: Float
    let bodyFont: String
    let bodyColor: CommitRowColor
    let showMorePress: Int
    let showMoreLabel: String
    let showMoreFont: String
    let showMoreColor: CommitRowColor

    let actionsOffsetX: Float
    let actionsOffsetY: Float
    let actionMinWidth: Float
    let actionHeight: Float
    let actionPadding: Float
    let iconSize: CGFloat
    let labelSize: CGFloat
    let countFont: String
    let actionColor: CommitRowColor
    let accentColor: CommitRowColor

    let starPress: Int
    let starIcon: String
    let starOn: Bool
    let starCount: String
    let starA11y: String
    let starGap: Float

    let pastel: String
    let pastelPress: Int
    let pastelSrc: String
    let pastelSize: Float
    let pastelCount: String
    let pastelA11y: String

    let quotePress: Int
    let quoteIcon: String
    let quoteStash: Bool
    let quoteA11y: String
    let quoteGap: Float

    let replyPress: Int
    let replyIcon: String
    let replyLabel: String
    let replyStash: Bool
    let replyA11y: String
    let replyCentered: Bool
    let replyGap: Float
    let stashDotSize: Float
    let replyStashOffset: Float

    let repliesPress: Int
    let repliesIcon: String
    let repliesLabel: String
    let repliesA11y: String
    let repliesIconSize: CGFloat
    let repliesGap: Float

    let moreIcon: String
    let moreA11y: String
    let menu: [CommitRowMenuItem]

    let railX: Float
    let railWidth: Float
    let laneColor: CommitRowColor
    let previousColor: CommitRowColor?
    let previousHeight: Float
    let tail: Bool
    let tailTop: Float
    let tailBottom: Float
    let endDot: Bool
    let endDotBottom: Float
    let endDotLeft: Float
    let replyTail: Bool
    let replyTailTop: Float
    let replyTailHeight: Float
    let replyTailX: Float
    let replyTailWidth: Float
    let replyTailBorder: NodeBorder?

    let nodeIsDot: Bool
    let nodeTop: Float
    let nodeLeft: Float
    let nodePress: Int
    let nodeA11y: String
    let avatarSize: Float
    let avatarRing: Float
    let avatarInner: Float
    let avatarInitial: String
    let avatarInitialSize: CGFloat
    let avatarInitialFont: String
    let avatarInitialColor: CommitRowColor
    let avatarWash: CommitRowColor
    let avatarImage: NativeUINode?
    let dotSize: Float
    let dotInner: Float
    let dotRing: Float

    let pastelImage: NativeUINode?

    // Layout carriers, in the order each container draws its children.
    let rootLayout: NodeLayout
    let rootChildren: [NativeUINode]
    let contentLayout: NodeLayout
    let contentChildren: [NativeUINode]
    let headerLayout: NodeLayout
    let headerChildren: [NativeUINode]
    let replyingToLayout: NodeLayout
    let replyingToChildren: [NativeUINode]
    let showMoreChildren: [NativeUINode]
    let bodyLayout: NodeLayout
    let actionsLayout: NodeLayout
    let actionsChildren: [NativeUINode]
    let starLayout: NodeLayout
    let starChildren: [NativeUINode]
    let pastelLayout: NodeLayout
    let pastelChildren: [NativeUINode]
    let quoteLayout: NodeLayout
    let quoteChildren: [NativeUINode]
    let replyLayout: NodeLayout
    let replyChildren: [NativeUINode]
    let repliesLayout: NodeLayout
    let repliesChildren: [NativeUINode]
    let moreLabelLayout: NodeLayout
    let moreLabelChildren: [NativeUINode]
    let moreIconChildren: [NativeUINode]
    let previousLayout: NodeLayout
    let tailLayout: NodeLayout
    let replyTailLayout: NodeLayout
    let endDotLayout: NodeLayout
    let dotLayout: NodeLayout
    let dotChildren: [NativeUINode]
    let dotInnerLayout: NodeLayout
    let nodeLayout: NodeLayout
    let nodeChildren: [NativeUINode]
    let ringLayout: NodeLayout
    let ringChildren: [NativeUINode]
    let discLayout: NodeLayout
    let discChildren: [NativeUINode]
    let stashDotLayout: NodeLayout
    let replyStashLayout: NodeLayout

    private static var key: UInt8 = 0

    /// The node's props, decoded the first time a body asks and kept on the node.
    static func of(_ node: NativeUINode) -> CommitRowProps {
        if let kept = objc_getAssociatedObject(node, &key) as? CommitRowProps {
            return kept
        }
        let decoded = CommitRowProps(node)
        objc_setAssociatedObject(node, &key, decoded, .OBJC_ASSOCIATION_RETAIN_NONATOMIC)
        return decoded
    }

    init(_ node: NativeUINode) {
        let p = node.props
        func f(_ key: String) -> Float { p.getFloat(key) }
        func c(_ key: String) -> CommitRowColor { CommitRowColor(p.getStringList(key)) }
        id = node.id

        surface = c("surface_color")
        gutter = f("gutter")
        contentMinHeight = f("content_min_height")
        contentPaddingTop = f("content_padding_top")
        contentPaddingBottom = f("content_padding_bottom")
        contentPaddingRight = f("content_padding_right")
        contentGap = f("content_gap")
        hairline = p.getBool("hairline") ? NodeBorder(
            props: GenericProps(["border_t": Float(0), "border_r": Float(0), "border_b": Float(1), "border_l": Float(0)]),
            width: CGFloat(f("hairline_width")), radius: 0, radii: nil
        ) : nil
        hairlineColor = c("hairline_color")

        name = p.getString("name")
        nameSize = CGFloat(f("name_size"))
        nameFont = p.getString("name_font")
        nameColor = c("name_color")
        handle = p.getString("handle")
        time = p.getString("time")
        separator = p.getString("separator")
        metaSize = CGFloat(f("meta_size"))
        metaFont = p.getString("meta_font")
        metaColor = c("meta_color")
        headerGap = CGFloat(f("header_gap"))

        replyingTo = p.getString("replying_to")
        replyingToPress = p.getCallbackId("on_replying_to")
        replyingToA11y = p.getString("replying_to_a11y")
        replyingToIcon = p.getString("replying_to_icon")
        replyingToIconSize = CGFloat(f("replying_to_icon_size"))
        replyingToSize = CGFloat(f("replying_to_size"))
        replyingToFont = p.getString("replying_to_font")
        replyingToColor = c("replying_to_color")
        replyingToGap = f("replying_to_gap")

        paragraphs = Self.paragraphs(p)
        bodySize = f("body_size")
        bodyLineHeight = f("body_line_height")
        bodyFont = p.getString("body_font")
        bodyColor = c("body_color")
        showMorePress = p.getCallbackId("on_show_more")
        showMoreLabel = p.getString("show_more_label")
        showMoreFont = p.getString("show_more_font")
        showMoreColor = c("show_more_color")

        actionsOffsetX = f("actions_offset_x")
        actionsOffsetY = f("actions_offset_y")
        actionMinWidth = f("action_min_width")
        actionHeight = f("action_height")
        actionPadding = f("action_padding")
        iconSize = CGFloat(f("icon_size"))
        labelSize = CGFloat(f("label_size"))
        countFont = p.getString("count_font")
        actionColor = c("action_color")
        accentColor = c("accent_color")

        starPress = p.getCallbackId("on_star")
        starIcon = p.getString("star_icon")
        starOn = p.getBool("star_on")
        starCount = p.getString("star_count")
        starA11y = p.getString("star_a11y")
        starGap = f("star_gap")

        pastel = p.getString("pastel")
        pastelPress = p.getCallbackId("on_pastel")
        pastelSrc = p.getString("pastel_src")
        pastelSize = f("pastel_size")
        pastelCount = p.getString("pastel_count")
        pastelA11y = p.getString("pastel_a11y")

        quotePress = p.getCallbackId("on_quote")
        quoteIcon = p.getString("quote_icon")
        quoteStash = p.getBool("quote_stash")
        quoteA11y = p.getString("quote_a11y")
        quoteGap = f("quote_gap")

        replyPress = p.getCallbackId("on_reply")
        replyIcon = p.getString("reply_icon")
        replyLabel = p.getString("reply_label")
        replyStash = p.getBool("reply_stash")
        replyA11y = p.getString("reply_a11y")
        replyCentered = p.getBool("reply_centered")
        replyGap = f("reply_gap")
        stashDotSize = f("stash_dot_size")
        replyStashOffset = f("reply_stash_offset")

        repliesPress = p.getCallbackId("on_replies")
        repliesIcon = p.getString("replies_icon")
        repliesLabel = p.getString("replies_label")
        repliesA11y = p.getString("replies_a11y")
        repliesIconSize = CGFloat(f("replies_icon_size"))
        repliesGap = f("replies_gap")

        moreIcon = p.getString("more_icon")
        moreA11y = p.getString("more_a11y")
        menu = CommitRowMenuItem.list(p)

        railX = f("rail_x")
        railWidth = f("rail_width")
        laneColor = c("lane_color")
        previousColor = p.has("previous_color") ? c("previous_color") : nil
        previousHeight = f("previous_height")
        tail = p.getBool("tail")
        tailTop = f("tail_top")
        tailBottom = f("tail_bottom")
        endDot = p.getBool("end_dot")
        endDotBottom = f("end_dot_bottom")
        endDotLeft = f("end_dot_left")
        replyTail = p.getBool("reply_tail")
        replyTailTop = f("reply_tail_top")
        replyTailHeight = f("reply_tail_height")
        replyTailX = f("reply_tail_x")
        replyTailWidth = f("reply_tail_width")
        replyTailBorder = NodeBorder(
            props: GenericProps(["border_style": 1, "border_t": Float(0), "border_r": Float(0), "border_b": Float(0), "border_l": Float(1)]),
            width: CGFloat(replyTailWidth), radius: 0, radii: nil
        )

        nodeIsDot = p.getString("node") == "dot"
        nodeTop = f("node_top")
        nodeLeft = f("node_left")
        nodePress = p.getCallbackId("on_node")
        nodeA11y = p.getString("node_a11y")
        avatarSize = f("avatar_size")
        avatarRing = f("avatar_ring")
        avatarInner = f("avatar_inner")
        avatarInitial = p.getString("avatar_initial")
        avatarInitialSize = CGFloat(f("avatar_initial_size"))
        avatarInitialFont = p.getString("avatar_initial_font")
        avatarInitialColor = c("avatar_initial_color")
        avatarWash = c("avatar_wash")
        dotSize = f("dot_size")
        dotInner = f("dot_inner")
        dotRing = f("dot_ring")

        let avatarSrc = p.getString("avatar_src")
        avatarImage = avatarSrc.isEmpty ? nil : NativeUINode(
            id: 0, type: "image",
            layout: Self.layout(width: avatarInner, height: avatarInner),
            style: NodeStyle(bgColor: 0, borderRadius: 9999, borderWidth: 0, borderColor: 0xFF000000, opacity: 1, elevation: 0),
            props: GenericProps(["src": avatarSrc, "fit": p.getInt("avatar_fit")]),
            onPress: 0, onLongPress: 0, children: []
        )
        pastelImage = pastelSrc.isEmpty || pastel.isEmpty ? nil : NativeUINode(
            id: 0, type: "image",
            layout: Self.layout(width: pastelSize, height: pastelSize),
            style: Self.plainStyle,
            props: GenericProps(["src": pastelSrc, "fit": 1]),
            onPress: 0, onLongPress: 0, children: []
        )

        // The Blade row's layouts, value for value.
        let carry = Self.carrier
        let wrapped = Self.layout()

        rootLayout = Self.layout(widthMode: SizeMode.fill, flexDirection: FlexDirection.row)
        contentLayout = Self.layout(
            padding: (contentPaddingTop, contentPaddingRight, contentPaddingBottom, 0),
            margin: (0, 0, 0, gutter), flexGrow: 1, flexShrink: 1, gap: contentGap, minHeight: contentMinHeight
        )
        previousLayout = Self.layout(width: railWidth, height: previousHeight, position: (Self.authored(0), 0, 0, railX))
        tailLayout = Self.layout(width: railWidth, position: (tailTop, 0, Self.authored(tailBottom), railX))
        replyTailLayout = replyTailHeight > 0
            ? Self.layout(width: replyTailWidth, height: replyTailHeight, position: (0, 0, Self.authored(0), replyTailX))
            : Self.layout(width: replyTailWidth, position: (replyTailTop, 0, Self.authored(0), replyTailX))
        endDotLayout = Self.layout(width: dotSize, height: dotSize, alignItems: AlignItems.center, justify: JustifyContent.center, position: (0, 0, endDotBottom, endDotLeft))
        dotLayout = Self.layout(width: dotSize, height: dotSize, alignItems: AlignItems.center, justify: JustifyContent.center)
        dotInnerLayout = Self.layout(width: dotInner, height: dotInner)
        dotChildren = [carry(dotInnerLayout)]
        nodeLayout = Self.layout(position: (nodeTop, 0, 0, nodeLeft))
        ringLayout = Self.layout(width: avatarSize, height: avatarSize, alignItems: AlignItems.center, justify: JustifyContent.center)
        discLayout = Self.layout(width: avatarInner, height: avatarInner, alignItems: AlignItems.center, justify: JustifyContent.center)
        nodeChildren = [carry(nodeIsDot ? dotLayout : ringLayout)]
        ringChildren = [carry(discLayout)]
        discChildren = avatarImage.map { [carry(wrapped), $0] } ?? [carry(wrapped)]

        var root = [carry(contentLayout)]
        if previousColor != nil { root.append(carry(previousLayout)) }
        if tail { root.append(carry(tailLayout)) }
        if replyTail { root.append(carry(replyTailLayout)) }
        if endDot { root.append(carry(endDotLayout)) }
        root.append(carry(nodeLayout))
        rootChildren = root

        headerLayout = Self.layout(widthMode: SizeMode.fill, alignItems: AlignItems.center, gap: Float(headerGap))
        headerChildren = [carry(Self.layout(flexShrink: 1)), carry(Self.layout(flexShrink: 1)), carry(wrapped), carry(Self.layout(flexShrink: 0))]
        replyingToLayout = Self.layout(alignItems: AlignItems.center, gap: replyingToGap, flexDirection: FlexDirection.row)
        replyingToChildren = [carry(wrapped), carry(wrapped)]
        showMoreChildren = [carry(wrapped)]

        let paragraphLayout = Self.layout(widthMode: SizeMode.fill)
        bodyLayout = paragraphLayout
        actionsLayout = Self.layout(widthMode: SizeMode.fill, margin: (actionsOffsetY, 0, actionsOffsetY, actionsOffsetX), alignItems: AlignItems.center)
        var content = [carry(headerLayout)]
        if !replyingTo.isEmpty { content.append(carry(replyingToLayout)) }
        content += Array(repeating: carry(paragraphLayout), count: paragraphs.count)
        if showMorePress != 0 { content.append(carry(wrapped)) }
        content.append(carry(actionsLayout))
        contentChildren = content

        let height = actionHeight, padding = actionPadding, minimum = actionMinWidth
        func target(gap: Float, centered: Bool, minWidth: Bool = true) -> NodeLayout {
            Self.layout(
                height: height, padding: (0, padding, 0, padding),
                alignItems: AlignItems.center, justify: centered ? JustifyContent.center : JustifyContent.start,
                gap: gap, minWidth: minWidth ? minimum : 0, flexDirection: FlexDirection.row
            )
        }
        stashDotLayout = Self.layout(width: stashDotSize, height: stashDotSize)
        replyStashLayout = Self.layout(width: stashDotSize, height: stashDotSize, margin: (0, 0, 0, replyStashOffset))
        starLayout = target(gap: starGap, centered: false)
        starChildren = starCount.isEmpty ? [carry(wrapped)] : [carry(wrapped), carry(wrapped)]
        pastelLayout = target(gap: starGap, centered: false)
        pastelChildren = pastelImage.map { [$0, carry(wrapped)] } ?? []
        quoteLayout = target(gap: quoteGap, centered: true)
        quoteChildren = quoteStash ? [carry(wrapped), carry(stashDotLayout)] : [carry(wrapped)]
        replyLayout = target(gap: replyGap, centered: replyCentered)
        var reply = [carry(wrapped)]
        if !replyLabel.isEmpty { reply.append(carry(wrapped)) }
        if replyStash { reply.append(carry(replyStashLayout)) }
        replyChildren = reply
        repliesLayout = target(gap: repliesGap, centered: false, minWidth: false)
        repliesChildren = [carry(wrapped), carry(wrapped)]
        moreLabelLayout = Self.layout(height: actionHeight, alignItems: AlignItems.center, justify: JustifyContent.center, minWidth: actionMinWidth)
        moreLabelChildren = [carry(moreLabelLayout)]
        moreIconChildren = [carry(wrapped)]

        var actions: [NativeUINode] = []
        if starPress != 0 { actions.append(carry(starLayout)) }
        if pastelImage != nil { actions.append(carry(pastelLayout)) }
        if quotePress != 0 { actions.append(carry(quoteLayout)) }
        if replyPress != 0 { actions.append(carry(replyLayout)) }
        if repliesPress != 0 { actions.append(carry(repliesLayout)) }
        actions.append(carry(Self.layout(flexGrow: 1)))
        actions.append(carry(wrapped))
        actionsChildren = actions
    }

    // MARK: Building

    static let plainStyle = NodeStyle(bgColor: 0, borderRadius: 0, borderWidth: 0, borderColor: 0xFF000000, opacity: 1, elevation: 0)

    private static let noProps = GenericProps()

    /// A node that only carries a layout, for a container to read.
    static func carrier(_ layout: NodeLayout) -> NativeUINode {
        NativeUINode(id: 0, type: "column", layout: layout, style: plainStyle, props: noProps, onPress: 0, onLongPress: 0, children: [])
    }

    /// An inset written as a value: FlexContainer reads +0.0 as "not set", so a 0 is sent as -0.0.
    static func authored(_ value: Float) -> Float {
        value == 0 ? -0.0 : value
    }

    /// A layout as the device decodes it for a node that sets only these: sizes wrap unless given, flex-shrink is 1.
    static func layout(
        width: Float? = nil, widthMode: Int? = nil, height: Float? = nil,
        padding: (Float, Float, Float, Float) = (0, 0, 0, 0), margin: (Float, Float, Float, Float) = (0, 0, 0, 0),
        flexGrow: Float = 0, flexShrink: Float = 1, alignItems: Int = 0, justify: Int = 0, gap: Float = 0,
        minWidth: Float = 0, minHeight: Float = 0, flexDirection: Int = FlexDirection.column,
        position: (Float, Float, Float, Float)? = nil
    ) -> NodeLayout {
        NodeLayout(
            width: width ?? 0, widthMode: widthMode ?? (width == nil ? SizeMode.wrap : SizeMode.fixed),
            height: height ?? 0, heightMode: height == nil ? SizeMode.wrap : SizeMode.fixed,
            paddingTop: padding.0, paddingRight: padding.1, paddingBottom: padding.2, paddingLeft: padding.3,
            marginTop: margin.0, marginRight: margin.1, marginBottom: margin.2, marginLeft: margin.3,
            flexGrow: flexGrow, flexShrink: flexShrink, alignSelf: 0, alignItems: alignItems, justifyContent: justify,
            gap: gap, safeArea: 0, minWidth: minWidth, minHeight: minHeight, maxWidth: 0, maxHeight: 0,
            flexBasis: 0, flexBasisMode: 0, flexWrap: 0, flexDirection: flexDirection,
            positionType: position == nil ? PositionType.relative : PositionType.absolute,
            positionTop: position?.0 ?? 0, positionRight: position?.1 ?? 0,
            positionBottom: position?.2 ?? 0, positionLeft: position?.3 ?? 0,
            display: 0, overflow: 0, alignContent: 0, direction: 0, aspectRatio: 0, rowGap: 0
        )
    }

    /// The body's runs, from the parallel `run_*` lists, split into paragraphs by `paragraph_runs`.
    private static func paragraphs(_ p: GenericProps) -> [[CommitRowRun]] {
        let counts = p.getStringList("paragraph_runs").map { Int($0) ?? 0 }
        let text = p.getStringList("run_text")
        let font = p.getStringList("run_font")
        let weight = p.getStringList("run_weight")
        let size = p.getStringList("run_size")
        let color = p.getStringList("run_color")
        let darkColor = p.getStringList("run_dark_color")
        let background = p.getStringList("run_bg")
        let darkBackground = p.getStringList("run_dark_bg")
        let press = p.getStringList("run_press")
        let tracking = p.getStringList("run_tracking")
        func at(_ list: [String], _ i: Int) -> String { i < list.count ? list[i] : "" }
        func hex(_ s: String) -> Int { s.isEmpty ? 0 : ColorParser.parse(s, default: 0) }

        var out: [[CommitRowRun]] = []
        var i = 0
        for count in counts {
            var runs: [CommitRowRun] = []
            for _ in 0..<count where i < text.count {
                runs.append(CommitRowRun(
                    text: text[i], font: at(font, i), weight: Int(at(weight, i)) ?? 0, size: Float(at(size, i)) ?? 0,
                    color: hex(at(color, i)), darkColor: hex(at(darkColor, i)),
                    background: hex(at(background, i)), darkBackground: hex(at(darkBackground, i)),
                    press: Int(at(press, i)) ?? 0, tracking: Float(at(tracking, i)) ?? 0
                ))
                i += 1
            }
            out.append(runs)
        }
        return out
    }
}

struct CommitRowMenuItem: Identifiable {
    let id: Int
    let label: String
    let icon: String
    let press: Int
    let divider: Bool
    let destructive: Bool

    static func list(_ p: GenericProps) -> [CommitRowMenuItem] {
        let labels = p.getStringList("menu_label")
        let icons = p.getStringList("menu_icon")
        let presses = p.getStringList("menu_press")
        let kinds = p.getStringList("menu_kind")
        return labels.indices.map { i in
            let kind = i < kinds.count ? kinds[i] : ""
            return CommitRowMenuItem(
                id: i, label: labels[i], icon: i < icons.count ? icons[i] : "",
                press: i < presses.count ? Int(presses[i]) ?? 0 : 0,
                divider: kind == "divider", destructive: kind == "destructive"
            )
        }
    }
}

// MARK: - EDGE's per-node layers, only where a node uses them

extension View {
    /// NodeLayoutModifier's frame: the one `.frame` every EDGE node carries.
    func commitRowFrame(_ l: NodeLayout?) -> some View {
        frame(
            minWidth: CGFloat((l?.minWidth ?? 0) > 0 ? l!.minWidth : 0),
            idealWidth: l.flatMap { $0.widthMode == SizeMode.fixed && $0.width > 0 ? CGFloat($0.width) : nil },
            maxWidth: Self.commitRowMaxExtent(mode: l?.widthMode, value: l?.width, cap: l?.maxWidth),
            minHeight: CGFloat((l?.minHeight ?? 0) > 0 ? l!.minHeight : 0),
            idealHeight: l.flatMap { $0.heightMode == SizeMode.fixed && $0.height > 0 ? CGFloat($0.height) : nil },
            maxHeight: Self.commitRowMaxExtent(mode: l?.heightMode, value: l?.height, cap: l?.maxHeight),
            alignment: .topLeading
        )
    }

    /// NodeLayoutModifier's padding, for nodes that have any.
    func commitRowPadding(_ l: NodeLayout) -> some View {
        padding(EdgeInsets(
            top: CGFloat(l.paddingTop), leading: CGFloat(l.paddingLeft),
            bottom: CGFloat(l.paddingBottom), trailing: CGFloat(l.paddingRight)
        ))
    }

    static func commitRowMaxExtent(mode: Int?, value: Float?, cap: Float?) -> CGFloat {
        let base: CGFloat
        if mode == SizeMode.fixed, let v = value, v > 0 {
            base = CGFloat(v)
        } else {
            base = .infinity
        }
        if let cap, cap > 0 { return min(base, CGFloat(cap)) }
        return base
    }

    /// The clear `.shadow` every decorated EDGE node carries. It draws nothing but changes how a rounded
    /// clip antialiases, so nodes with a visible fill, clip or border keep it for the same pixels.
    func commitRowClearShadow() -> some View {
        shadow(color: .clear, radius: 0, x: 0, y: 0)
    }

    /// A tap that reaches PHP as the Blade row's pressable would send it.
    func commitRowPress(_ callback: Int, node: Int) -> some View {
        contentShape(Rectangle())
            .onTapGesture { CommitRowPress.send(callback, node: node) }
    }

    /// A button's VoiceOver label, which EDGE's pressables don't apply on iOS.
    func commitRowButton(_ label: String) -> some View {
        accessibilityElement(children: .ignore)
            .accessibilityLabel(label)
            .accessibilityAddTraits(.isButton)
    }
}

enum CommitRowPress {
    static func send(_ callback: Int, node: Int) {
        guard callback != 0 else { return }
        KeyboardFocusPolicy.dispatchPress { NativeElementBridge.sendPressEvent(callback, nodeId: node) }
    }
}

// MARK: - The row

struct CommitRowView: View {
    let row: CommitRowProps
    let dark: Bool

    var body: some View {
        FlexContainer(direction: FlexDirection.row, justify: JustifyContent.start, align: 0, gap: 0, childNodes: row.rootChildren) {
            CommitRowContent(row: row, dark: dark)
            if let previous = row.previousColor {
                Color.clear
                    .commitRowFrame(row.previousLayout)
                    .background(colorFromARGB(previous.argb(dark)))
                    .commitRowClearShadow()
            }
            if row.tail {
                Color.clear
                    .commitRowFrame(row.tailLayout)
                    .background(colorFromARGB(row.laneColor.argb(dark)))
                    .commitRowClearShadow()
            }
            if row.replyTail, let border = row.replyTailBorder {
                Color.clear
                    .commitRowFrame(row.replyTailLayout)
                    .overlay {
                        NodeBorderShape(border: border)
                            .fill(colorFromARGB(row.laneColor.argb(dark)))
                            .allowsHitTesting(false)
                    }
            }
            if row.endDot {
                CommitRowDot(row: row, layout: row.endDotLayout, dark: dark)
            }
            if row.nodeIsDot {
                FlexContainer(direction: FlexDirection.column, justify: JustifyContent.start, align: 0, gap: 0, childNodes: row.nodeChildren) {
                    CommitRowDot(row: row, layout: row.dotLayout, dark: dark)
                }
                .commitRowFrame(row.nodeLayout)
                .commitRowPress(row.nodePress, node: row.id)
                .commitRowButton(row.nodeA11y)
            } else {
                CommitRowAvatar(row: row, dark: dark)
            }
        }
        .commitRowFrame(row.rootLayout)
        .background(colorFromARGB(row.surface.argb(dark)))
        .commitRowClearShadow()
    }
}

struct CommitRowContent: View {
    let row: CommitRowProps
    let dark: Bool

    var body: some View {
        FlexContainer(direction: FlexDirection.column, justify: JustifyContent.start, align: 0, gap: CGFloat(row.contentGap), childNodes: row.contentChildren) {
            FlexContainer(direction: FlexDirection.row, justify: JustifyContent.start, align: AlignItems.center, gap: row.headerGap, childNodes: row.headerChildren) {
                CommitRowText(text: row.name, size: row.nameSize, font: row.nameFont, color: row.nameColor.argb(dark), maxLines: 1)
                CommitRowText(text: row.handle, size: row.metaSize, font: row.metaFont, color: row.metaColor.argb(dark), maxLines: 1)
                CommitRowText(text: row.separator, size: row.metaSize, font: "", color: row.metaColor.argb(dark), maxLines: 0)
                CommitRowText(text: row.time, size: row.metaSize, font: row.metaFont, color: row.metaColor.argb(dark), maxLines: 0)
            }
            .commitRowFrame(row.headerLayout)

            if !row.replyingTo.isEmpty {
                CommitRowReplyingTo(row: row, dark: dark)
            }

            ForEach(row.paragraphs.indices, id: \.self) { index in
                CommitRowParagraph(row: row, runs: row.paragraphs[index], dark: dark)
                    .commitRowFrame(row.bodyLayout)
            }

            if row.showMorePress != 0 {
                FlexContainer(direction: FlexDirection.column, justify: JustifyContent.start, align: 0, gap: 0, childNodes: row.showMoreChildren) {
                    CommitRowText(text: row.showMoreLabel, size: CGFloat(row.bodySize), font: row.showMoreFont, color: row.showMoreColor.argb(dark), maxLines: 0)
                }
                .commitRowFrame(nil)
                .commitRowPress(row.showMorePress, node: row.id)
                .commitRowButton(row.showMoreLabel)
            }

            CommitRowActions(row: row, dark: dark)
                .commitRowFrame(row.actionsLayout)
        }
        .commitRowFrame(row.contentLayout)
        .commitRowPadding(row.contentLayout)
        .overlay {
            if let hairline = row.hairline {
                NodeBorderShape(border: hairline)
                    .fill(colorFromARGB(row.hairlineColor.argb(dark)))
                    .allowsHitTesting(false)
            }
        }
    }
}

/// A leaf text: NativeUITextRenderer's leaf path, then its node's frame.
struct CommitRowText: View {
    let text: String
    let size: CGFloat
    let font: String
    let color: Int
    let maxLines: Int

    var body: some View {
        if maxLines > 0 {
            styled
                .lineLimit(maxLines)
                .truncationMode(.tail)
                .frame(maxWidth: .infinity, alignment: .leading)
                .fixedSize(horizontal: false, vertical: true)
                .commitRowFrame(nil)
        } else {
            styled
                .frame(maxWidth: .infinity, alignment: .leading)
                .fixedSize(horizontal: false, vertical: true)
                .commitRowFrame(nil)
        }
    }

    private var styled: some View {
        Text(text)
            .nuiScaledFont(size: size, fontName: font.isEmpty ? nil : font)
            .lineSpacing(NativeUIFontResolver.lineSpacing(px: 0, mult: 0, fontSize: size, fontName: font))
            .foregroundColor(Color(argb: color))
            .multilineTextAlignment(.leading)
    }
}

/// A paragraph: NativeUITextRenderer's composed path, one AttributedString of its runs.
struct CommitRowParagraph: View {
    let row: CommitRowProps
    let runs: [CommitRowRun]
    let dark: Bool

    @ObservedObject private var theme = NativeUITheme.shared

    var body: some View {
        Text(attributed)
            .lineSpacing(NativeUIFontResolver.lineSpacing(
                px: 0, mult: row.bodyLineHeight, fontSize: CGFloat(row.bodySize), fontName: row.bodyFont
            ))
            .multilineTextAlignment(.leading)
            .frame(maxWidth: .infinity, alignment: .leading)
            .fixedSize(horizontal: false, vertical: true)
            .environment(\.openURL, OpenURLAction { url in
                guard url.scheme == "nativephp-press" else { return .systemAction }
                let parts = url.pathComponents.filter { $0 != "/" }
                if parts.count == 2, let callback = Int(parts[0]), let node = Int(parts[1]) {
                    CommitRowPress.send(callback, node: node)
                }
                return .handled
            })
    }

    private var attributed: AttributedString {
        let family = theme.resolve(for: dark ? .dark : .light).fontFamily
        var out = AttributedString()
        for run in runs {
            let size = run.size > 0 ? run.size : row.bodySize
            var name = run.font.isEmpty ? row.bodyFont : run.font
            if name.isEmpty, !family.isEmpty, family != "System" {
                name = family
            }
            let weight = Self.weight(run.weight)
            var piece = AttributedString(run.text)
            if !name.isEmpty, let custom = NativeUIFontResolver.font(name, size: CGFloat(size), italic: false) {
                piece.font = custom.weight(weight)
            } else {
                piece.font = Font.system(size: CGFloat(size), weight: weight, design: .default)
            }
            let light = run.color != 0 ? run.color : row.bodyColor.light
            let darkColor = run.darkColor != 0 ? run.darkColor : row.bodyColor.dark
            let fg = dark && darkColor != 0 ? darkColor : light
            piece.foregroundColor = Color(argb: fg)
            let bg = dark && run.darkBackground != 0 ? run.darkBackground : run.background
            if bg != 0 {
                piece.backgroundColor = Color(argb: bg)
            }
            if run.tracking != 0 {
                piece.kern = CGFloat(run.tracking) * CGFloat(size)
            }
            if run.press != 0 {
                piece.link = URL(string: "nativephp-press://run/\(run.press)/\(row.id)")
            }
            out += piece
        }
        return out
    }

    private static func weight(_ weight: Int) -> Font.Weight {
        switch weight {
        case 1: return .thin
        case 2: return .light
        case 3: return .regular
        case 4: return .medium
        case 5: return .semibold
        case 6: return .bold
        case 7: return .heavy
        default: return .regular
        }
    }
}

struct CommitRowIcon: View {
    let name: String
    let size: CGFloat
    let color: Int

    var body: some View {
        NativeIcon.image(name)
            .resizable()
            .aspectRatio(contentMode: .fit)
            .frame(width: size, height: size)
            .foregroundColor(Color(argb: color))
            .accessibilityHidden(true)
            .commitRowFrame(nil)
    }
}

struct CommitRowReplyingTo: View {
    let row: CommitRowProps
    let dark: Bool

    var body: some View {
        let color = row.replyingToColor.argb(dark)
        let line = FlexContainer(direction: FlexDirection.row, justify: JustifyContent.start, align: AlignItems.center, gap: CGFloat(row.replyingToGap), childNodes: row.replyingToChildren) {
            CommitRowIcon(name: row.replyingToIcon, size: row.replyingToIconSize, color: color)
            CommitRowText(text: row.replyingTo, size: row.replyingToSize, font: row.replyingToFont, color: color, maxLines: 0)
        }
        .commitRowFrame(row.replyingToLayout)

        if row.replyingToPress != 0 {
            line
                .commitRowPress(row.replyingToPress, node: row.id)
                .commitRowButton(row.replyingToA11y)
        } else {
            line
        }
    }
}

// MARK: - Actions

struct CommitRowActions: View {
    let row: CommitRowProps
    let dark: Bool

    var body: some View {
        let muted = row.actionColor.argb(dark)
        let accent = row.accentColor.argb(dark)
        FlexContainer(direction: FlexDirection.row, justify: JustifyContent.start, align: AlignItems.center, gap: 0, childNodes: row.actionsChildren) {
            if row.starPress != 0 {
                let color = row.starOn ? accent : muted
                CommitRowTarget(layout: row.starLayout, children: row.starChildren, press: row.starPress, node: row.id, a11y: row.starA11y) {
                    CommitRowIcon(name: row.starIcon, size: row.iconSize, color: color)
                    if !row.starCount.isEmpty {
                        CommitRowText(text: row.starCount, size: row.labelSize, font: row.countFont, color: color, maxLines: 0)
                    }
                }
            }
            if let image = row.pastelImage {
                CommitRowTarget(layout: row.pastelLayout, children: row.pastelChildren, press: row.pastelPress, node: row.id, a11y: row.pastelA11y) {
                    NativeUIImageRenderer(node: image)
                        .commitRowFrame(image.layout)
                    CommitRowText(text: row.pastelCount, size: row.labelSize, font: row.countFont, color: muted, maxLines: 0)
                }
            }
            if row.quotePress != 0 {
                CommitRowTarget(layout: row.quoteLayout, children: row.quoteChildren, press: row.quotePress, node: row.id, a11y: row.quoteA11y) {
                    CommitRowIcon(name: row.quoteIcon, size: row.iconSize, color: muted)
                    if row.quoteStash {
                        CommitRowStashDot(layout: row.stashDotLayout, color: muted)
                    }
                }
            }
            if row.replyPress != 0 {
                CommitRowTarget(layout: row.replyLayout, children: row.replyChildren, press: row.replyPress, node: row.id, a11y: row.replyA11y) {
                    CommitRowIcon(name: row.replyIcon, size: row.iconSize, color: muted)
                    if !row.replyLabel.isEmpty {
                        CommitRowText(text: row.replyLabel, size: row.labelSize, font: "", color: muted, maxLines: 0)
                    }
                    if row.replyStash {
                        CommitRowStashDot(layout: row.replyStashLayout, color: muted)
                    }
                }
            }
            if row.repliesPress != 0 {
                CommitRowTarget(layout: row.repliesLayout, children: row.repliesChildren, press: row.repliesPress, node: row.id, a11y: row.repliesA11y) {
                    CommitRowIcon(name: row.repliesIcon, size: row.repliesIconSize, color: accent)
                    CommitRowText(text: row.repliesLabel, size: row.labelSize, font: "", color: accent, maxLines: 0)
                }
            }
            Color.clear.commitRowFrame(nil)
            CommitRowMore(row: row, dark: dark)
        }
    }
}

/// An action: a pressable laid out as a row, its whole box the target.
struct CommitRowTarget<Content: View>: View {
    let layout: NodeLayout
    let children: [NativeUINode]
    let press: Int
    let node: Int
    let a11y: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        let target = FlexContainer(
            direction: FlexDirection.row, justify: layout.justifyContent, align: layout.alignItems,
            gap: CGFloat(layout.gap), childNodes: children
        ) {
            content()
        }
        .commitRowFrame(layout)
        .commitRowPadding(layout)
        .commitRowClearShadow()

        // The pastel's count only (no press) is a plain row, read out by its label.
        if press != 0 {
            target
                .commitRowPress(press, node: node)
                .commitRowButton(a11y)
        } else {
            target
                .accessibilityElement(children: .ignore)
                .accessibilityLabel(a11y)
        }
    }
}

struct CommitRowStashDot: View {
    let layout: NodeLayout
    let color: Int

    var body: some View {
        Color.clear
            .commitRowFrame(layout)
            .background(colorFromARGB(color))
            .clipShape(RoundedRectangle(cornerRadius: 9999))
            .commitRowClearShadow()
    }
}

/// More: the platform's menu, its label the 44x36 column centring the icon.
struct CommitRowMore: View {
    let row: CommitRowProps
    let dark: Bool

    var body: some View {
        Menu {
            ForEach(row.menu) { item in
                if item.divider {
                    Divider()
                } else {
                    Button(role: item.destructive ? .destructive : nil) {
                        if item.press != 0 {
                            NativeElementBridge.sendPressEvent(item.press, nodeId: row.id)
                        }
                    } label: {
                        if !item.icon.isEmpty {
                            Label { Text(item.label) } icon: { NativeIcon.image(item.icon) }
                        } else {
                            Text(item.label)
                        }
                    }
                    .tint(item.destructive ? .red : nil)
                }
            }
        } label: {
            FlexContainer(direction: FlexDirection.column, justify: JustifyContent.start, align: 0, gap: 0, childNodes: row.moreLabelChildren) {
                FlexContainer(direction: FlexDirection.column, justify: JustifyContent.center, align: AlignItems.center, gap: 0, childNodes: row.moreIconChildren) {
                    CommitRowIcon(name: row.moreIcon, size: row.iconSize, color: row.actionColor.argb(dark))
                }
                .commitRowFrame(row.moreLabelLayout)
            }
            .contentShape(Rectangle())
        }
        .commitRowFrame(nil)
        .accessibilityLabel(row.moreA11y)
    }
}

// MARK: - The rail's node

/// A lane dot: a disc in the lane with a centre ringed in the surface colour.
struct CommitRowDot: View {
    let row: CommitRowProps
    let layout: NodeLayout
    let dark: Bool

    var body: some View {
        let lane = colorFromARGB(row.laneColor.argb(dark))
        NativeUIStackLayout(childNodes: row.dotChildren) {
            Color.clear
                .commitRowFrame(row.dotInnerLayout)
                .background(lane)
                .clipShape(RoundedRectangle(cornerRadius: 9999))
                .overlay {
                    RoundedRectangle(cornerRadius: 9999)
                        .strokeBorder(colorFromARGB(row.surface.argb(dark)), lineWidth: CGFloat(row.dotRing))
                }
                .commitRowClearShadow()
        }
        .commitRowFrame(layout)
        .background(lane)
        .clipShape(RoundedRectangle(cornerRadius: 9999))
        .commitRowClearShadow()
    }
}

/// The author's avatar ringed in their lane, with a gap in the surface colour.
struct CommitRowAvatar: View {
    let row: CommitRowProps
    let dark: Bool

    var body: some View {
        FlexContainer(direction: FlexDirection.column, justify: JustifyContent.start, align: 0, gap: 0, childNodes: row.nodeChildren) {
            NativeUIStackLayout(childNodes: row.ringChildren) {
                NativeUIStackLayout(childNodes: row.discChildren) {
                    CommitRowText(text: row.avatarInitial, size: row.avatarInitialSize, font: row.avatarInitialFont, color: row.avatarInitialColor.argb(dark), maxLines: 0)
                    if let image = row.avatarImage {
                        NativeUIImageRenderer(node: image)
                            .commitRowFrame(image.layout)
                            .clipShape(RoundedRectangle(cornerRadius: 9999))
                            .commitRowClearShadow()
                    }
                }
                .commitRowFrame(row.discLayout)
                .background(colorFromARGB(row.avatarWash.argb(dark)))
                .clipShape(RoundedRectangle(cornerRadius: 9999))
                .commitRowClearShadow()
            }
            .commitRowFrame(row.ringLayout)
            .background(colorFromARGB(row.surface.argb(dark)))
            .clipShape(RoundedRectangle(cornerRadius: 9999))
            .overlay {
                RoundedRectangle(cornerRadius: 9999)
                    .strokeBorder(colorFromARGB(row.laneColor.argb(dark)), lineWidth: CGFloat(row.avatarRing))
            }
            .commitRowClearShadow()
        }
        .commitRowFrame(row.nodeLayout)
        .commitRowPress(row.nodePress, node: row.id)
        .commitRowButton(row.nodeA11y)
    }
}
