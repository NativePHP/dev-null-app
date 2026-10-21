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
            'max_length' => Commit::MaxRawLength,
            'autofocus' => true,
            'value' => '',
        ])
        // The same list, so it stays scrolled where it was.
        ->and(nodeByRef($screen, 'log')['id'])->toBe($list);
});

it('keeps Commit disabled until the commit is long enough, and short enough', function () {
    $screen = composeSheetOnLog();

    expect(sheetCommitDisabled($screen))->toBeTrue();

    $screen->input('sheet-body', '  ');
    expect(sheetCommitDisabled($screen))->toBeTrue();

    $screen->input('sheet-body', 'a');
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

it('counts long prose URLs like the web without shortening the submitted destination', function () {
    $body = str_repeat('a', 3976).' https://example.com/'.str_repeat('x', 100);
    $screen = composeSheetOnLog()->input('sheet-body', $body);

    expect(sheetCommitDisabled($screen))->toBeFalse()
        ->and(nodeByRef($screen, 'sheet-budget')['props']['text'])->toBe('0');

    $screen->tap('sheet-commit');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['body'] === $body);
});

it('explains the URL length limit without applying it to inline code', function () {
    $url = 'https://example.com/'.str_repeat('x', 2030);
    $screen = composeSheetOnLog()->input('sheet-body', $url)
        ->assertSee('Each link must not be greater than 2048 characters.');
    expect(sheetCommitDisabled($screen))->toBeTrue();
    $screen->call('commitFromSheet');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

    $screen->input('sheet-body', '`'.$url.'`')->assertDontSee('Each link must not be greater than 2048 characters.');
    expect(sheetCommitDisabled($screen))->toBeFalse();
});

it('does not submit a closed composer twice and leaves a failed draft retryable', function () {
    $screen = composeSheetOnLog()->input('sheet-body', 'a')->tap('sheet-commit')->call('commitFromSheet');
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);

    $screen->press('composeInSheet')->input('sheet-body', 'a')->set('sheetPosting', true)->call('commitFromSheet');
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
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
        ->sheetView->toBe('report')
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
        ->and($screen->instance()->sheetPosting)->toBeFalse()
        ->and(array_map(fn (Commit $commit): string => $commit->id, $screen->instance()->commits))->toBe(['c1']);
});

it('keeps the draft when the sheet is swiped away, and opens on it again, marked as restored', function () {
    $screen = composeSheetOnLog()->input('sheet-body', 'Half a thought');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'sheet-stash'))->toBeNull();

    $screen->dismissSheet('sheet');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse()
        ->and($screen->instance()->sheetBody)->toBe('');

    $screen->press('composeInSheet');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('Half a thought')
        ->and(nodeByRef($screen, 'sheet-stash'))->not->toBeEmpty()
        ->and(sheetCommitDisabled($screen))->toBeFalse();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('closes the sheet from Cancel in its bar, keeping the draft as a swipe does', function () {
    $screen = composeSheetOnLog()->input('sheet-body', 'Half a thought')->tap('sheet-cancel');

    expect(nodeByRef($screen, 'sheet')['props']['visible'])->toBeFalse();

    $screen->dismissSheet('sheet')->press('composeInSheet');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('Half a thought')
        ->and(nodeByRef($screen, 'sheet-stash'))->not->toBeEmpty();
});

it('draws the bar on Liquid Glass on iOS, and as a full-screen dialog header on Android', function () {
    $commit = nodeByRef(composeSheetOnLog(), 'sheet-commit');

    expect($commit['props']['glass'] ?? null)->toBe(3)
        ->and($commit['props']['label'])->toBe('Commit');

    fakeApi(['GET /api/v1/log/following' => [pageJson([])]]);
    $android = Native::visit('/log', platform: 'android')->press('composeInSheet');

    expect(nodeByRef($android, 'sheet-commit')['props'])->toMatchArray(['label' => 'Commit', 'variant' => 'ghost'])
        ->and(nodeByRef($android, 'sheet-commit')['props'])->not->toHaveKey('glass');
});

it('only commits from a sheet that has a composer in it', function () {
    fakeApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'username' => 'ada'])])])]]);

    Native::visit('/log', platform: 'ios')
        ->tap('report-c1')
        ->set('sheetBody', 'Not a composer')
        ->call('commitFromSheet');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});
