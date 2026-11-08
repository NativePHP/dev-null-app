<?php

use App\Devnull\DraftImages;
use App\Devnull\NataNullers\Game;
use App\Devnull\Outbox;
use App\Devnull\Stash;
use App\Models\PendingWrite;
use App\NativeComponents\CommitDetail;
use App\NativeComponents\FeedWorkspace;
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

it('opens the existing game from the retained feed logo when it is installed', function () {
    skipWithoutSkia();
    serveWorkspace();

    Native::test(FeedWorkspace::class, platform: 'ios')
        ->call('hostChanged', json_encode(['action' => 'game']))
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

    Native::test(FeedWorkspace::class, platform: 'ios')
        ->call('hostChanged', json_encode(['action' => 'game']))
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
    $event = ['expected_depth' => 3, 'expected_revision' => 3, 'expected_history' => ['conversation-1', 'conversation-2', 'conversation-3']];
    $screen->call('hostChanged', json_encode([...$event, 'action' => 'back', 'depth' => 2]))
        ->call('hostChanged', json_encode([...$event, 'action' => 'back', 'depth' => 1]))
        ->call('hostChanged', json_encode([...$event, 'action' => 'delete']))
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
        $screen->call('hostChanged', json_encode(['action' => 'compose']));
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

it('invalidates old native commands when a rejected sheet reopens after dismissal', function (string $action) {
    holdRefreshes();
    serveWorkspace();
    $screen = finishRefreshes(Native::test(FeedWorkspace::class, platform: 'ios'))->tap('commit-c2');
    finishRefreshes($screen)->input('reply-input', 'Fast rejected reply')->tap('reply-expand')->tap('sheet-commit');
    $old = ['action' => $action, 'depth' => 0, 'expected_depth' => 1, 'expected_revision' => 1, 'expected_history' => ['conversation-1']];
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
})->with(['back', 'delete']);

it('rejects old native commands after a newer same-conversation intent', function (string $action) {
    serveWorkspace();
    $screen = Native::test(FeedWorkspace::class, platform: 'ios')
        ->call('openConversation', 'c2')
        ->call('openConversation', 'c3');
    $detail = findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail')[1]['id'];
    $event = ['action' => $action, 'depth' => 1, 'expected_depth' => 2, 'expected_revision' => 2, 'expected_history' => ['conversation-1', 'conversation-2']];

    $screen->call('openConversation', 'c3', ['reply' => true])->call('hostChanged', json_encode($event))
        ->assertNativeNotCalled('Dialog.Alert');

    expect(array_column($screen->get('conversations'), 'id'))->toBe(['c2', 'c3']);
    expect(findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'adaptive_detail')[1]['id'])->toBe($detail);
    expect($screen->tree()['props']['intent_revision'])->toBe(3);
    if ($action === 'delete') {
        $screen->call('hostChanged', json_encode([...$event, 'expected_revision' => 3]))
            ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Delete this commit?');
    }
})->with(['back', 'delete']);

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
        $screen->call('hostChanged', json_encode(['action' => 'delete', 'expected_depth' => 1, 'expected_revision' => 1, 'expected_history' => ['conversation-1']]));
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
