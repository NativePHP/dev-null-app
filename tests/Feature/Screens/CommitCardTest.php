<?php

use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $this->travelTo(now()->setDateTime(2026, 10, 16, 12, 0));
    $this->freezeTime();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function votedPollJson(array $overrides = []): array
{
    return [
        'id' => 'p1',
        'closes_at' => now()->addHours(3)->addMinutes(20)->toIso8601ZuluString(),
        'is_closed' => false,
        'has_voted' => true,
        'total_votes' => 4,
        'options' => [
            ['id' => 'o1', 'label' => 'Tabs', 'votes_count' => 3, 'voted' => true, 'percentage' => 75],
            ['id' => 'o2', 'label' => 'Spaces', 'votes_count' => 1, 'voted' => false, 'percentage' => 25],
        ],
        ...$overrides,
    ];
}

/**
 * Shows a log with these commits, by someone else.
 *
 * @param  list<array<string, mixed>>  $commits
 */
function logOf(array $commits): TestableComponent
{
    Http::fake(['devnull.test/*' => Http::response(pageJson(array_map(
        fn (array $commit): array => commitJson(['author' => userJson(['id' => 'u2', 'handle' => 'ada', 'name' => 'Ada']), ...$commit]),
        $commits,
    )))]);

    return Native::visit('/log', platform: 'ios');
}

it('shortens visible star counts without shortening their accessibility label', function () {
    $screen = logOf([['id' => 'c1', 'stars_count' => 12500]])->assertSee('12.5k');
    expect(nodeByRef($screen, 'star-c1')['props']['a11y_label'])->toBe('Star commit, 12500 stars');
});

it('draws a poll you voted on like the web: bars, percentages, your vote checked, the votes and time left', function () {
    $screen = logOf([commitJson(['id' => 'c1', 'body' => 'Tabs or spaces?', 'poll' => votedPollJson()])])
        ->assertSee('Tabs')
        ->assertSee('75%')
        ->assertSee('25%')
        ->assertSee('4 votes · 3h left');

    $tabs = nodeByRef($screen, 'poll-option-o1');
    $spaces = nodeByRef($screen, 'poll-option-o2');
    $soft = config('native-ui.theme.light.primary-soft');
    $wash = config('native-ui.theme.light.wash');
    $fill = fn (array $option): array => findNode($option, fn (array $node): bool => ($node['ref'] ?? null) === str_replace('poll-option-', 'poll-bar-', $option['ref']))['children'];

    expect($tabs['props']['a11y_label'])->toBe('Tabs, your vote, 75%, 3 votes')
        ->and($spaces['props']['a11y_label'])->toBe('Spaces, 25%, 1 vote')
        ->and($fill($tabs)[0]['style']['bg_color'] ?? null)->toBe($soft)
        ->and($fill($spaces)[0]['style']['bg_color'] ?? null)->toBe($wash)
        ->and(findNode($tabs, fn (array $node): bool => ($node['ref'] ?? null) === 'poll-voted-o1'))->not->toBeNull()
        ->and(findNode($spaces, fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'poll-voted-')))->toBeNull()
        ->and(nodeByRef($screen, 'poll-c1')['props']['a11y_label'])->toBe('Poll results');

    // The bars grow by each option's share, against the space left beside them.
    $grow = fn (array $option): array => array_map(fn (array $child): mixed => $child['layout']['flex_grow'] ?? $child['layout']['flexGrow'] ?? $child['props']['flex_grow'] ?? null, $fill($option));

    expect($grow($tabs))->toEqual([75, 25])
        ->and($grow($spaces))->toEqual([25, 75]);
});

it('fills the whole option at 100%, with no spacer for EDGE to grow', function () {
    $screen = logOf([commitJson(['id' => 'c1', 'body' => 'Tabs or spaces?', 'poll' => votedPollJson([
        'total_votes' => 1,
        'options' => [
            ['id' => 'o1', 'label' => 'Tabs', 'votes_count' => 1, 'voted' => true, 'percentage' => 100],
            ['id' => 'o2', 'label' => 'Spaces', 'votes_count' => 0, 'voted' => false, 'percentage' => 0],
        ],
    ])])])->assertSee('100%');

    $bar = nodeByRef($screen, 'poll-bar-o1')['children'];

    expect($bar)->toHaveCount(1)
        ->and($bar[0]['layout']['flex_grow'] ?? $bar[0]['layout']['flexGrow'] ?? null)->toEqual(100);
});

