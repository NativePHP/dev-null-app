<?php

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Pasteis;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    $this->bridge = signIn();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 15:30:00', 'UTC'));
});

/**
 * Someone else, the author of the commits pastéis go to.
 *
 * @return array<string, mixed>
 */
function pastelAuthor(): array
{
    return userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada Lovelace']);
}

/**
 * Pastéis mode on, with $remaining left today, as `GET /me` would have said.
 */
function pasteisOn(int $remaining = Pasteis::Daily): void
{
    app(Account::class)->rememberPasteis(new Pasteis($remaining, CarbonImmutable::parse('2026-10-18T00:00:00Z')));
}

/**
 * What devnull answers when a pastel goes.
 *
 * @param  array<string, mixed>  $commit
 * @return array<string, mixed>
 */
function pastelAnswer(array $commit, int $remaining, int $received = 1): array
{
    return ['data' => $commit, 'pasteis_remaining' => $remaining, 'pasteis_resets_at' => '2026-10-18T00:00:00Z', 'author_pasteis_received' => $received];
}

/**
 * The messages toasted so far.
 *
 * @return list<string>
 */
function pastelToasts(FakeBridge $bridge): array
{
    return array_map(fn (array $call): string => $call['params']['message'], $bridge->callsTo('ToastKit.Show'));
}

/**
 * The pastéis given so far, as the commits they went to.
 *
 * @return list<string>
 */
function pasteisSent(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $sent): bool => $sent[0]->method() === 'POST' && str_ends_with($sent[0]->url(), '/pasteis'))
        ->map(fn (array $sent): string => (string) preg_replace('#^.*/commits/([^/]+)/pasteis$#', '$1', $sent[0]->url()))
        ->values()
        ->all();
}

/**
 * The first text inside an element, such as a count beside its icon.
 */
function textInside(TestableComponent $screen, string $ref): ?string
{
    return findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['type'] ?? null) === 'text')['props']['text'] ?? null;
}

it('leaves pastéis out of the rows while the mode is off, as the web hides them', function () {
    fakeApi(['GET /api/v1/log/following' => [pageJson([
        commitJson(['id' => 'c2', 'pasteis_count' => 4, 'replies_count' => 3, 'author' => pastelAuthor()]),
        commitJson(['id' => 'c1', 'pasteis_count' => 2]),
    ])]]);

    $screen = Native::visit('/log', platform: 'ios')->assertSee('3 replies');

    expect(array_values(array_filter(childRefs($screen, 'actions-c2'))))->toBe(['star-c2', 'quote-c2', 'reply-to-c2', 'replies-c2', 'more-c2'])
        ->and(findNode($screen->tree(), fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'pastel')))->toBeNull()
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['props']['src'] ?? null) === Pasteis::Image))->toBeNull();

    $screen->call('givePastel', 'c2');

    expect(pasteisSent())->toBe([]);
});

it('puts the pastel and its count beside the star in pastéis mode', function () {
    pasteisOn(7);
    fakeApi(['GET /api/v1/log/following' => [pageJson([
        commitJson(['id' => 'c2', 'pasteis_count' => 3, 'stars_count' => 1, 'replies_count' => 1, 'author' => pastelAuthor()]),
        commitJson(['id' => 'c1', 'pasteis_count' => 0, 'author' => pastelAuthor()]),
    ])]]);

    $screen = Native::visit('/log', platform: 'ios');

    expect(array_values(array_filter(childRefs($screen, 'actions-c2'))))->toBe(['star-c2', 'pastel-c2', 'quote-c2', 'reply-to-c2', 'replies-c2', 'more-c2'])
        ->and(nodeByRef($screen, 'pastel-c2')['props']['a11y_label'])->toBe('Give a pastel, 3 pastéis, 7 left today')
        ->and(nodeByRef($screen, 'pastel-c1')['props']['a11y_label'])->toBe('Give a pastel, 0 pastéis, 7 left today')
        // The web shows the count even when it's 0.
        ->and(textInside($screen, 'pastel-c1'))->toBe('0')
        ->and(textInside($screen, 'pastel-c2'))->toBe('3')
        ->and(findNode(nodeByRef($screen, 'pastel-c2'), fn (array $node): bool => ($node['type'] ?? null) === 'image')['props']['src'])->toBe(Pasteis::Image);
});

