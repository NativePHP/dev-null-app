<?php

use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Notifications;
use App\NativeComponents\NotificationsWorkspace;
use App\NativeComponents\Remotes;
use App\NativeComponents\RemotesWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\System;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    // As iOS routes the tab, so what opens over it opens beneath /notifications.
    NativeRouter::register('/notifications', NotificationsWorkspace::class, TabsLayout::class);
});

afterEach(function () {
    NativeRouter::register('/notifications', Notifications::class, TabsLayout::class);
});

/**
 * devnull answering the inbox with a reply to your commit, and any commit's page, as yours. A commit that has gone
 * answers 404.
 */
function serveNotificationsWorkspace(bool $commitsGone = false): void
{
    serveApi(function (Request $request) use ($commitsGone) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/api/v1/notifications') {
            return Http::response([
                ...pageJson([notificationJson('replied')], path: 'http://devnull.test/api/v1/notifications'),
                'filters' => ['type' => 'all'],
                'read_at' => now()->toIso8601ZuluString(),
            ]);
        }

        if (preg_match('~/commits/([^/]+)$~', $path, $matches)) {
            return $commitsGone
                ? Http::response(['message' => 'No query results for model [App\\Models\\Commit] '.$matches[1]], 404)
                : Http::response(commitPageJson(commitJson(['id' => $matches[1], 'body' => 'Commit '.$matches[1]])));
        }

        return Http::response(pageJson([]));
    });
}

function notificationRowBackground(TestableComponent $screen): string
{
    return strtolower(nodeByRef($screen, 'notification-n-replied')['style']['bg_color']);
}

it('opens Notifications and Remotes as workspaces on iOS, and as screens on Android', function (string $platform, string $uri, string $component) {
    try {
        System::rememberPlatform($platform);
        $this->refreshApplication();

        expect(NativeRouter::resolve($uri)['class'])->toBe($component);
    } finally {
        System::rememberPlatform(null);
        $this->refreshApplication();
    }
})->with([
    'Notifications on iOS' => ['ios', '/notifications', NotificationsWorkspace::class],
    'Notifications on Android' => ['android', '/notifications', Notifications::class],
    'Remotes on iOS' => ['ios', '/remotes', RemotesWorkspace::class],
    'Remotes on Android' => ['android', '/remotes', Remotes::class],
]);

it('opens the commit a notification is about beside the list, and marks its row while it is open', function () {
    serveNotificationsWorkspace();
    $screen = finishRefreshes(Native::visit('/notifications', platform: 'ios'));
    $surface = notificationRowBackground($screen);
    expect(treeShows(nodeByRef($screen, 'no-conversation'), 'no commit selected'))->toBeTrue();

    finishRefreshes($screen->tap('notification-n-replied'))->assertSee('Commit c-replied')->assertNoNavigation();

    expect(array_column($screen->get('conversations'), 'id'))->toBe(['c-replied'])
        ->and(notificationRowBackground($screen))->toBe(strtolower((string) theme('wash')));

    $screen->call('hostChanged', json_encode(['action' => 'back', 'depth' => 0, 'expected_depth' => 1, 'expected_revision' => 1, 'expected_history' => ['conversation-1']]));

    expect($screen->get('conversations'))->toBe([])
        ->and(notificationRowBackground($screen))->toBe($surface);
});

it('still opens profiles over the whole tab, as the Log does', function () {
    serveNotificationsWorkspace();

    finishRefreshes(Native::visit('/notifications', platform: 'ios'))
        ->tap('actor-name-n-replied')
        ->assertNavigatedTo('/notifications/users/ada')
        ->assertSet('conversations', []);
});

it('keeps the settings button on the list\'s bar, where it opens the settings over the tab', function () {
    serveNotificationsWorkspace();
    $screen = finishRefreshes(Native::visit('/notifications', platform: 'ios'));

    expect(workspaceBar($screen)['props']['title'])->toBe('Notifications');

    pressWorkspaceBar($screen, 'notification-settings')->assertNavigatedTo('/notifications/settings');
});

it('puts the commit\'s title, short hash and Delete on its own bar, where Delete asks that commit', function () {
    serveNotificationsWorkspace();
    $screen = finishRefreshes(finishRefreshes(Native::visit('/notifications', platform: 'ios'))->tap('notification-n-replied'));
    $bar = workspaceBar($screen, 'conversation-1');

    expect($bar['props']['title'])->toBe('Commit')
        ->and($bar['props']['subtitle'])->toBe(commitJson(['id' => 'c-replied'])['short_hash'])
        ->and(workspaceBarAction($screen, 'delete', 'conversation-1')['props']['a11y_label'])->toBe('Delete commit')
        ->and(workspaceBarAction($screen, 'notification-settings', 'conversation-1'))->toBeNull();

    pressWorkspaceBar($screen, 'delete', 'conversation-1')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Delete this commit?');
});

it('opens a pushed commit beside the list once the tab has drawn', function () {
    serveNotificationsWorkspace();

    $screen = finishRefreshes(Native::visit('/notifications', ['open' => '/commits/c1'], platform: 'ios'))
        ->assertNoNavigation()
        ->assertSee('Commit c1');

    expect($screen->get('conversations'))->toBe([['slot' => 1, 'id' => 'c1', 'data' => ['pushed' => true]]]);
});

it('closes a pushed commit that has gone, saying why, and stays on the list', function () {
    serveNotificationsWorkspace(commitsGone: true);

    finishRefreshes(Native::visit('/notifications', ['open' => '/commits/c1'], platform: 'ios'))
        ->assertSet('conversations', [])
        ->assertNoNavigation()
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'That commit has been deleted.');
});

it('opens a pushed profile over the whole tab, as before', function () {
    serveNotificationsWorkspace();

    Native::visit('/notifications', ['open' => '/users/ada'], platform: 'ios')
        ->assertNavigatedTo('/notifications/users/ada')
        ->assertSet('conversations', []);
});
