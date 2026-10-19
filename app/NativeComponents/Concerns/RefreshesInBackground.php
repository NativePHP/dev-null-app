<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\CurrentUser;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\Devnull\FetchInBackground;
use App\Devnull\ImageCache;
use App\Devnull\ResponseCache;
use App\Devnull\Timezone;
use Closure;
use Throwable;

/**
 * Stale-while-revalidate for a screen: show what was kept last time at once,
 * then ask devnull again on a background PHP thread and apply the answer when
 * it lands, without ever holding up a tap.
 *
 * The request is signed here, on the UI thread, where the Keychain is; the
 * background thread only sends it. The answer is kept here too, so nothing
 * but the UI thread ever writes the cache. NativePHP drops the answer when the
 * screen that asked is no longer on top (NativeComponent::handleAsyncCompletion),
 * so a screen asks again whenever it comes back.
 *
 * When a refresh fails over something worth showing, the screen keeps it and
 * says so quietly, with a retry. Only a screen with nothing to show prints the
 * error. A 401 signs the phone out, as any request does. A server error is
 * asked once more first, signed afresh, since it's often a passing one. A 200
 * that isn't devnull's answer, such as a maintenance page, fails like any
 * other refresh, and what was kept stays kept.
 */
trait RefreshesInBackground
{
    /**
     * The last refresh failed, so what's showing may be out of date.
     */
    public bool $refreshFailed = false;

    /**
     * When each refresh still out was sent, by path and query.
     *
     * @var array<string, float>
     */
    protected array $refreshing = [];

    /**
     * Asks again for everything the screen shows.
     */
    abstract protected function revalidate(): void;

    /**
     * Whether the screen has something worth keeping on it when a refresh fails.
     */
    abstract protected function showsContent(): bool;

    /**
     * The quiet line's retry.
     */
    public function retry(): void
    {
        $this->refreshFailed = false;
        $this->refreshing = [];
        $this->revalidate();
    }

    /**
     * The kept JSON for a GET, or null when there's none to show.
     *
     * @param  array<string, string|null>  $query
     * @return array<string, mixed>|null
     */
    protected function cached(string $path, array $query = []): ?array
    {
        return app(ResponseCache::class)->get($path, $query);
    }

    /**
     * Asks devnull for a GET in the background and hands $apply the JSON when it
     * lands. A refresh of the same thing already on its way isn't sent twice,
     * unless $again says it must be. A $quiet one never says it failed on the
     * screen. It only tells $failed, for a caller that has something else to
     * do then. One $withImages brings the answer's images down before it
     * lands, rather than after in a task of their own (see FetchInBackground).
     *
     * @param  array<string, string|null>  $query
     * @param  Closure(array<string, mixed>): void  $apply
     * @param  (Closure(): void)|null  $failed
     */
    protected function refresh(string $path, array $query, Closure $apply, bool $again = false, bool $quiet = false, ?Closure $failed = null, bool $withImages = false, bool $retried = false): void
    {
        $key = ResponseCache::key('', $path, $query);

        if (! $again && isset($this->refreshing[$key]) && microtime(true) - $this->refreshing[$key] < 30) {
            return;
        }

        try {
            $request = app(Client::class)->signedGet($path, $query);
        } catch (KeychainUnavailable $exception) {
            if ($quiet) {
                $failed?->__invoke();
            } else {
                $this->couldNotRefresh($exception->getMessage());
            }

            return;
        }

        $this->refreshing[$key] = microtime(true);

        FetchInBackground::dispatch($request->url, $request->headers, 'GET', '', $withImages, $withImages ? ImageCache::takePrune() : null)
            ->timeout(30)
            ->finished(function (mixed $result) use ($key, $path, $query, $apply, $quiet, $failed, $withImages, $retried): void {
                unset($this->refreshing[$key]);

                $result = is_array($result) ? $result : [];

                if (! $retried && (int) ($result['status'] ?? 0) >= 500) {
                    $this->refresh($path, $query, $apply, again: true, quiet: $quiet, failed: $failed, withImages: $withImages, retried: true);

                    return;
                }

                $this->settleRefresh($result, $path, $query, $apply, $quiet, $failed, $withImages);
            })
            ->failed(function (Throwable $exception) use ($key, $quiet, $failed): void {
                unset($this->refreshing[$key]);

                if ($quiet) {
                    $failed?->__invoke();
                } else {
                    $this->couldNotRefresh('fatal: '.$exception->getMessage());
                }
            });
    }