it('fits the pastel into the row beside the full Reply and replies labels, with More holding the rest', function (bool $pasteisMode) {
    if ($pasteisMode) {
        pasteisOn();
    }

    fakeApi([
        'GET /api/v1/log/following' => [pageJson([
            commitJson(['id' => 'c2', 'replies_count' => 3, 'author' => pastelAuthor()]),
            commitJson(['id' => 'c1', 'author' => pastelAuthor()]),
        ])],
        'GET /api/v1/commits/c2/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c2'])])],
    ]);

    $screen = Native::visit('/log', platform: 'ios');
    $pastel = $pasteisMode ? ['pastel-c1'] : [];

    // Star, the pastel in pastéis mode, Quote, Reply with its word, then More: no bookmark or link in the row.
    expect(array_values(array_filter(childRefs($screen, 'actions-c1'))))->toBe(['star-c1', ...$pastel, 'quote-c1', 'reply-to-c1', 'more-c1'])
        ->and(textInside($screen, 'reply-to-c1'))->toBe('Reply')
        ->and(textInside($screen, 'replies-c2'))->toBe('3 replies')
        ->and(nodeByRef($screen, 'replies-c2')['props']['a11y_label'])->toBe('3 replies');

    $screen->tap('replies-c2');

    expect(textInside($screen, 'replies-c2'))->toBe('Hide replies');
})->with(['pastéis mode on' => true, 'pastéis mode off' => false]);

it('only counts the pastéis on your own commits, and on ones that are not published', function () {
    pasteisOn();
    fakeApi(['GET /api/v1/log/following' => [pageJson([
        commitJson(['id' => 'c2', 'pasteis_count' => 5]),
        commitJson(['id' => 'c1', 'pasteis_count' => 1, 'status' => 'pending']),
    ])]]);

    $screen = Native::visit('/log', platform: 'ios')
        ->assertMissingElement('pressable', fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'pastel-'))
        ->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'pastel-count-c2' && ($node['props']['a11y_label'] ?? null) === '5 pastéis')
        ->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'pastel-count-c1' && ($node['props']['a11y_label'] ?? null) === '1 pastel')
        ->call('givePastel', 'c2')
        ->call('givePastel', 'c1');

    expect(pasteisSent())->toBe([])
        ->and($screen->instance()->commits[0]->pasteisCount)->toBe(5);
});

