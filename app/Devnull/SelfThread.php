<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use Closure;

/**
 * A thread its author writes as replies to themselves, read the way the web's
 * CommitContinuationQuery reads it: the author's earliest reply to their own
 * commit carries it on, then their earliest reply to that one, and so on. A
 * deleted reply doesn't carry it on, and nor does a quote, which isn't a reply.
 *
 * devnull's API doesn't say which reply that is (the web's continuation_id
 * isn't in its commits yet), so it's worked out from the replies, oldest
 * first, as they come. When devnull does say, its word goes.
 *
 * On a commit page the parts after the commit follow it on its rail, ten at a
 * time as on the web, and each wears its place in the thread ("2/5"). Each
 * step down needs the replies of the part above it, so the walk stops where
 * they haven't come yet and says whose it's waiting for ($waiting).
 */
final readonly class SelfThread
{
    /**
     * How many parts a commit page shows at a time, as the web's CommitContinuationQuery::PER_PAGE.
     */
    public const int PerPage = 10;

    /**
     * @param  list<Commit>  $parts  The commits carrying the thread on below the commit, in order, at most the limit.
     * @param  bool  $hasMore  Whether another part follows the last of $parts.
     * @param  bool  $hasEnded  Whether it's known that no part follows the last of $parts.
     * @param  string|null  $waiting  The commit whose replies the walk needs to go on.
     */
    public function __construct(
        public array $parts = [],
        public bool $hasMore = false,
        public bool $hasEnded = false,
        public ?string $waiting = null,
    ) {}

    /**
     * The reply among $replies that carries $commit's thread on, if it's one of them.
     *
     * @param  iterable<Commit>  $replies
     */
    public static function next(Commit $commit, iterable $replies): ?Commit
    {
        if ($commit->isDeleted() || $commit->isStaged() || $commit->author->id === '') {
            return null;
        }

        $earliest = null;

        foreach ($replies as $reply) {
            if ($commit->continuationId !== null) {
                if ($reply->id === $commit->continuationId) {
                    return $reply;
                }

                continue;
            }

            if ($reply->parentId !== $commit->id || $reply->isDeleted() || $reply->isStaged() || $reply->author->id !== $commit->author->id) {
                continue;
            }

            if ($earliest === null || self::isEarlier($reply, $earliest)) {
                $earliest = $reply;
            }
        }

        return $earliest;
    }

    /**
     * $replies without the one that carries $commit's thread on, which shows as the next part of it instead.
     *
     * @param  list<Commit>  $replies
     * @return list<Commit>
     */
    public static function others(Commit $commit, array $replies): array
    {
        $next = self::next($commit, $replies);

        return $next === null ? $replies : array_values(array_filter($replies, fn (Commit $reply): bool => $reply->id !== $next->id));
    }

    /**
     * Walks down from $commit through the parts that carry its thread on, as far as $limit and the replies that
     * have come allow.
     *
     * @param  Closure(Commit): (list<Commit>|null)  $repliesOf  A commit's first page of replies, or null until it comes.
     */
    public static function below(Commit $commit, Closure $repliesOf, int $limit = self::PerPage): self
    {
        $parts = [];
        $above = $commit;

        while (true) {
            $replies = $repliesOf($above);

            if ($replies === null) {
                return new self($parts, waiting: $above->id);
            }

            $next = self::next($above, $replies);

            if ($next === null || in_array($next, $parts, true) || $next->id === $commit->id) {
                return new self($parts, hasEnded: true);
            }

            if (count($parts) >= $limit) {
                return new self($parts, hasMore: true);
            }

            $parts[] = $next;
            $above = $next;
        }
    }

    /**
     * How many of the commits just above $commit, of $ancestors (oldest first), are earlier parts of its thread,
     * and whose replies are needed to look further up. Only the author's own commits can be; each one is a part
     * when its earliest reply from them is the one below it.
     *
     * @param  list<Commit>  $ancestors
     * @param  Closure(Commit): (list<Commit>|null)  $repliesOf
     * @return array{0: int, 1: string|null}
     */
    public static function above(array $ancestors, Commit $commit, Closure $repliesOf): array
    {
        $count = 0;
        $below = $commit;

        foreach (array_reverse($ancestors) as $ancestor) {
            if ($below->isDeleted() || $ancestor->isDeleted() || $ancestor->author->id !== $below->author->id || $below->parentId !== $ancestor->id) {
                break;
            }

            $replies = $repliesOf($ancestor);

            if ($replies === null) {
                return [$count, $ancestor->id];
            }

            if (self::next($ancestor, $replies)?->id !== $below->id) {
                break;
            }

            $count++;
            $below = $ancestor;
        }

        return [$count, null];
    }

    /**
     * The place in its thread of the commit at $position, as the web's ThreadPositionBadge writes it: "3/7", with
     * a "+" when more of the thread follows than has been read. Null for a commit that isn't part of one.
     */
    public static function position(int $position, int $size, bool $isOpenEnded): ?string
    {
        return $size > 1 && $position >= 1 ? $position.'/'.$size.($isOpenEnded ? '+' : '') : null;
    }

    private static function isEarlier(Commit $reply, Commit $than): bool
    {
        if ($reply->createdAt === null || $than->createdAt === null || $reply->createdAt->equalTo($than->createdAt)) {
            return strcmp($reply->id, $than->id) < 0;
        }

        return $reply->createdAt->lessThan($than->createdAt);
    }
}
