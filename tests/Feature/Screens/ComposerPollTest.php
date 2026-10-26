<?php

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

function pollComposerOnLog(): TestableComponent
{
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])])],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'p1', 'body' => 'Tabs or spaces?', 'status' => 'pending', 'poll' => [
            'id' => 'poll1', 'closes_at' => null, 'is_closed' => false, 'has_voted' => false, 'total_votes' => null,
            'options' => [['id' => 'o1', 'label' => 'Tabs', 'votes_count' => null, 'voted' => false, 'percentage' => null], ['id' => 'o2', 'label' => 'Spaces', 'votes_count' => null, 'voted' => false, 'percentage' => null]],
        ]])], 201],
    ]);

    return Native::visit('/log', platform: 'ios')->press('composeInSheet');
}

function sheetCanCommit(TestableComponent $screen): bool
{
    return sheetButtonEnabled($screen, 'sheet-commit');
}

it('adds a poll with two empty options, open for a day, and waits for both before it commits', function () {
    $screen = pollComposerOnLog()->input('sheet-body', 'Tabs or spaces?')->tap('sheet-poll');

    expect(nodeByRef($screen, 'poll-option-0')['props'])->toMatchArray(['label' => 'Option 1', 'value' => '', 'autofocus' => true, 'max_length' => 50])
        ->and(nodeByRef($screen, 'poll-option-1')['props'])->toMatchArray(['label' => 'Option 2', 'value' => ''])
        ->and(nodeByRef($screen, 'poll-length')['props'])->toMatchArray(['label' => 'Poll length', 'options' => ['1 hour', '1 day', '3 days', '7 days'], 'value' => '1 day'])
        ->and(findNode($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['poll-option-0-remove', 'poll-option-1-remove'], true)))->toBeNull()
        // The poll button is on, and a commit with a poll can't have photos.
        ->and(nodeByRef($screen, 'sheet-poll')['type'])->toBe('column')
        ->and(nodeByRef($screen, 'sheet-photos')['type'])->toBe('column')
        ->and(sheetCanCommit($screen))->toBeFalse();

    $screen->input('poll-option-0', 'Tabs');
    expect(sheetCanCommit($screen))->toBeFalse();

    $screen->input('poll-option-1', '   ');
    expect(sheetCanCommit($screen))->toBeFalse();

    $screen->input('poll-option-1', 'Spaces');
    expect(sheetCanCommit($screen))->toBeTrue();
});

it('adds options up to four, and takes off any past the first two', function () {
    $screen = pollComposerOnLog()->tap('sheet-poll')->tap('poll-add-option')->tap('poll-add-option');

    expect($screen->instance()->sheetPoll)->toBe(['', '', '', ''])
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'poll-add-option'))->toBeNull();

    $screen->input('poll-option-2', 'Both')->input('poll-option-3', 'Neither')->tap('poll-option-2-remove');

    expect($screen->instance()->sheetPoll)->toBe(['', '', 'Neither']);

    // The first two stay, whatever.
    $screen->call('removePollOption', 1);
    expect($screen->instance()->sheetPoll)->toBe(['', '', 'Neither']);
});

it('commits the poll with its options and length, as devnull takes it', function () {
    pollComposerOnLog()
        ->input('sheet-body', 'Tabs or spaces?')
        ->tap('sheet-poll')
        ->input('poll-option-0', 'Tabs')
        ->input('poll-option-1', ' Spaces ')
        ->select('poll-length', '3 days')
        ->tap('sheet-commit')
        ->assertSee('Voting opens once approved');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->body() === '{"body":"Tabs or spaces?","poll":{"options":["Tabs","Spaces"],"duration":4320}}');
});

it('keeps an option to 50 characters', function () {
    $screen = pollComposerOnLog()->tap('sheet-poll')->input('poll-option-0', str_repeat('a', 60));

    expect($screen->instance()->sheetPoll[0])->toBe(str_repeat('a', 50));
});

it('drops the poll with Remove poll, and with the sheet', function () {
    $screen = pollComposerOnLog()->tap('sheet-poll')->tap('poll-remove');

    expect($screen->instance()->sheetPoll)->toBeNull()
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'poll-editor'))->toBeNull()
        ->and(nodeByRef($screen, 'sheet-photos')['type'])->toBe('pressable');

    $screen->tap('sheet-poll')->dismissSheet('sheet')->press('composeInSheet');

    expect($screen->instance()->sheetPoll)->toBeNull();
});

it('offers no poll once there are photos', function () {
    $screen = pollComposerOnLog()->emitNative(MediaSelected::class, ['success' => true, 'files' => [['path' => testPhoto()]]]);

    expect(nodeByRef($screen, 'sheet-poll')['type'])->toBe('column');

    $screen->call('addPoll');

    expect($screen->instance()->sheetPoll)->toBeNull();
});

it('quotes with a poll, as the web lets you', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'handle' => 'ada'])])])],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'q1', 'quoted_commit_id' => 'c1'])], 201],
    ]);

    Native::visit('/log', platform: 'ios')
        ->call('quoteCommit', 'c1')
        ->input('sheet-body', 'Which is it?')
        ->tap('sheet-poll')
        ->input('poll-option-0', 'DNS')
        ->input('poll-option-1', 'Not DNS')
        ->tap('sheet-commit');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->body() === '{"body":"Which is it?","quoted_commit_id":"c1","poll":{"options":["DNS","Not DNS"],"duration":1440}}');
});
