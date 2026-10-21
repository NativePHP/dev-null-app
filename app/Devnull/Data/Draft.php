<?php

namespace App\Devnull\Data;

use Carbon\CarbonImmutable;

/**
 * A commit you're writing in the composer sheet: what it needs before it can
 * be committed, and the commit it would become, for the preview.
 */
final class Draft
{
    /**
     * Whether Commit may be pressed, as the web decides it: a body that fits
     * (see Commit::fits()), or with images none at all, and a poll whose every
     * option has something in it.
     *
     * @param  list<DraftImage>  $images
     * @param  list<string>|null  $poll
     */
    public static function canCommit(string $body, array $images = [], ?array $poll = null): bool
    {
        return (Commit::fits($body) || ($images !== [] && trim($body) === ''))
            && ($poll === null || PollDraft::isComplete($poll));
    }

    /**
     * The draft as the commit it would become, by you, with what devnull sent
     * back for its body when it's for this draft: the body as it would be
     * kept, its code and mentions. Until then it's the body as written.
     *
     * @param  array<string, mixed>|null  $preview  devnull's `POST /commits/preview` for the draft, with the body it was for under `draft`.
     * @param  list<DraftImage>  $images
     * @param  list<string>|null  $poll
     */
    public static function asCommit(User $author, string $body, ?array $preview = null, array $images = [], ?array $poll = null, ?Commit $quoted = null): Commit
    {
        $body = trim($body);
        $shown = ($preview['draft'] ?? null) === $body ? $preview : ['body' => $body];

        $commit = Commit::fromArray([
            ...$shown,
            'id' => 'draft',
            'status' => 'approved',
            'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
        ]);

        return new Commit(...[
            ...get_object_vars($commit),
            'author' => $author,
            'images' => array_map(fn (DraftImage $image, int $index): CommitImage => $image->asCommitImage($index), $images, array_keys($images)),
            'poll' => $poll === null ? null : PollDraft::preview($poll),
            'quotedCommitId' => $quoted?->id,
            'quotedCommit' => $quoted,
        ]);
    }
}
