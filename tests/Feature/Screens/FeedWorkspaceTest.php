<?php

use App\Devnull\DraftImages;
use App\Devnull\NataNullers\Game;
use App\Devnull\Outbox;
use App\Devnull\Stash;
use App\Models\PendingWrite;
use App\NativeComponents\CommitDetail;
use App\NativeComponents\FeedWorkspace;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Log;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\System;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Victorycodedev\ToastKit\Events\ToastActionPressed;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

function serveWorkspace(): void
{
    serveApi(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if (preg_match('~/commits/([^/]+)$~', $path, $matches)) {
            return Http::response(commitPageJson(commitJson(['id' => $matches[1], 'body' => 'Conversation '.$matches[1]])));
        }

        if (str_contains($path, '/log/')) {
            return Http::response(pageJson([commitJson(['id' => 'c2', 'body' => 'Browsable feed'])]));
        }

        return Http::response(pageJson([]));
    });
}

it('selects the native Log route for the platform present at application boot', function (string $platform, string $component) {
    try {
        System::rememberPlatform($platform);
        $this->refreshApplication();

        expect(NativeRouter::resolve('/log')['class'])->toBe($component);
    } finally {
        System::rememberPlatform(null);
        $this->refreshApplication();
    }
})->with([
    'iOS retained workspace' => ['ios', FeedWorkspace::class],
    'Android feed' => ['android', Log::class],
]);

it('keeps the feed live beside conversation history and ignores obsolete native back', function () {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')->assertSee('Browsable feed');
    expect(treeShows(nodeByRef($screen, 'no-conversation'), 'no commit selected'))->toBeTrue();

    $screen->call('openConversation', 'c2', ['replies' => true], true)
        ->assertSee('Conversation c2')
        ->assertSee('Browsable feed')
        ->call('openConversation', 'c3')
        ->assertSee('Conversation c3');
    $oldBack = json_encode(['action' => 'back', 'depth' => 1, 'expected_depth' => 2, 'expected_revision' => 2, 'expected_history' => ['conversation-1', 'conversation-2']]);

    $screen->call('openConversation', 'c4', [], true)->call('hostChanged', $oldBack);

    expect(array_column($screen->get('conversations'), 'id'))->toBe(['c4']);
    $screen->assertSee('Conversation c4')->assertSee('Browsable feed')->assertDontSee('Conversation c3');

    $screen->call('hostChanged', json_encode(['action' => 'back', 'depth' => 0, 'expected_depth' => 1, 'expected_revision' => 3, 'expected_history' => ['conversation-3']]));
    expect($screen->get('conversations'))->toBe([]);
    $screen->assertSee('Browsable feed')->assertDontSee('Conversation c4');
});

it('marks the feed row whose conversation is open beside it', function () {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios');
    $background = fn (): string => strtolower(nodeByRef($screen, 'commit-c2')['style']['bg_color']);
    $surface = $background();

    $screen->tap('commit-c2');
    expect($background())->toBe(strtolower((string) theme('wash')));

    $screen->call('openConversation', 'c3');
    expect($background())->toBe(strtolower((string) theme('wash')));

    $screen->call('hostChanged', json_encode(['action' => 'back', 'depth' => 0, 'expected_depth' => 2, 'expected_revision' => 2, 'expected_history' => ['conversation-1', 'conversation-2']]));
    expect($background())->toBe($surface)->not->toBe(strtolower((string) theme('wash')));
});

