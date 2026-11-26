<?php

namespace App\NativeComponents;

use App\Models\PendingWrite;
use App\NativeComponents\Concerns\OpensPushedScreen;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;

/**
 * A tab on iOS that keeps its screen in the primary column and opens what you tap there beside it, in the detail
 * column (see the adaptive-layout package): the Log and Notifications open commits, Remotes opens threads. On one
 * column, an iPhone or a folded Duo, the detail slides in over the primary column instead.
 *
 * The primary component and each detail are children of this one, embedded, so they keep their state, drafts and
 * scroll positions through a fold or an unfold. PHP owns the detail history ($conversations); the native side
 * reports back and pops through hostChanged(). Each page's bar is its component's own navigationOptions(), drawn
 * by the host, and a press on it calls that component (see callbacksOf()).
 *
 * Only this screen's polls run, so a workspace forwards the ones its children need.
 */
abstract class Workspace extends NativeComponent
{
    use OpensPushedScreen;

    protected bool $forceFullFrames = true;

    /**
     * The detail history, oldest first. A slot names each one's child for as long as it's open, so opening the same
     * commit or thread again later makes a fresh one.
     *
     * @var list<array{slot: int, id: string, data: array<string, mixed>}>
     */
    public array $conversations = [];

    /**
     * Goes up with each change of history PHP makes, so the native side's reports about an older one are dropped.
     */
    public int $navigationRevision = 0;

    private int $nextSlot = 0;

    private ?NativeComponent $photoOwner = null;

    /**
     * The component in the primary column.
     */
    abstract public function primary(): ?NativeComponent;

    /**
     * The detail a path in this tab opens, as the id its history keeps, or null for a path that opens over the
     * whole tab as before: profiles, organizations and everything else.
     */
    abstract public function paneFor(string $path): ?string;

    /**
     * The child component tag each detail mounts.
     */
    abstract protected function paneTag(): string;

    public function mount(): void
    {
        $this->openPushedScreen();
    }

    /**
     * The detail open in a slot.
     */
    public function paneAt(int $slot): ?NativeComponent
    {
        return $this->nativeChildComponents[$this->paneTag().'|key:conversation-'.$slot] ?? null;
    }

    /**
     * The primary component and every detail still in the history.
     *
     * @return list<NativeComponent>
     */
    public function panes(): array
    {
        $primary = $this->primary();

        return array_values(array_filter(
            $this->nativeChildComponents,
            fn (NativeComponent $child, string $key): bool => $child === $primary || str_starts_with($key, $this->paneTag().'|key:conversation-'),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * The detail on top, or the primary component when none is open.
     */
    public function foregroundPane(): ?NativeComponent
    {
        $current = end($this->conversations);

        return $current === false ? $this->primary() : $this->paneAt($current['slot']);
    }

    public function claimPhotoPicker(NativeComponent $owner): void
    {
        $this->photoOwner = $owner;
    }

    public function ownsPhotoPicker(NativeComponent $owner): bool
    {
        return $this->photoOwner === $owner;
    }

    public function recoverOutboxDraft(PendingWrite $write): bool
    {
        $pane = $this->foregroundPane();

        if ($pane !== null && method_exists($pane, 'reopenOutboxDraft') && $pane->reopenOutboxDraft($write)) {
            $this->navigationRevision++;

            return true;
        }

        return false;
    }

    /**
     * Opens a detail. One opened from the primary column replaces the history, as choosing another row does. The
     * one already on top only takes the new intent, such as Reply, keeping its draft and place.
     *
     * @param  array<string, mixed>  $data
     */
    #[On('open-conversation')]
    public function openConversation(string $id, array $data = [], bool $fromFeed = false): void
    {
        $this->navigationRevision++;
        $current = end($this->conversations);

        if ($current !== false && $current['id'] === $id) {
            $this->conversations[array_key_last($this->conversations)]['data'] = $data;
            $pane = $this->paneAt($current['slot']);
            if ($pane !== null && method_exists($pane, 'applyConversationIntent')) {
                $pane->applyConversationIntent($data);
            }

            return;
        }

        if ($fromFeed) {
            $this->conversations = [];
        }

        $this->conversations[] = ['slot' => ++$this->nextSlot, 'id' => $id, 'data' => $data];
    }

    /**
     * Closes a detail and any opened from it, as back would, when it is still open: the thread you stashed, or a
     * pushed commit that has gone since.
     */
    public function closePane(NativeComponent $pane): void
    {
        foreach ($this->conversations as $index => $conversation) {
            if ($this->paneAt($conversation['slot']) === $pane) {
                $this->conversations = array_slice($this->conversations, 0, $index);
                $this->navigationRevision++;
                $this->foregroundPane()?->onResume();

                return;
            }
        }
    }

    /**
     * A commit you deleted closes every detail from the first one about it, and the rest of the workspace leaves it.
     */
    public function conversationDeleted(string $id): void
    {
        foreach ($this->conversations as $index => $conversation) {
            if ($conversation['id'] === $id) {
                $this->conversations = array_slice($this->conversations, 0, $index);
                $this->navigationRevision++;
                break;
            }
        }

        $retained = array_map(fn (array $conversation): ?NativeComponent => $this->paneAt($conversation['slot']), $this->conversations);
        foreach ($this->nativeChildComponents as $child) {
            if (($child === $this->primary() || in_array($child, $retained, true)) && method_exists($child, 'applyWorkspaceDeletion')) {
                $child->applyWorkspaceDeletion($id);
            }
        }
    }

    /**
     * The native side went back: a back swipe or chevron, in either column. It says which history it saw, and one
     * about an older history is dropped.
     */
    public function hostChanged(string $event): void
    {
        $change = json_decode($event, true);

        if (! is_array($change) || ($change['action'] ?? null) !== 'back') {
            return;
        }

        $identities = array_map(fn (array $conversation): string => 'conversation-'.$conversation['slot'], $this->conversations);
        $expected = $change['expected_history'] ?? null;

        if (! is_array($expected)
            || ($change['expected_revision'] ?? null) !== $this->navigationRevision
            || ($change['expected_depth'] ?? null) !== count($expected)
            || array_slice($expected, 0, count($identities)) !== $identities) {
            return;
        }

        $depth = $change['depth'] ?? count($this->conversations) - 1;

        if (is_int($depth) && $depth >= 0 && $depth < count($this->conversations)) {
            $this->conversations = array_slice($this->conversations, 0, $depth);
            $this->foregroundPane()?->onResume();
        }
    }

    public function onResume(): void
    {
        foreach ($this->nativeChildComponents as $child) {
            $child->onResume();
        }
    }

    /**
     * Where a page's bar registers its buttons: the component the bar belongs to, so a press calls it.
     */
    protected function callbacksOf(?NativeComponent $pane): ?CallbackRegistry
    {
        return $pane?->nativeCallbacks;
    }

    /**
     * The workspace draws every bar itself.
     */
    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->hidden();
    }
}
