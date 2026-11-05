<?php

use App\Devnull\Account;
use App\Devnull\Data\Commit;
use App\Devnull\Data\User;
use App\Devnull\ResponseCache;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskRunner;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    app(Account::class)->rememberTranslationSettings(['translation_language' => 'en']);
});

/** @return array<string, mixed> */
function translationJson(bool $translated = false): array
{
    return [
        'language' => 'en', 'source_language' => 'fr', 'source_name' => 'French', 'available' => true,
        'translated' => $translated ? ['body_segments' => [['type' => 'text', 'text' => 'Hello https://translated.test']], 'preview_segments' => null] : null,
    ];
}

it('only generates on request and keeps original overrides when shared cached translations arrive', function () {
    $commit = commitJson(['id' => 'c1', 'body' => 'Bonjour', 'author' => userJson(['id' => 'other']), 'translation' => translationJson()]);
    serveApi([
        'GET /api/v1/log/following' => [pageJson([$commit])],
        'GET /api/v1/commits/c1/translation' => [['translation' => translationJson(true)]],
        'POST /api/v1/commits/c1/translation' => [['translation' => translationJson(true)]],
    ]);
    $screen = Native::visit('/log', platform: 'android');
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST' && str_contains($request->url(), '/translation'));
    $shown = $screen->get('commits')[0];
    expect($screen->instance()->isTranslated($shown))->toBeTrue()
        ->and($shown->body)->toBe('Bonjour');

    $screen->call('showOriginal', 'c1')->call('onResume');
    expect($screen->instance()->isTranslated($screen->get('commits')[0]))->toBeFalse();
    $screen->call('translateCommit', 'c1');
    expect($screen->instance()->isTranslated($screen->get('commits')[0]))->toBeTrue();
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST' && str_contains($request->url(), '/translation'));
});

it('deduplicates generation and ignores its answer after the preferred language changes', function () {
    $commit = commitJson(['id' => 'c1', 'body' => 'Bonjour', 'author' => userJson(['id' => 'other']), 'translation' => translationJson()]);
    keep('log/following', pageJson([$commit]));
    serveApi([
        'GET /api/v1/log/following' => [pageJson([$commit])],
        'POST /api/v1/commits/c1/translation' => [['translation' => translationJson(true)]],
    ]);
    holdRefreshes();
    $screen = Native::visit('/log', platform: 'android')->call('translateCommit', 'c1');
    $sent = refreshesSent($screen);
    $screen->call('translateCommit', 'c1');
    expect(refreshesSent($screen))->toBe($sent)
        ->and($screen->get('translating'))->toHaveKey('c1');

    app(Account::class)->rememberTranslationSettings(['translation_language' => 'de']);
    finishRefreshes($screen);
    expect($screen->get('translating'))->toBe([])
        ->and($screen->instance()->isTranslated($screen->get('commits')[0]))->toBeFalse()
        ->and(kept('log/following')['data'][0]['translation']['translated'])->toBeNull();
});

it('never offers translation for own deleted unavailable or old-backend commits', function () {
    $screen = Native::visit('/log', platform: 'android');
    foreach ([
        commitJson(['author' => userJson(['id' => app(Account::class)->user()->id]), 'translation' => translationJson()]),
        commitJson(['deleted_at' => '2026-01-01', 'translation' => translationJson()]),
        commitJson(['translation' => [...translationJson(), 'available' => false]]),
        commitJson(),
    ] as $json) {
        expect($screen->instance()->canTranslate(Commit::fromArray($json)))->toBeFalse();
    }
});

/** Completes only the selected translation request, allowing deterministic out-of-order replies. */
function finishTranslationRequest(TestableComponent $screen, string $method): void
{
    foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
        $id = $call['params']['id'];
        $payload = AsyncTaskTransport::readPayload($id);
        $work = $payload === null ? [] : unserialize($payload);
        $args = $work['args'] ?? [];

        if (($args[2] ?? null) === $method && str_contains($args[0] ?? '', '/translation')) {
            $result = AsyncTaskRunner::normalizeResult(AsyncTaskRunner::invoke($work));
            $screen->emitNative(AsyncTaskFinished::class, ['id' => $id, 'result' => $result]);

            return;
        }
    }

    throw new RuntimeException('No pending '.$method.' translation request.');
}