it('draws the Log\'s bar from the Log itself: the wordmark, then search and compose, which call the Log', function () {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios');
    $bar = workspaceBar($screen);
    $actions = array_column(findNodes($bar, fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_action'), 'props');

    expect($bar['props'])->toMatchArray([
        'title' => 'devnull',
        'title_image' => TabsLayout::LightLogo,
        'title_image_dark' => TabsLayout::DarkLogo,
    ])
        ->and($bar['on_long_press'] ?? null)->not->toBeNull()
        // The glyphs the workspace has always drawn there.
        ->and(array_column($actions, 'icon', 'id'))->toBe(['search' => 'magnifyingglass', 'compose' => 'square.and.pencil'])
        ->and(array_column($actions, 'a11y_label', 'id'))->toBe(['search' => 'Search', 'compose' => 'New commit']);

    pressWorkspaceBar($screen, 'search')->assertNavigatedTo('/log/search');
});

it('gives each commit beside the log its own bar: its title, short hash, and Delete only on your own', function () {
    serveApi(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(match ($path) {
            '/api/v1/commits/c2' => commitPageJson(commitJson(['id' => 'c2', 'body' => 'Mine'])),
            '/api/v1/commits/c3' => commitPageJson(commitJson(['id' => 'c3', 'body' => 'Theirs', 'author' => userJson(['handle' => 'ada'])])),
            default => pageJson([]),
        });
    });
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')
        ->call('openConversation', 'c2')
        ->call('openConversation', 'c3');

    expect(workspaceBar($screen, 'conversation-1')['props'])->toMatchArray(['title' => 'Commit', 'subtitle' => commitJson(['id' => 'c2'])['short_hash']])
        ->and(workspaceBarAction($screen, 'delete', 'conversation-1')['props']['icon'])->toBe('trash')
        ->and(workspaceBar($screen, 'conversation-2')['props']['subtitle'])->toBe(commitJson(['id' => 'c3'])['short_hash'])
        ->and(workspaceBarAction($screen, 'delete', 'conversation-2'))->toBeNull();
});

it('opens the existing game from the retained feed logo when it is installed', function () {
    skipWithoutSkia();
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios');

    $screen->fireEvent('hostChanged', TestableComponent::EVENT_LONG_PRESS, ['callback_id' => workspaceBar($screen)['on_long_press']])
        ->assertNavigatedTo(Game::Route);
});

it('refreshes the retained destination after native back without losing its draft', function () {
    $body = 'Parent before opening another conversation';
    serveApi(function (Request $request) use (&$body) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(match ($path) {
            '/api/v1/commits/c2' => commitPageJson(commitJson(['id' => 'c2', 'body' => $body])),
            '/api/v1/commits/c3' => commitPageJson(commitJson(['id' => 'c3', 'body' => 'Deeper conversation'])),
            default => pageJson([]),
        });
    });
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')->call('openConversation', 'c2');
    $parent = $screen->instance()->foregroundPane();
    $screen->input('reply-input', 'Keep this parent draft')->call('openConversation', 'c3');
    $body = 'Parent refreshed after returning';

    $screen->call('hostChanged', json_encode(['action' => 'back', 'depth' => 1, 'expected_depth' => 2, 'expected_revision' => 2, 'expected_history' => ['conversation-1', 'conversation-2']]))
        ->assertSee('Parent refreshed after returning')->assertSee('Keep this parent draft');

    expect($screen->instance()->foregroundPane())->toBe($parent);
});

it('refreshes the live feed when going back from its selected conversation', function () {
    $body = 'Feed before opening a conversation';
    serveApi(function (Request $request) use (&$body) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(match ($path) {
            '/api/v1/log/following' => pageJson([commitJson(['id' => 'c2', 'body' => $body])]),
            '/api/v1/commits/c2' => commitPageJson(commitJson(['id' => 'c2', 'body' => 'Selected conversation'])),
            default => pageJson([]),
        });
    });
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')->tap('commit-c2');
    $body = 'Feed refreshed after going back';

    $screen->call('hostChanged', json_encode(['action' => 'back', 'depth' => 0, 'expected_depth' => 1, 'expected_revision' => 1, 'expected_history' => ['conversation-1']]))
        ->assertSee('Feed refreshed after going back');

    expect($screen->get('conversations'))->toBe([]);
});

it('ignores the retained logo shortcut when the game is disabled', function () {
    config(['devnull.nata_nullers' => false]);
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios');

    $screen->fireEvent('hostChanged', TestableComponent::EVENT_LONG_PRESS, ['callback_id' => workspaceBar($screen)['on_long_press']])
        ->assertNoNavigation();
});

