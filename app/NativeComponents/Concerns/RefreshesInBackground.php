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
     * The refreshes that owe the screen feedback, still out, by the path they
     * were asked for — a feed's plain ask and its ?since ask are one feed's
     * ask. Counted, not flagged: a refresh forced through beside one already
     * out owes feedback until both have landed, and only the ones asked for
     * it are counted at all — the quiet ones a poll or a resume makes never
     * show. Cleared when the screen goes under another, whose answers were
     * dropped, so a dropped answer can't leave feedback up for good (see
     * forgetRefreshes).
     *
     * @var array<string, int>
     */
    protected array $feedbackRefreshes = [];

    /**
     * Bumped whenever refreshes are forgotten (a screen going under another,
     * whose answers were dropped). A completion carrying an older one owns
     * nothing anymore — not the dedupe markers, not the feedback — though
     * what it brought still applies, as ever. As Log guards its held pages by
     * the list they were cut from (Log::$listGeneration), this guards the
     * refreshes by the screen that asked for them.
     */
    protected int $refreshGeneration = 0;

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
     * A $feedback one is counted among the refreshes the screen says it's
     * waiting on (see $feedbackRefreshes), landing or failing or failing its
     * way into a retry.
     *
     * @param  array<string, string|null>  $query
     * @param  Closure(array<string, mixed>): void  $apply
     * @param  (Closure(): void)|null  $failed
     */
    protected function refresh(string $path, array $query, Closure $apply, bool $again = false, bool $quiet = false, ?Closure $failed = null, bool $withImages = false, bool $retried = false, bool $feedback = false, ?int $generation = null): void
    {
        $key = ResponseCache::key('', $path, $query);
        $generation ??= $this->refreshGeneration;
        $current = $generation === $this->refreshGeneration;

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

        if ($current) {
            $this->refreshing[$key] = microtime(true);

            if ($feedback) {
                $this->feedbackRefreshes[$path] = ($this->feedbackRefreshes[$path] ?? 0) + 1;
            }
        }

        FetchInBackground::dispatch($request->url, $request->headers, 'GET', '', $withImages, $withImages ? ImageCache::takePrune() : null)
            ->timeout(30)
            ->finished(function (mixed $result) use ($key, $path, $query, $apply, $quiet, $failed, $withImages, $retried, $feedback, $generation): void {
                $result = is_array($result) ? $result : [];
                $serverError = ! $retried && (int) ($result['status'] ?? 0) >= 500;

                // A completion from before the screen went under another owns
                // nothing: it doesn't touch the markers of the refreshes asked
                // since. What it brought still applies, below, as ever — and
                // its retry is still worth asking for, under its own old
                // generation, owning nothing too.
                if ($generation === $this->refreshGeneration) {
                    unset($this->refreshing[$key]);
                    $this->endedFeedbackRefresh($path, $feedback);
                }

                if ($serverError) {
                    $this->refresh($path, $query, $apply, again: true, quiet: $quiet, failed: $failed, withImages: $withImages, retried: true, feedback: $feedback, generation: $generation);

                    return;
                }

                $this->settleRefresh($result, $path, $query, $apply, $quiet, $failed, $withImages);
            })
            ->failed(function (Throwable $exception) use ($key, $path, $quiet, $failed, $feedback, $generation): void {
                if ($generation === $this->refreshGeneration) {
                    unset($this->refreshing[$key]);
                    $this->endedFeedbackRefresh($path, $feedback);
                }

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
     * A screen that has its own way of saying devnull refused it, such as a
     * profile across a block, says so here and returns true. Anything else
     * prints the error.
     */
    protected function refused(ApiException $exception): bool
    {
        return false;
    }

    /**
     * Whether a refresh the screen owes feedback for is still out — any of
     * them, or the ones asking for $path, a feed's plain ask and its ?since
     * ask alike. No clock in it: an answer that never comes keeps its
     * feedback, and only landing or failing takes it down.
     */
    public function refreshFeedbackOut(?string $path = null): bool
    {
        return $path === null ? $this->feedbackRefreshes !== [] : isset($this->feedbackRefreshes[$path]);
    }

    /**
     * One feedback refresh done, landed or failed. Others asking for the
     * same path may still be out, forced through beside it.
     */
    protected function endedFeedbackRefresh(string $path, bool $feedback): void
    {
        if (! $feedback) {
            return;
        }

        if (($this->feedbackRefreshes[$path] ?? 0) <= 1) {
            unset($this->feedbackRefreshes[$path]);

            return;
        }

        $this->feedbackRefreshes[$path]--;
    }

    /**
     * Forgets refreshes that were still out, whose answers NativePHP dropped
     * when this screen went under another. Their feedback goes with them,
     * and whatever lands from them afterwards owns nothing.
     */
    protected function forgetRefreshes(): void
    {
        $this->refreshing = [];
        $this->feedbackRefreshes = [];
        $this->refreshGeneration++;
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

            $this->refreshFailed = false;
            $this->error = null;
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
