import SwiftUI
import UIKit

struct AdaptiveFeedRenderer: View {
    let node: NativeUINode
    @ObservedObject private var themeStore = NativeUITheme.shared
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        AdaptiveFeedHost(node: node)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            // UIKit owns vertical safe areas. Insetting this host at the top
            // as well would reserve the header space twice in portrait.
            // Retained pages still inset controls and scrolling content.
            .ignoresSafeArea(.container, edges: .vertical)
            .background {
                // Backgrounds fill the rail region; readable rows and controls
                // continue to use the tab host's asymmetric safe area.
                themeStore.resolve(for: colorScheme).surface
                    .ignoresSafeArea(.container, edges: .all)
            }
            .environment(\.nativeUITheme, themeStore.resolve(for: colorScheme))
    }
}

// The detail tag is a descriptor consumed by the feed host, not a second router.
struct AdaptiveDetailRenderer: View {
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
        // The outer tab host already removes the system rail. UIKit still
        // propagates its window-side inset into each nested hosting root.
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

private final class AdaptiveWorkspaceController: UIViewController, UINavigationControllerDelegate {
    private let primary = UINavigationController()
    private let secondary = UINavigationController()
    private let divider = UIView()
    private let emptySelection = UILabel()
    private var pages: [Int: AdaptivePageController] = [:]
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
        emptySelection.text = "Select a commit to view its conversation"
        emptySelection.textAlignment = .center
        emptySelection.numberOfLines = 0
        emptySelection.adjustsFontForContentSizeCategory = true
        emptySelection.isHidden = true
        view.addSubview(emptySelection)
        secondary.view.isHidden = true
        divider.isHidden = true
    }

    func receive(_ node: NativeUINode, environment: EnvironmentValues) {
        latest = node
        inherited = environment
        revision &+= 1
        guard isViewLoaded else { return }
        reconcile()
    }

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        reconcile()
        layoutColumns()
        updateBottomBarClearance()
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
            // Only the horizontal bottom bar obstructs the reply field. A
            // side rail is already excluded by the outer SwiftUI tab host.
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

    private func layoutColumns() {
        let bounds = view.bounds
        if wide == true {
            let feedWidth = min(420, bounds.width * 0.42)
            primary.view.frame = CGRect(x: 0, y: 0, width: feedWidth, height: bounds.height)
            secondary.view.frame = CGRect(x: feedWidth + 1, y: 0, width: max(0, bounds.width - feedWidth - 1), height: bounds.height)
            divider.frame = CGRect(x: feedWidth, y: 0, width: 1, height: bounds.height)
            emptySelection.frame = secondary.view.frame.insetBy(dx: 32, dy: 0)
        } else {
            primary.view.frame = bounds
        }
    }

    private func reconcile() {
        guard !reconciling, !applying, !transitioning,
              primary.transitionCoordinator == nil, secondary.transitionCoordinator == nil,
              let latest, let inherited, view.bounds.width > 0, view.bounds.height > 0,
              let feed = latest.children.first(where: { $0.type != "adaptive_detail" }) else { return }
        // The window shape survives keyboard resizing and does not depend on
        // an interface orientation that may lag a Duo display switch.
        let viewport = view.window?.bounds ?? view.bounds
        let wantsWide = view.bounds.width >= 820 && viewport.width > viewport.height
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
            if let page = pages[node.id] {
                page.update(node, detail: detail, environment: inherited)
            } else {
                let page = AdaptivePageController(node: node, detail: detail, environment: inherited)
                page.onSafeAreaChange = { [weak self] in self?.view.setNeedsLayout() }
                pages[node.id] = page
            }
        }
        guard let feedPage = pages[feed.id] else { return }
        let detailPages: [UIViewController] = activeDetails.compactMap { pages[$0.id] }
        let primaryStack: [UIViewController] = wantsWide ? [feedPage] : [feedPage] + detailPages
        let secondaryStack: [UIViewController] = wantsWide ? detailPages : []

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
        emptySelection.isHidden = !wantsWide || !detailPages.isEmpty
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
        configureBars(feed: feed, details: details, environment: inherited, wide: wantsWide)
        primary.view.layoutIfNeeded()
        secondary.view.layoutIfNeeded()
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

    private func configureBars(feed: NativeUINode, details: [NativeUINode], environment: EnvironmentValues, wide: Bool) {
        let tokens = environment.nativeUITheme
        view.backgroundColor = UIColor(tokens.surface)
        divider.backgroundColor = UIColor(tokens.outlineVariant)
        emptySelection.textColor = UIColor(tokens.onSurfaceVariant)
        let font = NativeChromeFontResolver.postScriptName(for: "default")
            .flatMap { UIFont(name: $0, size: 15) } ?? UIFont.systemFont(ofSize: 15)
        emptySelection.font = UIFontMetrics(forTextStyle: .subheadline).scaledFont(for: font)
        for navigation in [primary, secondary] {
            navigation.navigationBar.tintColor = UIColor(tokens.onSurface)
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
            if wide {
                let close = UIBarButtonItem(title: "Close", style: .plain, target: self, action: #selector(AdaptiveWorkspaceController.close))
                close.accessibilityLabel = "Close conversation"
                actions.append(close)
            }
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
    @objc private func close() { send("close") }
    @objc private func deleteConversation() { send("delete") }

    private func send(_ action: String, depth: Int? = nil) {
        guard let latest else { return }
        let callback = latest.props.getCallbackId("on_change")
        guard callback != 0 else { return }
        // These describe the last APPLIED PHP history, not `latest`: a newer
        // publish may already be queued during an interactive pop. PHP must
        // compare both before accepting a delayed back/close notification.
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