it('applies held child refreshes and stashes the selected conversation draft', function () {
    serveWorkspace();
    holdRefreshes();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios');
    finishRefreshes($screen);

    $screen->call('openConversation', 'c2', ['replies' => true], true);
    finishRefreshes($screen)->assertSee('Conversation c2');
    $screen->input('reply-input', 'A draft for c2');

    expect(app(Stash::class)->get(userJson()['id'], 'reply-c2')->body)->toBe('A draft for c2');
    $screen->call('openConversation', 'c3', [], true);
    finishRefreshes($screen)->assertSee('Conversation c3')->assertDontSee('Conversation c2');
    $screen->call('openConversation', 'c2', [], true);
    finishRefreshes($screen)->assertSee('A draft for c2');
});

it('keeps all three feed tabs interactive without replacing the selected conversation', function () {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios');
    $pager = nodeByRef($screen, 'log-pager')['id'];
    $screen->tap('commit-c2')->input('reply-input', 'Keep this conversation draft');

    foreach ([1, 2, 0] as $page) {
        $screen->changeTab('showPage', $page)
            ->assertSee('Conversation c2')
            ->assertSee('Keep this conversation draft')
            ->assertNoNavigation();

        expect(nodeByRef($screen, 'log-pager')['id'])->toBe($pager);
        expect(nodeByRef($screen, 'log-pager')['props']['page'])->toBe($page);
        expect(array_column($screen->get('conversations'), 'id'))->toBe(['c2']);
    }
});

it('retries a child server failure and recovers an API error on resume', function () {
    holdRefreshes();
    $attempts = 0;
    serveApi(function (Request $request) use (&$attempts) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/v1/commits/c2') {
            $attempts++;

            return match ($attempts) {
                1 => Http::response(['message' => 'Temporary failure'], 503),
                2 => Http::response(['message' => 'Conversation unavailable'], 422),
                default => Http::response(commitPageJson(commitJson(['id' => 'c2', 'body' => 'Recovered conversation']))),
            };
        }

        return Http::response(pageJson([]));
    });
    $screen = finishRefreshes(Native::test(FeedWorkspace::class, platform: 'ios'));
    finishRefreshes($screen->call('openConversation', 'c2', [], true))
        ->assertSee('Conversation unavailable');

    expect($attempts)->toBe(2);
    finishRefreshes($screen->call('onResume'))->assertSee('Recovered conversation')->assertDontSee('Conversation unavailable');
    expect($attempts)->toBe(3);
});

it('drops a detached conversation completion rather than caching or displaying its result', function () {
    serveWorkspace();
    holdRefreshes();
    $screen = finishRefreshes(Native::test(FeedWorkspace::class, platform: 'ios'));
    $screen->call('openConversation', 'c2', [], true)
        ->call('openConversation', 'c3', [], true);

    finishRefreshes($screen)->assertSee('Conversation c3')->assertDontSee('Conversation c2');
    expect(kept('commits/c2'))->toBeNull();
    expect(kept('commits/c3')['data']['body'])->toBe('Conversation c3');
});

it('accepts consecutive ancestor back events but rejects a stale delete and divergent back', function () {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')
        ->call('openConversation', 'c2')
        ->call('openConversation', 'c3')
        ->call('openConversation', 'c4');
    $staleDelete = workspaceBarAction($screen, 'delete', 'conversation-3')['on_press'];
    $event = ['expected_depth' => 3, 'expected_revision' => 3, 'expected_history' => ['conversation-1', 'conversation-2', 'conversation-3']];
    $screen->call('hostChanged', json_encode([...$event, 'action' => 'back', 'depth' => 2]))
        ->call('hostChanged', json_encode([...$event, 'action' => 'back', 'depth' => 1]))
        ->fireEvent('hostChanged', TestableComponent::EVENT_PRESS, ['callback_id' => $staleDelete])
        ->assertNativeNotCalled('Dialog.Alert');
    expect(array_column($screen->get('conversations'), 'id'))->toBe(['c2']);

    $screen->call('openConversation', 'c5', [], true)
        ->call('hostChanged', json_encode([...$event, 'action' => 'back', 'depth' => 0]));
    expect(array_column($screen->get('conversations'), 'id'))->toBe(['c5']);
});

