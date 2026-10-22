<?php

use App\Devnull\Account;
use App\Devnull\CommitRows;
use App\Devnull\Data\Pasteis;
use App\Devnull\Stash;
use App\NativeComponents\ThisDevice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * A Log that holds most of what a feed row can show: pastéis mode, unsent drafts, a deleted and a live parent, a
 * folded body, an open thread, your own commit with a big star count, runs of the same author, and a body with
 * mentions, inline code, links and the wordmark.
 */
function variedLog(): void
{
    app(Account::class)->rememberPasteis(new Pasteis(7, CarbonImmutable::parse('2026-10-18T00:00:00Z')));
    $me = app(Account::class)->user();
    app(Stash::class)->put($me->id, 'quote-v2', 'unsent quote');
    app(Stash::class)->put($me->id, 'reply-v3', 'unsent reply');

    $ada = userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada Lovelace', 'lane' => 2]);
    $bob = userJson(['id' => 'u3', 'username' => 'bob', 'name' => 'Bob', 'lane' => 4, 'avatar_url' => null]);
    $long = str_repeat('A body long enough to fold in a list, as devnull cuts it. ', 12);
    $rich = '@ada ran `composer test` see github.com/laravel/framework/pull/42 and https://example.com/foo then /dev/null';

    Http::fake(['devnull.test/api/v1/log/following' => Http::response(pageJson([
        commitJson(['id' => 'v1', 'body' => 'My own commit', 'stars_count' => 1234, 'starred' => true, 'pasteis_count' => 3, 'author' => userJson()]),
        commitJson(['id' => 'v2', 'body' => 'Quote me', 'author' => $ada, 'pasteis_count' => 12, 'replies_count' => 3]),
        commitJson(['id' => 'v3', 'body' => 'Reply to me', 'author' => $ada, 'parent_id' => 'gone', 'parent' => commitJson(['id' => 'gone', 'author' => $bob, 'deleted_at' => now()->toIso8601ZuluString()]), 'stars_count' => 2]),
        commitJson(['id' => 'v4', 'body' => $long, 'preview_segments' => [['type' => 'text', 'text' => substr($long, 0, 200).'…']], 'author' => $bob, 'parent_id' => 'v1', 'parent' => commitJson(['id' => 'v1', 'author' => $ada])]),
        commitJson(['id' => 'v5', 'body' => 'With a thread under it', 'author' => $bob, 'replies_count' => 4, 'reply_preview' => ['data' => [replyJson(['id' => 'r1', 'parent_id' => 'v5', 'body' => 'A reply', 'author' => $ada])], 'next_cursor' => 'more']]),
        commitJson(['id' => 'v6', 'body' => $rich, 'author' => $ada, 'body_segments' => [
            ['type' => 'text', 'text' => '@ada ran '],
            ['type' => 'code', 'code' => 'composer test'],
            ['type' => 'text', 'text' => ' see github.com/laravel/framework/pull/42 and https://example.com/foo then /dev/null'],
        ], 'mentions' => [['username' => 'ada', 'offset' => 0, 'length' => 4]]]),
    ]))]);
}

/**
 * Renders a screen with the native commit row off and on.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function bothRows(Closure $visit): array
{
    config(['devnull.native_commit_row' => false]);
    $blade = $visit()->tree();
    config(['devnull.native_commit_row' => true]);
    $native = $visit()->tree();

    return [$blade, $native];
}

/**
 * A run's style with its parents' folded in, as the text renderers resolve it.
 *
 * @param  array<string, mixed>  $run
 * @param  array<string, mixed>  $inherited
 * @return list<list<string>>
 */
