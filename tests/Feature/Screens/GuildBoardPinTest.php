<?php

use App\Devnull\Data\TimelineRow;
use App\Devnull\Timeline;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * #rust's board, c1 pinned on top of c1, c2 and c3, as its moderator or not.
 * devnull remembers a pin it took, so the board asked for again after it
 * shows the new one, and refuses a pin with $refusal when one is given.
 *
 * @param  array{0: mixed, 1: int}|null  $refusal
 */
function rustBoard(bool $moderating = true, ?array $refusal = null): TestableComponent
{
    $pinned = 'c1';

    serveApi(function (Request $request) use (&$pinned, $moderating, $refusal) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && $path === '/api/v1/guilds/rust') {
            return Http::response(boardOf(['c1', 'c2', 'c3'], overrides: ['pinned_commit' => $pinned === null ? null : commitJson(['id' => $pinned]), 'can_moderate' => $moderating]));
        }

        if (preg_match('#^/api/v1/guilds/rust/commits/(?<id>[^/]+)/pin$#', (string) $path, $matches) === 1) {
            if ($refusal !== null) {
                return Http::response($refusal[0], $refusal[1]);
            }

            $pinned = $request->method() === 'PUT' ? $matches['id'] : null;

            return Http::response(null, 204);
        }

        return Http::response(['message' => 'No fake.'], 404);
    });

    return finishRefreshes(Native::visit('/log/guilds/rust', platform: 'android'));
}

/**
 * The menu item ids a commit's More holds.
 *
 * @return list<string>
 */
function moreIds(TestableComponent $screen, string $id): array
{
    return array_values(array_filter(array_map(fn (array|string $item): ?string => is_array($item) ? $item[0] : null, menuItems($screen, 'more-'.$id))));
}

/**
 * The ids of the commits the board draws below its pinned one, in order.
 *
 * @return list<string>
 */
function boardRowIds(TestableComponent $screen): array
{
    $guild = $screen->instance();
    $commits = array_values(array_filter($guild->commits, fn ($commit): bool => $commit->id !== $guild->pinnedId));

    return array_values(array_map(fn (TimelineRow $row): string => $row->commit->id, array_filter(Timeline::feed($commits), fn (TimelineRow $row): bool => ! $row->isDayMarker())));
}

it('offers the moderator Pin to board, and Unpin from board on the pinned commit', function () {
    $screen = rustBoard();

    expect(menuItems($screen, 'more-c2'))->toContain(['board-pin-c2', 'Pin to board', 'custom:pin'])
        ->and(menuItems($screen, 'more-c1'))->toContain(['board-pin-c1', 'Unpin from board', 'custom:pin-off']);
});

it('offers no board pin to anyone but the moderator', function () {
    $screen = rustBoard(moderating: false);

    expect(moreIds($screen, 'c2'))->not->toContain('board-pin-c2')
        ->and(moreIds($screen, 'c1'))->not->toContain('board-pin-c1');
});

it('offers no board pin outside the guild page', function () {
    serveApi(fn () => Http::response(pageJson([commitJson(['id' => 'c2'])])));

    expect(moreIds(Native::visit('/log', platform: 'android'), 'c2'))->not->toContain('board-pin-c2');
});

it('pins a commit to the board and puts the old pin back on it', function () {
    $screen = rustBoard();

    $screen->tap('board-pin-c2')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Commit pinned to the board.')
        ->assertSet('pinnedId', 'c2');

    expect(sentTo('PUT', 'guilds/rust/commits/c2/pin'))->toHaveCount(1)
        ->and(boardRowIds($screen))->toBe(['c1', 'c3'])
        ->and(menuItems($screen, 'more-c2'))->toContain(['board-pin-c2', 'Unpin from board', 'custom:pin-off'])
        ->and(menuItems($screen, 'more-c1'))->toContain(['board-pin-c1', 'Pin to board', 'custom:pin']);
});

it('unpins the pinned commit back onto the board', function () {
    $screen = rustBoard();

    $screen->tap('board-pin-c1')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Commit unpinned.')
        ->assertSet('pinnedId', null);

    expect(boardRowIds($screen))->toBe(['c1', 'c2', 'c3']);
});

it('leaves the board as it was when devnull refuses the pin', function () {
    $screen = rustBoard(refusal: [['message' => 'Not Found.'], 404]);

    $screen->tap('board-pin-c2')->assertSet('pinnedId', 'c1');

    expect(boardRowIds($screen))->toBe(['c2', 'c3']);
});

it('asks for the board again after a pin, even while an earlier ask is still out', function () {
    $screen = rustBoard();
    holdRefreshes();

    $screen->call('load');
    $before = count($screen->bridge()->callsTo('AsyncTask.Dispatch'));

    $screen->tap('board-pin-c2');

    expect(count($screen->bridge()->callsTo('AsyncTask.Dispatch')))->toBe($before + 1);
});
