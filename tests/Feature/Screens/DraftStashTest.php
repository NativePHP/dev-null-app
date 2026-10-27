<?php

use App\Devnull\Account;
use App\Devnull\Data\Pasteis;
use App\Devnull\Data\StashedDraft;
use App\Devnull\Display;
use App\Devnull\Stash;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Ada, someone else whose commits you can quote and reply to.
 *
 * @return array<string, mixed>
 */
function stashAda(): array
{
    return userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']);
}

/**
 * A Following log with two commits of Ada's, c1 and c2, that answers a new commit with one of yours.
 */
function serveStashLog(): void
{
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([
            commitJson(['id' => 'c2', 'body' => 'Another commit of Ada\'s', 'author' => stashAda()]),
            commitJson(['id' => 'c1', 'body' => 'Required reading.', 'author' => stashAda()]),
        ])],
        'POST /api/v1/commits' => [['data' => commitJson(['id' => 'mine', 'body' => 'Sent.'])], 201],
        'GET /api/v1/commits/c2' => [commitPageJson(commitJson(['id' => 'c2', 'body' => 'Another commit of Ada\'s', 'author' => stashAda()]))],
        'GET /api/v1/commits/c2/replies' => [pageJson([])],
    ]);
}

function stashed(string $name): string
{
    return app(Stash::class)->get(app(Account::class)->user()?->id, $name)->body;
}

function stash(string $name, string $body): void
{
    app(Stash::class)->put(app(Account::class)->user()?->id, $name, new StashedDraft($body));
}

function showsRef(TestableComponent $screen, string $ref): bool
{
    return findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === $ref) !== null;
}

/**
 * The text of the label a restored draft shows, and the a11y label of its drop.
 *
 * @return array{0: ?string, 1: ?string}
 */
function stashLabel(TestableComponent $screen, string $ref): array
{
    $label = findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['type'] ?? null) === 'text');
    $drop = nodeByRef($screen, "{$ref}-drop");

    return [$label['text'] ?? $label['props']['text'] ?? null, $drop['props']['a11y_label'] ?? $drop['a11y_label'] ?? null];
}

// ── The Log's composer ─────────────────────────

it('keeps your drafts to you', function () {
    serveStashLog();
    app(Stash::class)->put('u2', 'commit', new StashedDraft("Ada's draft"));

    $screen = Native::visit('/log', platform: 'ios')->press('composeInSheet');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('')
        ->and(showsRef($screen, 'sheet-stash'))->toBeFalse();
});

// ── The compose sheet ──────────────────────────

it("stashes the compose sheet's draft, and forgets it once it's committed", function () {
    serveStashLog();

    $screen = Native::visit('/log', platform: 'ios')
        ->press('composeInSheet')
        ->input('sheet-body', 'Written in the sheet');

    expect(stashed('commit'))->toBe('Written in the sheet');

    $screen->dismissSheet('sheet')->press('composeInSheet');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('Written in the sheet')
        ->and(stashLabel($screen, 'sheet-stash'))->toBe(['stash@{0}', 'Discard the restored draft']);

    $screen->tap('sheet-commit')->dismissSheet('sheet');

    expect(stashed('commit'))->toBe('');

    $screen->press('composeInSheet');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('')
        ->and(showsRef($screen, 'sheet-stash'))->toBeFalse();
});

it("drops the sheet's restored draft", function () {
    serveStashLog();
    stash('commit', 'Half a thought');

    $screen = Native::visit('/log', platform: 'ios')
        ->press('composeInSheet')
        ->tap('sheet-stash-drop')
        ->assertSet('sheetBody', '');

    expect(stashed('commit'))->toBe('')
        ->and(showsRef($screen, 'sheet-stash'))->toBeFalse();
});

// ── Quotes ─────────────────────────────────────

