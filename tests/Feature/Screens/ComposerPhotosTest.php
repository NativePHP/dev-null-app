<?php

use App\Devnull\Account;
use App\Devnull\Data\DraftImage;
use App\Devnull\DraftImages;
use App\Devnull\Stash;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoTaken;
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
 * The Log's composer open in the sheet, whose commit devnull answers with $answer.
 *
 * @param  array{0: mixed, 1?: int}|null  $answer
 */
function composerOnLog(?array $answer = null): TestableComponent
{
    serveApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'An older commit', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])])],
        'POST /api/v1/commits' => $answer ?? [['data' => commitJson(['id' => 'n1', 'body' => 'Look at this', 'status' => 'pending', 'images' => [['id' => 'i1', 'url' => 'http://devnull.test/commit-images/i1?expires=1&signature=abc', 'width' => 40, 'height' => 30, 'description' => null]]])], 201],
    ]);

    return Native::visit('/log', platform: 'ios')->press('composeInSheet');
}

function isTool(TestableComponent $screen, string $ref): bool
{
    return nodeByRef($screen, $ref)['type'] === 'pressable';
}

it('pins the toolbar under the scroll view, so it stays just above the keyboard', function () {
    $screen = composerOnLog();
    $toolbar = nodeByRef($screen, 'sheet-toolbar');

    expect(findNode(nodeByRef($screen, 'sheet-composer'), fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-toolbar'))->toBeNull()
        ->and(findNode($toolbar, fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-tools'))->not->toBeNull()
        ->and(findNode($toolbar, fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-preview'))->not->toBeNull();
});

it('opens the photo library for up to three photos, then for what\'s left', function () {
    $screen = composerOnLog()->tap('sheet-photos');

    $screen->assertNativeCalled('Camera.PickMedia', fn (array $params): bool => $params['mediaType'] === 'images' && $params['multiple'] === true && $params['maxItems'] === 3);

    photosPicked($screen, [testPhoto()])->tap('sheet-photos');

    $screen->assertNativeCalled('Camera.PickMedia', fn (array $params): bool => $params['maxItems'] === 2)
        ->tap('sheet-camera')
        ->assertNativeCalled('Camera.GetPhoto');
});

it('attaches the photos the library hands back and the one the camera takes, as the web shows them', function () {
    $screen = photosPicked(composerOnLog(), [testPhoto('jpg', 40, 30), testPhoto('webp', 20, 20)])
        ->emitNative(PhotoTaken::class, ['path' => testPhoto('jpg', 30, 40), 'mimeType' => 'image/jpeg']);

    $images = $screen->instance()->sheetImages;

    expect(array_map(fn (DraftImage $image): array => [$image->mimeType, $image->width, $image->height], $images))->toBe([
        ['image/jpeg', 40, 30],
        ['image/webp', 20, 20],
        ['image/jpeg', 30, 40],
    ])
        ->and(findNode(nodeByRef($screen, 'sheet-image-0'), fn (array $node): bool => ($node['type'] ?? null) === 'image')['props']['src'])->toBe($images[0]->path)
        ->and(nodeByRef($screen, 'sheet-image-1-alt')['props']['a11y_label'])->toBe('Add description to photo 2')
        ->and(nodeByRef($screen, 'sheet-image-2-remove')['props']['a11y_label'])->toBe('Remove photo 3')
        // Three is all a commit takes, so both photo tools grey out, and a poll can't come with photos.
        ->and(isTool($screen, 'sheet-photos'))->toBeFalse()
        ->and(isTool($screen, 'sheet-camera'))->toBeFalse()
        ->and(isTool($screen, 'sheet-poll'))->toBeFalse()
        ->and(nodeByRef($screen, 'sheet-poll')['props']['a11y_label'])->toBe('A commit can have images or a poll, not both');
});

it('says why a photo won\'t do, in the web\'s words', function (Closure $photos, string $message, int $kept) {
    $screen = photosPicked(composerOnLog(), $photos());

    expect(nodeByRef($screen, 'sheet-error')['props']['text'])->toBe($message)
        ->and($screen->instance()->sheetImages)->toHaveCount($kept);
})->with([
    'a gif' => [fn (): array => [testPhoto('gif')], DraftImages::Unsupported, 0],
    'too many pixels' => [fn (): array => [testPhoto('png', 6001, 1)], DraftImages::TooLarge, 0],
    'more than three' => [fn (): array => [testPhoto(), testPhoto(), testPhoto(), testPhoto()], DraftImages::TooMany, 3],
]);

it('asks for the camera in Settings when it\'s not allowed', function () {
    $screen = composerOnLog()->emitNative(PermissionDenied::class, ['action' => 'camera']);

    expect(nodeByRef($screen, 'sheet-error')['props']['text'])->toBe(DraftImages::CameraDenied);
});

it('describes a photo for people who can\'t see it, and takes one off the draft and the phone', function () {
    $screen = photosPicked(composerOnLog(), [testPhoto(), testPhoto()])->tap('sheet-image-0-alt');

    expect(nodeByRef($screen, 'sheet-image-description-input')['props'])->toMatchArray(['autofocus' => true, 'max_length' => 1000]);

    $screen->input('sheet-image-description-input', 'The cabin at sunrise.');

    expect($screen->instance()->sheetImages[0]->description)->toBe('The cabin at sunrise.')
        ->and(nodeByRef($screen, 'sheet-image-0-alt')['props']['a11y_label'])->toBe('Edit description of photo 1')
        ->and(treeShows($screen->tree(), '21/1000'))->toBeTrue();

    $screen->tap('sheet-image-description-done');
    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-image-description'))->toBeNull();

    $first = $screen->instance()->sheetImages[0]->path;
    $screen->tap('sheet-image-0-remove');

    expect(file_exists($first))->toBeFalse()
        ->and($screen->instance()->sheetImages)->toHaveCount(1)
        ->and($screen->instance()->sheetImages[0]->description)->toBe('');
});

it('commits photos with a short body, or none, as multipart, then shows the commit and lets the photos go', function (string $body, string $sent) {
    $screen = photosPicked(composerOnLog(), [testPhoto('jpg'), testPhoto('png')]);
    $paths = array_map(fn (DraftImage $image): string => $image->path, $screen->instance()->sheetImages);
    $contents = array_map(file_get_contents(...), $paths);

    $screen->tap('sheet-image-1-alt')->input('sheet-image-description-input', 'A terminal.');

    if ($body !== '') {
        $screen->input('sheet-body', $body);
    }

    expect(sheetButtonEnabled($screen, 'sheet-commit'))->toBeTrue();

    $screen->tap('sheet-commit')->dismissSheet('sheet');

    Http::assertSent(function (Request $request) use ($contents, $sent): bool {
        if ($request->method() !== 'POST' || $request->url() !== 'http://devnull.test/api/v1/commits') {
            return false;
        }

        expect(multipartParts($request))->toBe([
            ['name' => 'payload', 'type' => 'application/json', 'contents' => $sent],
            ['name' => 'images[]', 'type' => 'image/jpeg', 'contents' => $contents[0]],
            ['name' => 'images[]', 'type' => 'image/png', 'contents' => $contents[1]],
        ])
            ->and($request->hasHeader('X-SSH-Signature'))->toBeTrue();

        return true;
    });

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse()
        ->and($screen->instance()->commits[0]->id)->toBe('n1')
        ->and($screen->instance()->commits[0]->images[0]->url)->toBe('http://devnull.test/commit-images/i1?expires=1&signature=abc')
        ->and($screen->instance()->sheetImages)->toBe([])
        ->and(array_filter($paths, file_exists(...)))->toBe([])
        ->and(app(Stash::class)->get(app(Account::class)->user()?->id, 'commit')->body)->toBe('');
})->with([
    'none' => ['', '{"body":"","image_descriptions":[null,"A terminal."]}'],
    'short' => ['ok', '{"body":"ok","image_descriptions":[null,"A terminal."]}'],
]);

it('shows the commit staged with its photos while they upload, and opens the sheet on them again if devnull turns them down', function () {
    holdRefreshes();

    $screen = photosPicked(composerOnLog([['message' => 'The images.0 field must be an image.', 'errors' => ['images.0' => ['The images.0 field must be an image.']]], 422]), [testPhoto()])
        ->input('sheet-body', 'Look at this');
    $path = $screen->instance()->sheetImages[0]->path;
    $sent = refreshesSent($screen);

    $screen->tap('sheet-commit')->dismissSheet('sheet');

    // The sheet goes at once, and the commit shows staged with its photo, which stays on the phone while it uploads.
    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse()
        ->and($screen->instance()->commits[0]->isStaged())->toBeTrue()
        ->and($screen->instance()->commits[0]->images[0]->url)->toBe($path)
        ->and(file_exists($path))->toBeTrue()
        ->and(refreshesSent($screen))->toBe($sent + 1);

    // Pressed again once it's gone, nothing more goes.
    $screen->call('commitFromSheet');
    expect(refreshesSent($screen))->toBe($sent + 1);

    finishRefreshes($screen)
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'The images.0 field must be an image.' && $params['variant'] === 'error');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeTrue()
        ->and(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('Look at this')
        ->and(sheetButtonEnabled($screen, 'sheet-commit'))->toBeTrue()
        ->and($screen->instance()->sheetImages)->toHaveCount(1)
        ->and($screen->instance()->sheetImages[0]->path)->toBe($path)
        ->and(file_exists($path))->toBeTrue()
        ->and(array_filter($screen->instance()->commits, fn ($commit): bool => $commit->isStaged()))->toBe([]);
});

it('keeps the photos with the draft when the sheet goes unsent, and deletes them once it\'s dropped', function () {
    $screen = photosPicked(composerOnLog(), [testPhoto()])
        ->tap('sheet-image-0-alt')
        ->input('sheet-image-description-input', 'The cabin at sunrise.');
    $path = $screen->instance()->sheetImages[0]->path;

    $screen->dismissSheet('sheet');

    expect(file_exists($path))->toBeTrue()
        ->and($screen->instance()->sheetImages)->toBe([]);

    // A photo that arrives once the sheet has gone attaches to nothing.
    photosPicked($screen, [testPhoto()]);

    expect($screen->instance()->sheetImages)->toBe([]);

    $screen->press('composeInSheet');

    expect($screen->instance()->sheetImages)->toHaveCount(1)
        ->and($screen->instance()->sheetImages[0]->path)->toBe($path)
        ->and($screen->instance()->sheetImages[0]->description)->toBe('The cabin at sunrise.')
        ->and(nodeByRef($screen, 'sheet-image-0')['type'])->toBe('column')
        ->and(sheetButtonEnabled($screen, 'sheet-commit'))->toBeTrue()
        ->and(nodeByRef($screen, 'sheet-stash'))->not->toBeNull();

    $screen->tap('sheet-stash-drop');

    expect(file_exists($path))->toBeFalse()
        ->and($screen->instance()->sheetImages)->toBe([])
        ->and(app(Stash::class)->names(app(Account::class)->user()?->id))->toBe([]);
});

/**
 * The commit page for c2, by Ada, which a reply can be sent to.
 */
function replyPage(string $platform = 'ios'): TestableComponent
{
    serveApi([
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'body' => 'The focused commit', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada'])]))],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
        'POST /api/v1/commits' => [['data' => replyJson(['id' => 'r9', 'parent_id' => 'c2', 'body' => 'Look'])], 201],
    ]);

    return Native::visit('/log/commits/c2', platform: $platform);
}

it('adds photos to a reply from the bar, from the library or the camera, without the sheet', function () {
    $screen = replyPage()->input('reply-input', 'Look')->tap('reply-pick-photos');

    $screen->assertNativeCalled('Camera.PickMedia', fn (array $params): bool => $params['mediaType'] === 'images' && $params['multiple'] === true && $params['maxItems'] === 3);

    photosPicked($screen, [testPhoto()])->tap('reply-take-photo')->assertNativeCalled('Camera.GetPhoto');
    $screen->emitNative(PhotoTaken::class, ['path' => testPhoto('jpg', 30, 40), 'mimeType' => 'image/jpeg']);

    // They show above the field, which keeps its place, and go in the reply's stash with its text.
    expect($screen->instance()->replyImages)->toHaveCount(2)
        ->and(childRefs($screen, 'reply-rows'))->toBe(['reply-mention-slot', 'reply-photos-slot', 'reply-row'])
        ->and(nodeByRef($screen, 'reply-photo-0'))->not->toBeNull()
        ->and(nodeByRef($screen, 'reply-photo-1'))->not->toBeNull()
        ->and(nodeByRef($screen, 'sheet')['props']['visible'] ?? false)->toBeFalse()
        ->and(app(Stash::class)->get(app(Account::class)->user()?->id, 'reply-c2')->images)->toHaveCount(2);

    // A third fills it, and the button can't add more.
    photosPicked($screen, [testPhoto()]);

    expect(menuItems($screen, 'reply-add-photos'))->toBe([])
        ->and(nodeByRef($screen, 'reply-add-photos')['a11y_label'] ?? nodeByRef($screen, 'reply-add-photos')['props']['a11y_label'] ?? null)->toBe('A reply can have up to 3 photos');
});

it('takes a photo off the reply in the bar with its ✕, and off the phone', function () {
    $screen = photosPicked(replyPage(), [testPhoto(), testPhoto()]);
    $removed = $screen->instance()->replyImages[0]->path;

    $screen->tap('reply-photo-0-remove');

    expect($screen->instance()->replyImages)->toHaveCount(1)
        ->and(file_exists($removed))->toBeFalse()
        ->and(app(Stash::class)->get(app(Account::class)->user()?->id, 'reply-c2')->images)->toHaveCount(1);
});

it('keeps a reply\'s photos for when you come back to the commit, and replies with them', function () {
    photosPicked(replyPage()->input('reply-input', 'Look'), [testPhoto()]);

    $screen = Native::visit('/log/commits/c2');
    $path = $screen->instance()->replyImages[0]->path;

    expect($screen->instance()->reply)->toBe('Look')
        ->and(nodeByRef($screen, 'reply-photo-0'))->not->toBeNull()
        ->and(nodeByRef($screen, 'reply-stash'))->not->toBeNull();

    $screen->tap('send-reply')->assertSee('Look');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && multipartParts($request)[0]['contents'] === '{"body":"Look","parent_id":"c2"}'
        && multipartParts($request)[1]['name'] === 'images[]');

    expect($screen->instance()->replyImages)->toBe([])
        ->and(rendersRef($screen, 'reply-photo-0'))->toBeFalse()
        ->and(file_exists($path))->toBeFalse()
        ->and(app(Stash::class)->names(app(Account::class)->user()?->id))->toBe([]);
});

it('previews a reply with its photos in the bar', function () {
    $screen = photosPicked(replyPage()->input('reply-input', 'Look'), [testPhoto()])->tap('reply-preview');

    // The photos move into the preview, as the reply will show them, and their row above the field goes.
    expect(treeShows(nodeByRef($screen, 'reply-draft-preview'), 'Look'))->toBeTrue()
        ->and(findNode(nodeByRef($screen, 'reply-draft-preview'), fn (array $node): bool => ($node['type'] ?? null) === 'image'))->not->toBeNull()
        ->and(rendersRef($screen, 'reply-photo-0'))->toBeFalse();
});

it('drops a reply\'s photos with it, off the phone', function () {
    $screen = photosPicked(replyPage(), [testPhoto()]);
    $path = $screen->instance()->replyImages[0]->path;

    $screen->tap('reply-stash-drop');

    expect($screen->instance()->replyImages)->toBe([])
        ->and(file_exists($path))->toBeFalse()
        ->and(app(Stash::class)->names(app(Account::class)->user()?->id))->toBe([]);
});

it('hands a reply opened in the sheet back to the reply bar, photos too, when the sheet goes unsent', function (string $platform) {
    $screen = replyPage($platform)->input('reply-input', 'Half')->call('replyInSheet', 'c2');

    photosPicked($screen, [testPhoto()])->input('sheet-body', 'Half a reply');

    $platform === 'ios' ? $screen->dismissSheet('sheet') : $screen->tap('sheet-cancel');

    expect(nodeByRef($screen, 'reply-input')['props']['value'])->toBe('Half a reply')
        ->and($screen->instance()->replyImages)->toHaveCount(1)
        ->and(app(Stash::class)->get(app(Account::class)->user()?->id, 'reply-c2')->images)->toHaveCount(1);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with(['ios', 'android']);
