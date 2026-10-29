---
name: ui-screenshots
description: "Takes the before and after screenshots and recordings a pull request needs when it changes what the app looks like or how it moves: iOS and Android, light and dark mode, published to an orphan branch and pasted into the PR description. Activate before editing any screen, EDGE view, layout, theme token, font, icon or animation, and again when opening the pull request."
---

# UI screenshots

Reviewers need to see a visible change on both platforms in both appearances. Three artisan commands do the work:

| Command | What it does |
|---|---|
| `php artisan screenshots:take before` | Screenshots whatever is on screen on the booted iOS simulator and the Android emulator, in light then dark mode, then puts each device's appearance back. |
| `php artisan screenshots:record after` | Records each platform for `--seconds` (8 by default), in light then dark mode. |
| `php artisan screenshots:publish` | Commits everything to an orphan `{branch}-screenshots` branch and prints the markdown table for the PR description. |

Files go in `storage/app/screenshots/{branch}/`, named like `log-ios-dark-after.png`. Git ignores that folder and the app bundle leaves it out.

## Before you edit anything

1. Create the pull request branch first. The commands file everything under the current branch and refuse to run on `main`.
2. The app has to be running on an iOS simulator and an Android emulator. Start it on both yourself. Find the devices with `xcrun simctl list devices booted` and `adb devices`, then run `php artisan native:run ios <UDID> --watch --no-tty -n` and `php artisan native:run android <SERIAL> --watch --no-tty -n` in the background. `--watch` makes later changes hot reload, and it never exits, so stop both when the screenshots are published. If no simulator or emulator is booted, say so and give the person the commands.
3. Ask the person you're working with to open the screen you're about to change, in the state that shows it (scrolled, sheet open, signed in, and so on). Both devices should show the same thing.
4. Take the before shots: `php artisan screenshots:take before --screen=log`. Give `--screen` a short name whenever the PR touches more than one screen, and repeat for each one.

If you've already started editing, `git stash`, let hot reload bring the old screen back, take the before shots, then `git stash pop`. A brand new screen has no before shots, so skip them.

## After the change

1. Make sure both devices show the change. Hot reload covers PHP, Blade and config. A change to native code, a plugin, an icon in `resources/icons` or `composer.json` needs a fresh build, so stop `native:run` on both and start it again.
2. Take `php artisan screenshots:take after --screen=log`, with the screen in the same state as the before shots.
3. Look at every screenshot before publishing. Check the change is actually visible, both appearances look right, and nothing is cut off or still loading. Retake anything that's wrong.

## Animations

Anything that moves needs recordings as well as screenshots.

- Recording only captures what happens on the device, so tell the person what to do before you start: "When it says Recording, tap Save and let the sheet close." It records one platform and appearance at a time, so they'll do it up to four times.
- `php artisan screenshots:record after --screen=log --seconds=6`. Use `--appearance=dark` or `--platform=ios` to redo just one.
- Record the before state too when the change alters motion that already existed.

## Publishing

1. `php artisan screenshots:publish` pushes to `origin`. Working from a fork, push to the fork instead with `--remote=` and the fork's remote name.
2. Put the printed table under "Screenshots" in the PR description.
3. After a retake, run it again. It adds a commit and prints new links, so replace the table in the description.

Images show inline. GitHub only plays videos uploaded through its web editor, so recordings are links.

## When something's missing

- If one platform can't run (no Android emulator, no Mac for iOS), take what you can and say in the PR description which platform is missing and why.
- The commands use an emulator when there is one. If only an Android phone is connected they use it and warn, since its system-wide dark mode is switched while they run. Ask before using someone's own phone. Physical iPhones aren't supported.
- The "Screenshots" CI check fails a ready-for-review PR that touches `app/NativeComponents`, `resources/views/native`, `config/native-ui.php`, fonts, icons or animations, unless the description has at least four images or "No visible change" is ticked.