it('stashes a quote per commit quoted, and marks Quote on that commit', function () {
    serveStashLog();

    $screen = Native::visit('/log', platform: 'ios')
        ->tap('quote-c1')
        ->input('sheet-body', 'This, all of it.')
        ->dismissSheet('sheet');

    expect(stashed('quote-c1'))->toBe('This, all of it.')
        ->and(stashed('commit'))->toBe('')
        ->and(showsRef($screen, 'quote-stash-c1'))->toBeTrue()
        ->and(nodeByRef($screen, 'quote-c1')['props']['a11y_label'])->toBe('Quote, you have an unsent quote here')
        ->and(showsRef($screen, 'quote-stash-c2'))->toBeFalse()
        ->and(nodeByRef($screen, 'quote-c2')['props']['a11y_label'])->toBe('Quote');

    // Another commit's quote starts empty.
    $screen->tap('quote-c2');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('')
        ->and(showsRef($screen, 'sheet-stash'))->toBeFalse();

    $screen->dismissSheet('sheet')->tap('quote-c1');

    expect(nodeByRef($screen, 'sheet-body')['props']['value'])->toBe('This, all of it.')
        ->and(stashLabel($screen, 'sheet-stash'))->toBe(['stash@{0}', 'Discard the restored draft']);
});

it("forgets a quote's stash once it's sent", function () {
    serveStashLog();
    stash('quote-c1', 'This, all of it.');

    $screen = Native::visit('/log', platform: 'ios')
        ->tap('quote-c1')
        ->tap('sheet-commit')
        ->dismissSheet('sheet');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->body() === '{"body":"This, all of it.","quoted_commit_id":"c1"}');

    expect(stashed('quote-c1'))->toBe('')
        ->and(showsRef($screen, 'quote-stash-c1'))->toBeFalse();
});

it('keeps a quote that devnull said no to', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'author' => stashAda()])])],
        'POST /api/v1/commits' => [['message' => 'Too Many Attempts.'], 429],
    ]);

    Native::visit('/log', platform: 'ios')
        ->tap('quote-c1')
        ->input('sheet-body', 'This, all of it.')
        ->tap('sheet-commit');

    expect(stashed('quote-c1'))->toBe('This, all of it.');
});

it('marks Quote on a profile with a stashed quote', function () {
    fakeApi([
        'GET /api/v1/users/ada' => [['data' => userWithCountsJson(['id' => 'u2', 'handle' => 'ada']), 'is_following' => false, 'activity' => ['scale' => 'week', 'buckets' => []], 'streaks' => ['longest' => 0, 'current' => 0], 'pinned_commit' => null]],
        'GET /api/v1/users/ada/commits' => [pageJson([commitJson(['id' => 'c1', 'author' => stashAda()])])],
    ]);
    stash('quote-c1', 'This, all of it.');

    expect(showsRef(Native::visit('/log/users/ada', platform: 'ios'), 'quote-stash-c1'))->toBeTrue();
});

// ── Replies ────────────────────────────────────

it('stashes a reply per commit, and brings it back in the reply bar marked as restored', function () {
    serveStashLog();

    $screen = Native::visit('/log/commits/c2')->input('reply-input', 'A reply in progress');
    $clearance = nodeByRef($screen, 'reply-bar-clearance')['layout']['height'];

    expect(stashed('reply-c2'))->toBe('A reply in progress')
        ->and(showsRef($screen, 'reply-stash'))->toBeFalse();

    $screen = Native::visit('/log/commits/c2');

    expect(nodeByRef($screen, 'reply-input')['props']['value'])->toBe('A reply in progress')
        ->and(stashLabel($screen, 'reply-stash'))->toBe(['stash@{0}', 'Discard the restored draft'])
        ->and(nodeByRef($screen, 'send-reply')['props']['disabled'] ?? false)->toBeFalse()
        // The page ends above the row that marks it, too.
        ->and(nodeByRef($screen, 'reply-bar-clearance')['layout']['height'])->toEqual($clearance + Display::ReplyBarStashLabelHeight);
});

it("forgets a reply's stash once it's sent", function () {
    serveStashLog();
    stash('reply-c2', 'Sent.');

    $screen = Native::visit('/log/commits/c2')
        ->tap('send-reply')
        ->assertSet('reply', '');

    expect(stashed('reply-c2'))->toBe('')
        ->and(showsRef($screen, 'reply-stash'))->toBeFalse();
});

