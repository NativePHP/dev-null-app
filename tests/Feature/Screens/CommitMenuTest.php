<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

it('fills More with what you can do to the commit', function (string $platform) {
    $ada = userJson(['id' => 'u2', 'handle' => 'ada']);

    serveApi(fn () => Http::response(pageJson([
        commitJson(['id' => 'c4', 'starred' => true, 'author' => $ada]),
        commitJson(['id' => 'c3', 'status' => 'pending', 'author' => $ada]),
        commitJson(['id' => 'c2']),
        commitJson(['id' => 'c1', 'status' => 'pending']),
    ])));

    $screen = Native::visit('/log', platform: $platform);
    $more = nodeByRef($screen, 'more-c4');

    // Tapping More only opens its menu.
    expect($more['props']['has_menu'] ?? null)->toBeTrue()
        ->and($more['on_press'] ?? $more['props']['on_press'] ?? null)->toBeNull();

    // Someone else's published commit: share it or report it.
    expect(menuItems($screen, 'more-c4'))->toBe([
        ['share-c4', 'Share link', 'custom:link'],
        'divider',
        ['report-c4', 'Report commit', 'custom:flag'],
    ])
        // Theirs, waiting for approval: only its link, as the web leaves the rest off.
        ->and(menuItems($screen, 'more-c3'))->toBe([['share-c3', 'Share link', 'custom:link']]);

    // Your own published one: share it, pin it to your profile, or delete it.
    expect(menuItems($screen, 'more-c2'))->toBe([
        ['share-c2', 'Share link', 'custom:link'],
        ['pin-c2', 'Pin to profile', 'custom:pin'],
        'divider',
        ['delete-c2', 'Delete commit', 'custom:trash-2'],
    ])
        // Yours, waiting for approval: the same, but it can't be pinned yet.
        ->and(menuItems($screen, 'more-c1'))->toBe([
            ['share-c1', 'Share link', 'custom:link'],
            'divider',
            ['delete-c1', 'Delete commit', 'custom:trash-2'],
        ]);

    foreach (['c2', 'c1'] as $id) {
        expect(nodeByRef($screen, 'delete-'.$id)['props']['destructive'] ?? null)->toBeTrue();
    }
})->with(['ios', 'android']);

it('deletes your own commit from the log after a confirm, and lets it go', function () {
    $deleted = false;

    serveApi(function (Request $request) use (&$deleted) {
        if ($request->method() === 'DELETE') {
            $deleted = true;

            return Http::response(null, 204);
        }

        return Http::response(pageJson([
            ...($deleted ? [] : [commitJson(['id' => 'c2', 'body' => 'Delete me'])]),
            commitJson(['id' => 'c1', 'body' => 'Keep me']),
        ]));
    });

    $screen = Native::visit('/log', platform: 'ios');

    // An alert some other screen put up isn't this one's to answer.
    $screen->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit']);

    $screen->tap('delete-c2')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Delete this commit?' && $params['message'] === "There's no undo.")
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => 'delete-commit'])
        ->assertSee('Delete me');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    $screen->tap('delete-c2')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit'])
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'rm c2 → /dev/null')
        ->assertNoNavigation()
        ->assertDontSee('Delete me')
        ->assertSee('Keep me');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/commits/c2');
});

it('deletes your own commit from your profile', function () {
    $deleted = false;

    serveApi(function (Request $request) use (&$deleted) {
        return match ($request->method().' '.parse_url($request->url(), PHP_URL_PATH)) {
            'DELETE /api/v1/commits/c2' => tap(Http::response(null, 204), function () use (&$deleted): void {
                $deleted = true;
            }),
            'GET /api/v1/users/local_dev' => Http::response(['data' => userWithCountsJson(commits: $deleted ? 1 : 2), 'is_following' => false, 'pinned_commit' => null]),
            'GET /api/v1/users/local_dev/commits' => Http::response(pageJson([
                ...($deleted ? [] : [commitJson(['id' => 'c2', 'body' => 'Delete me'])]),
                commitJson(['id' => 'c1', 'body' => 'Keep me']),
            ])),
            default => Http::response(currentUserJson()),
        };
    });

    Native::visit('/log/users/local_dev', platform: 'ios')
        ->assertSee('Delete me')
        ->tap('delete-c2')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit'])
        ->assertNoNavigation()
        ->assertDontSee('Delete me')
        ->assertSee('Keep me');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/commits/c2');
});