it('gives a pastel at once, then takes the count devnull sends back and keeps it everywhere', function () {
    pasteisOn(3);
    holdRefreshes();
    $commit = commitJson(['id' => 'c1', 'pasteis_count' => 2, 'author' => pastelAuthor()]);
    keep('users/ada', ['data' => userWithCountsJson(['id' => 'u2', 'username' => 'ada']), 'pasteis_received' => 8]);
    keep('me', currentUserJson(['pasteis_mode' => true, 'pasteis_remaining' => 3, 'pasteis_resets_at' => '2026-10-18T00:00:00Z']));
    serveApi([
        'GET /api/v1/log/following' => [pageJson([$commit])],
        'GET /api/v1/me' => [currentUserJson(['pasteis_mode' => true, 'pasteis_remaining' => 3, 'pasteis_resets_at' => '2026-10-18T00:00:00Z'])],
        'POST /api/v1/commits/c1/pasteis' => [pastelAnswer([...$commit, 'pasteis_count' => 4], remaining: 2, received: 10)],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'ios'))->tap('pastel-c1');

    // Before devnull answers: one more on the commit, one fewer for you.
    expect($screen->instance()->commits[0]->pasteisCount)->toBe(3)
        ->and(textInside($screen, 'pastel-c1'))->toBe('3')
        ->and(app(Account::class)->pasteis()?->left())->toBe(2)
        ->and(nodeByRef($screen, 'pastel-c1')['props']['a11y_label'])->toBe('Give a pastel, 3 pastéis, 2 left today');

    finishRefreshes($screen);

    // Someone else gave it one meanwhile, so devnull's count is the truer.
    expect($screen->instance()->commits[0]->pasteisCount)->toBe(4)
        ->and(app(Account::class)->pasteis()?->left())->toBe(2)
        ->and(kept('log/following')['data'][0]['pasteis_count'])->toBe(4)
        ->and(kept('users/ada')['pasteis_received'])->toBe(10)
        ->and(kept('me')['data']['pasteis_remaining'])->toBe(2)
        ->and(pasteisSent())->toBe(['c1'])
        ->and(pastelToasts($this->bridge))->toBe([]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/'.Client::pastelPath('c1') && $request->hasHeader('X-SSH-Signature'));
});

it('gives one per tap, and keeps the count when devnull answers the first while others are on their way', function () {
    pasteisOn(5);
    holdRefreshes();
    $commit = commitJson(['id' => 'c1', 'pasteis_count' => 0, 'author' => pastelAuthor()]);
    serveApi([
        'GET /api/v1/log/following' => [pageJson([$commit])],
        'GET /api/v1/me' => [currentUserJson(['pasteis_mode' => true, 'pasteis_remaining' => 5, 'pasteis_resets_at' => '2026-10-18T00:00:00Z'])],
        'POST /api/v1/commits/c1/pasteis' => [pastelAnswer([...$commit, 'pasteis_count' => 1], remaining: 4)],
    ]);

    $screen = finishRefreshes(Native::visit('/log', platform: 'ios'))->tap('pastel-c1')->tap('pastel-c1')->tap('pastel-c1');

    expect($screen->instance()->commits[0]->pasteisCount)->toBe(3)
        ->and(app(Account::class)->pasteis()?->left())->toBe(2);

    finishRefreshes($screen);

    expect($screen->instance()->commits[0]->pasteisCount)->toBe(3)
        ->and(app(Account::class)->pasteis()?->left())->toBe(2)
        ->and(pasteisSent())->toBe(['c1', 'c1', 'c1']);
});

it('says so after the last pastel of the day goes, and gives no more until the reset', function () {
    pasteisOn(1);
    $commit = commitJson(['id' => 'c1', 'pasteis_count' => 0, 'author' => pastelAuthor()]);
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([$commit])],
        'POST /api/v1/commits/c1/pasteis' => [pastelAnswer([...$commit, 'pasteis_count' => 1], remaining: 0)],
    ]);

    $screen = Native::visit('/log', platform: 'ios')->tap('pastel-c1');

    expect(app(Account::class)->pasteis()?->left())->toBe(0)
        ->and(pastelToasts($this->bridge))->toBe(['you ran out of pastéis. wait until tomorrow.']);

    // None left: the toast again, and nothing sent.
    $screen->tap('pastel-c1');

    expect(pasteisSent())->toBe(['c1'])
        ->and($screen->instance()->commits[0]->pasteisCount)->toBe(1)
        ->and(pastelToasts($this->bridge))->toHaveCount(2);

    // At 00:00 UTC there are ten again.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 00:00:00', 'UTC'));

    expect(app(Account::class)->pasteis()?->left())->toBe(Pasteis::Daily);

    $screen->tap('pastel-c1');

    expect(pasteisSent())->toBe(['c1', 'c1']);
});

it('takes the pastel back when devnull says you ran out, and gives no more today', function () {
    pasteisOn(4);
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'pasteis_count' => 6, 'author' => pastelAuthor()])])],
        'POST /api/v1/commits/c1/pasteis' => [['message' => 'you ran out of pastéis. wait until tomorrow.', 'code' => 'pasteis_exhausted'], 422],
    ]);

    $screen = Native::visit('/log', platform: 'ios')->tap('pastel-c1');

    expect($screen->instance()->commits[0]->pasteisCount)->toBe(6)
        ->and(app(Account::class)->pasteis()?->left())->toBe(0)
        ->and(pastelToasts($this->bridge))->toBe(['you ran out of pastéis. wait until tomorrow.'])
        ->and($screen->instance()->error)->toBeNull();
});