function edgeRuns(array $run, array $inherited = []): array
{
    $style = [
        'font' => $run['props']['font_name'] ?? $inherited['font'] ?? '',
        'weight' => $run['props']['font_weight'] ?? $inherited['weight'] ?? '',
        'size' => $run['props']['font_size'] ?? $inherited['size'] ?? '',
        'color' => $run['props']['color'] ?? $inherited['color'] ?? '',
        'dark' => $run['props']['dark_color'] ?? $inherited['dark'] ?? '',
        'press' => $run['on_press'] ?? $inherited['press'] ?? '',
        'tracking' => $run['props']['letter_spacing'] ?? $inherited['tracking'] ?? '',
    ];
    $runs = isset($run['props']['text'])
        ? [[$run['props']['text'], ...array_values($style), $run['style']['bg_color'] ?? '', $run['props']['dark_bg_color'] ?? '']]
        : [];

    foreach ($run['children'] ?? [] as $child) {
        array_push($runs, ...edgeRuns($child, $style));
    }

    return array_map(fn (array $values): array => array_map(fn (mixed $value): string => is_float($value) || is_int($value) ? (string) (float) $value : (string) $value, $values), $runs);
}

/**
 * What a Blade commit row draws, read off its EDGE nodes.
 *
 * @param  array<string, mixed>  $row
 * @return array<string, mixed>
 */
