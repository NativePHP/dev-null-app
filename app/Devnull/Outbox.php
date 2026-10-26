<?php

namespace App\Devnull;

use App\Models\PendingWrite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Native\Mobile\Support\AsyncTaskRegistry;

/**
 * What you did and devnull hasn't answered for yet, kept in the app's own
 * database so a tap never waits on devnull: your stars, commits, replies and
 * Remotes messages are written here first, the screen shows them at once, and
 * they go from the background straight away (see Concerns\SyncsOutbox).
 *
 * Each one stays here until devnull answers. Once it has, a star or a commit
 * goes, and the screen takes devnull's answer. If devnull turns it down, a
 * star goes and comes back off the commit, a commit stays as failed for you to
 * edit, and a message stays in its thread as rejected for you to retry.
 *
 * The app can be closed while one is on its way, or the phone can kill it.
 * The next time the app opens, what was still on its way is a leftover (see
 * leftovers()): stars and messages go again, since devnull can't do them
 * twice, and commits are marked failed, since devnull might have one already
 * and has nothing to know it by.
 *
 * It's kept per signed-in user, and signing out empties it (see Account).
 */
final class Outbox
{
    /**
     * The event devnull's answer to a write comes back as, to whichever screen
     * is showing by then, rather than only to the screen it was made on.
     */
    public const string Answered = 'devnull.outbox-answered';

    /**
     * How long a write may take before it's given up on, in seconds, and a
     * commit with photos, which takes longer to upload over a slow connection.
     */
    public const int Timeout = 30;

    public const int UploadTimeout = 120;

    /**
     * What a commit says when devnull may have it already but never said so:
     * its background task gave up waiting, or it was left over from when the
     * app closed.
     */
    public const string NoAnswer = "devnull didn't answer in time, so this commit may not have gone. Check before you send it again.";

    public const string ClosedBeforeAnswer = 'The app closed before devnull answered, so this commit may not have gone. Check before you send it again.';

    /**
     * Writes something down to go to devnull, under a client id of its own,
     * or the one a Remotes message is already staged under.
     *
     * @param  array<string, mixed>  $payload
     */
    public function add(string $kind, ?string $target, array $payload, ?string $clientId = null): ?PendingWrite
    {
        $userId = $this->userId();

        if ($userId === null) {
            return null;
        }

        return PendingWrite::query()->create([
            'user_id' => $userId,
            'kind' => $kind,
            'target' => $target,
            'client_id' => $clientId ?? (string) Str::uuid(),
            'payload' => $payload,
            'status' => PendingWrite::Queued,
        ]);
    }

    /**
     * It went, as the background task with this id.
     */
    public function sending(PendingWrite $write, string $taskId): void
    {
        $write->forceFill([
            'status' => PendingWrite::Sending,
            'task_id' => $taskId,
            'error' => null,
            'attempts' => $write->attempts + 1,
        ])->save();
    }

    /**
     * devnull turned it down, in its own words.
     */
    public function failed(PendingWrite $write, string $error): void
    {
        $write->forceFill(['status' => PendingWrite::Failed, 'task_id' => null, 'error' => $error])->save();
    }

    public function forget(PendingWrite $write): void
    {
        $write->delete();
    }

    /**
     * The write a background task was sending.
     */
    public function forTask(string $taskId): ?PendingWrite
    {
        return $taskId === '' ? null : $this->mine()->where('task_id', $taskId)->first();
    }

    public function find(int $id): ?PendingWrite
    {
        return $this->mine()->whereKey($id)->first();
    }

    public function findByClientId(string $clientId): ?PendingWrite
    {
        return $this->mine()->where('client_id', $clientId)->first();
    }

    /**
     * What's still waiting to go, or on its way, of a kind, about $target
     * when given, oldest first. Failed writes aren't, unless asked for too.
     *
     * @return Collection<int, PendingWrite>
     */
    public function pending(string $kind, ?string $target = null, bool $withFailed = false): Collection
    {
        return $this->mine()
            ->where('kind', $kind)
            ->when($target !== null, fn ($query) => $query->where('target', $target))
            ->whereIn('status', $withFailed ? [PendingWrite::Queued, PendingWrite::Sending, PendingWrite::Failed] : [PendingWrite::Queued, PendingWrite::Sending])
            ->orderBy('id')
            ->get();
    }

    /**
     * Commits on their way that reply to $parentId, or with null, new commits and quotes.
     *
     * @return Collection<int, PendingWrite>
     */
    public function commitsUnder(?string $parentId): Collection
    {
        return $this->pending(PendingWrite::Commit)->filter(fn (PendingWrite $write): bool => $write->parentId() === $parentId)->values();
    }

    /**
     * Whether a star or an unstar of the commit other than $write is still waiting or on its way, which has the last word.
     */
    public function starsAfter(PendingWrite $write): bool
    {
        return $this->mine()
            ->whereIn('kind', [PendingWrite::Star, PendingWrite::Unstar])
            ->where('target', $write->target)
            ->where('id', '>', $write->id)
            ->whereIn('status', [PendingWrite::Queued, PendingWrite::Sending])
            ->exists();
    }

    /**
     * The commit devnull last turned down that was written under the draft $stashName, for its composer to have back.
     */
    public function failedDraft(string $stashName): ?PendingWrite
    {
        return $this->mine()
            ->where('kind', PendingWrite::Commit)
            ->where('status', PendingWrite::Failed)
            ->get()
            ->filter(fn (PendingWrite $write): bool => $write->stashName() === $stashName)
            ->last();
    }

    /**
     * What was waiting or on its way when the app last closed: anything not
     * sent from this run of the app, since a background task doesn't outlive
     * the app, and one still on its way is known to it (see AsyncTaskRegistry).
     *
     * @return Collection<int, PendingWrite>
     */
    public function leftovers(): Collection
    {
        return $this->mine()
            ->whereIn('status', [PendingWrite::Queued, PendingWrite::Sending])
            ->orderBy('id')
            ->get()
            ->filter(fn (PendingWrite $write): bool => $write->task_id === null || AsyncTaskRegistry::scope($write->task_id) === null)
            ->values();
    }

    /**
     * Empties the outbox of a user signing out.
     */
    public function forgetAll(?string $userId): void
    {
        if ($userId !== null) {
            PendingWrite::query()->where('user_id', $userId)->delete();
        }
    }

    /**
     * @return Builder<PendingWrite>
     */
    private function mine()
    {
        return PendingWrite::query()->where('user_id', $this->userId() ?? '');
    }

    private function userId(): ?string
    {
        $id = app(Account::class)->user()?->id;

        return $id === null || $id === '' ? null : $id;
    }
}
