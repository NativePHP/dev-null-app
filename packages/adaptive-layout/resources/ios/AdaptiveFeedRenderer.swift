import SwiftUI
import UIKit

struct AdaptiveFeedRenderer: View {
    let node: NativeUINode
    @ObservedObject private var themeStore = NativeUITheme.shared
    @StateObject private var chrome = AdaptiveWorkspaceChrome()
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        AdaptiveFeedHost(node: node, chrome: chrome)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            // The workspace fills the window and its pages inset themselves.
            // On the outer display, nested navigation bars were observed to
            // move into the vertical bar only when their controller reaches
            // its edge; inset here, they stayed horizontal.
            .ignoresSafeArea(.container, edges: .all)
            .background(themeStore.resolve(for: colorScheme).surface)
            .environment(\.nativeUITheme, themeStore.resolve(for: colorScheme))
            // The workspace sits on the tab's root level, where core keeps the
            // tab bar. A conversation pushed over the feed in one column is a
            // detail screen, so hide the bar there as core does for its own
            // pushed levels. Beside the feed in two columns it stays.
            .toolbar(chrome.hidesTabBar ? .hidden : .automatic, for: .tabBar)
    }
}

private final class AdaptiveWorkspaceChrome: ObservableObject {
    @Published var hidesTabBar = false
}

// The detail and placeholder tags are descriptors consumed by the feed host,
// not a second router.
struct AdaptiveDetailRenderer: View {
    let node: NativeUINode

    var body: some View {
        ForEach(node.children) { child in
            NodeView(node: child).equatable()
        }
    }
}

// A page's bar is read by the feed host onto that page's navigation item,
// never drawn in the page.
struct AdaptiveBarRenderer: View {
    let node: NativeUINode

    var body: some View {
        EmptyView()
    }
}

private struct AdaptiveFeedHost: UIViewControllerRepresentable {
    let node: NativeUINode
    let chrome: AdaptiveWorkspaceChrome

    func makeUIViewController(context: Context) -> AdaptiveWorkspaceController {
        let controller = AdaptiveWorkspaceController()
        controller.chrome = chrome
        controller.receive(node, environment: context.environment)
        return controller
    }

    func updateUIViewController(_ controller: AdaptiveWorkspaceController, context: Context) {
        controller.receive(node, environment: context.environment)
    }
}

private final class AdaptivePaneInsets: ObservableObject {
    @Published var bottom: CGFloat = 0
    @Published var horizontal = HorizontalInsets()

    struct HorizontalInsets: Equatable {
        var leading: CGFloat = 0
        var trailing: CGFloat = 0
    }
}

/// A stable root type for every retained hosting controller. Only the direct
/// bottom_bar sentinel is removed; all native node ids and ancestors survive.
private struct AdaptiveNodeRoot: View {
    let content: NativeUINode
    let bottom: NativeUINode?
    let inherited: EnvironmentValues
    @ObservedObject var paneInsets: AdaptivePaneInsets

    var body: some View {
        GeometryReader { geometry in
            GeometryReader { contentGeometry in
                NodeView(node: content)
                    .equatable()
                    .environment(\.availableHeight, contentGeometry.size.height)
            }
            .modifier(AdaptiveBottomInset(bar: bottom, clearance: max(0, paneInsets.bottom - geometry.safeAreaInsets.bottom)))
            .environment(\.availableWidth, geometry.size.width)
            .environment(\.availableHeight, geometry.size.height)
            .environment(\.nativeSafeAreaTop, geometry.safeAreaInsets.top)
            .environment(\.nativeSafeAreaBottom, geometry.safeAreaInsets.bottom)
        }
        .safeAreaPadding(.leading, paneInsets.horizontal.leading)
        .safeAreaPadding(.trailing, paneInsets.horizontal.trailing)
        // A navigation controller hands its pages the window's horizontal
        // insets even in a column that stops short of the vertical bar. The
        // workspace measures what each column actually overlaps instead, as
        // safe area, so lists and backgrounds can still reach under the bar.
        .ignoresSafeArea(.container, edges: .horizontal)
        // UIHostingController is a new SwiftUI root, so it does not inherit the
        // representable's theme, window breakpoints, locale, or custom keys.
        .environment(\.self, inherited)
    }
}

/// Pins a detail's bottom bar (its composer) to the foot of its page as core
/// pins a screen's `bottom_bar` in a tab (`BottomBarInsetModifier`), lifted
/// over a tab bar that still shows below the column.
///
/// A bar that asks for the scroll edge effect (`edge_blur`) is placed with
/// `.safeAreaBar` on iOS 26+, so the system blurs what scrolls under it.
/// `.safeAreaInset` draws no such effect. The page's hosting controller then
/// shows the effect, which the tab's root level hides (see
/// `AdaptiveHostingController`). Any other bar is inset, with core's soft
/// glass behind it when it carries `glass`.
private struct AdaptiveBottomInset: ViewModifier {
    let bar: NativeUINode?
    let clearance: CGFloat
    @Environment(\.colorScheme) private var colorScheme

