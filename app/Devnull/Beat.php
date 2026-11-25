<?php

namespace App\Devnull;

use Native\Mobile\AsyncTask;

/**
 * Waits $milliseconds on one of NativePHP's background PHP threads, so a
 * screen can draw a frame, then change it once the phone has drawn it.
 *
 * EDGE animates a node from what it showed to what the next frame says
 * (`animate-duration`), but it can't play a sequence or animate a row as it
 * comes into a list. A screen draws the first step, and the next one when its
 * Beat comes back: replies drawn see-through then faded in, a star scaled up
 * then sprung back (see Concerns\ExpandsThreads and Concerns\PopsReactions).
 */
final class Beat extends AsyncTask
{
    /**
     * How long a frame waits for the next step of an animation, so the phone
     * has drawn it first.
     */
    public const int Step = 60;

    public function handle(int $milliseconds): bool
    {
        // Tests run tasks inline, where only the order of the steps counts.
        if (! app()->runningUnitTests()) {
            usleep(max(0, $milliseconds) * 1000);
        }

        return true;
    }
}