function bladeRowValues(array $row): array
{
    $id = substr((string) $row['ref'], strlen('commit-'));
    [$content] = $row['children'];
    $kids = $content['children'];
    [$name, $handle, $separator, $time] = $kids[0]['children'];
    $text = fn (array $node): array => [$node['props']['text'], $node['props']['font_size'], $node['props']['font_name'] ?? '', [$node['props']['color'], $node['props']['dark_color']]];
    $byRef = fn (array $node, string $ref): ?array => findNode($node, fn (array $n): bool => ($n['ref'] ?? null) === $ref);
    $actions = $byRef($content, "actions-{$id}");

    $replyingTo = $byRef($content, "replying-to-{$id}");
    $paragraphs = array_values(array_filter($kids, fn (array $kid): bool => $kid['type'] === 'text' && isset($kid['children'])));
    $star = $byRef($actions, "star-{$id}");
    $pastel = $byRef($actions, "pastel-{$id}") ?? $byRef($actions, "pastel-count-{$id}");
    $quote = $byRef($actions, "quote-{$id}");
    $reply = $byRef($actions, "reply-to-{$id}");
    $replies = $byRef($actions, "replies-{$id}");
    $more = $byRef($actions, "more-{$id}");

    $rail = array_slice($row['children'], 1);
    $piece = fn (callable $matches): ?array => array_values(array_filter($rail, $matches))[0] ?? null;
    $previous = $piece(fn (array $n): bool => $n['type'] === 'column' && isset($n['layout']['height']) && ($n['layout']['position'][0] ?? null) === -0.0);
    $tail = $piece(fn (array $n): bool => $n['type'] === 'column' && ! isset($n['layout']['height']) && ! isset($n['props']['border_style']));
    $dashed = $piece(fn (array $n): bool => isset($n['props']['border_style']));
    $endDot = $piece(fn (array $n): bool => ($n['ref'] ?? null) === 'end-dot');
    $node = $piece(fn (array $n): bool => $n['type'] === 'pressable');
    $ring = $node['children'][0];
    $isDot = $ring['layout']['width'] === 18.0;
    $disc = $isDot ? null : $ring['children'][0];

    return [
        'press' => $row['on_press'],
        'surface' => [$row['style']['bg_color'], $row['props']['dark_bg_color']],
        'content' => [$content['layout']['min_height'], $content['layout']['padding'][0], $content['layout']['padding'][1], $content['layout']['padding'][2], $content['layout']['margin'][3], $content['layout']['gap']],
        'hairline' => isset($content['style']['border_width']) ? [$content['style']['border_color'], $content['props']['dark_border_color']] : null,
        'header' => [$text($name), $text($handle), [$separator['props']['text'], $separator['props']['font_size'], [$separator['props']['color'], $separator['props']['dark_color']]], $text($time)],
        'replyingTo' => $replyingTo === null ? null : [$replyingTo['children'][1]['props']['text'], $replyingTo['on_press'] ?? 0, $replyingTo['props']['a11y_label'] ?? ''],
        'paragraphs' => array_map(fn (array $paragraph): array => [$paragraph['props']['font_size'], $paragraph['props']['line_height'], [$paragraph['props']['color'], $paragraph['props']['dark_color']], edgeRuns(['children' => $paragraph['children']])], $paragraphs),
        'showMore' => $byRef($content, "show-more-{$id}")['on_press'] ?? 0,
        'star' => $star === null ? null : [$star['on_press'], $star['props']['a11y_label'], $star['children'][1]['props']['text'] ?? '', [$star['children'][0]['props']['color'], $star['children'][0]['props']['dark_color']], $star['children'][0]['props']['size']],
        'pastel' => $pastel === null ? null : [$pastel['type'] === 'pressable' ? 'give' : 'count', $pastel['on_press'] ?? 0, $pastel['children'][1]['props']['text'], $pastel['props']['a11y_label'], $pastel['children'][0]['layout']['width']],
        'quote' => $quote === null ? null : [$quote['on_press'], $quote['props']['a11y_label'], $byRef($quote, "quote-stash-{$id}") !== null],
        'reply' => [$reply['on_press'], $reply['props']['a11y_label'], $reply['children'][1]['props']['text'] ?? '', $byRef($reply, "reply-stash-{$id}") !== null, ($reply['layout']['justify_content'] ?? 0) === 1],
        'replies' => $replies === null ? null : [$replies['on_press'], $replies['children'][1]['props']['text'], $replies['props']['a11y_label'], $replies['children'][0]['props']['size']],
        'menu' => array_map(fn (array $item): array => [$item['props']['id'], $item['props']['label'] ?? '', $item['on_press'] ?? 0, ($item['props']['divider'] ?? false) ? 'divider' : (($item['props']['destructive'] ?? false) ? 'destructive' : '')], array_values(array_filter($more['children'], fn (array $n): bool => $n['type'] === 'top_bar_action'))),
        'rail' => [
            'previous' => $previous === null ? null : [$previous['style']['bg_color'], $previous['props']['dark_bg_color'], $previous['layout']['height']],
            'tail' => $tail === null ? null : [$tail['style']['bg_color'], $tail['layout']['position'][0], abs($tail['layout']['position'][2])],
            'dashed' => $dashed === null ? null : [$dashed['style']['border_color'], $dashed['layout']['height'] ?? 0.0, isset($dashed['layout']['height']) ? null : $dashed['layout']['position'][0]],
            'endDot' => $endDot === null ? null : [$endDot['style']['bg_color'], $endDot['layout']['position'][2], $endDot['layout']['position'][3]],
            'node' => [$isDot ? 'dot' : 'avatar', $node['layout']['position'][0], $node['layout']['position'][3], $node['on_press'], $node['props']['a11y_label']],
            'avatar' => $disc === null ? null : [$ring['layout']['width'], $ring['style']['border_width'], $ring['style']['border_color'], $disc['layout']['width'], $disc['children'][0]['props']['text'], $disc['children'][0]['props']['font_size'], $disc['children'][1]['props']['src'] ?? null],
        ],
    ];
}

/**
 * The same values, read off the native element's props.
 *
 * @param  array<string, mixed>  $row
 * @return array<string, mixed>
 */
