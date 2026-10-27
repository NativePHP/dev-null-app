<?php

namespace App\Devnull;

use App\Devnull\Data\DraftImage;
use App\Devnull\Data\StashedDraft;
use Illuminate\Support\Facades\Cache;

/**
 * What you wrote and didn't send, per user, as the web's useStash keeps it:
 * the text, and the photos and poll that go with it.
 *
 * Drafts go by the web's names: 'commit' for a new commit, from the compose
 * sheet, 'quote-{id}' for a quote of a commit and 'reply-{id}' for a
 * reply to one. A screen keeps a draft as it changes, and forgets it once
 * it's sent or there's nothing left in it. Signing out forgets them all (see
 * Account::forget).
 *
 * A stashed draft's photos stay on the phone for as long as it's kept (see
 * DraftImages). They're deleted when the draft is pushed out by newer ones
 * or you sign out, and otherwise by whoever takes them off it.
 */
final class Stash
{
    /**
     * How many drafts are kept per user. The one written longest ago goes first.
     */
    public const int MaxDrafts = 50;

    /**
     * The draft kept under $name, or an empty one when there's none.
     */
    public function get(?string $userId, string $name): StashedDraft
    {
        return $this->drafts($userId)[$name] ?? new StashedDraft;
    }

    /**
     * Keeps $draft under $name. An empty draft forgets it. The photos of a draft it replaces aren't deleted: they've
     * been taken off, or gone with the commit.
     */
    public function put(?string $userId, string $name, StashedDraft $draft): void
    {
        if ($userId === null) {
            return;
        }

        $drafts = $this->drafts($userId);

        if ($draft->isEmpty() && ! array_key_exists($name, $drafts)) {
            return;
        }

        unset($drafts[$name]);

        if (! $draft->isEmpty()) {
            $drafts = [...$drafts, $name => $draft];

            app(DraftImages::class)->discard(self::imagesOf(array_slice($drafts, 0, -self::MaxDrafts)));
            $drafts = array_slice($drafts, -self::MaxDrafts, preserve_keys: true);
        }

        $drafts === []
            ? Cache::forget($this->key($userId))
            : Cache::forever($this->key($userId), array_map(fn (StashedDraft $draft): array => $draft->toArray(), $drafts));
    }

    /**
     * The names of every draft kept, for the Quote and Reply buttons that mark one.
     *
     * @return list<string>
     */
    public function names(?string $userId): array
    {
        return array_map(strval(...), array_keys($this->drafts($userId)));
    }

    /**
     * Every photo the drafts hold, which DraftImages keeps for them.
     *
     * @return list<DraftImage>
     */
    public function images(?string $userId): array
    {
        return self::imagesOf($this->drafts($userId));
    }

    /**
     * Forgets every draft, and deletes their photos.
     */
    public function forget(?string $userId): void
    {
        if ($userId !== null) {
            app(DraftImages::class)->discard($this->images($userId));
            Cache::forget($this->key($userId));
        }
    }

    /**
     * @return array<string, StashedDraft>
     */
    private function drafts(?string $userId): array
    {
        $drafts = $userId === null ? null : Cache::get($this->key($userId));

        return is_array($drafts)
            ? array_filter(array_map(StashedDraft::fromArray(...), $drafts), fn (StashedDraft $draft): bool => ! $draft->isEmpty())
            : [];
    }

    /**
     * @param  array<string, StashedDraft>  $drafts
     * @return list<DraftImage>
     */
    private static function imagesOf(array $drafts): array
    {
        return array_merge(...array_values(array_map(fn (StashedDraft $draft): array => $draft->images, $drafts)));
    }

    private function key(string $userId): string
    {
        return "devnull.stash.{$userId}";
    }
}