it('drops a restored reply', function () {
    serveStashLog();
    stash('reply-c2', 'A reply in progress');

    $screen = Native::visit('/log/commits/c2')
        ->tap('reply-stash-drop')
        ->assertSet('reply', '');

    expect(stashed('reply-c2'))->toBe('')
        ->and(nodeByRef($screen, 'reply-input')['props']['value'])->toBe('')
        ->and(showsRef($screen, 'reply-stash'))->toBeFalse();
});

it('marks Reply on a commit with a stashed reply', function () {
    serveStashLog();
    stash('reply-c2', 'A reply in progress');

    $screen = Native::visit('/log', platform: 'ios');

    expect(showsRef($screen, 'reply-stash-c2'))->toBeTrue()
        ->and(nodeByRef($screen, 'reply-to-c2')['props']['a11y_label'])->toBe('Reply, you have an unsent reply here')
        ->and(showsRef($screen, 'reply-stash-c1'))->toBeFalse()
        ->and(nodeByRef($screen, 'reply-to-c1')['props']['a11y_label'])->toBe('Reply');
});

it('marks Quote and Reply on a starred commit with a stashed draft', function () {
    fakeApi(['GET /api/v1/starred' => [pageJson([commitJson(['id' => 'c1', 'starred' => true, 'author' => stashAda()])], path: 'http://devnull.test/api/v1/starred')]]);
    stash('quote-c1', 'This, all of it.');
    stash('reply-c1', 'A reply in progress');

    $screen = finishRefreshes(Native::visit('/profile/starred', platform: 'ios'));

    expect(showsRef($screen, 'quote-stash-c1'))->toBeTrue()
        ->and(showsRef($screen, 'reply-stash-c1'))->toBeTrue();
});

it('keeps the dots inside Quote and Reply in pastéis mode, beside the pastel, the Reply label and the replies link', function () {
    app(Account::class)->rememberPasteis(new Pasteis(Pasteis::Daily, CarbonImmutable::parse('2026-10-18T00:00:00Z')));
    fakeApi(['GET /api/v1/log/following' => [pageJson([
        commitJson(['id' => 'c2', 'replies_count' => 3, 'author' => stashAda()]),
        commitJson(['id' => 'c1', 'author' => stashAda()]),
    ])]]);
    stash('quote-c2', 'This, all of it.');
    stash('reply-c2', 'A reply in progress');
    stash('reply-c1', 'Another reply in progress');

    $screen = Native::visit('/log', platform: 'ios');

    // The dots sit inside the buttons, so the row has the same actions as without them.
    expect(array_values(array_filter(childRefs($screen, 'actions-c2'))))->toBe(['star-c2', 'pastel-c2', 'quote-c2', 'reply-to-c2', 'replies-c2', 'more-c2'])
        ->and(array_values(array_filter(childRefs($screen, 'actions-c1'))))->toBe(['star-c1', 'pastel-c1', 'quote-c1', 'reply-to-c1', 'more-c1'])
        ->and(showsRef($screen, 'quote-stash-c2'))->toBeTrue()
        ->and(showsRef($screen, 'reply-stash-c2'))->toBeTrue()
        ->and(showsRef($screen, 'reply-stash-c1'))->toBeTrue()
        ->and(findNode(nodeByRef($screen, 'reply-to-c1'), fn (array $node): bool => ($node['type'] ?? null) === 'text')['props']['text'] ?? null)->toBe('Reply');
});

// ── Signing out ────────────────────────────────

it('forgets every draft when you sign out or delete your account', function (string $button, string $alert) {
    serveApi(['GET /api/v1/me' => [currentUserJson()], 'DELETE /api/v1/me' => [null, 204]]);
    stash('commit', 'Half a thought');
    stash('quote-c1', 'This, all of it.');
    stash('reply-c2', 'A reply in progress');
    $me = app(Account::class)->user()?->id;

    Native::visit('/profile/device')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => $button, 'id' => $alert])
        ->assertReplacedWith('/onboarding');

    expect(app(Stash::class)->names($me))->toBe([]);
})->with([
    'sign out' => ['Sign out', 'sign-out'],
    'delete account' => ['Delete', 'delete-account'],
]);