it('settles a held reply once and updates the live detail or restores its rejected draft', function (bool $accepted) {
    holdRefreshes();
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c2', 'body' => 'Reply here'])])],
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'body' => 'Reply here']))],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
        'GET /api/v1/commits/c2/related' => [pageJson([])],
        'POST /api/v1/commits' => $accepted
            ? [['data' => replyJson(['id' => 'r-real', 'parent_id' => 'c2', 'body' => 'Held reply'])], 201]
            : [['message' => 'Reply rejected'], 422],
    ]);
    $screen = finishRefreshes(Native::test(FeedWorkspace::class, platform: 'ios'))->tap('commit-c2');
    finishRefreshes($screen)->input('reply-input', 'Held reply')->tap('send-reply')->assertSee('Held reply');
    expect(PendingWrite::query()->count())->toBe(1);

    finishRefreshes($screen)->assertSee('Reply here');
    expect(PendingWrite::query()->count())->toBe(0);
    if ($accepted) {
        expect(nodeByRef($screen, 'thread-reply-r-real'))->not->toBeNull();
        expect(app(Stash::class)->get(userJson()['id'], 'reply-c2')->body)->toBe('');
    } else {
        $screen->assertSee('Held reply')->assertSee('stash@{0}');
        expect(app(Stash::class)->get(userJson()['id'], 'reply-c2')->body)->toBe('Held reply');
    }
})->with([true, false]);

it('opens Edit for the already selected conversation without replacing its native identity', function () {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')->tap('commit-c2');
    $detail = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail')['id'];
    $write = app(Outbox::class)->add(PendingWrite::Commit, null, ['body' => 'Recovered from Edit', 'parent_id' => 'c2', 'composer' => 'bar', 'stash' => 'reply-c2']);
    app(Outbox::class)->failed($write, 'Try editing');

    $screen->emitNative(ToastActionPressed::class, ['actionId' => 'edit', 'data' => ['write' => $write->id]])
        ->assertSee('Recovered from Edit')->assertSee('stash@{0}');
    expect(findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail')['id'])->toBe($detail);
    expect(array_column($screen->get('conversations'), 'id'))->toBe(['c2']);
    expect(PendingWrite::query()->count())->toBe(0);
});

it('recovers and resubmits a rejected feed composer through the selected pane', function (int $page, bool $quote) {
    holdRefreshes();
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c2', 'body' => 'Browse during submission'])])],
        'GET /api/v1/log/latest' => [pageJson([commitJson(['id' => 'c2', 'body' => 'Browse during submission'])])],
        'GET /api/v1/log/trending' => [pageJson([commitJson(['id' => 'c2', 'body' => 'Browse during submission'])])],
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'body' => 'Browse during submission']))],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
        'GET /api/v1/commits/c2/related' => [pageJson([])],
        'POST /api/v1/commits' => [['message' => 'Controlled rejection'], 422],
    ]);
    $screen = finishRefreshes(Native::test(FeedWorkspace::class, platform: 'ios'));
    finishRefreshes($screen->changeTab('showPage', $page));
    if ($quote) {
        $screen->tap('quote-c2');
    } else {
        pressWorkspaceBar($screen, 'compose');
    }
    $screen->input('sheet-body', 'Rejected feed draft')->tap('sheet-commit')
        ->call('openConversation', 'c2', [], true);

    finishRefreshes($screen, newestFirst: true);
    $detail = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail');
    expect(findNode($detail, fn (array $node): bool => ($node['ref'] ?? null) === 'sheet')['props']['visible'])->toBeTrue();
    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse();
    $screen->assertSee('Rejected feed draft');

    serveApi([
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'c-new', 'body' => 'Rejected feed draft'])], 201],
    ]);
    $screen->input('reply-input', 'Keep my unrelated inline draft');
    $commit = findNode($detail, fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-commit');
    $screen->fireEvent('hostChanged', TestableComponent::EVENT_PRESS, ['callback_id' => $commit['on_press']]);
    $staged = PendingWrite::query()->sole();
    expect(nodeByRef($screen, 'commit-'.PendingWrite::stagedId($staged->client_id)))->not->toBeNull();
    $screen->assertSee('Keep my unrelated inline draft');

    finishRefreshes($screen);
    expect(nodeByRef($screen, 'commit-c-new'))->not->toBeNull();
    expect(PendingWrite::query()->count())->toBe(0);
    $screen->assertSee('Keep my unrelated inline draft');
})->with([[0, false], [1, false], [2, false], [2, true]]);