it('takes the pastel back when it does not go, and says so as the web does', function (array $answer, string $toast) {
    pasteisOn(4);
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'pasteis_count' => 6, 'author' => pastelAuthor()])])],
        'POST /api/v1/commits/c1/pasteis' => $answer,
    ]);

    $screen = Native::visit('/log', platform: 'ios')->tap('pastel-c1');

    expect($screen->instance()->commits[0]->pasteisCount)->toBe(6)
        ->and(app(Account::class)->pasteis()?->left())->toBe(4)
        ->and(pastelToasts($this->bridge))->toBe([$toast]);
})->with([
    'a server error' => [[['message' => 'Server Error'], 500], 'Could not award a pastel. Please try again.'],
    'a commit gone' => [[['message' => 'Not Found'], 404], 'Could not award a pastel. Please try again.'],
    'too many at once' => [[['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429], 'Too Many Attempts.'],
]);

it('gives pastéis to replies in a thread and on a commit page', function () {
    pasteisOn();
    $reply = commitJson(['id' => 'r1', 'parent_id' => 'c1', 'pasteis_count' => 1, 'author' => pastelAuthor()]);
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'replies_count' => 1, 'author' => pastelAuthor(), 'reply_preview' => ['data' => [$reply], 'next_cursor' => null]])])],
        'POST /api/v1/commits/r1/pasteis' => [pastelAnswer([...$reply, 'pasteis_count' => 2], remaining: 9)],
    ]);

    $screen = Native::visit('/log', platform: 'ios')->tap('pastel-r1');

    expect(pasteisSent())->toBe(['r1'])
        ->and(textInside($screen, 'pastel-r1'))->toBe('2');
});

it("shows how many pastéis a profile was given in pastéis mode, as the web does, and nothing while it's off", function (bool $on) {
    if ($on) {
        pasteisOn();
    }

    fakeApi([
        'GET /api/v1/users/ada' => [['data' => userWithCountsJson(['id' => 'u2', 'username' => 'ada']), 'is_following' => false, 'pasteis_received' => 12]],
        'GET /api/v1/users/ada/commits' => [pageJson([])],
    ]);

    $screen = Native::visit('/log/users/ada', platform: 'ios');

    if ($on) {
        expect(nodeByRef($screen, 'pasteis-received')['props']['a11y_label'])->toBe('12 pastéis received');
        $screen->assertSee('pastéis received');
    } else {
        $screen->assertDontSee('pastéis received');
        expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'pasteis-received'))->toBeNull();
    }
})->with(['on' => true, 'off' => false]);

it('learns pastéis mode and what is left today whenever it asks devnull about you', function () {
    serveApi([
        'GET /api/v1/log/following' => [pageJson([])],
        'GET /api/v1/me' => [currentUserJson(['pasteis_mode' => true, 'pasteis_remaining' => 6, 'pasteis_resets_at' => '2026-10-18T00:00:00Z', 'pasteis_received' => 2])],
    ]);

    Native::visit('/log', platform: 'ios');

    expect(app(Account::class)->pasteis()?->left())->toBe(6);

    serveApi([
        'GET /api/v1/log/following' => [pageJson([])],
        'GET /api/v1/me' => [currentUserJson(['pasteis_mode' => false, 'pasteis_remaining' => 6])],
    ]);

    Native::visit('/log', platform: 'ios');

    expect(app(Account::class)->pasteis())->toBeNull();
});

it('forgets your pastéis when you sign out', function () {
    pasteisOn();

    app(Account::class)->forget();

    expect(app(Account::class)->pasteis())->toBeNull();
});
