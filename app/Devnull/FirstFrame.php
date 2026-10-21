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
    public function handle(): bool
    {
        return true;
    }
}
