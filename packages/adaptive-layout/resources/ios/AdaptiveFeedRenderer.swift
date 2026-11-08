import SwiftUI
import UIKit

struct AdaptiveFeedRenderer: View {
    let node: NativeUINode
    @ObservedObject private var themeStore = NativeUITheme.shared
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        AdaptiveFeedHost(node: node)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            // The workspace fills the window and its pages inset themselves, as
            // in any UIKit container. UIKit only moves a navigation bar into the
            // vertical bar when its controller reaches the bar's edge; inset
            // here, the nested bars stay horizontal on the outer display.
            .ignoresSafeArea(.container, edges: .all)
            .background {
                themeStore.resolve(for: colorScheme).surface
                    .ignoresSafeArea(.container, edges: .all)
            }
            .environment(\.nativeUITheme, themeStore.resolve(for: colorScheme))
    }
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

struct AdaptivePlaceholderRenderer: View {
    let node: NativeUINode

    var body: some View {
        ForEach(node.children) { child in
            NodeView(node: child).equatable()
        }
    }
}

private struct AdaptiveFeedHost: UIViewControllerRepresentable {
    let node: NativeUINode

    func makeUIViewController(context: Context) -> AdaptiveWorkspaceController {
        let controller = AdaptiveWorkspaceController()
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
            .modifier(AdaptiveBottomInset(bottom: bottom, clearance: max(0, paneInsets.bottom - geometry.safeAreaInsets.bottom)))
            .environment(\.availableWidth, geometry.size.width)
            .environment(\.availableHeight, geometry.size.height)
            .environment(\.nativeSafeAreaTop, geometry.safeAreaInsets.top)
            .environment(\.nativeSafeAreaBottom, geometry.safeAreaInsets.bottom)
        }
        .padding(.leading, paneInsets.horizontal.leading)
        .padding(.trailing, paneInsets.horizontal.trailing)
        // A navigation controller hands its pages the window's horizontal
        // insets even in a column that stops short of the vertical bar. The
        // workspace measures what each column actually overlaps instead.
        .ignoresSafeArea(.container, edges: .horizontal)
        // UIHostingController is a new SwiftUI root, so it does not inherit the
        // representable's theme, window breakpoints, locale, or custom keys.
        .environment(\.self, inherited)
    }
}

private struct AdaptiveBottomInset: ViewModifier {
    let bottom: NativeUINode?
    let clearance: CGFloat
    @Environment(\.colorScheme) private var colorScheme

    func body(content: Content) -> some View {
        if let bottom {
            let dark = colorScheme == .dark ? bottom.props.getColor("dark_bg_color", default: 0) : 0
            let background = dark != 0 ? dark : (bottom.style?.bgColor ?? 0)
            content.safeAreaInset(edge: .bottom, spacing: 0) {
                NodeView(node: bottom)
                    .equatable()
                    .fixedSize(horizontal: false, vertical: true)
                    .frame(maxWidth: .infinity)
                    .padding(.bottom, clearance)
                    .background((background == 0 ? Color.clear : Color(argb: background))
                        .ignoresSafeArea(edges: .bottom))
            }
        } else {
            content
        }
    }
}

/// UIKit owns the navigation item; SwiftUI only owns the retained page content.
/// A hosting controller used directly as a navigation destination can replace
/// its title item when it moves between the two navigation controllers.
private final class AdaptivePageController: UIViewController {
    private let hosting: UIHostingController<AdaptiveNodeRoot>
    private let paneInsets: AdaptivePaneInsets
    private var requestedBottomInset: CGFloat = 0
    private var requestedHorizontalInsets = AdaptivePaneInsets.HorizontalInsets()
    var onSafeAreaChange: (() -> Void)?
    private(set) var source: NativeUINode
    private var contentNode: NativeUINode
    private var bottomNode: NativeUINode?