it("draws each option's label over its bar on Android too, which lays absolute layers over the rest", function () {
    $screen = logOf([commitJson(['id' => 'c1', 'body' => 'Tabs or spaces?', 'poll' => votedPollJson()])]);
    $option = nodeByRef($screen, 'poll-option-o1');
    $refs = array_column($option['children'], 'ref');
    $layer = fn (string $ref): array => $option['children'][array_search($ref, $refs, true)];
    $isAbsolute = fn (array $node): bool => ($node['layout']['position_type'] ?? $node['layout']['positionType'] ?? 0) === 1;

    // iOS draws layers in the order they're written, but Android draws every absolute one after the flow ones, so the
    // label is an absolute layer after the bar on both. An invisible copy in the flow gives the option its height.
    expect($refs)->toBe(['poll-sizer-o1', 'poll-bar-o1', 'poll-label-o1'])
        ->and($isAbsolute($layer('poll-sizer-o1')))->toBeFalse()
        ->and($layer('poll-sizer-o1')['style']['opacity'] ?? null)->toEqual(0)
        ->and($isAbsolute($layer('poll-bar-o1')))->toBeTrue()
        ->and($isAbsolute($layer('poll-label-o1')))->toBeTrue()
        ->and(findNode($layer('poll-label-o1'), fn (array $node): bool => ($node['ref'] ?? null) === 'poll-voted-o1'))->not->toBeNull();

    // The copy only sizes the option if it draws exactly what the label does, the check included.
    $content = function (array $node) use (&$content): array {
        return array_map($content, $node['children'] ?? []) + ['type' => $node['type'], 'props' => array_diff_key($node['props'] ?? [], ['ref' => true, 'id' => true]), 'style' => $node['style'] ?? [], 'layout' => $node['layout'] ?? []];
    };

    foreach (['o1', 'o2'] as $id) {
        $option = nodeByRef($screen, "poll-option-{$id}");
        $rows = array_column($option['children'], null, 'ref');

        expect(array_map($content, $rows["poll-sizer-{$id}"]['children']))->toEqual(array_map($content, $rows["poll-label-{$id}"]['children']));
    }
});

it('gives every action under a commit a target big enough to tap, without growing the row', function () {
    $screen = logOf([['id' => 'c1', 'stars_count' => 6, 'replies_count' => 3]]);
    // A button is its own box. More's box is the column inside it, beside the menu items that ride along as children.
    $target = fn (string $ref): array => (($node = nodeByRef($screen, $ref))['props']['has_menu'] ?? false)
        ? array_values(array_filter($node['children'], fn (array $child): bool => $child['type'] !== 'top_bar_action'))[0]['layout']
        : $node['layout'];

    foreach (['star-c1', 'quote-c1', 'more-c1'] as $ref) {
        expect($target($ref))->toMatchArray(['min_width' => 44.0, 'height' => 36.0]);
    }

    // The row takes its full 36pt, with no negative margins above or below, which Android draws as an offset and keeps
    // the space of. The padding under it takes back the 6pt its buttons reach past their icons.
    expect($target('reply-to-c1')['height'])->toBe(36.0)
        ->and($target('replies-c1')['height'])->toBe(36.0)
        ->and(nodeByRef($screen, 'actions-c1')['layout']['height'])->toBe(36.0)
        ->and(array_slice(nodeByRef($screen, 'actions-c1')['layout']['margin'] ?? [0.0, 0.0, 0.0], 0, 3))->toBe([0.0, 0.0, 0.0]);
});

