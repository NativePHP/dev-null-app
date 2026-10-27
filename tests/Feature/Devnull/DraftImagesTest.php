<?php

use App\Devnull\Account;
use App\Devnull\Data\DraftImage;
use App\Devnull\Data\StashedDraft;
use App\Devnull\DraftImages;
use App\Devnull\Exceptions\InvalidDraftImage;
use App\Devnull\Outbox;
use App\Devnull\Stash;
use App\Models\PendingWrite;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(storage_path('app/draft-images'));
});

it('moves a photo into the app\'s storage, as an image for a draft', function (string $type, string $mimeType) {
    $picked = testPhoto($type, 40, 30);

    $image = app(DraftImages::class)->keep($picked);

    expect($image)
        ->mimeType->toBe($mimeType)
        ->width->toBe(40)
        ->height->toBe(30)
        ->description->toBe('')
        ->and($image->path)->toStartWith(storage_path('app/draft-images/'))
        ->and(file_exists($image->path))->toBeTrue()
        ->and(file_exists($picked))->toBeFalse();
})->with([
    'jpeg' => ['jpg', 'image/jpeg'],
    'png' => ['png', 'image/png'],
    'webp' => ['webp', 'image/webp'],
]);

it('refuses what devnull would, in the web\'s words, and deletes it', function (Closure $picked, string $message) {
    $path = $picked();

    expect(fn () => app(DraftImages::class)->keep($path))->toThrow(InvalidDraftImage::class, $message)
        ->and(file_exists($path))->toBeFalse()
        ->and(glob(storage_path('app/draft-images/*')) ?: [])->toBe([]);
})->with([
    'a gif' => [fn (): string => testPhoto('gif'), DraftImages::Unsupported],
    'not an image' => [function (): string {
        $path = testPhoto();
        file_put_contents($path, 'not a photo');

        return $path;
    }, DraftImages::Unreadable],
    'over 10 MB' => [function (): string {
        $path = testPhoto();
        file_put_contents($path, str_repeat("\0", DraftImages::MaxBytes), FILE_APPEND);

        return $path;
    }, DraftImages::Unsupported],
    'wider than 6000 pixels' => [fn (): string => testPhoto('png', DraftImages::MaxDimension + 1, 1), DraftImages::TooLarge],
    'taller than 6000 pixels' => [fn (): string => testPhoto('png', 1, DraftImages::MaxDimension + 1), DraftImages::TooLarge],
]);

it('says a photo it can\'t find is unreadable', function () {
    expect(fn () => app(DraftImages::class)->keep('/nowhere/picked.png'))->toThrow(InvalidDraftImage::class, DraftImages::Unreadable);
});

it('deletes the photos of a draft, but never a file it didn\'t keep', function () {
    $kept = app(DraftImages::class)->keep(testPhoto());
    $elsewhere = testPhoto();

    app(DraftImages::class)->discard([$kept, new DraftImage($elsewhere, 'image/png', 40, 30)]);

    expect(file_exists($kept->path))->toBeFalse()
        ->and(file_exists($elsewhere))->toBeTrue();
});

it('deletes the photos of drafts left for more than a day', function () {
    $old = app(DraftImages::class)->keep(testPhoto());
    touch($old->path, time() - 86_401);
    $recent = app(DraftImages::class)->keep(testPhoto());
    touch($recent->path, time() - 3_600);

    app(DraftImages::class)->keep(testPhoto());

    expect(file_exists($old->path))->toBeFalse()
        ->and(file_exists($recent->path))->toBeTrue();
});

it('keeps the photos a stashed draft or the outbox holds, however old', function () {
    signIn();
    $me = app(Account::class)->user()?->id;
    $stashed = app(DraftImages::class)->keep(testPhoto());
    $failed = app(DraftImages::class)->keep(testPhoto());
    $orphan = app(DraftImages::class)->keep(testPhoto());

    app(Stash::class)->put($me, 'commit', new StashedDraft('', [$stashed]));
    $write = app(Outbox::class)->add(PendingWrite::Commit, null, ['body' => '', 'images' => PendingWrite::imagePayload([$failed])]);
    app(Outbox::class)->failed($write, 'Something went wrong.');

    foreach ([$stashed, $failed, $orphan] as $photo) {
        touch($photo->path, time() - 86_401 * 7);
    }

    app(DraftImages::class)->keep(testPhoto());

    expect(file_exists($stashed->path))->toBeTrue()
        ->and(file_exists($failed->path))->toBeTrue()
        ->and(file_exists($orphan->path))->toBeFalse();
});
