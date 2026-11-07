<?php

namespace App\NativeComponents;

use App\Models\PendingWrite;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;

class FeedWorkspace extends NativeComponent
{
    protected bool $forceFullFrames = true;

    /** @var list<array{slot: int, id: string, data: array<string, mixed>}> */
    public array $conversations = [];

    public int $navigationRevision = 0;

    private int $nextSlot = 0;

    private ?NativeComponent $photoOwner = null;

    /** @return list<Log|CommitDetail> */
    public function panes(): array
    {
        return array_values(array_filter(
            $this->nativeChildComponents,
            fn (NativeComponent $child): bool => $child instanceof Log || $child instanceof CommitDetail,
        ));
    }

    public function claimPhotoPicker(NativeComponent $owner): void
    {
        $this->photoOwner = $owner;
    }

    public function ownsPhotoPicker(NativeComponent $owner): bool
    {
        return $this->photoOwner === $owner;
    }

    public function foregroundPane(): Log|CommitDetail|null
    {
        $current = end($this->conversations);

        return $current === false
            ? $this->feed()
            : ($this->nativeChildComponents['commit-detail|key:conversation-'.$current['slot']] ?? null);
    }

    public function recoverOutboxDraft(PendingWrite $write): bool
    {
        if ($this->foregroundPane()?->reopenOutboxDraft($write)) {
            $this->navigationRevision++;

            return true;
        }

        return false;
    }

    #[On('open-conversation')]
    public function openConversation(string $id, array $data = [], bool $fromFeed = false): void
    {
        $this->navigationRevision++;
        $current = end($this->conversations);

        if ($current !== false && $current['id'] === $id) {
            $index = array_key_last($this->conversations);
            $this->conversations[$index]['data'] = $data;
            $detail = $this->nativeChildComponents['commit-detail|key:conversation-'.$current['slot']] ?? null;
            if ($detail instanceof CommitDetail) {
                $detail->applyConversationIntent($data);
            }

            return;
        }

        if ($fromFeed) {
            $this->conversations = [];
        }

        $this->conversations[] = ['slot' => ++$this->nextSlot, 'id' => $id, 'data' => $data];
    }

    public function conversationDeleted(string $id): void
    {
        foreach ($this->conversations as $index => $conversation) {
            if ($conversation['id'] === $id) {
                $this->conversations = array_slice($this->conversations, 0, $index);
                $this->navigationRevision++;
                break;
            }
        }

        $retained = array_map(fn (array $conversation): string => 'commit-detail|key:conversation-'.$conversation['slot'], $this->conversations);
        foreach ($this->nativeChildComponents as $key => $child) {
            if ($child instanceof Log || ($child instanceof CommitDetail && in_array($key, $retained, true))) {
                $child->applyWorkspaceDeletion($id);
            }
        }
    }

    public function hostChanged(string $event): void
    {
        $change = json_decode($event, true);

        if (! is_array($change)) {
            return;
        }

        if (in_array($change['action'] ?? null, ['back', 'close', 'delete'], true)) {
            $identities = array_map(fn (array $conversation): string => 'conversation-'.$conversation['slot'], $this->conversations);
            $expected = $change['expected_history'] ?? null;
            $matches = $expected === $identities;
            if (($change['action'] ?? null) === 'back' && is_array($expected)) {
                $matches = array_slice($expected, 0, count($identities)) === $identities;
            }
            if (! is_array($expected) || ($change['expected_revision'] ?? null) !== $this->navigationRevision || ($change['expected_depth'] ?? null) !== count($expected) || ! $matches) {
                return;
            }
        }

        switch ($change['action'] ?? null) {
            case 'back':
                $depth = $change['depth'] ?? count($this->conversations) - 1;
                if (is_int($depth) && $depth >= 0 && $depth < count($this->conversations)) {
                    $this->conversations = array_slice($this->conversations, 0, $depth);
                }
                break;
            case 'close':
                $this->conversations = [];
                break;
            case 'compose':
                $this->feed()?->composeInSheet();
                break;
            case 'search':
                $this->feed()?->openSearch();
                break;
            case 'game':
                $this->feed()?->playNataNullers();
                break;
            case 'delete':
                $current = end($this->conversations);
                if ($current !== false) {
                    $detail = $this->nativeChildComponents['commit-detail|key:conversation-'.$current['slot']] ?? null;
                    if ($detail instanceof CommitDetail) {
                        $detail->confirmDelete($current['id']);
                    }
                }
                break;
        }
    }

    public function conversationCanDelete(int $slot): bool
    {
        $detail = $this->nativeChildComponents['commit-detail|key:conversation-'.$slot] ?? null;

        return $detail instanceof CommitDetail && $detail->isOwn();
    }

    public function onResume(): void
    {
        foreach ($this->nativeChildComponents as $child) {
            $child->onResume();
        }
    }

    #[Poll(30000)]
    public function checkForNewCommits(): void
    {
        $this->feed()?->checkForNewCommits();
    }

    private function feed(): ?Log
    {
        foreach ($this->nativeChildComponents as $child) {
            if ($child instanceof Log) {
                return $child;
            }
        }

        return null;
    }

    public function navTitle(): string
    {
        return 'devnull';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->hidden();
    }

    public function render(): View
    {
        return view('native.feed-workspace');
    }
}