it('does not let an older cache read erase a successful explicit generation', function () {
    $commit = commitJson(['id' => 'c1', 'body' => 'Bonjour', 'author' => userJson(['id' => 'other']), 'translation' => translationJson()]);
    serveApi([
        'GET /api/v1/log/following' => [pageJson([$commit])],
        'GET /api/v1/commits/c1/translation' => [['translation' => translationJson()]],
        'POST /api/v1/commits/c1/translation' => [['translation' => translationJson(true)]],
    ]);
    keep('log/following', pageJson([$commit]));
    holdRefreshes();
    $screen = Native::visit('/log', platform: 'android');
    // Resume settings refresh succeeds before the log's refresh, starting the cache read.
    serveApi([
        'GET /api/v1/me/settings' => [['data' => ['automatic_translation' => true]]],
        'GET /api/v1/commits/c1/translation' => [['translation' => translationJson()]],
        'POST /api/v1/commits/c1/translation' => [['translation' => translationJson(true)]],
    ]);
    $screen->call('onResume');
    foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
        $payload = AsyncTaskTransport::readPayload($call['params']['id']);
        $work = $payload === null ? [] : unserialize($payload);
        if (str_ends_with($work['args'][0] ?? '', '/me/settings')) {
            $result = AsyncTaskRunner::normalizeResult(AsyncTaskRunner::invoke($work));
            $screen->emitNative(AsyncTaskFinished::class, ['id' => $call['params']['id'], 'result' => $result]);
        }
    }
    foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
        $payload = AsyncTaskTransport::readPayload($call['params']['id']);
        $work = $payload === null ? [] : unserialize($payload);
        if (str_contains($work['args'][0] ?? '', '/translation')) {
            $readId = $call['params']['id'];
            $readWork = $work;
            break;
        }
    }
    $screen->call('translateCommit', 'c1');
    finishTranslationRequest($screen, 'POST');
    $screen->assertSee('Translated from French');
    $result = AsyncTaskRunner::normalizeResult(AsyncTaskRunner::invoke($readWork));
    $screen->emitNative(AsyncTaskFinished::class, ['id' => $readId, 'result' => $result]);
    $screen->assertSee('Translated from French');
    expect(kept('log/following')['data'][0]['translation']['translated'])->not->toBeNull();
});

it('renders translated links with their own indices and retains originals for excerpts', function () {
    $commit = commitJson(['id' => 'c1', 'body' => 'Bonjour https://original.test', 'author' => userJson(['id' => 'other']), 'translation' => translationJson(true)]);
    serveApi(['GET /api/v1/log/following' => [pageJson([$commit])]]);
    $screen = Native::visit('/log', platform: 'android')->assertSee('Translated from French')->assertDontSee('Bonjour');
    $screen->call('openLink', 'c1', 0);
    expect($screen->bridge()->callsTo('Browser.OpenInApp')[0]['params']['url'] ?? null)->toBe('https://translated.test')
        ->and($screen->get('commits')[0]->plainText())->toBe('Bonjour https://original.test');
    $screen->tap('show-original-c1')->assertSee('Bonjour')->assertDontSee('Translated from French');
    $screen->tap('translate-c1')->assertSee('Translated from French');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/translation'));
});

it('shows loading, retries failures, and rejects a generated answer for an edited body', function () {
    $commit = commitJson(['id' => 'c1', 'body' => 'Bonjour', 'author' => userJson(['id' => 'other']), 'translation' => translationJson()]);
    keep('log/following', pageJson([$commit]));
    serveApi(['POST /api/v1/commits/c1/translation' => [['message' => 'Translation unavailable. Try again.'], 503]]);
    holdRefreshes();
    $screen = Native::visit('/log', platform: 'android')->tap('translate-c1')->assertSee('Translating…');
    finishTranslationRequest($screen, 'POST');
    $screen->assertSee('Translate')->assertDontSee('Translating…');
    expect($screen->get('translating'))->toBe([]);

    serveApi(['POST /api/v1/commits/c1/translation' => [['translation' => translationJson(true)]]]);
    $screen->tap('translate-c1');
    $edited = [...$commit, 'body' => 'Changed body'];
    $screen->set('commits', [Commit::fromArray($edited)]);
    keep('log/following', pageJson([$edited]));
    finishTranslationRequest($screen, 'POST');
    $screen->assertDontSee('Translated from French');
    expect(kept('log/following')['data'][0]['translation']['translated'])->toBeNull();
});

