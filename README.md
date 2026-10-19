# /dev/null mobile

A native iOS and Android app for [devnull](https://dev-null.sh), the social network for developers drawn as a git log. It's built with [NativePHP Mobile](https://nativephp.com) v4, so the UI is fully native and the app logic is Laravel.

## What's in it

- The Following, Latest and Trending logs, drawn as a git graph with each author in their lane colour, and a pill for new commits.
- Commits with their threads, polls, quotes, link previews and images.
- Committing, replying and quoting from a sheet, with drafts kept the way the web's stash keeps them.
- Starring, saving, reporting, and pastéis mode.
- Notifications, grouped and filtered as on the web.
- Profiles with activity, streaks, achievements, pinned commits, and follower and following lists.
- Muting and blocking, your account settings, and your lane colour.
- Signing in with GitHub, or with an Apple ID you've linked.

## Building it yourself

Clone the repo and:

```sh
cd dev-null-app
composer setup
php artisan native:run
```

If CocoaPods fails with "Unicode Normalization not appropriate for ASCII-8BIT", run it with `LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8`.

`nativephp/mobile-secure-storage` is a paid plugin from `plugins.nativephp.com`, so `composer install` needs your NativePHP plugin credentials. See below if you don't have access to the plugin.

For now the app tracks unreleased branches, which Composer pulls straight from GitHub:

- `nativephp/mobile` on `combined/dev-null-app`, a branch just for this app. It's `feat/custom-icons`, which adds custom SVG icons ([NativePHP/mobile-air#544](https://github.com/NativePHP/mobile-air/pull/544)), plus the fix that stops the keyboard-dismiss tap swallowing taps on UIKit controls such as `native:toggle` and the Sign in with Apple button ([NativePHP/mobile-air#545](https://github.com/NativePHP/mobile-air/pull/545)).
- `nativephp/mobile-ui` on `combined/dev-null-app`, a branch just for this app. It's `feat/custom-icons` ([NativePHP/mobile-ui#132](https://github.com/NativePHP/mobile-ui/pull/132)) plus:
  - opening the iOS pager at its start page ([#131](https://github.com/NativePHP/mobile-ui/pull/131)) and running a swiping pager's lists under the tab bar ([#134](https://github.com/NativePHP/mobile-ui/pull/134)),
  - a pager that reports its page once the swipe settles ([#135](https://github.com/NativePHP/mobile-ui/pull/135)) and draws its tabs natively ([#136](https://github.com/NativePHP/mobile-ui/pull/136)),
  - button variants named after a theme colour ([#137](https://github.com/NativePHP/mobile-ui/pull/137)),
  - a sticky header for lists ([#138](https://github.com/NativePHP/mobile-ui/pull/138)),
  - a pager header that scrolls with the page showing ([#139](https://github.com/NativePHP/mobile-ui/pull/139)).

Once those are released, the app goes back to tagged versions and the combined branches can be deleted.

### Without plugin credentials

```sh
composer setup:free
```

This installs everything except the Secure Storage plugin, from a copy of `composer.json` and `composer.lock` that it writes to `composer.free.*`. To install again later, run `COMPOSER=composer.free.json composer install`.

Build the same way, since `native:run` runs its own `composer install` on a copy of the app:

```sh
COMPOSER=composer.free.json php artisan native:run ios
```

Without the plugin, the app keeps its key in a file encrypted with `APP_KEY` instead of in the Keychain. That's fine for a simulator, but don't ship it. On a real phone the app works this out for itself and refuses to make a key. `DEVNULL_KEY_STORE` (`keychain` or `file`) overrides the choice, so `file` makes it use the file on a phone too.

### Configuration

These can be set in `.env`:

- `DEVNULL_URL` is the devnull instance to talk to. It defaults to production, https://dev-null.sh.
- `NATIVEPHP_DEEPLINK_SCHEME` must be `devnull`, as in `.env.example`. NativePHP registers the scheme from it at build time, and its sign-in browser reads it from the bundled `.env` to know when dev-null.sh has sent the browser back.
- `DEVNULL_APPLE_SIGN_IN=false` hides Sign in with Apple, for a build whose App ID doesn't have the capability yet.
- `GOOGLE_SERVER_CLIENT_ID`, `GOOGLE_IOS_CLIENT_ID` and `GOOGLE_IOS_REVERSED_CLIENT_ID` belong to the social-auth plugin's Google Sign-In, which the app doesn't use. NativePHP won't build without the first, so keep the placeholders from `.env.example`.

A production build for a real iPhone needs an explicit App ID for `NATIVEPHP_APP_ID` with the Sign in with Apple capability, since the plugin adds its entitlement. A wildcard provisioning profile can't carry it.

## Running it in Jump

[Jump](https://bifrost.nativephp.com/jump) is NativePHP's free app for running a project on your phone without Xcode or Android Studio. It can run this app, but you can't sign in there yet, so you won't get past the welcome screen.

To run it, install Jump on your phone, clone the repo and run `composer setup` as above, then start Jump's server and scan the QR code it shows with Jump. Your phone and your computer need to be on the same Wi-Fi.

```sh
php artisan native:jump
```

Without plugin credentials, add `DEVNULL_KEY_STORE=file` to `.env` as well. Jump runs on a real phone, where the app won't make its key without the Secure Storage plugin.

Signing in is what doesn't work. dev-null.sh sends the sign-in browser back to the app at `devnull://auth/callback`, but inside Jump the browser only comes back for Jump's own `jump://` links. Getting that far would take:

1. devnull running locally, behind a tunnel (ngrok, Cloudflare and so on), with `DEVNULL_URL` set to the tunnel's URL,
2. `jump://` added to `redirect_uri_prefixes` in devnull's `config/mobile.php`, so the authorize page will redirect there,
3. the app sending `jump://auth/callback` as its `redirect_uri`, which it doesn't today.

Even then, Jump doesn't pass a `jump://` link on to the app it's running, so the sign-in never reaches the app. Until it does, build the app yourself to sign in.

## How signing in works

There are no passwords or tokens. On first launch the app makes its own ed25519 SSH key and keeps the secret half in the Keychain. Every request to devnull is signed with it.

Sign in with GitHub opens dev-null.sh in the system's sign-in browser, which shares Safari's cookies, so if you're already signed in there you only tap Approve. dev-null.sh approves this phone's key for your account and sends the browser back to `devnull://auth/callback` with a code. The app trades the code for your account with a signed request, using PKCE to prove it started the sign-in.

On iOS you can also link an Apple ID from this device, under your profile. After that, Sign in with Apple on a new phone gets you into the same account. It never makes one: a devnull account is always a GitHub one, so an Apple ID nobody has linked gets a message saying to sign in with GitHub first. It comes from [codingwithrk/nativephp-social-auth](https://github.com/codingwithrk/nativephp-social-auth), which hashes the app's nonce for Apple, and the app asks Apple for no name or email.

## The API

The app talks to devnull's signed `/api/v1`, which came in with [nunomaduro/dev-null.sh#130](https://github.com/nunomaduro/dev-null.sh/pull/130) and runs on dev-null.sh.

Some features use endpoints that are still in review upstream, so against dev-null.sh they fail until those land:

- starring and saving ([#550](https://github.com/nunomaduro/dev-null.sh/pull/550)),
- deleting your account ([#551](https://github.com/nunomaduro/dev-null.sh/pull/551)),
- reporting ([#552](https://github.com/nunomaduro/dev-null.sh/pull/552)),
- quoting ([#553](https://github.com/nunomaduro/dev-null.sh/pull/553)),
- syncing from GitHub and saving your colour ([#554](https://github.com/nunomaduro/dev-null.sh/pull/554)),
- muting and blocking ([#555](https://github.com/nunomaduro/dev-null.sh/pull/555)),
- follower and following lists ([#556](https://github.com/nunomaduro/dev-null.sh/pull/556)),
- pastéis mode ([#557](https://github.com/nunomaduro/dev-null.sh/pull/557)),
- Sign in with Apple ([#558](https://github.com/nunomaduro/dev-null.sh/pull/558)).

To try them sooner, run devnull with those branches merged, put it behind a tunnel (ngrok, Cloudflare and so on), and set `DEVNULL_URL` to the tunnel's URL before building.

## Tests

```sh
php artisan test
```

## License

MIT. Geist and Geist Mono are under the SIL Open Font License, see `resources/fonts/OFL.txt`. The icons in `resources/icons` are from [Lucide](https://lucide.dev), as on the web, under the ISC License, see `resources/icons/LICENSE`. The pastel de nata in `public/images` is drawn from the web's own `public/pastel-de-nata.svg`.