it('invalidates an old native back when a rejected sheet reopens after dismissal', function () {
    holdRefreshes();
    serveWorkspace();
    $screen = finishRefreshes(Native::test(FeedWorkspace::class, platform: 'ios'))->tap('commit-c2');
    finishRefreshes($screen);

    // The reply bar sends a reply without the sheet, so the conversation's sheet here is a quote of its commit.
    $inDetail = fn (string $ref): array => findNode(
        findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail'),
        fn (array $node): bool => ($node['ref'] ?? null) === $ref,
    );
    $screen->fireEvent('hostChanged', TestableComponent::EVENT_PRESS, ['callback_id' => $inDetail('quote-c2')['on_press']])
        ->input('sheet-body', 'Fast rejected reply')->tap('sheet-commit');
    $old = ['action' => 'back', 'depth' => 0, 'expected_depth' => 1, 'expected_revision' => 1, 'expected_history' => ['conversation-1']];
    serveApi(['POST /api/v1/commits' => [['message' => 'Fast rejection'], 422]]);
    finishRefreshes($screen);
    $detail = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail');
    $sheet = findNode($detail, fn (array $node): bool => ($node['ref'] ?? null) === 'sheet');
    expect($sheet['props']['visible'])->toBeFalse();

    $screen->fireEvent('hostChanged', TestableComponent::EVENT_SHEET_DISMISS, ['callback_id' => $sheet['props']['on_dismiss']])
        ->call('hostChanged', json_encode($old))
        ->assertSee('Fast rejected reply')->assertNativeNotCalled('Dialog.Alert');

    expect(array_column($screen->get('conversations'), 'id'))->toBe(['c2']);
    expect($screen->get('navigationRevision'))->toBe(2);
    $detail = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail');
    expect(findNode($detail, fn (array $node): bool => ($node['ref'] ?? null) === 'sheet')['props']['visible'])->toBeTrue();
});

it('rejects old native commands after a newer same-conversation intent', function () {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')
        ->call('openConversation', 'c2')
        ->call('openConversation', 'c3');
    $detail = findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail')[1]['id'];
    $event = ['action' => 'back', 'depth' => 1, 'expected_depth' => 2, 'expected_revision' => 2, 'expected_history' => ['conversation-1', 'conversation-2']];

    $screen->call('openConversation', 'c3', ['reply' => true])->call('hostChanged', json_encode($event))
        ->assertNativeNotCalled('Dialog.Alert');

    expect(array_column($screen->get('conversations'), 'id'))->toBe(['c2', 'c3']);
    expect(findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail')[1]['id'])->toBe($detail);
    expect($screen->tree()['props']['intent_revision'])->toBe(3);

    // The commit still open under the newer intent deletes from its own bar.
    pressWorkspaceBar($screen, 'delete', 'conversation-2')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Delete this commit?');
});

it('synchronizes successful deletion from either pane without replacing the feed', function (bool $fromDetail) {
    $deleted = false;
    serveApi(function (Request $request) use (&$deleted) {
        if ($request->method() === 'DELETE') {
            $deleted = true;

            return Http::response(null, 204);
        }
        $commit = commitJson(['id' => 'c2', 'body' => 'Delete from either pane']);
        $path = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(str_ends_with($path, '/commits/c2') ? commitPageJson($commit) : pageJson(str_contains($path, '/log/') && ! $deleted ? [$commit] : []));
    });
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')->tap('commit-c2');
    $feed = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'pager')['id'];
    if ($fromDetail) {
        pressWorkspaceBar($screen, 'delete', 'conversation-1');
    } else {
        $screen->press('delete-c2');
    }
    $screen->emitNative(ButtonPressed::class, ['label' => 'Delete', 'id' => 'delete-commit'])->assertDontSee('Delete from either pane');

    expect($screen->get('conversations'))->toBe([]);
    expect(findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'pager')['id'])->toBe($feed);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/commits/c2'));
})->with([true, false]);