    func body(content: Content) -> some View {
        if let bar, let inner = bar.children.first {
            let edgeBlur = bar.props.getString("edge_blur", default: "")
            #if compiler(>=6.2)
            if !edgeBlur.isEmpty, #available(iOS 26.0, *) {
                let style: ScrollEdgeEffectStyle = edgeBlur == "hard" ? .hard : .soft
                content
                    .safeAreaBar(edge: .bottom) {
                        barContent(inner, bar, softGlass: false)
                    }
                    .scrollEdgeEffectStyle(style, for: .bottom)
            } else {
                inset(content, inner, bar)
            }
            #else
            inset(content, inner, bar)
            #endif
        } else {
            content
        }
    }

    private func inset(_ content: Content, _ inner: NativeUINode, _ bar: NativeUINode) -> some View {
        content.safeAreaInset(edge: .bottom, spacing: 0) {
            barContent(inner, bar, softGlass: true)
        }
    }

    /// The bar's root at its own height: the inset APIs propose a finite one,
    /// which a flex root would fill. Its background colour and the glass run
    /// on through the clearance and the bottom safe area.
    private func barContent(_ inner: NativeUINode, _ bar: NativeUINode, softGlass: Bool) -> some View {
        let dark = colorScheme == .dark ? inner.props.getColor("dark_bg_color", default: 0) : 0
        let background = dark != 0 ? dark : (inner.style?.bgColor ?? 0)
        return NodeView(node: inner)
            .equatable()
            .fixedSize(horizontal: false, vertical: true)
            .frame(maxWidth: .infinity)
            .padding(.bottom, clearance)
            .background((background == 0 ? Color.clear : Color(argb: background))
                .ignoresSafeArea(edges: .bottom))
            .background(alignment: .top) {
                BottomBarSoftGlass(
                    flags: softGlass ? bar.props.getInt("glass", default: 0) : 0,
                    opacity: CGFloat(bar.props.getFloat("glass_opacity", default: 1))
                )
            }
    }
}

/// A page's hosting controller. Under a bar that asks for the scroll edge
/// effect it shows that effect on the page's scroll view, after each layout.
///
/// SwiftUI can't show it here. The workspace sits on a tab's root level,
/// where core hides the bottom edge effect for the blur band it lays under
/// the tab bar (`TabBarEdgeBlurModifier`), and every page inherits that
/// environment. No view can show an effect an ancestor hid, so the effect is
/// turned back on in UIKit. SwiftUI only sets it again when its own setting
/// changes, so it stays on.
private final class AdaptiveHostingController: UIHostingController<AdaptiveNodeRoot> {
    var showsBottomEdgeEffect = false {
        didSet {
            if showsBottomEdgeEffect != oldValue { viewIfLoaded?.setNeedsLayout() }
        }
    }
    private weak var pageScrollView: UIScrollView?
    private var retryScheduled = false

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        showBottomEdgeEffect(retrying: true)
    }

    private func showBottomEdgeEffect(retrying: Bool) {
        #if compiler(>=6.2)
        guard showsBottomEdgeEffect, #available(iOS 26.0, *) else { return }
        if pageScrollView?.isDescendant(of: view) != true {
            pageScrollView = view.adaptivePageScrollView(in: view)
        }
        guard let scrollView = pageScrollView else {
            // SwiftUI can add the scroll view after this pass. Look once more
            // on the next turn, without asking for another layout.
            guard retrying, !retryScheduled else { return }
            retryScheduled = true
            DispatchQueue.main.async { [weak self] in
                self?.retryScheduled = false
                self?.showBottomEdgeEffect(retrying: false)
            }
            return
        }
        if scrollView.bottomEdgeEffect.isHidden {
            scrollView.bottomEdgeEffect.isHidden = false
        }
        #endif
    }
}

/// UIKit owns the navigation item; SwiftUI only owns the retained page content.
/// A hosting controller used directly as a navigation destination can replace
/// its title item when it moves between the two navigation controllers.
private final class AdaptivePageController: UIViewController {
    private let hosting: AdaptiveHostingController
    private let paneInsets: AdaptivePaneInsets
    private var requestedBottomInset: CGFloat = 0
    private var requestedHorizontalInsets = AdaptivePaneInsets.HorizontalInsets()
    var onSafeAreaChange: (() -> Void)?
    /// The primary column's buttons, when this page's bar carries them, and
    /// what PHP sent for them when they were made.
    var feedActions: [UIBarButtonItem]?
    var feedActionsSignature: [String] = []
    /// This page's own buttons, and what PHP sent for them when they were made.
    var ownActions: [UIBarButtonItem] = []
    var ownActionsSignature: [String] = []
    private(set) var source: NativeUINode
    private var contentNode: NativeUINode
    private var bottomNode: NativeUINode?
    /// The page's adaptive_bar: its title and buttons, which the workspace
    /// puts on this page's navigation item.
    private(set) var barNode: NativeUINode?

    init(node: NativeUINode, detail: Bool, environment: EnvironmentValues) {
        source = node
        let parts = Self.parts(of: node, detail: detail)
        contentNode = parts.content
        bottomNode = parts.bottom
        barNode = parts.bar
        let paneInsets = AdaptivePaneInsets()
        self.paneInsets = paneInsets
        hosting = AdaptiveHostingController(rootView: AdaptiveNodeRoot(content: parts.content, bottom: parts.bottom, inherited: environment, paneInsets: paneInsets))
        super.init(nibName: nil, bundle: nil)
        hosting.showsBottomEdgeEffect = Self.asksForEdgeBlur(parts.bottom)
        navigationItem.largeTitleDisplayMode = .never
    }

    /// Whether a bottom bar is placed with `.safeAreaBar` for the scroll edge
    /// effect (see `AdaptiveBottomInset`).
    private static func asksForEdgeBlur(_ bar: NativeUINode?) -> Bool {
        guard let bar, bar.children.first != nil else { return false }
        return !bar.props.getString("edge_blur", default: "").isEmpty
    }

