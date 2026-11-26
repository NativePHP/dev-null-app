<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;

/**
 * The Log on iOS: the logs in the primary column, and the commit you open beside them (see Workspace).
 */
class FeedWorkspace extends Workspace
{
    public function primary(): ?Log
    {
        foreach ($this->nativeChildComponents as $child) {
            if ($child instanceof Log) {
                return $child;
            }
        }

        return null;
    }

    public function paneFor(string $path): ?string
    {
        return str_starts_with($path, '/commits/') ? substr($path, strlen('/commits/')) : null;
    }

    protected function paneTag(): string
    {
        return 'commit-detail';
    }

    /**
     * The commit of the conversation open from the log, which its row marks.
     */
    public function selectedCommit(): ?string
    {
        return $this->conversations[0]['id'] ?? null;
    }

    #[Poll(30000)]
    public function checkForNewCommits(): void
    {
        $this->primary()?->checkForNewCommits();
    }

    public function navTitle(): string
    {
        return 'devnull';
    }

    public function render(): View
    {
        return view('native.feed-workspace');
    }
}
