<laravel-boost-guidelines>
=== .ai/screenshots rules ===

# Screenshots in pull requests

- A pull request that changes anything a user can see needs before and after screenshots on iOS and Android, each in light and dark mode. Anything that moves (animations, transitions, sheets, scroll behaviour) needs recordings as well.
- Activate the `ui-screenshots` skill as soon as you start a change like that. The before shots are taken before you edit anything, so don't leave it until the PR.
- Never commit screenshots or recordings to the pull request branch. `php artisan screenshots:publish` puts them on an orphan branch and prints the table for the description.
- Open pull requests with the sections in `.github/pull_request_template.md`. `gh pr create --body` skips the template, so copy its headings yourself.
- If nothing visible changed, tick "No visible change" in the template instead of leaving the section empty. A CI check fails ready-for-review PRs that touch screens without either.

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== nativephp/mobile/core rules ===

## NativePHP Mobile

- NativePHP Mobile is a Laravel package for building **fully native** iOS and Android apps with PHP. Screens are
rendered as real SwiftUI (iOS) and Jetpack Compose (Android) UI — driven entirely by PHP via SuperNative components
and EDGE Blade elements. A full PHP runtime runs directly on the device with SQLite — no web server required.
- Documentation: `https://nativephp.com/docs/mobile/4/**`
- IMPORTANT: Always activate the `nativephp-mobile` skill every time you work on any NativePHP functionality.
- New app: `laravel new my-app --using=nativephp/mobile-starter --no-node`. A screen is a `NativeComponent` class,
  a Blade view in `resources/views/native/` (`php artisan native:make Name` creates both) and a `Route::native()`
  line in `routes/mobile.php`.
- Check behaviour with Pest and `Native\Mobile\Testing\Native::test()`. It needs no device and takes seconds, where a
  device build takes minutes. The skill has a worked screen, its test, and where builds put the .app and .apk.

### Native UI First — Always

**Always build screens with native UI: `NativeComponent` classes registered via `Route::native()`, rendering EDGE
elements (`native:column`, `native:text`, `native:button`, …).** This is the way to build NativePHP apps.

- Never scaffold new screens as web views, Blade-over-WebView pages, Livewire components, or Inertia pages.
- The web view (the `native:web-view` element) is a legacy/edge-case escape hatch for embedding web content — never the
  foundation of a screen. If the user asks for a webview-based screen, build it natively with EDGE instead and
  explain why; only fall back to the web view if they explicitly insist.
- If the app contains legacy webview screens, proactively suggest converting them to native UI (see the
  `nativephp-webview-to-native` skill).
- EDGE elements are rendered by the `nativephp/mobile-ui` plugin, even `column` and `text`. It must be installed
  and registered (`php artisan native:plugin:register nativephp/mobile-ui`) or elements disappear from the screen.
  Text fields are `outlined-text-input`, `filled-text-input` and `bare-text-input`; there is no `text-input`.
- Style EDGE elements with Tailwind utility classes via `class="..."` / `:class="..."` only — never inline
  CSS `style="..."` attributes or ad-hoc styling props.
- Compose screens from **nested child components**: any `NativeComponent` under `app/NativeComponents` mounts
  as a tag (`UserCard` → `<native:user-card :user="$u" key="user-{{ $u->id }}" @saved="onSaved" />`) with live
  props, its own persistent state, and `emit()` events bubbling to `@event` tag bindings / `#[On('event')]`
  listeners. Prefer extracting a reusable child component over duplicating Blade across screens; give list
  children a stable domain `key` (never the loop index).
- Use `native:icon` (SF Symbols on iOS, Material Icons on Android) for iconography — never emoji characters in
  UI text, labels, or buttons, unless the user explicitly asks for emojis. Prefer the typed icon enums
  (`App\Icons\Ios`, `App\Icons\Android`, `App\Icons\AndroidOutlined`) bound via the `:ios` / `:android`
  attributes, e.g. `:ios="Ios::Gearshape" :android="Android::Settings"`, importing each enum into the view with
  Blade's use directive first. The enums are generated, not shipped — if `app/Icons/` doesn't exist yet, run
  `php artisan native-ui:generate-icons` first (safe to run yourself).