    /// What a page draws, its bottom bar, and its bar. A detail's content is
    /// its component, the tag's first child; the primary column's is the
    /// whole tag. Neither draws the bar. The bottom bar is the bottom_bar
    /// element itself, holding the bar's root, as its props say how to
    /// place it (edge_blur) and what goes behind it (glass).
    private static func parts(of node: NativeUINode, detail: Bool) -> (content: NativeUINode, bottom: NativeUINode?, bar: NativeUINode?) {
        let bar = node.children.first { $0.type == "adaptive_bar" }
        var root = detail ? (node.children.first { $0.type != "adaptive_bar" } ?? node) : node
        if !detail, bar != nil {
            root = root.copy(children: root.children.filter { $0.type != "adaptive_bar" })
        }
        let sentinel = detail ? root.children.first { $0.type == "bottom_bar" } : nil
        let content = sentinel == nil ? root : root.copy(children: root.children.filter { $0.type != "bottom_bar" })
        return (content, sentinel, bar)
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) { fatalError("init(coder:) is not supported") }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .clear
        hosting.view.backgroundColor = .clear
        addChild(hosting)
        hosting.view.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(hosting.view)
        NSLayoutConstraint.activate([
            hosting.view.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            hosting.view.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            hosting.view.topAnchor.constraint(equalTo: view.topAnchor),
            hosting.view.bottomAnchor.constraint(equalTo: view.bottomAnchor),
        ])
        hosting.didMove(toParent: self)
    }

    override func viewSafeAreaInsetsDidChange() {
        super.viewSafeAreaInsetsDidChange()
        onSafeAreaChange?()
    }

    func reserveBottom(_ inset: CGFloat) {
        guard abs(requestedBottomInset - inset) > 0.5 else { return }
        requestedBottomInset = inset
        // UIKit may be laying out during a SwiftUI update. Publish after
        // that pass, and discard obsolete measurements from a transition.
        DispatchQueue.main.async { [weak self] in
            guard let self, self.requestedBottomInset == inset else { return }
            self.paneInsets.bottom = inset
        }
    }

    func reserveHorizontal(leading: CGFloat, trailing: CGFloat) {
        let insets = AdaptivePaneInsets.HorizontalInsets(leading: leading, trailing: trailing)
        guard insets != requestedHorizontalInsets else { return }
        requestedHorizontalInsets = insets
        DispatchQueue.main.async { [weak self] in
            guard let self, self.requestedHorizontalInsets == insets else { return }
            self.paneInsets.horizontal = insets
        }
    }

    func update(_ node: NativeUINode, detail: Bool, environment: EnvironmentValues) {
        if source !== node {
            source = node
            let parts = Self.parts(of: node, detail: detail)
            contentNode = parts.content
            bottomNode = parts.bottom
            barNode = parts.bar
            hosting.showsBottomEdgeEffect = Self.asksForEdgeBlur(parts.bottom)
        }
        // A resize never copies text from PHP or replaces a controller. The
        // unchanged node references also let equatable NodeView skip echoes.
        hosting.rootView = AdaptiveNodeRoot(content: contentNode, bottom: bottomNode, inherited: environment, paneInsets: paneInsets)
    }
}

private final class AdaptiveWordmarkView: UIImageView {
    override var intrinsicContentSize: CGSize { CGSize(width: 120, height: 32) }

    override func sizeThatFits(_ size: CGSize) -> CGSize { intrinsicContentSize }
}

/// A navigation controller gives its bar the window's horizontal insets even
/// in a column that stops short of the vertical bar. Limit them to what the
/// bar actually overlaps of its container's safe area.
private final class AdaptiveNavigationBar: UINavigationBar {
    override var safeAreaInsets: UIEdgeInsets {
        var insets = super.safeAreaInsets
        guard let superview else { return insets }
        let safeFrame = convert(superview.safeAreaLayoutGuide.layoutFrame, from: superview)
        insets.left = max(0, min(bounds.width, safeFrame.minX - bounds.minX))
        insets.right = max(0, min(bounds.width, bounds.maxX - safeFrame.maxX))
        return insets
    }
}

private final class AdaptiveWorkspaceController: UIViewController, UINavigationControllerDelegate {
    private let primary = UINavigationController(navigationBarClass: AdaptiveNavigationBar.self, toolbarClass: nil)
    private let secondary = UINavigationController(navigationBarClass: AdaptiveNavigationBar.self, toolbarClass: nil)
    private let divider = UIView()
    private var pages: [Int: AdaptivePageController] = [:]
    private var placeholder: AdaptivePageController?
    private var appliedFoldGap: CGRect?
    private var latest: NativeUINode?
    private var inherited: EnvironmentValues?
    private var wide: Bool?
    private var applying = false
    private var transitioning = false
    private var expectedPrimary: [UIViewController] = []
    private var expectedSecondary: [UIViewController] = []
    private var history: [Int] = []
    private var historyIdentities: [String] = []
    private var logicalDepth = 0
    private var intentRevision = 0
    // Hold the completed native pop until PHP acknowledges it. Repeated old
    // publishes must not immediately push the conversation back on screen.
    private var awaitingHistory: [Int]?
    private var poppedDepth: Int?
    private var reconciling = false
    private var revision: UInt64 = 0
    private var appliedRevision: UInt64?
    /// The primary column's title image, as last loaded, and its long press.
    private var titleImageName: String?
    private var titleImageView: UIImageView?
    private var titleLongPress: (callback: Int, node: Int) = (0, 0)
    weak var chrome: AdaptiveWorkspaceChrome?
    /// Whether the primary column's buttons were last put on the detail
    /// column's bar rather than its own. See `feedActionsOnDetail`.
    private var placedFeedActionsOnDetail: Bool?
    /// The primary column's buttons, as its bar last sent them.
    private var primaryActionNodes: [NativeUINode] = []

    /// Hide the tab bar while one column shows a conversation over the feed.
    /// Published on the next turn: this runs inside SwiftUI and UIKit updates.
    private func updateTabBar(top: UIViewController?) {
        let hides = wide == false && top != nil && top !== primary.viewControllers.first
        DispatchQueue.main.async { [weak self] in
            guard let chrome = self?.chrome, chrome.hidesTabBar != hides else { return }
            chrome.hidesTabBar = hides
        }
    }

