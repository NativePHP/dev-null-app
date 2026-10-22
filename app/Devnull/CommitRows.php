<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use Illuminate\Support\Facades\Cache;

/**
 * Which commit row the feeds draw: the Blade row, built from about 33 EDGE
 * nodes, or the same row as one native element (devnull/commit-row, see
 * partials/commit-row-native). It's there to measure the two against each
 * other on the phones.
 *
 * The build's .env picks the row (DEVNULL_NATIVE_COMMIT_ROW). With
 * DEVNULL_COMMIT_ROW_SWITCH on, This Device has a switch that picks it on this
 * phone instead, so one build can be measured both ways.
 *
 * A singleton: every row asks, so the phone's choice is read once.
 */
final class CommitRows
{
    private const string NativeKey = 'devnull.native-commit-row';

    private ?bool $native = null;

    public function native(): bool
    {
        if (! self::switchable()) {
            return (bool) config('devnull.native_commit_row');
        }

        return $this->native ??= (bool) Cache::get(self::NativeKey, (bool) config('devnull.native_commit_row'));
    }

    /**
     * Picks the row on this phone, while the switch is offered.
     */
    public function useNative(bool $native): void
    {
        if (! self::switchable()) {
            return;
        }

        Cache::forever(self::NativeKey, $native);
        $this->native = $native;
    }

    /**
     * Whether the native row draws everything this commit shows. It has no
     * media, polls, quotes, link previews or code blocks, no series position
     * and no status chip; those commits keep the Blade row.
     *
     * @param  list<string>  $unfolded  The folded commits opened in place.
     */
    public static function draws(Commit $commit, array $unfolded = []): bool
    {
        $folded = $commit->isFolded() && ! in_array($commit->id, $unfolded, true);

        foreach ($folded ? $commit->previewContent : $commit->content as $block) {
            if ($block->isCode()) {
                return false;
            }
        }

        return $commit->isApproved()
            && ! $commit->isSeriesPart()
            && $commit->images === []
            && $commit->poll === null
            && ! $commit->isQuote()
            && ! ($commit->linkPreview?->isReady() ?? false);
    }

    public static function switchable(): bool
    {
        return (bool) config('devnull.commit_row_switch');
    }
}