### Theme Tokens, Font Aliases, and Layouts — the Design System Trio

Every app's visual identity belongs in `config/native-ui.php` (publish with
`php artisan vendor:publish --tag=native-ui-config`), not scattered through the markup. When building or
reviewing screens, enforce all three:

1. **Theme tokens over hardcoded colors.** Define the palette once in the config's `theme` block, then style
   with `bg-theme-*` / `text-theme-*` / `border-theme-*` classes (`bg-theme-surface`, `text-theme-on-surface`,
   `border-theme-outline`). Never sprinkle `bg-[#1E2021]`-style arbitrary values for what is really a theme
   role — they can't be re-skinned and don't get automatic dark-mode pairs. Arbitrary color values are for
   genuine data-driven color (per-category identity colors, map imagery, chart series), and those belong in
   one PHP home (an enum or model method), never inline per view. Two capabilities that prevent hex fallbacks:
   - **The token map is open-ended.** When a design needs a role the shipped set lacks (a success green, an
     `outline-variant`), add it to both `light` and `dark` blocks — `bg-theme-success` works immediately; no
     package change required.
   - **Theme classes take opacity modifiers** just like palette classes: `bg-theme-primary/15` is the correct
     tonal-fill idiom (applies to the dark companion too) — never approximate with a hardcoded alpha hex.
2. **Font aliases over file tokens.** Register semantic aliases in the config's `fonts` array
   (`'headline' => 'ArchivoNarrow-Bold'`, `'mono' => 'JetBrainsMono-Regular'`, `'default' => …` for the
   app-wide font) and write `font="headline"` in views — never `font="ArchivoNarrow-Bold"`. Swapping a
   typeface must be a one-line config change.
3. **Native chrome via composable chrome elements (layouts optional).** Author nav bars, tab bars, fabs, and
   side navs directly in the screen's Blade — `<native:top-bar>` (+ `top-bar-action`), `<native:bottom-nav>`
   (+ `bottom-nav-item`), `<native:fab>`, `<native:bottom-bar>`, `<native:side-nav>`. They hoist onto the real
   NavigationStack/TabView chrome (edge-swipe back, predictive back, large titles, Liquid Glass/Material You),
   and their attributes are Blade expressions over screen state, so badges/subtitles/icons are reactive. A
   `NativeLayout` (attached via `Route::native(...)->layout(...)` or `Route::nativeGroup(...)`) is **optional**
   — reach for one only when many screens share identical chrome (e.g. one tabs layout for a tab section); an
   inline chrome element on a screen always overrides the layout's bar for that slot. Add the `custom`
   attribute to a chrome tag only for designs the system bars genuinely can't express — it renders in-tree as
   an ordinary drawn element. Never hand-roll top bars or bottom navs out of rows and pressables — that
   forfeits native back gestures, safe-area handling, and Liquid Glass/Material You. Chrome colors take theme
   tokens (inline: theme classes / `theme()`-fed attributes; builders: `->activeColor(theme('primary'))`) —
   never pasted hex. Bar icons take the platform enums via `:ios-icon` / `:android-icon` with a plain `icon`
   string as cross-platform fallback; bar fonts take config aliases (`font="mono"` / `->font('mono')`). Only
   screens rendered without any chrome (no layout AND no inline bars) may use `safe-area` classes.

### When a Capability Is Missing

If the app needs native functionality or a UI component that core and `native-ui` don't provide:

1. **Look for an existing plugin first.** Check the plugin marketplace (`https://plugins.nativephp.com`) and the
   official core plugins. (If a marketplace-lookup MCP tool is available in your session, use it.)
2. **If no plugin exists, build a custom plugin** with `php artisan native:plugin:create` — plugins bundle
   Swift/Kotlin bridge functions, events, permissions, and can even ship their own native EDGE components.
3. **Never fall back to the web view to fill a native gap.** A missing capability is a reason to write a plugin,
   not a reason to build a webview screen.

### Installing Plugins — Always Register and Verify

Requiring a plugin with Composer is NOT enough — an installed-but-unregistered plugin does nothing. Every plugin
install must follow all three steps:

