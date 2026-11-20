<?php

namespace App\NativeComponents\Concerns;

/**
 * New commits on a screen with no new commits pill, a profile or an
 * organization, used with HoldsNewCommits. While you're at the top of the
 * list they go straight in on top. Otherwise they wait out of sight, in
 * $newCommits, which nothing draws, for the next time you're at the top.
 *
 * EDGE never says where a list is scrolled, so this goes by what the screen
 * knows. The list is at its top when the screen opens, after a pull to
 * refresh, and when a tab tap or a swipe starts it again. Once you've been
 * somewhere else and come back, or the list has asked for its next page, you
 * may be anywhere in it, and rows put above the ones you're reading would
 * push them down. So the commits wait until you pull to refresh or tap the
 * tab you're on.
 */
trait SlotsInNewCommits
{
    /**
     * You may have scrolled the list away from its top since it was last known to be there.
     */
    public bool $mayBeScrolled = false;

    /**
     * Whether newer commits in a fresh first page go in now. $showNewer says you asked for them.
     */
    protected function newCommitsGoIn(bool $showNewer): bool
    {
        return $showNewer || ! $this->mayBeScrolled;
    }

    /**
     * The list is at its top, with any commits that were waiting on top of it.
     */
    protected function backAtTheTop(): void
    {
        $this->mayBeScrolled = false;
        $this->putNewCommitsOnTop();
    }
}
