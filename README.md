# devnull mobile

A native iOS and Android app for [devnull](https://dev-null.sh), the social network for developers drawn as a git log. It's built with [NativePHP Mobile](https://nativephp.com) v4, so the UI is fully native and the app logic is Laravel.

## How signing in works

There are no passwords or tokens. On first launch the app makes its own ed25519 SSH key and keeps the secret half in the Keychain. You add the public half to your GitHub account as a signing key, and every request to devnull is signed with it. devnull checks the key against your GitHub account, the same way devnull's commit-from-the-terminal script does.

The app can add the key to GitHub for you through GitHub's device flow. It asks for permission to add a signing key, adds it, and drops the token straight away. You can also copy the key and add it yourself.

## The API

The app talks to the signed `/api/v1` from [nunomaduro/dev-null.sh#130](https://github.com/nunomaduro/dev-null.sh/pull/130), which isn't merged yet. Until it is, point the app at a devnull instance running that branch.

## Setup

```sh
composer install
cp .env.example .env
php artisan key:generate
```

`nativephp/mobile-secure-storage` is a paid plugin from `plugins.nativephp.com`, so `composer install` needs your NativePHP plugin licence.

### Without a plugin licence

```sh
composer setup:free
```

This installs everything except the Secure Storage plugin, from a copy of `composer.json` and `composer.lock` that it writes to `composer.free.*`. To install again later, run `COMPOSER=composer.free.json composer install`.

Build the same way, since `native:run` runs its own `composer install` on a copy of the app:

```sh
COMPOSER=composer.free.json php artisan native:run ios
```

Without the plugin, the app keeps its key in a file encrypted with `APP_KEY` instead of in the Keychain. That's fine for a simulator, but don't ship it. `DEVNULL_KEY_STORE` (`keychain` or `file`) overrides the choice.

Then set these in `.env`:
These can be set in `.env`:

- `DEVNULL_URL` is the devnull instance to talk to. It defaults to production.
- `DEVNULL_GITHUB_CLIENT_ID` defaults to the devnull mobile GitHub App. To use your own, create a GitHub App that any account can install, with device flow enabled and the account permission "SSH signing keys" set to read and write. No client secret is needed. Set it to an empty value and the app only offers the copy-and-paste flow.

## Running it

```sh
php artisan native:run ios
```

If CocoaPods fails with "Unicode Normalization not appropriate for ASCII-8BIT", run it with `LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8`.

## Tests

```sh
php artisan test
```

CI needs a `COMPOSER_AUTH` repository secret with your `plugins.nativephp.com` credentials.

## License

MIT. Geist and Geist Mono are under the SIL Open Font License, see `resources/fonts/OFL.txt`.