1. `composer require vendor/plugin-name`
2. `php artisan vendor:publish --tag=nativephp-plugins-provider` — publishes the app's `NativeServiceProvider`
   (needed once, before the first plugin registration; harmless to re-run)
3. `php artisan native:plugin:register vendor/plugin-name` — adds it to the `NativeServiceProvider`
4. `php artisan native:plugin:list` — verify it shows as registered

Then rebuild with `native:run` (see Build and Run below; native code only compiles in at build time). If
`native:run` warns "The following plugins are installed but not registered", go back to
step 3.

### Database Seeding — Always via Migrations

On-device there is no `db:seed` — NativePHP runs **migrations** on app start (once each, tracked, versioned).
Whenever asked to seed the database, use the migration trick: create a dedicated migration
(`php artisan make:migration seed_app_settings`) and put the inserts in `up()`. If a Seeder class helps organize
the data, still create it — but invoke it **from the migration's `up()`** (e.g. `(new CategorySeeder)->run()`),
never rely on `db:seed` being run. Seed migrations must be safe for both fresh installs and updates of existing
user databases.

### Build and Run — Do It Yourself

Check behaviour with `Native::test()` first. When a change needs a build to take effect or to be checked, run it
yourself from the Laravel root against a booted simulator or running emulator:

```bash
xcrun simctl list devices booted     # iOS simulator UDIDs
adb devices                          # Android serials
php artisan native:run ios <UDID> --no-tty -n
php artisan native:run android <SERIAL> --no-tty -n
```

Use the platform the user named; otherwise use whichever device is running. If no simulator or emulator is
available, say so and give the user the command instead. It returns once the app is installed and launched.

Ask first, or leave to the user:
- `--watch` and `native:watch` never exit, so don't block on them. Start one in the background only when the user
  wants hot reload.
- Release builds (`--build=release|bundle`), signing, `native:package` and store uploads.

Other commands: `native:open` (Xcode or Android Studio), `native:install` (install or upgrade the native shell).
The `./native` shortcut wraps the `native:` namespace. The Vite dev server is **opt-in** in v4: add `--vite` only
when the app uses JS/CSS HMR; native UI screens hot-reload without it. `npm run build -- --mode=ios|android` is only
needed for apps with web-view assets.

=== nativephp/mobile-browser/core rules ===

## nativephp/browser

Open URLs in system browser, in-app browser, and OAuth authentication sessions.

### PHP Usage (Livewire/Blade)

Use the `Browser` facade:

<code-snippet name="Using Browser Facade" lang="php">
use Native\Mobile\Facades\Browser;

// Open in in-app browser (keeps users in your app)
Browser::inApp('https://nativephp.com/mobile');

// Open in system browser (leaves your app)
Browser::open('https://nativephp.com/mobile');

// OAuth authentication with automatic redirect handling
Browser::auth('https://provider.com/oauth/authorize?client_id=123&redirect_uri=nativephp://127.0.0.1/auth/callback');
</code-snippet>

### JavaScript Usage (Vue/React/Inertia)

<code-snippet name="Using Browser in JavaScript" lang="javascript">
import { browser } from '#nativephp';

// Open in in-app browser
await browser.inApp('https://nativephp.com/mobile');

// Open in system browser
await browser.open('https://nativephp.com/mobile');

// OAuth authentication
await browser.auth('https://provider.com/oauth/authorize?client_id=123&redirect_uri=nativephp://127.0.0.1/auth/callback');
</code-snippet>

### Methods

- `Browser::inApp(string $url)` - Opens in embedded browser (SFSafariViewController/Chrome Custom Tabs)
- `Browser::open(string $url)` - Opens in device's default browser
- `Browser::auth(string $url)` - Opens OAuth authentication browser with automatic redirect handling

### When to Use Each Method

- **`inApp()`**: Keep users within your app for documentation, help pages, or related content
- **`open()`**: Use when full browser features are required for complex web applications
- **`auth()`**: Implement OAuth authentication flows with secure, automatic redirect handling

=== nativephp/mobile-ui/core rules ===

## nativephp/mobile-ui

