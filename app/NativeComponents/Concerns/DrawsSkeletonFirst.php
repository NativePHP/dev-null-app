<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\FirstFrame;

/**
 * A tab that opens on skeleton rows and fills in what it kept straight after.
 *
 * Drawing a screen of kept rows took 2 to 3 seconds on a slow Android phone,
 * with nothing on screen to say the tap had worked. A first frame of skeleton
 * rows draws at once, and its kept rows and the refresh behind them follow as
 * soon as FirstFrame comes back from the background thread.
 *
 * config('devnull.skeleton_first') turns it off, as phpunit.xml does, so a
 * test sees a screen as it fills in.
 */
trait DrawsSkeletonFirst
{
    /**
     * True for the first frame, which shows skeleton rows only.
     */
    public bool $skeletonFrame = false;

    /**
     * Shows what was kept, then calls $then (the screen's refresh), after a
     * first frame of skeleton rows.
     */
    protected function fillInAfterFirstFrame(string $then): void
    {
        if (! config('devnull.skeleton_first')) {
            $this->fillIn($then);

            return;
        }

        $this->skeletonFrame = true;

        FirstFrame::dispatch()
            ->timeout(10)
            ->finished(fn () => $this->fillIn($then))
            ->failed(fn () => $this->fillIn($then));
    }

    private function fillIn(string $then): void
    {
        $this->skeletonFrame = false;
        $this->showKept();
        $this->{$then}();
    }
}
