<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;

/**
 * The list rows of a commit's thread opened in a feed (see
 * Concerns\ExpandsThreads), in order, each with what its partial draws.
 *
 * Under the commit come its replies, each followed by its own replies when
 * they're open, one step in, and "Show more" after the replies of a thread
 * with more. Every row is a list row of its own, and the last one closes the
 * commit's row with its hairline.
 *
 * The thread hangs off the rail in dashes, as on the web: the first reply on
 * an elbow from the commit's rail, each later one on a short link from the
 * line down from the reply above, which is in that reply's lane. A reply's
 * own replies hang the same way off the line down from its avatar, which runs
 * on past them ($spine) when another reply of the commit follows.
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
     * @param  array<string, list<Commit>>  $threads  Open threads, by the id of the commit or reply they answer.
     * @param  array<string, string>  $cursors  Where each open thread with more carries on.
     * @param  array<string, int>  $shown  How many of each open thread's replies show.
     * @param  int|string|null  $railLane  The commit's rail running on past its thread, when its author's run carries on below.
     * @return list<array{key: string, partial: string, data: array<string, mixed>, reply: Commit|null}>
     */
    public static function under(Commit $commit, array $threads, array $cursors, array $shown, int|string|null $railLane = null): array
    {
        $thread = fn (string $id): array => array_slice($threads[$id] ?? [], 0, $shown[$id] ?? null);
        $hasMore = fn (string $id): bool => isset($cursors[$id]) || count($thread($id)) < count($threads[$id] ?? []);
        $replies = $thread($commit->id);

        if ($replies === []) {
            return [];
        }

        $rows = [];
        $more = $hasMore($commit->id);

        foreach ($replies as $index => $reply) {
            $nested = $reply->isDeleted() ? [] : $thread($reply->id);
            $nestedMore = $nested !== [] && $hasMore($reply->id);
            $followed = $index < count($replies) - 1 || $more;

            $rows[] = self::reply($reply, [
                'lane' => $commit->lane(),
                'previousLane' => $index === 0 ? null : $replies[$index - 1]->lane(),
                'hasNext' => $followed || $nested !== [],
                'railLane' => $railLane,
                'thread' => $commit,
                'repliesInPlace' => true,
                ...($index === 0 ? ['top' => self::FirstReplyTop] : []),
            ]);

            foreach ($nested as $nestedIndex => $nestedReply) {
                $rows[] = self::reply($nestedReply, [
                    'lane' => $reply->lane(),
                    'previousLane' => $nestedIndex === 0 ? null : $nested[$nestedIndex - 1]->lane(),
                    'hasNext' => $nestedIndex < count($nested) - 1 || $nestedMore,
                    'railLane' => $railLane,
                    'indent' => 32,
                    'spine' => $followed ? $reply->lane() : null,
                    'thread' => $reply,
                    'repliesInPlace' => false,
                ]);
            }

            if ($nestedMore) {
                $rows[] = self::more($reply->id, $nested[array_key_last($nested)]->lane(), $railLane, 32, $followed ? $reply->lane() : null);
            }
        }

        if ($more) {
            $rows[] = self::more($commit->id, $replies[array_key_last($replies)]->lane(), $railLane);
        }

        $last = array_key_last($rows);
        $rows[$last]['data']['last'] = true;

        return $rows;
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
            'data' => ['reply' => $reply, 'indent' => 0, 'spine' => null, 'last' => false, 'fades' => true, ...$data],
            'reply' => $reply,
        ];
    }

    /**
     * "Show more" at the foot of a thread, on a line down in the lane of the reply above it.
     *
     * @return array{key: string, partial: string, data: array<string, mixed>, reply: null}
     */
    private static function more(string $threadId, int|string $lane, int|string|null $railLane, int $indent = 0, int|string|null $spine = null): array
    {
        return [
            'key' => 'thread-more-'.$threadId,
            'partial' => 'native.partials.thread-more-row',
            'data' => ['threadId' => $threadId, 'lane' => $lane, 'railLane' => $railLane, 'indent' => $indent, 'spine' => $spine, 'last' => false],
            'reply' => null,
        ];
    }
}
