# devnull/commit-row

dev/null's Log commit row as one native element, `<native:commit-row>`, instead of the ~33 EDGE nodes the Blade row
builds. It's a hand-written version of what a Blade-to-native compiler would emit for that one template, there to
measure the payoff on the phones before anyone builds a compiler.

It lives in the app (`packages/devnull/commit-row`, a path repository) and is registered in
`App\Providers\NativeServiceProvider`. The app's `partials/commit-row-native.blade.php` is the only template that uses
it, behind `App\Devnull\CommitRows` (see `config/devnull.php`).

## The props contract

The element takes no Tailwind classes. Every value it draws is a typed prop of its own, set through a typed setter on
`Devnull\CommitRow\Elements\CommitRow` (the docblocks there describe each one) or the matching kebab-case Blade
attribute (`:name-size="15"` calls `nameSize(15)`). Whatever picks a value stays in the template: the element and its
native views only draw final values. Values the feed row never varies (the 64pt gutter, 44x36 targets, the rail's
geometry) are props too, with the feed row's values as defaults, so nothing is hard-coded in Swift or Kotlin.

Types:

- `ColorPair`: a light and a dark hex. `ColorPair::theme('ink')` reads a theme token, `ColorPair::of('#3b6fe0')` any
  colour a class could name.
- `PlatformIcon`: an icon named for iOS and Android, resolved for the platform the app runs on.
- `Run`: one stretch of a paragraph in one style. Null fields take the paragraph's value.
- `MenuItem`: one of More's items, or a divider.
- Callbacks are method expressions (`"toggleStar('c1')"`), sent as callback ids. `null` means "not drawn".

On the wire a colour pair is a two-string list, callbacks are `on_*` ints, and the body's runs and the menu go as
parallel string lists (`run_*`, `menu_*`, with `paragraph_runs` splitting the runs into paragraphs).

## What it can't draw

Images, polls, quotes, link previews, code blocks, a series position or a status chip. `CommitRows::draws()` keeps
those commits on the Blade row.

## EDGE props that don't work on their own

Found while writing this, and worth knowing for a compiler's slot schema:

- The flat buffer only carries lists of strings. Any other list item is sent as `""` without a warning, so numbers in
  lists have to be strings.
- Any prop whose key starts with `on_` is written as a callback id, whatever its value. A list named `on_*` arrives as
  garbage.
- A node can carry at most 255 props; the rest are dropped with a PHP warning.
- `a11y-label` on a pressable, column or row is ignored on iOS. Only some mobile-ui renderers (icon, button, a few
  controls) read it. The native row sets VoiceOver labels on its buttons itself.
- Absolute insets: +0.0 means "not set" and -0.0 means "set to 0", so `bottom-0` anchors and `bottom-[0]` doesn't
  (rail.blade.php notes it). The element takes plain numbers and the native views send -0.0 where a 0 anchors.
- An unset `flex-shrink` is 1 on the device (the C encoder's default) but 0 in Swift's `NodeLayout.empty`, which a
  benchmark building nodes from JSON starts from. Such a benchmark lays out rows that overflow differently from the
  phone unless it applies the device's defaults.
