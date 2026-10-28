<?php

use App\NativeComponents\AddOrgMembers;
use App\NativeComponents\AuthCallback;
use App\NativeComponents\CommitDetail;
use App\NativeComponents\Connections;
use App\NativeComponents\Conversation;
use App\NativeComponents\Launch;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Log;
use App\NativeComponents\NewOrg;
use App\NativeComponents\NewRemote;
use App\NativeComponents\Notifications;
use App\NativeComponents\Onboarding;
use App\NativeComponents\OrgMembers;
use App\NativeComponents\Profile;
use App\NativeComponents\Remotes;
use App\NativeComponents\Safety;
use App\NativeComponents\Starred;
use App\NativeComponents\ThisDevice;
use Illuminate\Support\Facades\Route;

Route::native('/', Launch::class);
Route::native('/onboarding', Onboarding::class);
Route::native('/auth/callback', AuthCallback::class);

Route::nativeGroup(TabsLayout::class, function (): void {
    Route::native('/log', Log::class);
    Route::native('/notifications', Notifications::class);
    Route::native('/remotes', Remotes::class);
    Route::native('/remotes/new', NewRemote::class);
    Route::native('/remotes/new-org', NewOrg::class);
    Route::native('/profile', Profile::class);
    Route::native('/profile/device', ThisDevice::class);
    Route::native('/profile/starred', Starred::class);

    // Commits, profiles, their followers, the people you mute and block, and
    // Remotes threads open beneath the tab they were tapped in, so that tab's
    // own navigation stack pushes them (see Concerns\OpensInTab).
    foreach (TabsLayout::Tabs as $tab) {
        Route::native("{$tab}/commits/{id}", CommitDetail::class);
        Route::native("{$tab}/users/{username}", Profile::class);
        Route::native("{$tab}/users/{username}/{type}", Connections::class)->whereIn('type', Connections::Types);
        Route::native("{$tab}/safety", Safety::class);
        Route::native("{$tab}/conversations/{username}", Conversation::class);
        Route::native("{$tab}/orgs/{org}", Conversation::class);
        Route::native("{$tab}/orgs/{org}/members", OrgMembers::class);
        Route::native("{$tab}/orgs/{org}/members/add", AddOrgMembers::class);
    }
});