function nativeRowValues(array $row): array
{
    $p = $row['props'];
    $pair = fn (string $key): array => $p[$key];
    $runs = [];
    $index = 0;

    foreach ($p['paragraph_runs'] as $count) {
        $paragraph = [];

        foreach (range(1, (int) $count) as $ignored) {
            $value = fn (string $key): string => $p[$key][$index];
            $number = fn (string $key): string => $value($key) === '' ? '' : (string) (float) $value($key);
            $paragraph[] = [$value('run_text'), $value('run_font'), $number('run_weight'), $number('run_size'), $value('run_color'), $value('run_dark_color'), $number('run_press'), $number('run_tracking'), $value('run_bg'), $value('run_dark_bg')];
            $index++;
        }

        $runs[] = [$p['body_size'], $p['body_line_height'], $pair('body_color'), $paragraph];
    }

    return [
        'press' => $row['on_press'],
        'surface' => $pair('surface_color'),
        'content' => [$p['content_min_height'], $p['content_padding_top'], $p['content_padding_right'], $p['content_padding_bottom'], $p['gutter'], $p['content_gap']],
        'hairline' => $p['hairline'] ? $pair('hairline_color') : null,
        'header' => [
            [$p['name'], $p['name_size'], $p['name_font'], $pair('name_color')],
            [$p['handle'], $p['meta_size'], $p['meta_font'], $pair('meta_color')],
            [$p['separator'], $p['meta_size'], $pair('meta_color')],
            [$p['time'], $p['meta_size'], $p['meta_font'], $pair('meta_color')],
        ],
        'replyingTo' => $p['replying_to'] === '' ? null : [$p['replying_to'], $p['on_replying_to'], $p['replying_to_a11y']],
        'paragraphs' => $runs,
        'showMore' => $p['on_show_more'],
        'star' => $p['on_star'] === 0 ? null : [$p['on_star'], $p['star_a11y'], $p['star_count'], $pair($p['star_on'] ? 'accent_color' : 'action_color'), $p['icon_size']],
        'pastel' => $p['pastel'] === '' ? null : [$p['pastel'], $p['on_pastel'], $p['pastel_count'], $p['pastel_a11y'], $p['pastel_size']],
        'quote' => $p['on_quote'] === 0 ? null : [$p['on_quote'], $p['quote_a11y'], $p['quote_stash']],
        'reply' => [$p['on_reply'], $p['reply_a11y'], $p['reply_label'], $p['reply_stash'], $p['reply_centered']],
        'replies' => $p['on_replies'] === 0 ? null : [$p['on_replies'], $p['replies_label'], $p['replies_a11y'], $p['replies_icon_size']],
        'menu' => array_map(fn (int $i): array => [$p['menu_id'][$i], $p['menu_label'][$i], (int) $p['menu_press'][$i], $p['menu_kind'][$i]], array_keys($p['menu_id'])),
        'rail' => [
            'previous' => isset($p['previous_color']) ? [...$p['previous_color'], $p['previous_height']] : null,
            'tail' => $p['tail'] ? [$p['lane_color'][0], $p['tail_top'], $p['tail_bottom']] : null,
            'dashed' => $p['reply_tail'] ? [$p['lane_color'][0], $p['reply_tail_height'], $p['reply_tail_height'] > 0 ? null : $p['reply_tail_top']] : null,
            'endDot' => $p['end_dot'] ? [$p['lane_color'][0], $p['end_dot_bottom'], $p['end_dot_left']] : null,
            'node' => [$p['node'], $p['node_top'], $p['node_left'], $p['on_node'], $p['node_a11y']],
            'avatar' => $p['node'] === 'dot' ? null : [$p['avatar_size'], $p['avatar_ring'], $p['lane_color'][0], $p['avatar_inner'], $p['avatar_initial'], $p['avatar_initial_size'], $p['avatar_src'] ?? null],
        ],
    ];
}

it('draws the Blade row while the native row is off', function () {
    variedLog();

    Native::visit('/log')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'commit-v2')
        ->assertMissingElement('commit_row');
});