    override func viewDidLoad() {
        super.viewDidLoad()
        for navigation in [primary, secondary] {
            navigation.delegate = self
            navigation.navigationBar.prefersLargeTitles = false
            addChild(navigation)
            view.addSubview(navigation.view)
            navigation.didMove(toParent: self)
        }
        view.addSubview(divider)
        secondary.view.isHidden = true
        divider.isHidden = true
        registerForTraitChanges([UITraitHorizontalSizeClass.self]) { (self: Self, _: UITraitCollection) in
            self.view.setNeedsLayout()
        }
        if #available(iOS 27.1, *) {
            // Hinge updates say nothing about when the division region turns
            // active, and it can turn active just after one, so re-read the
            // gap then and once more on the next run loop.
            view.addInteraction(UIHingeInteraction { [weak self] _, _ in
                self?.layOutIfFoldGapMoved()
                DispatchQueue.main.async { [weak self] in self?.layOutIfFoldGapMoved() }
            })
        }
    }

    func receive(_ node: NativeUINode, environment: EnvironmentValues) {
        latest = node
        inherited = environment
        revision &+= 1
        guard isViewLoaded else { return }
        // Deliver state even during transitions: a sheet's dismissed false
        // must reach its retained root before the next request to show it.
        // Only navigation-stack mutations wait for reconciliation below.
        for child in node.children {
            pages[child.id]?.update(child, detail: child.type == "adaptive_detail", environment: environment)
        }
        if let placeholderNode = node.children.first(where: { $0.type == "adaptive_placeholder" }) {
            placeholder?.update(placeholderNode, detail: false, environment: environment)
        }
        reconcile()
    }

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        if let placed = placedFeedActionsOnDetail, placed != feedActionsOnDetail {
            appliedRevision = nil
        }
        reconcile()
        layoutColumns()
        updateBottomBarClearance()
        updateHorizontalInsets()
    }

    override func viewSafeAreaInsetsDidChange() {
        super.viewSafeAreaInsetsDidChange()
        view.setNeedsLayout()
    }

    override func viewWillTransition(to size: CGSize, with coordinator: UIViewControllerTransitionCoordinator) {
        super.viewWillTransition(to: size, with: coordinator)
        // Duo can leave its system tab bar covering the entire container after
        // resizing. Ask the owning container to lay out outside the transition
        // callback, without overriding its frames or retrying child layout.
        coordinator.animate(alongsideTransition: nil) { [weak self] _ in
            DispatchQueue.main.async { [weak self] in
                guard let self, self.viewIfLoaded?.window != nil, let tabs = self.tabBarController else { return }
                tabs.view.setNeedsLayout()
                tabs.view.layoutIfNeeded()
                self.view.setNeedsLayout()
            }
        }
    }

    private func updateBottomBarClearance() {
        var bottomBar: UIView?
        if let bar = tabBarController?.tabBar, !bar.isHidden, bar.alpha > 0,
           bar.window === view.window {
            let overlap = view.bounds.intersection(bar.convert(bar.bounds, to: view))
            // Only the horizontal bottom bar obstructs the reply field. The
            // vertical bar is a horizontal safe-area inset of each column.
            // Reject the full-window tab-bar frame seen during Duo resizing.
            if !overlap.isNull, overlap.width > overlap.height,
               overlap.height < view.bounds.height / 2 {
                bottomBar = bar
            }
        }
        for navigation in [primary, secondary] {
            guard let page = navigation.topViewController as? AdaptivePageController,
                  page.isViewLoaded, page.view.window === view.window else { continue }
            let required = bottomBar.map { bar in
                max(0, page.view.bounds.maxY - bar.convert(bar.bounds, to: page.view).minY)
            } ?? 0
            // SwiftUI subtracts its own live safe area from this reservation.
            // Updating UIKit's additional insets is insufficient after a page
            // moves: its hosting view can keep the previous parent's insets.
            page.reserveBottom(required)
        }
    }

    private func updateHorizontalInsets() {
        // The safe-area guide is solved lazily, and these columns are laid out
        // by frame, so after a resize it can keep an intermediate size's frame.
        let safe = view.bounds.inset(by: view.safeAreaInsets)
        let isRightToLeft = view.effectiveUserInterfaceLayoutDirection == .rightToLeft
        for navigation in [primary, secondary] where !navigation.view.isHidden {
            let column = navigation.view.frame
            let leading = max(0, min(column.width, safe.minX - column.minX))
            let trailing = max(0, min(column.width, column.maxX - safe.maxX))
            for case let page as AdaptivePageController in navigation.viewControllers {
                page.reserveHorizontal(leading: isRightToLeft ? trailing : leading, trailing: isRightToLeft ? leading : trailing)
            }
        }
    }

    private func layoutColumns() {
        let bounds = view.bounds
        guard wide == true else {
            primary.view.frame = bounds
            return
        }
        let fold = foldGap(in: bounds)
        appliedFoldGap = fold
        primary.view.frame = CGRect(x: bounds.minX, y: bounds.minY, width: max(0, fold.minX - bounds.minX), height: bounds.height)
        secondary.view.frame = CGRect(x: fold.maxX, y: bounds.minY, width: max(0, bounds.maxX - fold.maxX), height: bounds.height)
        let hairline = 1 / max(1, view.traitCollection.displayScale)
        divider.frame = CGRect(x: fold.midX - hairline / 2, y: bounds.minY, width: hairline, height: bounds.height)
    }

    private func layOutIfFoldGapMoved() {
        guard wide == true, foldGap(in: view.bounds) != appliedFoldGap else { return }
        view.setNeedsLayout()
    }

    /// Where the columns part: the fold, for an even split around the hinge.
    /// Flat, they meet on it. Partly folded, its division region is active
    /// and they stop at its margins, keeping controls out of the crease.
    /// A display without a fold across the split gets an even split.
    /// PagerSplit in the patched nativephp/mobile-ui NativeUIPagerRenderer.swift
    /// applies the same rule; keep the two alike.
    private func foldGap(in bounds: CGRect) -> CGRect {
        let middle = CGRect(x: bounds.minX + (bounds.width / 2).rounded(), y: bounds.minY, width: 0, height: bounds.height)
        guard #available(iOS 27.1, *) else { return middle }
        let divisions = view.reservedRegions(kind: .division, options: .includeInactive)
            .filter { $0.frame.height > $0.frame.width && $0.frame.minX > bounds.minX && $0.frame.maxX < bounds.maxX }
        guard let division = divisions.min(by: { abs($0.frame.midX - bounds.midX) < abs($1.frame.midX - bounds.midX) }) else {
            return middle
        }
        return division.isActive ? division.frame : CGRect(x: division.frame.midX, y: bounds.minY, width: 0, height: bounds.height)
    }

    private func reconcile() {
        guard !reconciling, !applying, !transitioning,
              primary.transitionCoordinator == nil, secondary.transitionCoordinator == nil,
              let latest, let inherited, view.bounds.width > 0, view.bounds.height > 0,
              let feed = latest.children.first(where: { $0.type != "adaptive_detail" && $0.type != "adaptive_placeholder" }) else { return }
        // Regular width alone would split the inner display in portrait into
        // two skinny columns. Like Apple's arrangements, also weigh the aspect
        // ratio: only a window wider than it is tall gets two columns. The
        // window's shape, unlike the view's, survives keyboard resizing.
        // A workspace with nothing to open beside its primary column (Remotes
        // while it's off) asks to keep one column.
        let window = view.window?.bounds ?? view.bounds
        let wantsWide = latest.props.getBool("split", default: true)
            && traitCollection.horizontalSizeClass == .regular && window.width > window.height
        // Root-view assignment can schedule another UIKit layout. A bounds-only
        // layout in the same mode must not assign all hosting roots again.
        guard appliedRevision != revision || wide != wantsWide else { return }
        reconciling = true
        defer { reconciling = false }
        appliedRevision = revision

        let details = latest.children.filter { $0.type == "adaptive_detail" }
        let requestedDepth = max(0, latest.props.getInt("depth"))
        let requestedIntent = latest.props.getInt("intent_revision")
        let currentHistory = details.map(\.id)
        if let awaitingHistory, let poppedDepth {
            // Two completed pops can outrun PHP. An intermediate ancestor
            // acknowledgement must not repush the second screen just popped.
            let ancestor = currentHistory.count <= awaitingHistory.count
                && currentHistory == Array(awaitingHistory.prefix(currentHistory.count))
            if requestedIntent != intentRevision || !ancestor || requestedDepth <= poppedDepth {
                self.awaitingHistory = nil
                self.poppedDepth = nil
            }
        }
        history = currentHistory
        historyIdentities = details.map { $0.props.getString("identity", default: String($0.id)) }
        logicalDepth = requestedDepth
        intentRevision = requestedIntent
        // PHP owns history. Truncate to its explicit depth; never invent a
        // detail screen while children are still arriving asynchronously.
        let visibleDepth = min(details.count, poppedDepth ?? requestedDepth)
        let activeDetails = Array(details.prefix(visibleDepth))
        let migrating = wide != wantsWide
        let oldSelection = (wide == true ? secondary.topViewController : primary.topViewController)
        let focused = migrating ? view.adaptiveFirstResponder() : nil

        for (node, detail) in [(feed, false)] + details.map({ ($0, true) }) {
            if pages[node.id] == nil {
                let page = AdaptivePageController(node: node, detail: detail, environment: inherited)
                page.onSafeAreaChange = { [weak self] in self?.view.setNeedsLayout() }
                pages[node.id] = page
            }
        }
        if let placeholderNode = latest.children.first(where: { $0.type == "adaptive_placeholder" }), placeholder == nil {
            placeholder = AdaptivePageController(node: placeholderNode, detail: false, environment: inherited)
        }
        guard let feedPage = pages[feed.id] else { return }
        let detailPages: [UIViewController] = activeDetails.compactMap { pages[$0.id] }
        let emptyDetail: [UIViewController] = placeholder.map { [$0] } ?? []
        let primaryStack: [UIViewController] = wantsWide ? [feedPage] : [feedPage] + detailPages
        let secondaryStack: [UIViewController] = wantsWide ? (detailPages.isEmpty ? emptyDetail : detailPages) : []

        // One column on screen that only gains or loses levels at the top is a
        // push or a pop, so it slides like one. Anything else (first mount,
        // resizing into or out of two columns, a swapped history) snaps.
        let oldPrimary = primary.viewControllers
        let animatePrimary = !migrating && !wantsWide && view.window != nil && !oldPrimary.isEmpty
            && oldPrimary.count != primaryStack.count
            && (sameControllers(oldPrimary, Array(primaryStack.prefix(oldPrimary.count)))
                || sameControllers(primaryStack, Array(oldPrimary.prefix(primaryStack.count))))

        applying = true
        if migrating {
            // Release both old parents BEFORE adding any page to the other
            // nav controller. UINavigationController cannot share children.
            primary.setViewControllers([], animated: false)
            secondary.setViewControllers([], animated: false)
        }
        wide = wantsWide
        // Establish destination visibility and bounds before attaching retained
        // hosting roots, rather than first mounting them at the old geometry.
        secondary.view.isHidden = !wantsWide
        divider.isHidden = !wantsWide
        layoutColumns()
        expectedPrimary = primaryStack
        expectedSecondary = secondaryStack
        if !sameControllers(primary.viewControllers, primaryStack) {
            // `applying` keeps the delegate from treating this as the user's
            // back gesture. Further reconciles wait out the transition
            // coordinator, and didShow reconciles again once it ends.
            primary.setViewControllers(primaryStack, animated: animatePrimary)
        }
        if !sameControllers(secondary.viewControllers, secondaryStack) {
            secondary.setViewControllers(secondaryStack, animated: false)
        }
        if migrating {
            // Removing an item from its old bar can detach the title view
            // after it has been assigned to the destination. Rebuild only
            // the chrome, once both retained stacks have their new parents.
            titleImageName = nil
            for page in pages.values { page.navigationItem.titleView = nil }
        }
        configureBars(feed: feed, details: details, environment: inherited)
        primary.view.layoutIfNeeded()
        secondary.view.layoutIfNeeded()
        updateHorizontalInsets()
        applying = false
        updateTabBar(top: primary.topViewController)
        let validIDs = Set([feed.id] + currentHistory)
        pages = pages.filter { validIDs.contains($0.key) }

        let selection = wantsWide ? secondary.topViewController : primary.topViewController
        if migrating, oldSelection === selection, let focused {
            // Best effort only. Do not steal a newly acquired focus or write
            // any text/selection value; SwiftUI/UIKit may discard the field.
            DispatchQueue.main.async { [weak self, weak focused, weak selection] in
                guard let self, let focused, let selection,
                      (self.wide == true ? self.secondary.topViewController : self.primary.topViewController) === selection,
                      focused.window != nil, focused.canBecomeFirstResponder,
                      focused.window?.adaptiveFirstResponder() == nil else { return }
                focused.becomeFirstResponder()
            }
        }
    }

    private func sameControllers(_ left: [UIViewController], _ right: [UIViewController]) -> Bool {
        left.count == right.count && zip(left, right).allSatisfy { $0.0 === $0.1 }
    }

    func navigationController(_ navigationController: UINavigationController, willShow viewController: UIViewController, animated: Bool) {
        guard !applying else { return }
        // Bring the tab bar back as a back swipe or chevron starts, not after it.
        if navigationController === primary { updateTabBar(top: viewController) }
        guard animated else { return }
        transitioning = true
    }

    func navigationController(_ navigationController: UINavigationController, didShow viewController: UIViewController, animated: Bool) {
        guard !applying else { return }
        transitioning = false
        // A cancelled back swipe ends where it started.
        updateTabBar(top: primary.topViewController)
        let expected = navigationController === primary ? expectedPrimary : expectedSecondary
        let actual = navigationController.viewControllers
        // didShow fires after a cancelled gesture too: only a STRICT prefix
        // removal is a committed user pop. Resize mutations are synchronous
        // and guarded by applying, so cannot produce a back event.
        if actual.count < expected.count,
           sameControllers(actual, Array(expected.prefix(actual.count))) {
            let depth = navigationController === primary ? max(0, actual.count - 1) : actual.count
            awaitingHistory = history
            poppedDepth = depth
            if navigationController === primary {
                expectedPrimary = actual
            } else {
                expectedSecondary = actual
            }
            send("back", depth: depth)
        }
        // The coordinator may still be non-nil in didShow. Wait one turn;
        // updates received during the gesture remain in latest until here.
        DispatchQueue.main.async { [weak self] in self?.reconcile() }
    }

    private func configureBars(feed: NativeUINode, details: [NativeUINode], environment: EnvironmentValues) {
        let tokens = environment.nativeUITheme
        view.backgroundColor = UIColor(tokens.surface)
        divider.backgroundColor = UIColor(tokens.outlineVariant)
        let appearance = UINavigationBarAppearance()
        appearance.configureWithOpaqueBackground()
        appearance.backgroundColor = UIColor(tokens.surface)
        for navigation in [primary, secondary] {
            navigation.navigationBar.tintColor = UIColor(tokens.onSurface)
            navigation.navigationBar.standardAppearance = appearance
            navigation.navigationBar.scrollEdgeAppearance = appearance
        }
        if let placeholder {
            // Nothing sits in the empty detail's bar, so it draws no edge.
            let bare = appearance.copy()
            bare.shadowColor = .clear
            placeholder.navigationItem.standardAppearance = bare
            placeholder.navigationItem.scrollEdgeAppearance = bare
        }
        guard let feedPage = pages[feed.id] else { return }
        let primaryBar = feedPage.barNode
        primaryActionNodes = Self.actionNodes(of: primaryBar)
        feedPage.title = primaryBar?.props.getString("title")
        if #available(iOS 27.2, *) {
            feedPage.navigationItem.titleAlignment = .leading
        }
        feedPage.navigationItem.titleView = titleImage(for: primaryBar, environment: environment)
            ?? titleView(for: primaryBar, on: feedPage, tokens: tokens)
        let actionsOnDetail = feedActionsOnDetail
        placedFeedActionsOnDetail = actionsOnDetail
        setRightItems(actionsOnDetail ? [] : feedActions(for: feedPage), on: feedPage)
        if let placeholder {
            setRightItems(actionsOnDetail ? feedActions(for: placeholder) : [], on: placeholder)
        }
        for detail in details {
            guard let page = pages[detail.id] else { continue }
            page.title = page.barNode?.props.getString("title")
            page.navigationItem.titleView = titleView(for: page.barNode, on: page, tokens: tokens)
            let actions = ownActions(for: page)
            if #available(iOS 26.0, *) {
                // Beside the primary column's buttons, each sits in its own
                // glass, apart from them, as the Log's delete always has.
                for item in actions { item.sharesBackground = !actionsOnDetail }
            }
            // The first right item sits last, so the primary column's actions
            // keep the top of the vertical bar whatever the page adds.
            page.navigationItem.rightBarButtonItems = actions + (actionsOnDetail ? feedActions(for: page) : [])
        }
    }

    /// The bar's title image (the Log's wordmark) in the appearance showing,
    /// with its long press. Kept while the image is the same.
    private func titleImage(for bar: NativeUINode?, environment: EnvironmentValues) -> UIImageView? {
        let light = bar?.props.getString("title_image") ?? ""
        let dark = bar?.props.getString("title_image_dark") ?? ""
        let name = environment.colorScheme == .dark && !dark.isEmpty ? dark : light
        titleLongPress = (bar?.onLongPress ?? 0, bar?.id ?? 0)
        guard !name.isEmpty else {
            titleImageName = nil
            titleImageView = nil
            return nil
        }
        if name != titleImageName {
            titleImageName = name
            if let image = UIImage(contentsOfFile: AppUpdateManager.shared.getAppPath() + "/public/" + name) {
                let imageView = AdaptiveWordmarkView(image: image)
                imageView.contentMode = .scaleAspectFit
                imageView.translatesAutoresizingMaskIntoConstraints = false
                NSLayoutConstraint.activate([
                    imageView.widthAnchor.constraint(equalToConstant: imageView.intrinsicContentSize.width),
                    imageView.heightAnchor.constraint(equalToConstant: imageView.intrinsicContentSize.height),
                ])
                imageView.isAccessibilityElement = true
                imageView.accessibilityLabel = bar?.props.getString("title")
                imageView.isUserInteractionEnabled = true
                imageView.addGestureRecognizer(UILongPressGestureRecognizer(target: self, action: #selector(AdaptiveWorkspaceController.titleLongPressed(_:))))
                titleImageView = imageView
            } else {
                titleImageView = nil
            }
        }
        return titleImageView
    }

    /// A title with its subtitle under it, as a commit's short hash or who is
    /// typing in a thread. A title alone is the bar's own. Rebuilt only when
    /// either changes.
    private func titleView(for bar: NativeUINode?, on page: AdaptivePageController, tokens: NativeUITokens) -> UIView? {
        let title = bar?.props.getString("title") ?? ""
        let subtitle = bar?.props.getString("subtitle") ?? ""
        guard !subtitle.isEmpty else { return nil }
        if let stack = page.navigationItem.titleView as? UIStackView, stack.accessibilityLabel == title + ", " + subtitle {
            (stack.arrangedSubviews.first as? UILabel)?.textColor = UIColor(tokens.onSurface)
            (stack.arrangedSubviews.last as? UILabel)?.textColor = UIColor(tokens.onSurfaceVariant)
            return stack
        }
        let heading = UILabel()
        heading.text = title
        heading.font = .preferredFont(forTextStyle: .headline)
        heading.adjustsFontForContentSizeCategory = true
        heading.textColor = UIColor(tokens.onSurface)
        let detail = UILabel()
        detail.text = subtitle
        detail.font = .preferredFont(forTextStyle: .caption1)
        detail.adjustsFontForContentSizeCategory = true
        detail.lineBreakMode = .byTruncatingMiddle
        detail.textColor = UIColor(tokens.onSurfaceVariant)
        let stack = UIStackView(arrangedSubviews: [heading, detail])
        stack.axis = .vertical
        stack.alignment = .center
        stack.isAccessibilityElement = true
        stack.accessibilityLabel = title + ", " + subtitle
        return stack
    }

    /// Whether the primary column's buttons (the Log's search and compose)
    /// belong on the detail column's bar.
    ///
    /// The system moves a navigation bar's items into the vertical bar only
    /// when that bar reaches the vertical bar's edge. In two columns only the
    /// detail column does, so the feed's own items would sit at the top in
    /// the middle of the screen, where no other screen puts its actions. They
    /// join the detail column's items instead, ahead of the detail's own.
    /// Without a vertical bar (one column, or a wide window elsewhere) they
    /// stay on the feed's bar.
    private var feedActionsOnDetail: Bool {
        guard wide == true, #available(iOS 27.1, *) else { return false }
        let edge = traitCollection.verticalBarEdge
        guard edge != .unspecified else { return false }
        // layoutColumns puts the feed on the left and the detail on the right.
        let rightToLeft = view.effectiveUserInterfaceLayoutDirection == .rightToLeft
        return (edge == .trailing) != rightToLeft
    }

    /// The primary column's buttons for one page. Each page keeps its own: a
    /// bar button item can't sit in two bars, and during a push or pop in the
    /// detail column both pages' bars show.
    private func feedActions(for page: AdaptivePageController) -> [UIBarButtonItem] {
        let signature = primaryActionNodes.map(Self.signature)
        if page.feedActions == nil || page.feedActionsSignature != signature {
            page.feedActionsSignature = signature
            page.feedActions = barButtons(for: primaryActionNodes)
        }
        return page.feedActions ?? []
    }

    /// A detail's own buttons, from its bar.
    private func ownActions(for page: AdaptivePageController) -> [UIBarButtonItem] {
        let nodes = Self.actionNodes(of: page.barNode)
        let signature = nodes.map(Self.signature)
        if page.ownActionsSignature != signature || page.ownActions.count != nodes.count {
            page.ownActionsSignature = signature
            page.ownActions = barButtons(for: nodes)
        }
        return page.ownActions
    }

    private static func actionNodes(of bar: NativeUINode?) -> [NativeUINode] {
        bar?.children.filter { $0.type == "top_bar_action" } ?? []
    }

    /// What PHP sent for a button, menu and all. A button is made again only
    /// when this changes, so a frame doesn't rebuild it or close its menu.
    private static func signature(_ action: NativeUINode) -> String {
        let props = action.props
        let own = [
            props.getString("id"), props.getString("icon"), props.getString("label"), props.getString("subtitle"),
            props.getString("a11y_label"), String(props.getBool("disabled")), String(props.getBool("destructive")),
            String(props.getBool("divider")), String(action.onPress),
        ].joined(separator: "\u{1F}")
        let items = action.children.filter { $0.type == "top_bar_action" }.map(signature)
        return items.isEmpty ? own : own + "[" + items.joined(separator: "\u{1E}") + "]"
    }

    /// Bar buttons for a bar's actions, in PHP's order from the leading side,
    /// as core's own bar draws them. The first right item sits last.
    private func barButtons(for actions: [NativeUINode]) -> [UIBarButtonItem] {
        actions.reversed().map(barButton)
    }

    /// A button, or a pull-down menu of its own actions, that sends its press
    /// to the callback PHP registered for it, as core's bar does. That
    /// callback belongs to the component the bar is for.
    private func barButton(for action: NativeUINode) -> UIBarButtonItem {
        let label = action.props.getString("label")
        let accessibility = action.props.getString("a11y_label")
        let image = Self.icon(action.props.getString("icon"))
        let items = action.children.filter { $0.type == "top_bar_action" }
        let item: UIBarButtonItem
        if !items.isEmpty {
            item = UIBarButtonItem(title: image == nil ? label : nil, image: image ?? UIImage(systemName: "ellipsis"), primaryAction: nil, menu: Self.menu(items))
        } else {
            let callback = action.onPress
            let node = action.id
            item = UIBarButtonItem(title: image == nil ? label : nil, image: image, primaryAction: UIAction { _ in
                if callback != 0 { NativeElementBridge.sendPressEvent(callback, nodeId: node) }
            }, menu: nil)
        }
        item.isEnabled = !action.props.getBool("disabled")
        item.accessibilityLabel = accessibility.isEmpty ? (label.isEmpty ? nil : label) : accessibility
        return item
    }

    /// A menu's actions, split into sections at each divider.
    private static func menu(_ items: [NativeUINode]) -> UIMenu {
        var sections: [[UIMenuElement]] = [[]]
        for item in items {
            if item.props.getBool("divider") {
                sections.append([])
                continue
            }
            let callback = item.onPress
            let node = item.id
            let action = UIAction(title: item.props.getString("label"), image: icon(item.props.getString("icon"), inMenu: true)) { _ in
                if callback != 0 { NativeElementBridge.sendPressEvent(callback, nodeId: node) }
            }
            let subtitle = item.props.getString("subtitle")
            if !subtitle.isEmpty { action.subtitle = subtitle }
            if item.props.getBool("destructive") { action.attributes.insert(.destructive) }
            if item.props.getBool("disabled") { action.attributes.insert(.disabled) }
            sections[sections.count - 1].append(action)
        }
        let filled = sections.filter { !$0.isEmpty }
        if filled.count == 1 { return UIMenu(children: filled[0]) }
        return UIMenu(children: filled.map { UIMenu(options: .displayInline, children: $0) })
    }

    /// An icon as PHP names it: an app icon (`custom:<name>`) from its
    /// template asset, sized as core's bar draws one in a button, else an SF
    /// Symbol.
    private static func icon(_ name: String, inMenu: Bool = false) -> UIImage? {
        guard !name.isEmpty else { return nil }
        guard let asset = NativeIcon.customAssetName(name), let image = UIImage(named: asset) else {
            return UIImage(systemName: getIconForName(name))
        }
        guard !inMenu else { return image.withRenderingMode(.alwaysTemplate) }
        let side = NativeIcon.side(forPointSize: 17)
        let sized = UIGraphicsImageRenderer(size: CGSize(width: side, height: side)).image { _ in
            image.draw(in: CGRect(x: 0, y: 0, width: side, height: side))
        }
        return sized.withRenderingMode(.alwaysTemplate)
    }

    /// Assigns only on a change, so a frame that leaves the items alone
    /// doesn't make the bar rebuild them.
    private func setRightItems(_ items: [UIBarButtonItem], on page: AdaptivePageController) {
        let current = page.navigationItem.rightBarButtonItems ?? []
        guard current.count != items.count || !zip(current, items).allSatisfy({ $0.0 === $0.1 }) else { return }
        page.navigationItem.rightBarButtonItems = items
    }

    @objc private func titleLongPressed(_ gesture: UILongPressGestureRecognizer) {
        guard gesture.state == .began, titleLongPress.callback != 0 else { return }
        NativeElementBridge.sendLongPressEvent(titleLongPress.callback, nodeId: titleLongPress.node)
    }

    private func send(_ action: String, depth: Int? = nil) {
        guard let latest else { return }
        let callback = latest.props.getCallbackId("on_change")
        guard callback != 0 else { return }
        // These describe the last APPLIED PHP history, not `latest`: a newer
        // publish may already be queued during an interactive pop. PHP must
        // compare both before accepting a delayed back notification.
        var payload: [String: Any] = [
            "action": action,
            "expected_depth": logicalDepth,
            "expected_revision": intentRevision,
            "expected_history": historyIdentities,
            "expected_node_ids": history,
        ]
        if let depth { payload["depth"] = depth }
        guard let bytes = try? JSONSerialization.data(withJSONObject: payload),
              let text = String(data: bytes, encoding: .utf8) else { return }
        NativeElementBridge.sendTextChangeEvent(callback, nodeId: latest.id, text: text)
    }
}

private extension UIView {
    func adaptiveFirstResponder() -> UIView? {
        if isFirstResponder { return self }
        for child in subviews {
            if let responder = child.adaptiveFirstResponder() { return responder }
        }
        return nil
    }

    /// The outermost scroll view that runs down to the foot of `page` and
    /// fills at least half of it: the thread or commit under the bottom bar,
    /// not a field or a strip of photos.
    func adaptivePageScrollView(in page: UIView) -> UIScrollView? {
        if let scrollView = self as? UIScrollView, !(self is UITextView) {
            let frame = convert(bounds, to: page)
            if frame.maxY >= page.bounds.maxY - 1, frame.height >= page.bounds.height / 2 {
                return scrollView
            }
        }
        for child in subviews {
            if let found = child.adaptivePageScrollView(in: page) { return found }
        }
        return nil
    }
}