it('keeps held cross-pane stars and toast retry consistent before transport settles', function () {
    holdRefreshes();
    $commit = commitJson(['id' => 'c2', 'body' => 'Star in either pane', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])]);
    serveApi(function (Request $request) use ($commit) {
        if ($request->method() !== 'GET') {
            return Http::response(['message' => 'Try again'], 422);
        }
        $path = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(str_ends_with($path, '/commits/c2') ? commitPageJson($commit) : pageJson(str_contains($path, '/log/') ? [$commit] : []));
    });
    $screen = finishRefreshes(Native::test(FeedWorkspace::class, platform: 'ios'))->tap('commit-c2');
    finishRefreshes($screen);
    $starLabels = fn (): array => array_column(array_column(findNodes($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'star-c2'), 'props'), 'a11y_label');

    $screen->tap('star-c2');
    expect($starLabels())->toBe(['Unstar commit, 1 star', 'Unstar commit, 1 star']);
    $detailStar = findNodes($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'star-c2')[1];
    $screen->fireEvent('hostChanged', TestableComponent::EVENT_PRESS, ['callback_id' => $detailStar['on_press']]);
    expect($starLabels())->toBe(['Star commit', 'Star commit']);
    expect(PendingWrite::query()->orderBy('id')->pluck('kind')->all())->toBe([PendingWrite::Star, PendingWrite::Unstar]);
    finishRefreshes($screen);

    $screen->emitNative(ToastActionPressed::class, ['actionId' => 'retry-star', 'data' => ['commit' => 'c2', 'starred' => true]]);
    expect($starLabels())->toBe(['Unstar commit, 1 star', 'Unstar commit, 1 star']);
    expect(PendingWrite::query()->count())->toBe(1);
    finishRefreshes($screen);
    expect($starLabels())->toBe(['Star commit', 'Star commit']);
});

it('attaches a photo only to the pane that opened the picker', function (bool $fromDetail, bool $fromCamera) {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')->tap('commit-c2');
    $workspace = $screen->instance();
    $feed = array_find($workspace->panes(), fn (Log|CommitDetail $pane): bool => $pane instanceof Log);
    $detail = $workspace->foregroundPane();
    $feed->composeInSheet();
    $detail->replyInSheet('c2');
    $owner = $fromDetail ? $detail : $feed;
    $other = $fromDetail ? $feed : $detail;

    if ($fromCamera) {
        $owner->takePhoto();
        $screen->emitNative(PhotoTaken::class, ['path' => testPhoto()]);
    } else {
        $owner->pickPhotos();
        photosPicked($screen, [testPhoto()]);
    }

    try {
        expect($owner->sheetImages)->toHaveCount(1)
            ->and($other->sheetImages)->toBe([])
            ->and($other->sheetError)->toBeNull();
    } finally {
        app(DraftImages::class)->discard($owner->sheetImages);
    }
})->with([[false, false], [false, true], [true, false], [true, true]]);

it('ignores a detached conversation picker result rather than attaching it to another pane', function () {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')->tap('commit-c2');
    $screen->instance()->foregroundPane()->replyInSheet('c2');
    $screen->instance()->foregroundPane()->pickPhotos();

    $screen->call('openConversation', 'c3', [], true);
    $screen->instance()->foregroundPane()->replyInSheet('c3');
    photosPicked($screen, [testPhoto()]);

    foreach ($screen->instance()->panes() as $pane) {
        expect($pane->sheetImages)->toBe([]);
    }
});

it('shows a camera permission error only on the pane that requested it', function () {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')->tap('commit-c2');
    $workspace = $screen->instance();
    $feed = array_find($workspace->panes(), fn (Log|CommitDetail $pane): bool => $pane instanceof Log);
    $feed->composeInSheet();
    $workspace->foregroundPane()->replyInSheet('c2');
    $feed->takePhoto();

    $screen->emitNative(PermissionDenied::class, ['action' => 'camera']);

    expect($feed->sheetError)->toBe(DraftImages::CameraDenied)
        ->and($workspace->foregroundPane()->sheetError)->toBeNull();
});

