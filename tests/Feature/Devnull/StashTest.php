<?php

use App\Devnull\Account;
use App\Devnull\Data\StashedDraft;
use App\Devnull\DraftImages;
use App\Devnull\Stash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(storage_path('app/draft-images'));
});

it('keeps each draft by its name, per user', function () {
    $stash = app(Stash::class);

    $stash->put('u1', 'commit', new StashedDraft('Half a thought'));
    $stash->put('u1', 'reply-c2', new StashedDraft('A reply in progress'));
    $stash->put('u2', 'commit', new StashedDraft('Someone else on this phone'));

    expect($stash->get('u1', 'commit')->body)->toBe('Half a thought')
        ->and($stash->get('u1', 'reply-c2')->body)->toBe('A reply in progress')
        ->and($stash->get('u1', 'quote-c2')->isEmpty())->toBeTrue()
        ->and($stash->get('u2', 'commit')->body)->toBe('Someone else on this phone')
        ->and($stash->names('u1'))->toBe(['commit', 'reply-c2']);
});

it('keeps a draft\'s photos with their descriptions, and its poll with how long it runs', function () {
    $stash = app(Stash::class);
    $photo = app(DraftImages::class)->keep(testPhoto())->withDescription('A terminal.');

    $stash->put('u1', 'reply-c2', new StashedDraft('', [$photo]));
    $stash->put('u1', 'quote-c1', new StashedDraft('Which is it?', poll: ['DNS', ''], pollLength: '3 days'));

    expect($stash->get('u1', 'reply-c2')->images)->toEqual([$photo])
        ->and($stash->get('u1', 'quote-c1'))->toEqual(new StashedDraft('Which is it?', poll: ['DNS', ''], pollLength: '3 days'))
        ->and($stash->images('u1'))->toEqual([$photo]);

    // A photo gone from the phone is gone from the draft, and a draft with nothing left in it goes too.
    unlink($photo->path);

    expect($stash->names('u1'))->toBe(['quote-c1']);
});

it('forgets a draft once there\'s nothing in it', function (StashedDraft $draft) {
    $stash = app(Stash::class);

    $stash->put('u1', 'commit', new StashedDraft('Half a thought'));
    $stash->put('u1', 'commit', $draft);

    expect($stash->get('u1', 'commit')->isEmpty())->toBeTrue()
        ->and($stash->names('u1'))->toBe([]);
})->with([
    'emptied' => [new StashedDraft],
    'with a poll nothing is written in' => [new StashedDraft(poll: ['', ' '])],
]);

it('keeps nothing when nobody is signed in', function () {
    $stash = app(Stash::class);

    $stash->put(null, 'commit', new StashedDraft('Half a thought'));

    expect($stash->get(null, 'commit')->isEmpty())->toBeTrue()
        ->and($stash->names(null))->toBe([]);
});

it('reads a draft kept before photos and polls were', function () {
    Cache::forever('devnull.stash.u1', ['commit' => 'Half a thought']);

    expect(app(Stash::class)->get('u1', 'commit'))->toEqual(new StashedDraft('Half a thought'));
});

it('lets the draft written longest ago go once there are too many, and deletes its photos', function () {
    $stash = app(Stash::class);
    $photo = app(DraftImages::class)->keep(testPhoto());

    foreach (range(1, Stash::MaxDrafts) as $number) {
        $stash->put('u1', "reply-c{$number}", new StashedDraft("Reply {$number}", $number === 2 ? [$photo] : []));
    }

    // Writing a draft again makes it the newest.
    $stash->put('u1', 'reply-c1', new StashedDraft('Reply 1, again'));
    $stash->put('u1', 'commit', new StashedDraft('One too many'));

    expect($stash->names('u1'))->toHaveCount(Stash::MaxDrafts)
        ->and($stash->get('u1', 'reply-c1')->body)->toBe('Reply 1, again')
        ->and($stash->get('u1', 'reply-c2')->isEmpty())->toBeTrue()
        ->and(file_exists($photo->path))->toBeFalse()
        ->and($stash->get('u1', 'commit')->body)->toBe('One too many');
});

it("forgets the signed-in user's drafts when the phone signs out, and deletes their photos", function () {
    signIn();
    $me = app(Account::class)->user()?->id;
    $stash = app(Stash::class);
    $photo = app(DraftImages::class)->keep(testPhoto());

    $stash->put($me, 'commit', new StashedDraft('Half a thought', [$photo]));
    $stash->put($me, 'quote-c1', new StashedDraft('This, all of it.'));
    $stash->put('u2', 'commit', new StashedDraft('Someone else on this phone'));

    app(Account::class)->forget();

    expect($stash->names($me))->toBe([])
        ->and(file_exists($photo->path))->toBeFalse()
        ->and($stash->get('u2', 'commit')->body)->toBe('Someone else on this phone');
});