it('draws what the Blade row draws, value for value', function (Closure $visit, int $rows) {
    [$blade, $native] = bothRows($visit);
    $nativeRows = findNodes($native, fn (array $node): bool => $node['type'] === 'commit_row');

    expect($nativeRows)->toHaveCount($rows);

    foreach ($nativeRows as $row) {
        // Same key, so the same node id: the phone keeps the row's place when the switch flips.
        $bladeRow = findNode($blade, fn (array $node): bool => $node['id'] === $row['id']);

        expect($bladeRow['type'])->toBe('pressable')
            ->and($bladeRow['ref'])->toBe($row['ref'])
            ->and(nativeRowValues($row))->toEqual(bladeRowValues($bladeRow));
    }
})->with([
    'the Log' => [function (): TestableComponent {
        variedLog();

        return Native::visit('/log', platform: 'ios');
    }, 6],
    'the Log on Android' => [function (): TestableComponent {
        variedLog();

        return Native::visit('/log', platform: 'android');
    }, 6],
    'the muted commits around a commit page\'s' => [function (): TestableComponent {
        fakeApi([
            'GET /api/v1/commits/c2' => [commitPageJson(
                commitJson(['id' => 'c2', 'body' => 'The commit', 'parent_id' => 'c1']),
                ancestors: [commitJson(['id' => 'c1', 'body' => 'The parent commit', 'stars_count' => 2, 'author' => userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada'])])],
            )],
            'GET /api/v1/commits/c2/replies' => [pageJson([])],
        ]);

        return unfolded(Native::visit('/log/commits/c2', platform: 'ios'));
    }, 1],
]);

it('keeps the Blade row for what the native row does not draw', function (array $commit) {
    config(['devnull.native_commit_row' => true]);
    Http::fake(['devnull.test/api/v1/log/following' => Http::response(pageJson([commitJson(['id' => 'c1', ...$commit])]))]);

    Native::visit('/log')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'commit-c1')
        ->assertMissingElement('commit_row');
})->with([
    'an image' => [['images' => [['id' => 'i1', 'url' => 'https://dev-null.sh/commit-images/i1', 'width' => 1200, 'height' => 900]]]],
    'a link preview' => [['link_preview' => ['url' => 'https://example.test/a', 'title' => 'A page', 'description' => null, 'image_url' => null, 'site_name' => 'example.test']]],
    'a code block' => [['body' => "Look:\n```php\necho 1;\n```", 'body_segments' => [['type' => 'text', 'text' => 'Look:'], ['type' => 'block', 'language' => 'php']]]],
    'a series' => [['series_id' => 's1', 'series_position' => 1, 'series_size' => 3]],
    'a status' => [['status' => 'pending', 'author' => userJson()]],
]);

it('sends a tap on the native row\'s parts to the same methods', function () {
    variedLog();
    config(['devnull.native_commit_row' => true]);

    $screen = Native::visit('/log')
        ->press("openProfile('ada')")
        ->assertNavigatedTo('/log/users/ada');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'commit-v2')['type'])->toBe('commit_row');
});

it('only offers the switch in builds made to compare the rows', function (bool $switchable) {
    config(['devnull.commit_row_switch' => $switchable]);
    fakeApi(['GET /api/v1/me' => [currentUserJson()]]);

    $screen = Native::test(ThisDevice::class);

    $switchable
        ? $screen->assertElement('toggle', fn (array $node): bool => ($node['ref'] ?? null) === 'native-commit-rows-switch')
        : $screen->assertMissingElement('toggle', fn (array $node): bool => ($node['ref'] ?? null) === 'native-commit-rows-switch');
})->with(['switchable' => true, 'not switchable' => false]);

it('picks the row on this phone with the switch, and the Log redraws its kept rows', function () {
    config(['devnull.commit_row_switch' => true, 'devnull.native_commit_row' => false]);
    variedLog();

    $log = Native::visit('/log')->assertMissingElement('commit_row');

    Native::test(ThisDevice::class)->call('setNativeCommitRows', true);

    expect(app(CommitRows::class)->native())->toBeTrue();

    $log->call('showRows', 0, 5)->assertElement('commit_row', fn (array $node): bool => ($node['ref'] ?? null) === 'commit-v2');
});

it('reads the .env\'s row unless the switch is offered', function () {
    config(['devnull.commit_row_switch' => false, 'devnull.native_commit_row' => true]);
    app(CommitRows::class)->useNative(false);

    expect(app(CommitRows::class)->native())->toBeTrue();

    config(['devnull.commit_row_switch' => true]);
    app(CommitRows::class)->useNative(false);

    expect(app(CommitRows::class)->native())->toBeFalse();
});