it('hides the tab bar on iOS while one column shows a conversation over the feed', function () {
    // The workspace pushes a conversation onto its own UIKit stack inside the tab's root level, where core keeps the
    // tab bar, so the renderer has to hide it itself, as core does for a pushed level.
    $swift = file_get_contents(base_path('packages/adaptive-layout/resources/ios/AdaptiveFeedRenderer.swift'));

    expect($swift)
        ->toContain('.toolbar(chrome.hidesTabBar ? .hidden : .automatic, for: .tabBar)')
        // Only in one column, and only with something over the feed.
        ->toContain('let hides = wide == false && top != nil && top !== primary.viewControllers.first')
        // After each frame is applied, as a back swipe or chevron starts, and again when it ends or is cancelled.
        ->and(substr_count($swift, 'updateTabBar(top: primary.topViewController)'))->toBe(2)
        ->and($swift)->toContain('if navigationController === primary { updateTabBar(top: viewController) }');
});

it('slides a conversation in and out over the feed on one column, and snaps everything else', function () {
    // The workspace sets its stack from each frame. A push or a pop on one column on screen animates like one. A
    // first mount, a fold or unfold between one and two columns, or a swapped history snaps.
    $swift = file_get_contents(base_path('packages/adaptive-layout/resources/ios/AdaptiveFeedRenderer.swift'));

    expect($swift)
        ->toContain('let animatePrimary = !migrating && !wantsWide && view.window != nil && !oldPrimary.isEmpty')
        ->toContain('&& oldPrimary.count != primaryStack.count')
        ->toContain('primary.setViewControllers(primaryStack, animated: animatePrimary)')
        ->not->toContain('primary.setViewControllers(primaryStack, animated: false)');
});

it('puts the Log\'s search and compose on the detail column\'s bar beside the Duo\'s vertical bar', function () {
    // iOS moves trailing bar items into the vertical bar only from a bar that reaches its edge. In two columns that's
    // the detail column's, so search and compose go there (on the commit or the empty placeholder) and leave the feed's.
    $swift = file_get_contents(base_path('packages/adaptive-layout/resources/ios/AdaptiveFeedRenderer.swift'));

    expect($swift)
        ->toContain('guard wide == true, #available(iOS 27.1, *) else { return false }')
        ->toContain('let edge = traitCollection.verticalBarEdge')
        ->toContain('setRightItems(actionsOnDetail ? [] : feedActions(for: feedPage), on: feedPage)')
        ->toContain('setRightItems(actionsOnDetail ? feedActions(for: placeholder) : [], on: placeholder)')
        ->toContain('page.navigationItem.rightBarButtonItems = actions + (actionsOnDetail ? feedActions(for: page) : [])')
        // The detail's own buttons keep their own glass under them.
        ->toContain('for item in actions { item.sharesBackground = !actionsOnDetail }');
});

it('builds every bar from the page\'s adaptive_bar, and sends each press to the component that registered it', function () {
    // Nothing about the Log is left in the renderer: the wordmark, search, compose and delete all come from PHP, as
    // each page's adaptive_bar, and their presses go straight to the callbacks the embedded component registered.
    $swift = file_get_contents(base_path('packages/adaptive-layout/resources/ios/AdaptiveFeedRenderer.swift'));

    expect($swift)
        ->toContain('let bar = node.children.first { $0.type == "adaptive_bar" }')
        ->toContain('if callback != 0 { NativeElementBridge.sendPressEvent(callback, nodeId: node) }')
        ->toContain('NativeElementBridge.sendLongPressEvent(titleLongPress.callback, nodeId: titleLongPress.node)')
        ->toContain('item = UIBarButtonItem(title: image == nil ? label : nil, image: image ?? UIImage(systemName: "ellipsis"), primaryAction: nil, menu: Self.menu(items))')
        // A workspace with nothing to open beside its primary column keeps one.
        ->toContain('let wantsWide = latest.props.getBool("split", default: true)')
        ->not->toContain('send("compose")')
        ->not->toContain('send("search")')
        ->not->toContain('send("game")')
        ->not->toContain('send("delete")')
        ->not->toContain('"magnifyingglass"')
        ->not->toContain('"square.and.pencil"');
});