Native UI components for NativePHP Mobile. Every element renders as a real
platform primitive — Material3 on Android, SwiftUI on iOS — not a webview
widget. Elements are declared in Blade with `<native:*>` tags or built
programmatically with the fluent `Native\Mobile\UI\Elements\*` API; both
paths serialize to the same wire tree.

### Core rules

- Activate the `nativephp-mobile-ui` skill when writing a screen's view. It lists each element's props and
  events, list-item swipe actions, and the text input traps.
- Text fields are `outlined-text-input`, `filled-text-input` and `bare-text-input`. There is no `text-input`.
  Bind them with `native:model.debounce.300ms` and also read the text `@submit` passes as the handler's last
  argument; live binding can drop characters under fast typing.
- `<native:list-item>` props are camelCase as written (`leadingCheckbox`, `trailingIconButton`). Swipe actions
  only work on list items that are direct children of `<native:list>`.
- Visual styling is theme-driven ("Model 3"): buttons, inputs, toggles, and
  other controls take their colors, radii, and typography from the theme
  (`Native\Mobile\UI\Theme`). Use semantic props like `variant="primary"`
  instead of per-instance colors — per-instance visual overrides on these
  controls are intentionally ignored.
- Bind state with `native:model="property"` (works on toggle, checkbox, chip,
  slider, select, radio-group, button-group, tab-row, and the text inputs).
  Use `.live` / `.blur` / `.debounce.Xms` modifiers to control sync frequency.
- Wire callbacks with event attributes (`@tap`, `@change`, `@submit`,
  `@dismiss`) pointing at public methods on the component.
- Image sources — `<native:image src>`, and `leadingAvatar` / `leadingImage`
  on `<native:list-item>` — all resolve the same way: a remote URL
  (`https://…`), a device file path (`/var/mobile/…/photo.jpg`, what the
  camera and gallery hand you), or a RELATIVE path, which resolves against the
  app's `public/` directory on device — `src="img/logo.png"` renders
  `public/img/logo.png`. Unlike the web, a leading slash means a device
  filesystem path, NOT the public root: write `img/logo.png`, never
  `/img/logo.png`.
- Text inputs also take `@selectionChange` for caret / selection reporting:
  the handler is called as `method(string $text, int $selectionStart, int
  $selectionEnd)` with offsets in Unicode code points (`start === end` for a
  plain caret). Events are coalesced natively (150ms default; tune with
  `selection-debounce-ms`). Never fired on `secure` inputs. Use it for
  typeahead / mention triggers where `@change` can't tell you *where* the
  user is typing.
- Each `@selectionChange` event carries the FULL current text and costs a
  component re-render, independent of the `native:model` sync mode — don't
  reach for it when plain `@change` would do.
- Add `revealable` alongside `secure` for the in-field eye that lets the user
  check what they typed. It is native-only — tapping it never round-trips to
  PHP and never touches the bound value — so don't hand-roll a Show / Hide
  button bound to a component property. Honored on `outlined-text-input` and
  `filled-text-input`; `bare-text-input` draws no chrome, so supply your own
  control there.
- `<native:date-picker>` handles dates, times, and both. Set `mode` to
  `date` (default), `time`, or `datetime`. Values are always wall-clock ISO
  strings — `2026-07-25`, `14:30`, `2026-07-25T14:30` — never offsets or
  epoch numbers, so they feed straight into `Carbon::parse()`. `value`,
  `min`, and `max` also accept any `DateTimeInterface`.
- On the picker, `timezone` (IANA) names the calendar the user picks in and
  never rewrites the value; `locale` (BCP-47) is display-only. Use
  `picker-style` = `compact` (default) / `inline` / `wheel` (NOT `display`,
  which is flex/layout display). `title`,
  `confirm-label`, and `cancel-label` are Android-only dialog chrome — pass
  translated strings. `min`/`max` are NOT supported for `mode="time"` (they
  throw), and sync-mode modifiers (`native:model.blur`) throw too — a picker
  always commits on selection.
- In tests, drive pickers with the `pickDate()` / `pickTime()` /
  `pickDateTime()` / `clearPicker()` macros and assert with
  `assertPicker()` / `assertPickerValue()` / `assertPickerEmpty()`.

