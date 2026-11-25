<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use Closure;

/**
 * The rows of a commit's thread opened in place (see
 * Concerns\ExpandsThreads), in order, each with what its partial draws: in a
 * feed, and on a commit page under the commit and under the last part of its
 * author's thread.
 *
 * Under the commit come its replies, each followed by its own replies when
 * they're open, and theirs, as deep as they go, with "Show more" after the
 * replies of a thread with more. Every row is a row of its own, and in a feed
 * the last one closes the commit's row with its hairline. On a commit page it
 * does only when another commit's row follows the thread.
 *
 * The thread hangs off the rail in dashes, as on the web: the first reply on
 * an elbow from the commit's rail, each later one on a short link from the
 * line down from the reply above, which is in that reply's lane. A reply's
 * own replies hang the same way off the line down from its avatar, one step
 * in, and that line runs on past them ($spines) when another reply follows.
 *
 * The steps in stop at the web's depth (MaxIndentDepth). Replies at that
 * depth each hang on an elbow of their own from the line of the reply they
 * answer, as the web draws every reply, since their replies line up under
 * them and a line down from one avatar to the next would run through those.
 * Deeper replies are flattened: in the same column, with no lines, and a
 * "↳ replying to @handle" line saying who they answer.
 *
 * In a feed only the commit's replies open theirs in place ($opensInPlace),
 * and a reply's replies open their page. On a commit page every reply opens
 * its replies in place.
 */
final class ThreadRows
{
    /**
     * How long a thread's rows take to fade in or out, in milliseconds.
     */
    public const int Fade = 220;

    /**
     * The space above the first reply's avatar, 7pt less than a reply's usual
     * 10pt. The commit's row keeps the 7pt of padding under its replies line,
     * where its hairline was, so it's as tall with its thread open as shut,
     * and a list never moves the commit when the thread opens or closes (see
     * partials/commit-row-content).
     */
    public const int FirstReplyTop = 3;

    /**
     * How deep replies step in, as the web's maxIndentDepth: the commit's
     * replies are 1, and deeper replies line up under those at this depth.
     */
    public const int MaxIndentDepth = 3;

    /**
     * How far in each step is, in points.
     */
    public const int Step = 32;

    /**
     * @param  array<string, list<Commit>>  $threads  Open threads, by the id of the commit or reply they answer.
     * @param  array<string, string>  $cursors  Where each open thread with more carries on.
     * @param  array<string, int>  $shown  How many of each open thread's replies show.
     * @param  int|string|null  $railLane  The commit's rail running on past its thread, when its author's run carries on below.
     * @param  int  $opensInPlace  How deep a reply's replies button opens its replies in place rather than its page.
     * @param  bool  $onPage  On a commit page: replies muted, deleted ones open theirs in place, and no hairline under them by default.
     * @param  string|null  $morePress  What the commit's own "Show more" presses, when it isn't showMoreReplies.
     * @param  bool|null  $hairline  Whether the last row closes the thread with a hairline: in a feed it does, and on a
     *                               commit page only when another commit's row comes next.
     * @return list<array{key: string, partial: string, data: array<string, mixed>, reply: Commit|null}>
     */
    public static function under(
        Commit $commit,
        array $threads,
        array $cursors,
        array $shown,
        int|string|null $railLane = null,
        int $opensInPlace = 1,
        bool $onPage = false,
        ?string $morePress = null,
        ?bool $hairline = null,
    ): array {
        $thread = fn (string $id): array => array_slice($threads[$id] ?? [], 0, $shown[$id] ?? null);
        $hasMore = fn (string $id): bool => isset($cursors[$id]) || count($thread($id)) < count($threads[$id] ?? []);

        $rows = [];
        self::branch($rows, $commit, 1, [], $thread, $hasMore, [
            'railLane' => $railLane,
            'opensInPlace' => $opensInPlace,
            'onPage' => $onPage,
            'morePress' => $morePress,
            'hairline' => $hairline ?? ! $onPage,
        ]);

        if ($rows === []) {
            return [];
        }

        $last = array_key_last($rows);
        $rows[$last]['data']['last'] = true;

        return $rows;
    }

    /**
     * How far in replies at $depth start, past the commit's text.
     */
    public static function indent(int $depth): int
    {
        return (min($depth, self::MaxIndentDepth) - 1) * self::Step;
    }

    /**
     * The x of the line down from the avatars of replies at $depth, or the commit's rail above them all.
     */
    private static function lineAt(int $depth): float
    {
        return $depth === 0 ? 31.5 : 75.5 + self::indent($depth);
    }