    /**
     * Asks devnull how many notifications are unread, for the badge on the
     * Notifications tab, and picks up any change to you while it's there,
     * your Remotes and their unread count among them.
     * The badge is a nicety, so a failure here is never put on the screen.
     */
    protected function refreshUnreadNotifications(): void
    {
        $this->refresh('me', [], function (array $json): void {
            $current = CurrentUser::fromArray($json);

            if ($current->user->username !== '') {
                app(Account::class)->remember($current->user);
                app(Account::class)->rememberPasteis($current->pasteis());
                app(Account::class)->rememberRemotes($current->remotes);
            }

            app(Account::class)->rememberUnreadNotifications($current->unreadNotificationsCount);
        }, quiet: true);
    }

    /**
     * Asks devnull for what only you see in the web's settings, and keeps the
     * timezone you chose there, which `/me` doesn't carry, so the app shows
     * times in it (see Timezone). It's a nicety, so a failure is never shown.
     */
    protected function refreshSettings(): void
    {
        $this->refresh(Client::SettingsPath, [], function (array $json): void {
            $settings = is_array($json['data'] ?? null) ? $json['data'] : [];
            $timezone = $settings['timezone'] ?? null;

            app(Account::class)->rememberTimezone(Timezone::isKnown($timezone) ? $timezone : null);
        }, quiet: true);
    }

    /**
     * A screen that has its own way of saying devnull refused it, such as a
     * profile across a block, says so here and returns true. Anything else
     * prints the error.
     */
    protected function refused(ApiException $exception): bool
    {
        return false;
    }

    /**
     * Forgets refreshes that were still out, whose answers NativePHP dropped
     * when this screen went under another.
     */
    protected function forgetRefreshes(): void
    {
        $this->refreshing = [];
    }

    /**
     * @param  array{status?: int, json?: mixed, retry_after?: string, from_devnull?: bool}  $result
     * @param  array<string, string|null>  $query
     * @param  Closure(array<string, mixed>): void  $apply
     * @param  (Closure(): void)|null  $failed
     */
    private function settleRefresh(array $result, string $path, array $query, Closure $apply, bool $quiet, ?Closure $failed = null, bool $withImages = false): void
    {
        $status = (int) ($result['status'] ?? 0);
        $successful = $status >= 200 && $status < 300;

        if ($successful && ($result['from_devnull'] ?? false) === true) {
            $json = is_array($result['json'] ?? null) ? $result['json'] : [];

            app(ResponseCache::class)->put($path, $query, $json);

            // Its images came with it. Any that didn't come in time load from their URLs.
            if (! $withImages) {
                ImageCache::prefetch($json);
            }

            if (! $quiet) {
                $this->refreshFailed = false;
                $this->error = null;
            }

            $apply($json);

            return;
        }

        if ($quiet) {
            $failed?->__invoke();

            return;
        }

        $exception = match (true) {
            $status === 0 => ApiException::couldNotConnect((string) config('devnull.url')),
            $successful => ApiException::unexpectedAnswer((string) config('devnull.url'), $status),
            default => ApiException::fromParts($status, $result['json'] ?? null, (string) ($result['retry_after'] ?? '')),
        };

        if ($exception instanceof Unauthenticated) {
            $this->signOut($exception);

            return;
        }

        // What was kept is gone from devnull, so it's no longer worth showing quietly.
        if (in_array($status, [403, 404, 410], true)) {
            app(ResponseCache::class)->forget($path, $query);

            if (! $this->refused($exception)) {
                $this->error = $exception->firstError();
            }

            return;
        }

        $this->couldNotRefresh($exception->firstError());
    }

    private function couldNotRefresh(string $message): void
    {
        if ($this->showsContent()) {
            $this->refreshFailed = true;

            return;
        }

        $this->error = $message;
    }
}
