<?php

namespace App\Devnull;

use Native\Mobile\AsyncTask;

/**
 * Nothing, on one of NativePHP's background PHP threads. Its answer comes back
 * once the screen that sent it has drawn its first frame, which is when a tab
 * fills in what it kept (see Concerns\DrawsSkeletonFirst).
 */
final class FirstFrame extends AsyncTask
{
    /**
     * How long a tab waits for it, in seconds, before it fills in anyway. It's
     * back in a moment unless every background thread is busy, as they all are
     * while devnull takes requests and doesn't answer them. The tab then
     * shows what it kept rather than skeleton rows until one is free.
     */
    public const int Within = 2;

    public function handle(): bool
    {
        return true;
    }
}
