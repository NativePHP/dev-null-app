<?php

use App\Devnull\Account;
use App\NativeComponents\AcceptTerms;
use App\NativeComponents\Notifications;
use App\NativeComponents\Remotes;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    serveApi([
        'GET /api/v1/remotes' => [remotesInboxJson([])],
        'GET /api/v1/notifications' => [['data' => [], 'meta' => ['next_cursor' => null]]],
    ]);
});

it('opens the tab a push belongs to with its screen to open on top', function (string $query, string $tab, ?array $data) {
    signIn();

    $screen = Native::visit('/push?'.$query)->assertReplacedWith($tab);

    expect($screen->navigationIntent()->data)->toBe($data ?? []);
})->with([
    'a reply' => ['type=replied&notification_id=n1&commit_id=c1&handle=ada', '/notifications', ['open' => '/commits/c1']],
    'a mention' => ['type=mentioned&notification_id=n1&commit_id=c1&handle=ada', '/notifications', ['open' => '/commits/c1']],
    'a follow' => ['type=followed&notification_id=n1&handle=ada', '/notifications', ['open' => '/users/ada']],
    'a direct message' => ['type=message&conversation_id=v1&kind=direct&handle=ada', '/remotes', ['open' => '/conversations/ada']],
    'a ping in an org' => ['type=ping&conversation_id=v1&kind=org', '/remotes', ['open' => '/orgs/v1']],
    'a message with nothing to open' => ['type=message&conversation_id=v1', '/remotes', null],
    'a quote, which a later devnull may push' => ['type=quoted&notification_id=n1&commit_id=c1&handle=ada', '/notifications', ['open' => '/commits/c1']],
    'a level-up, which is about no commit' => ['type=leveled_up&notification_id=n1', '/notifications', null],
    'a push of a kind this build does not know' => ['type=starred', '/notifications', null],
]);

it('checks the sign-in before opening a push, as the first screen does', function () {
    fakeKeychain();

    Native::visit('/push?type=replied&commit_id=c1')->assertReplacedWith('/onboarding');
});

it('opens a push after onboarding signs back in with the key, when the stored sign-in is gone', function () {
    // As on Android after an update, where clearing the app's caches takes the stored sign-in with them.
    fakeKeychain(testSeed());
    serveApi(['GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]]]);
    $this->freezeTime();

    $push = Native::visit('/push?type=replied&commit_id=c1')->assertReplacedWith('/onboarding');

    expect($push->navigationIntent()->data)->toBe(['then' => '/push?type=replied&commit_id=c1']);

    $onboarding = Native::visit('/onboarding', $push->navigationIntent()->data);
    $this->travel(1)->seconds();
    $onboarding->firePoll('tick')->assertReplacedWith('/push?type=replied&commit_id=c1');
});

it('signs back in to the log when onboarding is handed anything but a push', function () {
    fakeKeychain(testSeed());
    serveApi(['GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]]]);
    $this->freezeTime();

    $onboarding = Native::visit('/onboarding', ['then' => '/profile/device']);
    $this->travel(1)->seconds();
    $onboarding->firePoll('tick')->assertReplacedWith('/log');
});

it('puts owed terms in front of a push', function () {
    signIn();
    app(Account::class)->rememberTermsToAccept('2026-11-01T14:35:00Z');

    Native::visit('/push?type=replied&commit_id=c1')->assertReplacedWith(AcceptTerms::Path);
});

it('opens the pushed screen within the tab, so back lands on the tab', function (string $tab, string $uri, string $open) {
    signIn();

    $screen = Native::visit($uri, ['open' => $open])->assertNavigatedTo($uri.$open);

    expect($screen->navigationIntent()->data)->toBe(['pushed' => true]);
})->with([
    [Notifications::class, '/notifications', '/commits/c1'],
    [Remotes::class, '/remotes', '/conversations/ada'],
]);

it('opens nothing on top of a tab opened the usual way', function (string $uri) {
    signIn();

    expect(Native::visit($uri)->navigationIntent())->toBeNull();
})->with(['/notifications', '/remotes']);

it('fills a tab in at once when it opens a pushed screen, so back never lands on skeleton rows', function (string $uri) {
    signIn();
    config(['devnull.skeleton_first' => true]);

    Native::visit($uri, ['open' => '/users/ada'], platform: 'android')->assertSet('skeletonFrame', false);
})->with(['/notifications', '/remotes']);

it('goes back to the tab, saying why, when the commit a push opened has been deleted', function () {
    signIn();
    serveApi(fn () => Http::response(['message' => 'No query results for model [App\\Models\\Commit] c1'], 404));

    finishRefreshes(Native::visit('/notifications/commits/c1', ['pushed' => true]))
        ->assertWentBack()
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'That commit has been deleted.');
});

it('goes back to Remotes, saying why, when the thread a push opened is gone', function () {
    signIn();
    serveApi(fn () => Http::response(['message' => 'No query results for model [App\\Models\\Conversation] org-1'], 404));

    finishRefreshes(Native::visit('/remotes/orgs/org-1', ['pushed' => true]))
        ->assertWentBack()
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === "That Remote isn't there any more.");
});

it('stays on a deleted commit opened any other way, saying what devnull said', function () {
    signIn();
    serveApi(fn () => Http::response(['message' => 'No query results for model [App\\Models\\Commit] c1'], 404));

    finishRefreshes(Native::visit('/notifications/commits/c1'))
        ->assertNoNavigation()
        ->assertSee('No query results for model [App\\Models\\Commit] c1');
});

it('stays on a pushed commit that failed for any other reason, so Try again can bring it', function () {
    signIn();
    serveApi(fn () => Http::response(['message' => 'Server Error'], 500));

    finishRefreshes(Native::visit('/notifications/commits/c1', ['pushed' => true]))->assertNoNavigation();
});

/**
 * iOS reconciles a tab's stack only when it redraws the tabs, so of two frames that land together it sees the
 * second. A tab that pushed its push's screen as it mounted published its own frame and the pushed screen's back to
 * back, and iOS never saw the tab. It then stacked two or three copies of the pushed screen (NativePHP/mobile-air,
 * PerTabNavigationCoordinator), and back slid through each. The tab now waits for its first frame to come back.
 */
it('opens a push tapped on a cold start once, after the tab has drawn', function () {
    signIn();
    holdRefreshes();

    $tab = Native::visit('/push?type=replied&notification_id=n1&commit_id=c1')
        ->assertReplacedWith('/notifications')
        ->follow()
        ->assertNoNavigation();

    finishRefreshes($tab)->assertNavigatedTo('/notifications/commits/c1');

    expect($tab->navigationIntent()->data)->toBe(['pushed' => true]);

    serveApi(['GET /api/v1/commits/c1' => [['data' => commitJson(['id' => 'c1'])]]]);
    $tab->follow()->goBack();

    finishRefreshes($tab)->assertNoNavigation();
});

it('opens a push tapped while the app runs once, after the tab has drawn', function () {
    signIn();
    holdRefreshes();

    $tab = Native::visit('/log')
        ->emitNative('__deeplink', ['uri' => '/push?type=followed&notification_id=n1&handle=ada'])
        ->assertNavigatedTo('/push?type=followed&notification_id=n1&handle=ada')
        ->follow()
        ->assertReplacedWith('/notifications')
        ->follow()
        ->assertNoNavigation();

    finishRefreshes($tab)->assertNavigatedTo('/notifications/users/ada');
});