<code-snippet name="Declaring native elements in Blade" lang="blade">
<native:column class="gap-4 p-4">
    <native:outlined-text-input label="Email" native:model.blur="email" />
    <native:outlined-text-input label="Message" native:model="message" @selectionChange="onCaretMove" />
    <native:date-picker label="Starts" mode="datetime" native:model="startsAt" />
    <native:toggle label="Notifications" native:model="notify" />
    <native:button variant="primary" @tap="save">Save</native:button>
</native:column>
</code-snippet>

### Theming & colors

- Everywhere a color is authored — theme tokens in `config/native-ui.php`,
  element color props (`->color()`, `headline-color`, badge `color`, swipe
  `tint`), and arbitrary-value classes (`bg-[#…]`) — the same grammar applies:
  - Tailwind palette names: `red-300`, `orange-800`
  - Special names: `white`, `black`, `transparent`
  - CSS hex: `#F00`, `#B91C1C`, and with alpha `#8B5CF680` (#RRGGBBAA order)
  - Opacity modifiers on any of the above: `red-300/20`, `#8B5CF6/50`
- Alpha-bearing hex is always authored in CSS `#RRGGBBAA` order; PHP converts
  to the native wire order — never hand-author Android-style `#AARRGGBB`.
- Dark mode: theme tokens carry a `dark` block (auto-derived when omitted),
  and `bg-theme-*` / `text-theme-*` / `border-theme-*` classes emit both
  modes automatically. This works for Blade-declared AND programmatically
  built elements (`Element->class()`).
- The theme token map is open-ended: add any semantic role your design
  needs (`success`, `warning`, `outline-variant`, …) to both `light` and
  `dark` blocks and the matching `*-theme-*` classes resolve immediately —
  never fall back to hardcoded hex for a missing role.
- `success`/`on-success` and `outline-variant` are first-class tokens: the
  native theme stores parse them (with fallbacks), and `variant="success"`
  works on `<native:button>` and `<native:badge>` for confirm / "safe to
  proceed" actions — prefer it over green hex or misusing `primary`.
- Theme classes accept opacity modifiers like every other color class:
  `bg-theme-primary/15` is the tonal-fill idiom (alpha applies to the dark
  companion too).
- In PHP — layout chrome builders (`->activeColor()`, `->backgroundColor()`),
  dynamic styling — read tokens with the appearance-aware `theme()` helper
  (`theme('primary')`) instead of raw `config()` paths or pasted hex.
- Disabled controls use the `surface-variant` (fill) + `on-surface-variant`
  (label) tokens on both platforms — tune disabled contrast by adjusting
  those two tokens, not per-component.
- `outlined-text-input` has NO fill by default — its box is transparent, so on
  a colored screen it stops reading as a field. Declare the optional
  `input-fill` / `on-input` token pair to give it one; `on-input` recolors
  everything drawn inside the box. Do not reach for `surface` / `surface-variant`
  for this, or every card moves with the field.
- Buttons render their variant token solid; for a softer tonal fill set
  opacity on the token itself (e.g. `'secondary' => 'fuchsia-500/70'`).
- `<native:icon>` accepts platform enum overrides as attributes —
  `:ios="Ios::House"` / `:android="Android::Home"` — matching the
  programmatic `Icon::make(ios: …, android: …)`.

<code-snippet name="Theme tokens accept the full color grammar" lang="php">
// config/native-ui.php
'light' => [
    'primary'   => 'violet-600',      // tailwind palette name
    'secondary' => 'fuchsia-500/70',  // with opacity → tonal fills
    'surface'   => '#F8FAFC',         // plain hex
    'accent'    => '#00AAA680',       // CSS alpha hex (#RRGGBBAA)
],
</code-snippet>

### Typography

- **Custom fonts.** Drop `.ttf`/`.otf`/`.ttc` files into the app's
  `resources/fonts/` and reference one by its filename (minus extension) with
  the `font` attribute: `font="Inter-Bold"` for `resources/fonts/Inter-Bold.ttf`.
  Works on `<native:text>`, `<native:button>`, and the text inputs; also fluent
  as `->font('Inter-Bold')`. The build's `copy_assets` hook bundles the files
  (iOS registers them by PostScript name, Android loads from `assets/fonts/`);
  an unresolved name falls back to the system font. Font size/weight still come
  from `text-*` / `font-*` classes and the theme.
- **Downloading fonts.** `php artisan native:font Lobster` (or
  `native:font "Rock Salt"`) downloads one Google Fonts family into
  `resources/fonts/` with ready-to-use token names — no API key. Interactively
  pick styles; non-interactively only Regular is fetched when available.
  Useful flags: `--default` (set as app-wide default), `--force` (overwrite
  files / skip reset confirm), `--clear-cache`, `--reset` (wipe bundled fonts
  when present and restore `fonts.default` to `System`).
- **Font aliases + app-wide default.** Name fonts semantically in
  `config/native-ui.php`: `'fonts' => ['default' => 'Inter-Regular',
  'accent' => 'DynaPuff-Regular']`. Use an alias anywhere a font token works
  (`font="accent"`, chrome `->font()`, layout `$font`); the `default` alias
  applies app-wide (superseding the older `font-family` token). Per-element
  `font` attributes and `font-serif`/`font-mono` classes still win over the
  default. `native:font Inter --default` sets it for you.
- **Line height (leading).** `leading-none|tight|snug|normal|relaxed|loose`
  (unitless multipliers of the font size), plus arbitrary `leading-[1.4]`
  (multiplier) and `leading-[24px]` (absolute). Applies to `<native:text>` and
  the text inputs; button labels are single-line so it has no visible effect
  there. Only affects multi-line text. iOS caveat: SwiftUI's `Text` only exposes
  additive line spacing, so *increasing* leading (`relaxed`/`loose`, or a large
  `leading-[…px]`) is exact, but tightening below the font's natural line height
  (`none`/`tight`) is limited — measured against the actual font, so custom
  fonts aren't over-spaced. Android is exact both ways.

<code-snippet name="Custom font + line height" lang="blade">
<native:text font="Inter-Bold" class="text-2xl">Heading</native:text>
<native:text class="text-base leading-relaxed">
    A comfortably-spaced paragraph that wraps across several lines.
</native:text>
</code-snippet>

### Accessibility

Screen-reader support rides on two props that every element accepts:
`a11y-label` (what VoiceOver / TalkBack announces; maps to
`accessibilityLabel` on iOS and `contentDescription` on Android) and
`a11y-hint` (supplementary usage guidance, read after the label; maps to
`accessibilityHint` on iOS and is appended to the content description on
Android). Both are also available fluently as `->a11yLabel()` / `->a11yHint()`.

- ALWAYS set `a11y-label` on icon-only buttons, chips, and tabs — with no
  visible text there is nothing for the screen reader to announce.
- Icons are decorative by default: an `<native:icon>` without `a11y-label` is
  silent to screen readers. Give it a label only when the icon itself carries
  meaning.
- Use `alt` on `<native:image>` for meaningful images; omit it for purely
  decorative ones.
- Use `a11y-hint` sparingly, for supplementary guidance the label doesn't
  cover ("Double-tap to reorder"). Never repeat the label in the hint.
- List items with a trailing icon button take `trailing-a11y-label` to label
  that button separately from the row, and `on-trailing-press` for its handler.
- Text scales with the user's system font size on both platforms
  automatically — don't hardcode layouts that break at larger type sizes.

<code-snippet name="Accessible icon-only controls" lang="blade">
<native:button icon="trash" a11y-label="Delete draft" a11y-hint="Deletes the draft permanently" @tap="deleteDraft" />
<native:icon name="checkmark.seal" a11y-label="Verified" />
<native:list-item headline="Team meeting" trailingIconButton="ellipsis" trailing-a11y-label="More options" />
<native:list-item headline="Buy milk" trailingIconButton="trash" trailing-a11y-label="Delete" on-trailing-press="deleteTodo({{ $todo->id }})" />
</code-snippet>

<code-snippet name="Fluent a11y API" lang="php">
use Native\Mobile\UI\Elements\Button;

Button::make()
    ->icon('plus')
    ->a11yLabel('Add item')
    ->a11yHint('Adds a new item to the list')
    ->onPress('addItem');
</code-snippet>

</laravel-boost-guidelines>