    init(node: NativeUINode, detail: Bool, environment: EnvironmentValues) {
        source = node
        let root = detail ? (node.children.first ?? node) : node
        let sentinel = detail ? root.children.first { $0.type == "bottom_bar" } : nil
        let content = sentinel == nil ? root : root.copy(children: root.children.filter { $0.type != "bottom_bar" })
        let bottom = sentinel?.children.first
        contentNode = content
        bottomNode = bottom
        let paneInsets = AdaptivePaneInsets()
        self.paneInsets = paneInsets
        hosting = UIHostingController(rootView: AdaptiveNodeRoot(content: content, bottom: bottom, inherited: environment, paneInsets: paneInsets))
        super.init(nibName: nil, bundle: nil)
        navigationItem.largeTitleDisplayMode = .never
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
            let root = detail ? (node.children.first ?? node) : node
            let sentinel = detail ? root.children.first { $0.type == "bottom_bar" } : nil
            contentNode = sentinel == nil ? root : root.copy(children: root.children.filter { $0.type != "bottom_bar" })
            bottomNode = sentinel?.children.first
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
    private var hingeStatus: Int?
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
    private var logoName: String?
    private var logoView: UIImageView?

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
            // The fold's division region turns active as the hinge leaves
            // flat; angle updates in between do not move the columns.
            view.addInteraction(UIHingeInteraction { [weak self] _, update in
                guard let self else { return }
                let status = update.hinge?.status.rawValue
                guard status != self.hingeStatus else { return }
                self.hingeStatus = status
                self.view.setNeedsLayout()
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
        let safe = view.safeAreaLayoutGuide.layoutFrame
        for navigation in [primary, secondary] where !navigation.view.isHidden {
            let column = navigation.view.frame
            let leading = max(0, min(column.width, safe.minX - column.minX))
            let trailing = max(0, min(column.width, column.maxX - safe.maxX))
            let isRightToLeft = view.effectiveUserInterfaceLayoutDirection == .rightToLeft
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
        primary.view.frame = CGRect(x: bounds.minX, y: bounds.minY, width: max(0, fold.minX - bounds.minX), height: bounds.height)
        secondary.view.frame = CGRect(x: fold.maxX, y: bounds.minY, width: max(0, bounds.maxX - fold.maxX), height: bounds.height)
        let hairline = 1 / max(1, view.traitCollection.displayScale)
        divider.frame = CGRect(x: fold.midX - hairline / 2, y: bounds.minY, width: hairline, height: bounds.height)
    }

    /// Where the columns part: the fold, for an even split around the hinge.
    /// Flat, they meet on it. Partly folded, its division region is active
    /// and they stop at its margins, keeping controls out of the crease.
    /// A display without a fold across the split gets an even split.
    private func foldGap(in bounds: CGRect) -> CGRect {
        let middle = CGRect(x: bounds.midX, y: bounds.minY, width: 0, height: bounds.height)
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
        let wantsWide = traitCollection.horizontalSizeClass == .regular
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
            primary.setViewControllers(primaryStack, animated: false)
        }
        if !sameControllers(secondary.viewControllers, secondaryStack) {
            secondary.setViewControllers(secondaryStack, animated: false)
        }
        if migrating {
            // Removing an item from its old bar can detach the title view
            // after it has been assigned to the destination. Rebuild only
            // the chrome, once both retained stacks have their new parents.
            logoName = nil
            for page in pages.values { page.navigationItem.titleView = nil }
        }
        configureBars(feed: feed, details: details, environment: inherited)
        primary.view.layoutIfNeeded()
        secondary.view.layoutIfNeeded()
        updateHorizontalInsets()
        applying = false
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
        guard !applying, animated else { return }
        transitioning = true
    }

    func navigationController(_ navigationController: UINavigationController, didShow viewController: UIViewController, animated: Bool) {
        guard !applying else { return }
        transitioning = false
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
        feedPage.title = "Feed"
        if #available(iOS 27.2, *) {
            feedPage.navigationItem.titleAlignment = .leading
        }
        let name = environment.colorScheme == .dark ? "logo-dark@3x.png" : "logo-light@3x.png"
        if name != logoName {
            logoName = name
            if let image = UIImage(contentsOfFile: AppUpdateManager.shared.getAppPath() + "/public/images/" + name) {
                let imageView = AdaptiveWordmarkView(image: image)
                imageView.contentMode = .scaleAspectFit
                imageView.translatesAutoresizingMaskIntoConstraints = false
                NSLayoutConstraint.activate([
                    imageView.widthAnchor.constraint(equalToConstant: imageView.intrinsicContentSize.width),
                    imageView.heightAnchor.constraint(equalToConstant: imageView.intrinsicContentSize.height),
                ])
                imageView.isAccessibilityElement = true
                imageView.accessibilityLabel = "devnull"
                imageView.isUserInteractionEnabled = true
                imageView.addGestureRecognizer(UILongPressGestureRecognizer(target: self, action: #selector(AdaptiveWorkspaceController.playGame(_:))))
                logoView = imageView
            } else {
                logoView = nil
            }
        }
        feedPage.navigationItem.titleView = logoView
        if feedPage.navigationItem.rightBarButtonItems == nil {
            let compose = UIBarButtonItem(image: UIImage(systemName: "square.and.pencil"), style: .plain, target: self, action: #selector(AdaptiveWorkspaceController.compose))
            compose.accessibilityLabel = "Compose"
            let search = UIBarButtonItem(image: UIImage(systemName: "magnifyingglass"), style: .plain, target: self, action: #selector(AdaptiveWorkspaceController.search))
            search.accessibilityLabel = "Search"
            feedPage.navigationItem.rightBarButtonItems = [compose, search]
        }
        for detail in details {
            guard let page = pages[detail.id] else { continue }
            let title = detail.props.getString("title", default: "Commit")
            let subtitle = detail.props.getString("subtitle", default: "")
            if page.title != title || page.navigationItem.titleView?.accessibilityLabel != title + ", " + subtitle {
                page.title = title
                let heading = UILabel()
                heading.text = title
                heading.font = .preferredFont(forTextStyle: .headline)
                heading.adjustsFontForContentSizeCategory = true
                let hash = UILabel()
                hash.text = subtitle
                hash.font = .preferredFont(forTextStyle: .caption1)
                hash.adjustsFontForContentSizeCategory = true
                hash.lineBreakMode = .byTruncatingMiddle
                let stack = UIStackView(arrangedSubviews: [heading, hash])
                stack.axis = .vertical
                stack.alignment = .center
                stack.isAccessibilityElement = true
                stack.accessibilityLabel = title + ", " + subtitle
                page.navigationItem.titleView = stack
            }
            if let stack = page.navigationItem.titleView as? UIStackView {
                (stack.arrangedSubviews.first as? UILabel)?.textColor = UIColor(tokens.onSurface)
                (stack.arrangedSubviews.last as? UILabel)?.textColor = UIColor(tokens.onSurfaceVariant)
            }
            var actions: [UIBarButtonItem] = []
            if detail.props.getBool("can_delete") {
                let delete = UIBarButtonItem(image: UIImage(systemName: "trash"), style: .plain, target: self, action: #selector(AdaptiveWorkspaceController.deleteConversation))
                delete.accessibilityLabel = "Delete commit"
                actions.append(delete)
            }
            page.navigationItem.rightBarButtonItems = actions
        }
    }

    @objc private func compose() { send("compose") }
    @objc private func search() { send("search") }
    @objc private func playGame(_ gesture: UILongPressGestureRecognizer) {
        if gesture.state == .began { send("game") }
    }
    @objc private func deleteConversation() { send("delete") }

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
}
