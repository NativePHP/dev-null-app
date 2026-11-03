<?php

namespace App\NativeComponents\Concerns;

use Native\Mobile\Facades\Browser;

/**
 * Opens dev-null.sh's terms of service and privacy policy in the in-app browser, from wherever they're agreed to or
 * looked up again.
 */
trait OpensLegalPages
{
    public function openTerms(): void
    {
        Browser::inApp(rtrim((string) config('devnull.url'), '/').'/terms');
    }

    public function openPrivacy(): void
    {
        Browser::inApp(rtrim((string) config('devnull.url'), '/').'/privacy');
    }
}
