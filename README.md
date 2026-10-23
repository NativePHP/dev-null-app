# /dev/null mobile

A native iOS and Android app for [devnull](https://dev-null.sh), the social network for developers drawn as a git log. It's built with [NativePHP Mobile](https://nativephp.com) v4, so the UI is fully native and the app logic is Laravel.

## How signing in works

There are no passwords or tokens. On first launch the app makes its own ed25519 SSH key and keeps the secret half in the Keychain. Every request to devnull is signed with it.

Sign in with GitHub opens dev-null.sh in the system's sign-in browser, which shares Safari's cookies, so if you're already signed in there you only tap Approve. dev-null.sh approves this phone's key for your account and sends the browser back to `devnull://auth/callback` with a code. The app trades the code for your account with a signed request, using PKCE to prove it started the sign-in.

On iOS you can also link an Apple ID from this device, under your profile. After that, Sign in with Apple on a new phone gets you into the same account. It never makes one: a devnull account is always a GitHub one, so an Apple ID nobody has linked gets a message saying to sign in with GitHub first. It comes from [codingwithrk/nativephp-social-auth](https://github.com/codingwithrk/nativephp-social-auth), which hashes the app's nonce for Apple, and the app asks Apple for no name or email.

## The API

The app talks to the signed `/api/v1` from [nunomaduro/dev-null.sh#130](https://github.com/nunomaduro/dev-null.sh/pull/130), which isn't merged yet. Until it is, point the app at a devnull instance running that branch.

To make that instance work with the app on your phone, create a tunnel (ngrok, Cloudflare etc) and set the `DEVNULL_URL` .env key to that URL before compiling the app.

## Roadmap

These are the next things we want in the app. If you'd like to take one on, read [CONTRIBUTING.md](CONTRIBUTING.md) first.

- Styling for livestreams
- Search
- A notifications centre that matches the web
- Who to follow
- A better welcome screen, with sign up and log in that don't mention GitHub, and maybe the public feed
- Everything Remotes does on the web, including audio recordings and image uploads
- WebSockets

## Setup

Clone the repo and:

```sh
cd dev-null-app
composer setup
```

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

These can be set in `.env`:

- `DEVNULL_URL` is the devnull instance to talk to. It defaults to production.
- `NATIVEPHP_DEEPLINK_SCHEME` must be `devnull`, as in `.env.example`. NativePHP registers the scheme from it at build time, and its sign-in browser reads it from the bundled `.env` to know when dev-null.sh has sent the browser back.
- `GOOGLE_SERVER_CLIENT_ID`, `GOOGLE_IOS_CLIENT_ID` and `GOOGLE_IOS_REVERSED_CLIENT_ID` belong to the social-auth plugin's Google Sign-In, which the app doesn't use. NativePHP won't build without the first, so keep the placeholders from `.env.example`.

A production build for a real iPhone needs an explicit App ID (`com.nativephp.devnull`) with the Sign in with Apple capability, since the plugin adds its entitlement. A wildcard provisioning profile can't carry it.

## Running it

```sh
php artisan native:run
```

If CocoaPods fails with "Unicode Normalization not appropriate for ASCII-8BIT", run it with `LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8`.

## Tests

```sh
php artisan test
```

## License

MIT. Geist and Geist Mono are under the SIL Open Font License, see `resources/fonts/OFL.txt`. The icons in `resources/icons` are from [Lucide](https://lucide.dev), as on the web, under the ISC License, see `resources/icons/LICENSE`. The pastel de nata in `public/images` is drawn from the web's own `public/pastel-de-nata.svg`.
