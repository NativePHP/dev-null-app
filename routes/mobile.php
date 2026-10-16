<?php

use App\NativeComponents\CommitDetail;
use App\NativeComponents\Launch;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Log;
use App\NativeComponents\Notifications;
use App\NativeComponents\Onboarding;
use App\NativeComponents\Profile;
use App\NativeComponents\ThisDevice;
use App\NativeComponents\Welcome;
use Illuminate\Support\Facades\Route;

Route::native('/', Launch::class);
Route::native('/welcome', Welcome::class);
Route::native('/onboarding', Onboarding::class);

Route::nativeGroup(TabsLayout::class, function (): void {
    Route::native('/log', Log::class);
    Route::native('/notifications', Notifications::class);
    Route::native('/profile', Profile::class);
    Route::native('/profile/device', ThisDevice::class);

    // Commits and profiles open beneath the tab they were tapped in, so that tab's
    // own navigation stack pushes them (see Concerns\OpensInTab).
    foreach (TabsLayout::Tabs as $tab) {
        Route::native("{$tab}/commits/{id}", CommitDetail::class);
        Route::native("{$tab}/users/{username}", Profile::class);
    }
});