it('deletes your reply from a commit page, staying on the page', function () {
    $deleted = false;

    serveApi(function (Request $request) use (&$deleted) {
        return match ($request->method().' '.parse_url($request->url(), PHP_URL_PATH)) {
            'DELETE /api/v1/commits/r1' => tap(Http::response(null, 204), function () use (&$deleted): void {
                $deleted = true;
            }),
            'GET /api/v1/commits/c2' => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'Ada asks', 'replies_count' => $deleted ? 0 : 1, 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])]))),
            'GET /api/v1/commits/c2/replies' => Http::response(pageJson($deleted ? [] : [replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'Soon gone'])])),
            default => Http::response(['message' => 'No fake.'], 404),
        };
    });

    $screen = Native::visit('/log/commits/c2', platform: 'ios')
        ->tap('replies-c2')
        ->assertSee('Soon gone');

    // Ada's commit, which this page is about, is hers to delete.
    expect(array_column(array_filter(menuItems($screen, 'more-c2'), is_array(...)), 0))->not->toContain('delete-c2');

    $screen->tap('delete-r1')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit'])
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'rm r1 → /dev/null')
        ->assertNoNavigation()
        ->assertDontSee('Soon gone')
        ->assertSee('Ada asks');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/commits/r1');
});

it('keeps your commit when devnull says no, saying why', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Still here'])])],
        'DELETE /api/v1/commits/c1' => [['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429],
    ]);

    Native::visit('/log', platform: 'ios')
        ->tap('delete-c1')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => 'delete-commit'])
        ->assertNativeNotCalled('ToastKit.Show')
        ->assertSee('Too Many Attempts.')
        ->assertSee('Still here');
});

it('pins your commit to the top of your profile, and unpins it', function () {
    $pinned = null;

    serveApi(function (Request $request) use (&$pinned) {
        $commits = [commitJson(['id' => 'c2', 'body' => 'Pin me', 'is_pinned' => $pinned === 'c2']), commitJson(['id' => 'c1', 'body' => 'Leave me'])];

        return match ($request->method().' '.parse_url($request->url(), PHP_URL_PATH)) {
            'PUT /api/v1/commits/c2/pin' => tap(Http::response(['data' => [...$commits[0], 'is_pinned' => true]]), function () use (&$pinned): void {
                $pinned = 'c2';
            }),
            'DELETE /api/v1/commits/c2/pin' => tap(Http::response(['data' => [...$commits[0], 'is_pinned' => false]]), function () use (&$pinned): void {
                $pinned = null;
            }),
            'GET /api/v1/users/local_dev' => Http::response(['data' => userWithCountsJson(commits: 2), 'is_following' => false, 'pinned_commit' => $pinned === null ? null : [...$commits[0], 'is_pinned' => true]]),
            'GET /api/v1/users/local_dev/commits' => Http::response(pageJson(array_values(array_filter($commits, fn (array $commit): bool => $commit['id'] !== $pinned)))),
            default => Http::response(currentUserJson()),
        };
    });

    $screen = Native::visit('/log/users/local_dev', platform: 'ios');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'pinned'))->toBeNull();

    $screen->tap('pin-c2')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Commit pinned to your profile.')
        ->assertNoNavigation();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->url() === 'http://devnull.test/api/v1/commits/c2/pin');

    // It sits on top, once, and its menu now unpins it.
    expect($screen->instance()->pinnedCommit()?->id)->toBe('c2')
        ->and(array_column($screen->instance()->commits, 'id'))->toBe(['c1'])
        ->and(nodeByRef($screen, 'pinned'))->not->toBeEmpty()
        ->and(menuItems($screen, 'more-c2'))->toContain(['pin-c2', 'Unpin from profile', 'custom:pin']);

    $screen->tap('pin-c2')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Commit unpinned.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/commits/c2/pin');

    // Back in your commits, in order, with Pin to profile again.
    expect($screen->instance()->pinnedCommit())->toBeNull()
        ->and(array_column($screen->instance()->commits, 'id'))->toBe(['c2', 'c1'])
        ->and(menuItems($screen, 'more-c2'))->toContain(['pin-c2', 'Pin to profile', 'custom:pin']);
});

it("leaves Pin to profile off a reply, and off someone else's commit", function () {
    serveApi(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/v1/commits/c2' => Http::response(commitPageJson(commitJson(['id' => 'c2', 'replies_count' => 1, 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])]))),
        '/api/v1/commits/c2/replies' => Http::response(pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c2'])])),
        default => Http::response(['message' => 'No fake.'], 404),
    });

    $screen = Native::visit('/log/commits/c2', platform: 'ios')->tap('replies-c2');

    expect(array_column(array_filter([...menuItems($screen, 'more-c2'), ...menuItems($screen, 'more-r1')], is_array(...)), 0))
        ->not->toContain('pin-c2')
        ->not->toContain('pin-r1');

    $screen->call('togglePin', 'r1')->call('togglePin', 'c2');

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/pin'));
});