it('patches nested quote and reply caches and clears dropped generation state on resume', function () {
    $nested = commitJson(['id' => 'translated', 'body' => 'Bonjour', 'author' => userJson(['id' => 'other']), 'translation' => translationJson()]);
    $outer = commitJson(['id' => 'outer', 'quoted_commit_id' => 'translated', 'quoted_commit' => $nested, 'reply_preview' => ['data' => [$nested], 'next_cursor' => null]]);
    keep('log/following', pageJson([$outer]));
    keep('commits/translated', ['data' => $nested]);
    serveApi(['POST /api/v1/commits/translated/translation' => [['translation' => translationJson(true)]]]);
    holdRefreshes();
    $screen = Native::visit('/log', platform: 'android')->call('translateCommit', 'translated');
    finishTranslationRequest($screen, 'POST');
    expect($screen->get('commits')[0]->quotedCommit->translation->content)->not->toBeNull()
        ->and($screen->get('commits')[0]->replyPreview->replies[0]->translation->content)->not->toBeNull()
        ->and(kept('log/following')['data'][0]['reply_preview']['data'][0]['translation']['translated'])->not->toBeNull()
        ->and(kept('log/following')['data'][0]['quoted_commit']['translation']['translated'])->not->toBeNull()
        ->and(kept('commits/translated')['data']['translation']['translated'])->not->toBeNull();

    $screen->set('translating', ['translated' => 'dropped'])->call('onResume')->assertSet('translating', []);
});

it('invalidates cached rows when automatic display changes and preserves explicit original overrides', function () {
    $commit = commitJson(['id' => 'c1', 'body' => 'Bonjour', 'author' => userJson(['id' => 'other']), 'translation' => translationJson(true)]);
    serveApi(['GET /api/v1/log/following' => [pageJson([$commit])]]);
    $screen = Native::visit('/log', platform: 'android')->assertSee('Translated from French');
    app(Account::class)->rememberTranslationSettings(['automatic_translation' => false]);
    $screen->call('showMore', 'c1')->assertDontSee('Translated from French')->assertSee('Bonjour');
    $screen->tap('translate-c1')->assertSee('Translated from French')->tap('show-original-c1')->assertSee('Bonjour');
    app(Account::class)->rememberTranslationSettings(['automatic_translation' => true]);
    $screen->call('showMore', 'c1')->assertDontSee('Translated from French');
});

it('preserves explicit generation when an older enclosing resource lands after a cache miss', function (string $screenType, bool $nested) {
    $commit = commitJson(['id' => 'c1', 'body' => 'Bonjour', 'author' => userJson(['id' => 'other']), 'translation' => translationJson()]);
    $outer = $nested ? commitJson(['id' => 'outer', 'quoted_commit_id' => 'c1', 'quoted_commit' => $commit, 'reply_preview' => ['data' => [$commit], 'next_cursor' => null]]) : $commit;
    $path = $screenType === 'feed' ? 'log/following' : 'commits/'.$outer['id'];
    $resource = $screenType === 'feed' ? pageJson([$outer]) : commitPageJson($outer);
    serveApi([
        'GET /api/v1/'.$path => [$resource],
        'GET /api/v1/commits/c1/translation' => [['translation' => translationJson()]],
        'POST /api/v1/commits/c1/translation' => [['translation' => translationJson(true)]],
    ]);
    holdRefreshes();
    $screen = Native::visit($screenType === 'feed' ? '/log' : '/log/commits/'.$outer['id'], platform: 'android');
    finishRefreshes($screen);
    $screen->assertDontSee('Translated from French');
    Http::assertSent(fn ($request): bool => $request->method() === 'GET' && str_contains($request->url(), '/commits/c1/translation'));

    $screen->call('retry');
    foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
        $payload = AsyncTaskTransport::readPayload($call['params']['id']);
        $work = $payload === null ? [] : unserialize($payload);
        if (parse_url($work['args'][0] ?? '', PHP_URL_PATH) === '/api/v1/'.$path) {
            $resourceId = $call['params']['id'];
            $resourceWork = $work;
        }
    }
    $screen->call('translateCommit', 'c1');
    finishTranslationRequest($screen, 'POST');
    $screen->assertSee('Translated from French');
    $result = AsyncTaskRunner::normalizeResult(AsyncTaskRunner::invoke($resourceWork));
    $screen->emitNative(AsyncTaskFinished::class, ['id' => $resourceId, 'result' => $result])->assertSee('Translated from French');
    $kept = kept($path)['data'];
    $cachedCommit = $screenType === 'feed' ? $kept[0] : ($kept['commit'] ?? $kept);
    expect(($nested ? $cachedCommit['quoted_commit'] : $cachedCommit)['translation']['translated'])->not->toBeNull();
    if ($nested) {
        expect($cachedCommit['reply_preview']['data'][0]['translation']['translated'])->not->toBeNull();
    }
})->with([
    'feed' => ['feed', false],
    'nested feed' => ['feed', true],
    'detail' => ['detail', false],
    'nested detail' => ['detail', true],
]);