it('builds a row from as few views as it can, as a list builds and measures each of them while it scrolls', function () {
    $screen = logOf([['id' => 'c1', 'stars_count' => 6, 'replies_count' => 3]]);
    $row = nodeByRef($screen, 'commit-c1');
    $content = $row['children'][0];

    // One column beside the rail, its gutter a margin and the hairline under it a border, and buttons that are their
    // own rows. Wrapped the old way, this row took 47 views in eight levels.
    expect($row['layout']['flex_direction'])->toBe(1)
        ->and($content['layout']['margin'])->toBe([0.0, 0.0, 0.0, 64.0])
        ->and($content['props']['border_b'])->toBe(1.0)
        ->and(array_column(nodeByRef($screen, 'star-c1')['children'], 'type'))->toBe(['icon', 'text'])
        // Quote's label, and the end of the rail drawn in the action row, came to two more.
        ->and(count(findNodes($row, fn (): bool => true)))->toBeLessThanOrEqual(37);
});

it("draws a poll you haven't voted on as plain options, with nothing to tap, since the app can't vote yet", function () {
    $screen = logOf([commitJson(['id' => 'c1', 'poll' => votedPollJson([
        'has_voted' => false,
        'total_votes' => null,
        'options' => [
            ['id' => 'o1', 'label' => 'Tabs', 'votes_count' => null, 'voted' => false, 'percentage' => null],
            ['id' => 'o2', 'label' => 'Spaces', 'votes_count' => null, 'voted' => false, 'percentage' => null],
        ],
    ])])])
        ->assertSee('Tabs')
        ->assertSee('Spaces')
        ->assertSee('3h left')
        ->assertDontSee('%')
        ->assertDontSee('votes');

    $poll = nodeByRef($screen, 'poll-c1');

    expect($poll['props']['a11y_label'])->toBe('Poll options')
        ->and(findNode($poll, fn (array $node): bool => ($node['type'] ?? null) === 'pressable' || isset($node['on_press'])))->toBeNull()
        ->and(findNode($poll, fn (array $node): bool => in_array($node['type'] ?? null, ['button', 'toggle'], true)))->toBeNull();
});

it("says how a poll stands once it's closed, or before it opens", function (array $poll, string $line) {
    logOf([commitJson(['id' => 'c1', 'poll' => votedPollJson($poll)])])->assertSee($line);
})->with([
    'final results' => [['is_closed' => true, 'closes_at' => '2026-10-15T12:00:00Z'], '4 votes · Final results'],
    'waiting on moderation' => [['closes_at' => null, 'has_voted' => false, 'total_votes' => null], 'Voting opens once approved'],
]);

it('embeds the commit a commit quotes as the web card, which opens it', function () {
    $screen = logOf([commitJson([
        'id' => 'c1',
        'body' => 'Quoting this.',
        'quoted_commit_id' => 'q1',
        'quoted_commit' => array_diff_key(commitJson([
            'id' => 'q1',
            'body' => 'The original take',
            'author' => userJson(['id' => 'u3', 'handle' => 'grace', 'name' => 'Grace']),
            'created_at' => now()->subHours(2)->toIso8601ZuluString(),
            'poll' => votedPollJson(),
        ]), array_flip(['stars_count', 'replies_count', 'starred'])),
    ])])
        ->assertSee('Quoting this.')
        ->assertSee('The original take')
        ->assertSee('Grace')
        ->assertSee('@grace')
        ->assertSee('2h')
        // Its poll comes with it, static.
        ->assertSee('75%');

    $card = nodeByRef($screen, 'quoted-c1');

    expect($card['type'])->toBe('pressable')
        ->and($card['props']['a11y_label'])->toBe('Quoting Grace')
        // It embeds one level only, with no action row of its own.
        ->and(findNode($card, fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'actions-')))->toBeNull();

    $screen->tap('quoted-c1')->assertNavigatedTo('/log/commits/q1');

    expect($screen->navigationIntent()->data['commit']->body)->toBe('The original take');
});