    /**
     * The rows of $parent's replies at $depth, and theirs under each.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{left: float, lane: int|string, top?: int}>  $spines  Lines from further up running on past these rows.
     * @param  Closure(string): list<Commit>  $thread
     * @param  Closure(string): bool  $hasMore
     * @param  array{railLane: int|string|null, opensInPlace: int, onPage: bool, morePress: string|null, hairline: bool}  $options
     */
    private static function branch(array &$rows, Commit $parent, int $depth, array $spines, Closure $thread, Closure $hasMore, array $options): void
    {
        $replies = $thread($parent->id);

        if ($replies === []) {
            return;
        }

        $more = $hasMore($parent->id);
        $indent = self::indent($depth);
        $hangs = $depth === self::MaxIndentDepth;
        $flattened = $depth > self::MaxIndentDepth;
        $parentLine = self::lineAt($depth - 1);
        $onPage = $options['onPage'];

        foreach ($replies as $index => $reply) {
            $nested = $reply->isDeleted() && ! $onPage ? [] : $thread($reply->id);
            $followed = $index < count($replies) - 1 || $more;
            $top = ! $onPage && $depth === 1 && $index === 0 ? self::FirstReplyTop : 10;

            $rows[] = self::reply($reply, [
                'lane' => $parent->lane(),
                'previousLane' => $index === 0 || $hangs ? null : $replies[$index - 1]->lane(),
                'hasNext' => ! $hangs && ! $flattened && ($followed || $nested !== []),
                'links' => ! $flattened,
                'railLane' => $options['railLane'],
                'indent' => $indent,
                'top' => $top,
                // At the deepest step the line of the reply answered runs on under the elbow to the next one.
                'spines' => [...$spines, ...($hangs && $followed ? [['left' => $parentLine, 'lane' => $parent->lane(), 'top' => $top + 12]] : [])],
                'replyingTo' => $flattened ? self::replyingTo($reply, $parent) : null,
                'repliesInPlace' => $depth <= $options['opensInPlace'],
                'muted' => $onPage,
                'tombstones' => $onPage,
                'hairline' => $options['hairline'],
            ]);

            // The line that has to run on past this reply's own replies, to the next reply at this depth.
            $through = match (true) {
                ! $followed || $flattened => [],
                $hangs => [['left' => $parentLine, 'lane' => $parent->lane()]],
                default => [['left' => self::lineAt($depth), 'lane' => $reply->lane()]],
            };

            self::branch($rows, $reply, $depth + 1, [...$spines, ...$through], $thread, $hasMore, $options);
        }

        if ($more) {
            $rows[] = self::more($parent, [
                'lane' => $replies[array_key_last($replies)]->lane(),
                'parentLane' => $parent->lane(),
                'hangs' => $hangs,
                'links' => ! $flattened,
                'railLane' => $options['railLane'],
                'indent' => $indent,
                'spines' => $spines,
                'to' => $flattened && ! $parent->isDeleted() ? $parent->author->handle() : null,
                'hairline' => $options['hairline'],
                ...($depth === 1 && $options['morePress'] !== null ? ['press' => $options['morePress']] : []),
            ]);
        }
    }

    /**
     * "replying to @handle", or "quoting" when the reply quotes what it answers, as the web marks a flattened reply.
     */
    private static function replyingTo(Commit $reply, Commit $parent): string
    {
        return ($reply->quotedCommitId === $parent->id ? 'quoting ' : 'replying to ').($parent->isDeleted() ? '[deleted]' : $parent->author->handle());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{key: string, partial: string, data: array<string, mixed>, reply: Commit}
     */
    private static function reply(Commit $reply, array $data): array
    {
        return [
            'key' => 'thread-reply-'.$reply->id,
            'partial' => 'native.partials.thread-reply',
            'data' => ['reply' => $reply, 'last' => false, 'fades' => true, ...$data],
            'reply' => $reply,
        ];
    }

    /**
     * "Show more" at the foot of a thread, on a line down in the lane of the reply above it.
     *
     * @param  array<string, mixed>  $data
     * @return array{key: string, partial: string, data: array<string, mixed>, reply: null}
     */
    private static function more(Commit $parent, array $data): array
    {
        return [
            'key' => 'thread-more-'.$parent->id,
            'partial' => 'native.partials.thread-more-row',
            'data' => ['threadId' => $parent->id, 'last' => false, ...$data],
            'reply' => null,
        ];
    }
}
