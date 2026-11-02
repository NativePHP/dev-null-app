<?php

use App\Devnull\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/draft-images'));
});

/**
 * @param  array{0: mixed, 1?: int}|null  $preview
 */
function previewComposerOnLog(?array $preview = null): TestableComponent
{
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'A commit to quote', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])])])],
        'POST /api/v1/commits/preview' => $preview ?? [['data' => [
            'body' => 'Shipped 🚀 with @ada',
            'body_segments' => [['type' => 'text', 'text' => 'Shipped 🚀 with @ada']],
            'preview_segments' => null,
            'mentions' => [['handle' => 'ada', 'offset' => 15, 'length' => 4]],
        ]]],
    ]);

    return Native::visit('/log', platform: 'ios')->press('composeInSheet');
}

it('shows the draft as the commit it would become, with the body devnull would keep, in place of the field', function () {
    holdRefreshes();

    $screen = previewComposerOnLog()->input('sheet-body', 'Shipped :rocket: with @ada')->tap('sheet-preview');

    // At once, the body as written, while devnull is asked.
    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-body'))->toBeNull()
        ->and(nodeByRef($screen, 'sheet-preview')['props']['a11y_label'])->toBe('Edit')
        ->and(treeShows(nodeByRef($screen, 'sheet-draft-preview'), 'Shipped :rocket: with @ada'))->toBeTrue();

    finishRefreshes($screen);

    $preview = nodeByRef($screen, 'sheet-draft-preview');

    expect(treeShows($preview, 'Shipped 🚀 with '))->toBeTrue()
        ->and(paragraphRuns($screen, 'Shipped 🚀'))->toBe([['Shipped 🚀 with '], ['@ada', 'mention']])
        // Commit stays where it was, and works from here.
        ->and(sheetButtonEnabled($screen, 'sheet-commit'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/'.Client::PreviewPath
        && $request->body() === '{"body":"Shipped :rocket: with @ada"}');

    // Back to the field, with the draft as it was. Shown again, devnull isn't asked twice for the same draft.
    $screen->tap('sheet-preview');
    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('Shipped :rocket: with @ada');

    $screen->tap('sheet-preview');
    expect(collect(Http::recorded())->filter(fn (array $sent): bool => str_ends_with($sent[0]->url(), Client::PreviewPath)))->toHaveCount(1)
        ->and(treeShows(nodeByRef($screen, 'sheet-draft-preview'), 'Shipped 🚀 with '))->toBeTrue();
});

it('shows the photos, the poll and the commit being quoted in the preview', function () {
    $screen = previewComposerOnLog()
        ->call('quoteCommit', 'c1')
        ->input('sheet-body', 'Which is it?')
        ->tap('sheet-poll')
        ->input('poll-option-0', 'DNS')
        ->input('poll-option-1', 'Not DNS')
        ->select('poll-length', '7 days')
        ->tap('sheet-preview');

    $preview = nodeByRef($screen, 'sheet-draft-preview');

    expect(treeShows($preview, 'DNS'))->toBeTrue()
        ->and(treeShows($preview, 'Not DNS'))->toBeTrue()
        ->and(treeShows($preview, 'Open for 7 days once published'))->toBeTrue()
        ->and(treeShows($preview, 'A commit to quote'))->toBeTrue();

    $screen = previewComposerOnLog()
        ->emitNative(MediaSelected::class, ['success' => true, 'files' => [['path' => testPhoto()]]])
        ->tap('sheet-preview');

    $image = findNode(nodeByRef($screen, 'sheet-draft-preview'), fn (array $node): bool => ($node['type'] ?? null) === 'image');

    expect($image['props']['src'])->toBe($screen->instance()->sheetImages[0]->path);
});

it('says there\'s nothing to preview yet, without asking devnull', function () {
    $screen = previewComposerOnLog()->tap('sheet-preview')->assertSee('Nothing to preview yet.');

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), Client::PreviewPath));
});

it('keeps the body as written when devnull can\'t preview it', function () {
    $screen = previewComposerOnLog([['message' => 'Server Error'], 500])
        ->input('sheet-body', 'Shipped :rocket:')
        ->tap('sheet-preview');

    expect(treeShows(nodeByRef($screen, 'sheet-draft-preview'), 'Shipped :rocket:'))->toBeTrue()
        ->and($screen->instance()->sheetError)->toBeNull();
});