it('says when a quoted commit was deleted, or when you may no longer see it', function (?array $quoted, string $token, string $sentence) {
    $screen = logOf([commitJson(['id' => 'c1', 'quoted_commit_id' => 'q1', 'quoted_commit' => $quoted])])
        ->assertSee($token)
        ->assertSee($sentence);

    expect(nodeByRef($screen, 'quoted-c1')['type'])->toBe('column');
})->with([
    'deleted' => [tombstoneJson(['id' => 'q1']), '[deleted]', 'This commit was deleted.'],
    'unavailable' => [null, '[unavailable]', "This commit isn't available."],
]);

it('shows the start of a long commit with Show more, which opens the rest in place', function () {
    $body = str_repeat('A long line about shipping. ', 20).'The very end.';
    $preview = rtrim(substr(str_repeat('A long line about shipping. ', 20), 0, 270)).'…';

    $screen = logOf([commitJson([
        'id' => 'c1',
        'body' => $body,
        'body_segments' => [['type' => 'text', 'text' => $body]],
        'preview_segments' => [['type' => 'text', 'text' => $preview]],
    ])])
        ->assertDontSee('The very end.')
        ->assertSee('Show more');

    $screen->tap('show-more-c1')
        ->assertNoNavigation()
        ->assertSee('The very end.')
        ->assertDontSee('Show more');

    expect($screen->get('unfolded'))->toBe(['c1']);
});

it('shows a long commit whole on its own page', function () {
    $body = str_repeat('word ', 80).'The very end.';

    Http::fake(['devnull.test/api/v1/commits/c1' => Http::response(commitPageJson(commitJson([
        'id' => 'c1',
        'body' => $body,
        'body_segments' => [['type' => 'text', 'text' => $body]],
        'preview_segments' => [['type' => 'text', 'text' => 'word word…']],
    ]))), 'devnull.test/*' => Http::response(pageJson([]))]);

    Native::visit('/log/commits/c1')->assertSee('The very end.')->assertDontSee('Show more');
});

it('says which guild a commit was posted in, as the web does', function () {
    $screen = logOf([['id' => 'g1', 'guild_handle' => 'rust', 'guild' => ['handle' => 'rust', 'name' => 'Rust', 'emoji' => '🦀', 'icon_url' => null]]])
        ->assertSee('posted in')
        ->assertSee('Rust')
        ->assertSee('· not shown in general logs');

    expect(rendersRef($screen, 'guild-badge-g1'))->toBeTrue()
        ->and(nodeByRef($screen, 'guild-pill-g1')['props']['a11y_label'])->toBe('Rust, #rust');
});

it('opens the guild a commit was posted in', function () {
    logOf([['id' => 'g1', 'guild_handle' => 'rust', 'guild' => ['handle' => 'rust', 'name' => 'Rust', 'emoji' => '🦀', 'icon_url' => null]]])
        ->tap('guild-pill-g1')
        ->assertNavigatedTo('/log/guilds/rust');
});

it('says nothing about guilds on a commit that was not posted in one', function () {
    $screen = logOf([['id' => 'c1']]);

    expect(rendersRef($screen, 'guild-badge-c1'))->toBeFalse();
    $screen->assertDontSee('posted in');
});

it('shows the uploaded icon of the guild a commit was posted in', function () {
    $url = 'https://dev-null.sh/api/v1/guilds/php/icon?expires='.(time() + 3600).'&signature=x';
    $screen = logOf([['id' => 'g1', 'guild_handle' => 'php', 'guild' => ['handle' => 'php', 'name' => 'PHP', 'emoji' => '💻', 'icon_url' => $url]]]);

    expect(nodeByRef($screen, 'guild-pill-mark-g1')['type'])->toBe('image')
        ->and(nodeByRef($screen, 'guild-pill-mark-g1')['props']['src'])->toBe($url);
    $screen->assertDontSee('💻');
});

it('shows the emoji of the guild a commit was posted in when it has no uploaded icon', function () {
    $screen = logOf([['id' => 'g1', 'guild_handle' => 'rust', 'guild' => ['handle' => 'rust', 'name' => 'Rust', 'emoji' => '🦀', 'icon_url' => null]]]);

    expect(nodeByRef($screen, 'guild-pill-mark-g1')['props']['text'] ?? null)->toBe('🦀');
});