it('invalidates successful-generation protection on body account or target preference changes', function () {
    $cache = app(ResponseCache::class);
    $commit = commitJson(['id' => 'c1', 'body' => 'Bonjour', 'author' => userJson(['id' => 'other']), 'translation' => translationJson()]);
    $before = microtime(true) - 1;
    $cache->rememberGeneratedTranslation('c1', 'Bonjour', translationJson(true));
    expect($cache->preserveGeneratedTranslations($commit, $before)['translation']['translated'])->not->toBeNull()
        ->and($cache->preserveGeneratedTranslations($commit, microtime(true) + 1)['translation']['translated'])->toBeNull();
    $cache->preserveGeneratedTranslations([...$commit, 'body' => 'Edited'], $before);
    expect($cache->preserveGeneratedTranslations($commit, $before)['translation']['translated'])->toBeNull();

    $cache->rememberGeneratedTranslation('c1', 'Bonjour', translationJson(true));
    app(Account::class)->rememberTranslationSettings(['translation_language' => 'de']);
    app(Account::class)->rememberTranslationSettings(['translation_language' => 'en']);
    expect($cache->preserveGeneratedTranslations($commit, $before)['translation']['translated'])->toBeNull();

    $cache->rememberGeneratedTranslation('c1', 'Bonjour', translationJson(true));
    $originalUser = app(Account::class)->user();
    app(Account::class)->remember(User::fromArray(userJson(['id' => 'different'])));
    app(Account::class)->remember($originalUser);
    expect($cache->preserveGeneratedTranslations($commit, $before)['translation']['translated'])->toBeNull();
});

it('renders only truncated translated code until the collapsed card is expanded', function () {
    $translation = [...translationJson(true), 'translated' => [
        'body_segments' => [['type' => 'block', 'language' => 'php', 'html' => '<pre><code>first line'."\n".'full-only last line</code></pre>']],
        'preview_segments' => [['type' => 'block', 'language' => 'php', 'html' => '<pre><code>first line'."\n".'…</code></pre>']],
    ]];
    $commit = commitJson(['id' => 'c1', 'body' => 'Bonjour', 'author' => userJson(['id' => 'other']), 'translation' => $translation]);
    serveApi(['GET /api/v1/log/following' => [pageJson([$commit])]]);
    Native::visit('/log', platform: 'android')->assertSee('first line')->assertDontSee('full-only last line')
        ->tap('show-more-c1')->assertSee('full-only last line');
});

it('keeps translated quoted excerpts compact with independent controls and original static composer text', function (string $screenType) {
    $quoted = commitJson(['id' => 'c1', 'body' => 'Bonjour original', 'author' => userJson(['id' => 'other']), 'translation' => translationJson(true)]);
    $outer = commitJson(['id' => 'outer', 'quoted_commit_id' => 'c1', 'quoted_commit' => $quoted]);
    serveApi(['GET /api/v1/commits/outer' => [commitPageJson($outer)], 'GET /api/v1/log/following' => [pageJson([$outer])]]);
    $screen = Native::visit($screenType === 'feed' ? '/log' : '/log/commits/outer', platform: 'android')->assertSee('Translated from French');
    expect(nodeByRef($screen, 'quoted-body-c1')['props']['max_lines'])->toBe(6);
    $screen->tap('show-original-c1')->assertSee('Bonjour original')->assertDontSee('Translated from French');
    expect($screen->navigationIntent())->toBeNull();
    $screen->tap('translate-c1')->assertSee('Translated from French');
    expect($screen->navigationIntent())->toBeNull()
        ->and(nodeByRef($screen, 'quoted-body-c1')['props']['max_lines'])->toBe(6);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/translation'));

    $screen->call('quoteCommit', 'c1');
    $static = nodeByRef($screen, 'quote-card');
    expect(treeShows($static, 'Bonjour original'))->toBeTrue()
        ->and(treeShows($static, 'Translated from French'))->toBeFalse()
        ->and(findNode($static, fn (array $node): bool => ($node['ref'] ?? null) === 'show-original-c1'))->toBeNull();
    $screen->dismissSheet('sheet')->tap('quoted-outer')->assertNavigatedTo('/log/commits/c1');
    expect($screen->navigationIntent()->data['commit']->body)->toBe('Bonjour original');
})->with(['feed', 'detail']);
