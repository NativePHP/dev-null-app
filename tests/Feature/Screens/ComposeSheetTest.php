<?php

use App\Devnull\Data\Commit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A Following log with one commit of Ada's, its new commit answered with $answer, and the sheet's composer open from the nav bar.
 *
 * @param  array{0: mixed, 1?: int}|null  $answer
 */
function composeSheetOnLog(?array $answer = null): TestableComponent
{
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'An older commit', 'author' => userJson(['id' => 'u2', 'username' => 'ada'])])])],
        'POST /api/v1/commits' => $answer ?? [['data' => commitJson(['id' => '0199e5c4-2222-7000-8000-00000000cafe', 'body' => 'Shipped from the sheet.'])], 201],
    ]);

    return Native::visit('/log', platform: 'ios')->press('composeInSheet');
}

function sheetCommitDisabled(TestableComponent $screen): bool
{
    return nodeByRef($screen, 'sheet-commit')['props']['disabled'] ?? false;
}

it('opens a composer in the sheet from the nav bar, over the log where you are in it', function () {
    fakeApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1'])])]]);

    $screen = Native::visit('/log', platform: 'ios');
    $list = nodeByRef($screen, 'log')['id'];

    $screen->press('composeInSheet')
        ->assertNoNavigation()
        ->assertSee('New commit')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'quote-card');

    expect(nodeByRef($screen, 'sheet')['props'])->toMatchArray(['visible' => true, 'detents' => 'large'])
        ->and(nodeByRef($screen, 'sheet-body')['props'])->toMatchArray([
            'placeholder' => 'What did you ship?',
            'multiline' => true,
            'max_length' => Commit::MaxLength,
            'autofocus' => true,
            'value' => '',
        ])
        // The same list, so it stays scrolled where it was, and the composer at its top stays as it was.
        ->and(nodeByRef($screen, 'log')['id'])->toBe($list)
        ->and($screen->instance()->composing)->toBeFalse();
});

it('keeps Commit disabled until the commit is long enough, and short enough', function () {
    $screen = composeSheetOnLog();

    expect(sheetCommitDisabled($screen))->toBeTrue();

    $screen->input('sheet-body', '  ab  ');
    expect(sheetCommitDisabled($screen))->toBeTrue();

    $screen->input('sheet-body', 'abc');
    expect(sheetCommitDisabled($screen))->toBeFalse();

    $screen->input('sheet-body', str_repeat('a', Commit::MaxLength - 10));
    expect(sheetCommitDisabled($screen))->toBeFalse()
        ->and(nodeByRef($screen, 'sheet-budget')['props']['text'])->toBe('10');

    $screen->input('sheet-body', str_repeat('a', Commit::MaxLength + 1));
    expect(sheetCommitDisabled($screen))->toBeTrue();

    // Pressing it anyway sends nothing.
    $screen->call('commitFromSheet');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('commits from the sheet and puts the commit at the top of the log, leaving the list where it was', function () {
    $screen = composeSheetOnLog();
    $list = nodeByRef($screen, 'log')['id'];

    $screen->input('sheet-body', '  Shipped from the sheet.  ')
        ->tap('sheet-commit')
        ->assertNoNavigation()
        ->assertNativeNotCalled('Dialog.Toast')
        ->assertSee('Shipped from the sheet.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://devnull.test/api/v1/commits'
        && $request->body() === '{"body":"Shipped from the sheet."}');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse()
        ->and(array_map(fn (Commit $commit): string => $commit->id, $screen->instance()->commits))->toBe(['0199e5c4-2222-7000-8000-00000000cafe', 'c1'])
        ->and(nodeByRef($screen, 'log')['id'])->toBe($list);

    $json = json_encode($screen->tree());
    expect(strpos($json, '"commit-0199e5c4-2222-7000-8000-00000000cafe"'))->toBeLessThan(strpos($json, '"commit-c1"'));

    // Once it's gone, the draft goes with it.
    $screen->dismissSheet('sheet');

    expect($screen->instance())
        ->sheetView->toBe('more')
        ->sheetBody->toBe('');
});

it('shows why devnull said no in the sheet, keeping the draft', function () {
    $screen = composeSheetOnLog([['message' => 'Too Many Attempts.'], 429])
        ->input('sheet-body', 'One more')
        ->tap('sheet-commit');

    expect(nodeByRef($screen, 'sheet-error')['props']['text'])->toBe('Too Many Attempts.')
        ->and(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('One more')
        ->and(sheetCommitDisabled($screen))->toBeFalse()
        ->and(nodeByRef($screen, 'sheet')['props']['visible'])->toBeTrue()
        ->and($screen->instance()->error)->toBeNull()
        ->and(array_map(fn (Commit $commit): string => $commit->id, $screen->instance()->commits))->toBe(['c1']);
});

it('forgets the draft when the sheet is swiped away', function () {
    $screen = composeSheetOnLog()->input('sheet-body', 'Half a thought');

    $screen->dismissSheet('sheet');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse()
        ->and($screen->instance()->sheetBody)->toBe('');

    $screen->press('composeInSheet');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('')
        ->and(sheetCommitDisabled($screen))->toBeTrue();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('leaves a draft in the composer at the top of the log alone', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([])],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'mine', 'body' => 'Shipped from the sheet.'])], 201],
    ]);

    $screen = Native::visit('/log', platform: 'ios')
        ->input('body', 'Half a thought')
        ->press('composeInSheet');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('');

    $screen->input('sheet-body', 'Shipped from the sheet.')
        ->tap('sheet-commit')
        ->assertSet('body', 'Half a thought');

    expect(nodeByRef($screen, 'body')['props']['value'])->toBe('Half a thought')
        ->and(nodeByRef($screen, 'commit')['props']['disabled'] ?? false)->toBeFalse();
});

it('only commits from a sheet that has a composer in it', function () {
    fakeApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'username' => 'ada'])])])]]);

    Native::visit('/log', platform: 'ios')
        ->tap('more-c1')
        ->set('sheetBody', 'Not a composer')
        ->call('commitFromSheet');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});
