<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * What devnull's mention search answers: Ada and Adam, then the Adaptive organization.
 *
 * @return array{0: array<string, mixed>, 1?: int}
 */
function mentionsFound(): array
{
    return [[
        'data' => [
            userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 2]),
            userJson(['id' => 'u5', 'handle' => 'adam', 'name' => 'Adam']),
        ],
        'organizations' => [
            ['handle' => 'adaptive', 'name' => 'Adaptive', 'avatar_url' => null],
        ],
    ]];
}

/**
 * The query of every mention search sent, in order.
 *
 * @return list<array<string, string>>
 */
function mentionSearches(): array
{
    return Http::recorded(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/v1/search/mentions')
        ->map(function (array $sent): array {
            parse_str((string) parse_url($sent[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        })
        ->values()
        ->all();
}

/**
 * The commit page for c2, a reply to Ada's c1, with devnull's mention search answering $mentions.
 *
 * @param  array{0: mixed, 1?: int}|null  $mentions
 */
function mentionsCommitPage(?array $mentions = null): TestableComponent
{
    fakeApi([
        'GET /api/v1/commits/c2' => [commitPageJson(
            commitJson(['id' => 'c2', 'parent_id' => 'c1', 'body' => 'The focused commit']),
            [commitJson(['id' => 'c1', 'body' => 'The parent commit', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace'])])],
        )],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
        'GET /api/v1/commits/c2/related' => [['data' => []]],
        'GET /api/v1/search/mentions' => $mentions ?? mentionsFound(),
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'r9', 'parent_id' => 'c2'])], 201],
    ]);

    return Native::visit('/log/commits/c2', platform: 'ios');
}

/**
 * The Log, with Ada's c1 in it and devnull's mention search answering, and the composer sheet open: for a new commit,
 * or for a quote of c1.
 */
function mentionsComposer(bool $quoting = false): TestableComponent
{
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'body' => 'Required reading.', 'author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada Lovelace'])])])],
        'GET /api/v1/search/mentions' => mentionsFound(),
    ]);

    $screen = Native::visit('/log', platform: 'ios');

    return $quoting ? $screen->tap('quote-c1') : $screen->press('composeInSheet');
}

it("suggests devnull's people for a reply as you type @ in the reply bar, then organizations, in devnull's order", function () {
    $screen = mentionsCommitPage()
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'mention-suggestions')
        ->input('reply-input', 'thanks @ad', 10);

    expect(mentionSearches())->toBe([['q' => 'ad', 'reply' => 'c2']])
        ->and(childRefs($screen, 'mention-suggestions'))->toBe(['mention-ada', 'mention-adam', 'mention-adaptive'])
        ->and(nodeByRef($screen, 'mention-suggestions')['props']['a11y_label'])->toBe('Mention a developer');

    $screen->moveCaret('reply-input', 3, text: 'thanks @ad')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'mention-suggestions');
});

it('puts the person picked in the reply where the @ was, with the caret after them', function () {
    $screen = mentionsCommitPage()
        ->input('reply-input', 'cc @adfor review', 6)
        ->tap('mention-ada')
        ->assertSet('reply', 'cc @ada for review')
        ->assertSet('mention', null)
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'mention-suggestions');

    // The keyboard stays up through the tap on the suggestion, so typing goes on after the caret.
    expect(nodeByRef($screen, 'reply-input')['props'])->toMatchArray(['value' => 'cc @ada for review', 'selection_start' => 8, 'selection_end' => 8, 'keep_focus_on_submit' => true]);
});

it("puts an organization picked in the reply the same way, and heads a bare @ with nothing, as the web's reply does", function () {
    $screen = mentionsCommitPage()->input('reply-input', '@', 1);

    $screen->assertMissingElement('text', fn (array $node): bool => ($node['ref'] ?? null) === 'mention-heading')
        ->tap('mention-adaptive')
        ->assertSet('reply', '@adaptive ');

    expect(mentionSearches())->toBe([['reply' => 'c2']]);
});

it('asks devnull again only when what follows the @ changes', function () {
    mentionsCommitPage()
        ->input('reply-input', 'hi @a', 5)
        ->moveCaret('reply-input', 5, text: 'hi @a')
        ->input('reply-input', 'hi @ad', 6);

    expect(array_column(mentionSearches(), 'q'))->toBe(['a', 'ad']);
});

it("shows nothing, and says nothing, when devnull can't suggest anyone", function () {
    mentionsCommitPage([['message' => 'Too many requests.'], 429])
        ->input('reply-input', 'hi @ad', 6)
        ->assertSet('mentionPeople', [])
        ->assertSet('error', null)
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'mention-suggestions');
});

it('closes the suggestions once the reply is sent', function () {
    mentionsCommitPage()
        ->input('reply-input', 'Thanks for shipping this @ad', 28)
        ->tap('send-reply')
        ->assertSet('reply', '')
        ->assertSet('mention', null)
        ->assertSet('mentionCarets', []);
});

it("suggests the people you follow under the web's heading for a bare @ in a new commit, and puts the one picked in it", function () {
    $screen = mentionsComposer()->input('sheet-body', 'Shipped with @', 14);

    expect(mentionSearches())->toBe([[]])
        ->and(nodeByRef($screen, 'mention-heading')['text'] ?? nodeByRef($screen, 'mention-heading')['props']['text'] ?? null)->toBe('People you follow')
        ->and(childRefs($screen, 'mention-suggestions'))->toBe(['mention-heading', 'mention-ada', 'mention-adam', 'mention-adaptive']);

    $screen->tap('mention-adam')->assertSet('sheetBody', 'Shipped with @adam ');

    expect(nodeByRef($screen, 'sheet-body')['props'])->toMatchArray(['selection_start' => 19, 'selection_end' => 19, 'keep_focus_on_submit' => true]);
});

it("asks for a quote's people in the quote composer", function () {
    mentionsComposer(quoting: true)->input('sheet-body', 'see @a', 6);

    expect(mentionSearches())->toBe([['q' => 'a', 'quote' => 'c1']]);
});

it("asks for a reply's people when the reply opens in the sheet, and puts the one picked in it", function () {
    $screen = mentionsCommitPage()
        ->call('replyInSheet', 'c2')
        ->input('sheet-body', 'see @a', 6)
        ->assertMissingElement('text', fn (array $node): bool => ($node['ref'] ?? null) === 'mention-heading')
        ->assertMissingElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'reply-mention-slot' && ($node['children'] ?? []) !== [])
        ->tap('mention-ada')
        ->assertSet('sheetBody', 'see @ada ');

    expect(mentionSearches())->toBe([['q' => 'a', 'reply' => 'c2']])
        ->and(nodeByRef($screen, 'sheet-body')['props']['selection_start'])->toBe(9);
});

it('forgets the suggestions when the sheet closes', function () {
    mentionsComposer()
        ->input('sheet-body', 'hi @a', 5)
        ->press('hideCommitSheet')
        ->assertSet('mention', null)
        ->assertSet('mentionPeople', []);
});
