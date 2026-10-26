<?php

use App\Devnull\WhoToFollow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * Someone who to follow suggests, as `GET /me/suggested-users` sends them.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function suggestedJson(string $username, array $overrides = []): array
{
    return [
        ...userJson(['id' => 'id-'.$username, 'handle' => $username, 'name' => ucfirst($username), 'bio' => null, 'lane' => 1]),
        'followers_count' => 0,
        'followed_by_following_count' => 0,
        'follows_you' => false,
        'is_followed' => false,
        ...$overrides,
    ];
}

/**
 * A Following log of $count commits, and who to follow answering with $people.
 *
 * @param  list<array<string, mixed>>  $people
 * @param  array<string, array{0: mixed, 1?: int}>  $routes
 */
function serveLogWithSuggestions(array $people, int $count = 8, array $routes = []): void
{
    serveApi([
        'GET /api/v1/log/following' => [pageJson(array_map(fn (int $index): array => commitJson(['id' => "c{$index}", 'body' => "Commit {$index}"]), range(1, $count)))],
        'GET /api/v1/me' => [['data' => userWithCountsJson(), 'unread_notifications_count' => 0]],
        'GET /api/v1/me/suggested-users' => [['data' => $people]],
        ...$routes,
    ]);
}

/**
 * The refs of the log's rows, in order.
 *
 * @return list<string>
 */
function logRowRefs(TestableComponent $screen): array
{
    return array_values(array_filter(array_map(fn (array $row): ?string => $row['ref'] ?? null, showingLog($screen)['children'] ?? [])));
}

/**
 * The people who to follow shows, by username.
 *
 * @return list<string>
 */
function shownSuggestions(TestableComponent $screen): array
{
    return array_map(fn (array $node): string => substr($node['ref'], strlen('suggested-')), findNodes(nodeByRef($screen, 'who-to-follow'), fn (array $node): bool => preg_match('/^suggested-(?!follow-|dismiss-)/', $node['ref'] ?? '') === 1));
}

/**
 * The rounds asked for so far.
 *
 * @return list<string|null>
 */
function suggestionRoundsAsked(): array
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/v1/me/suggested-users'))
        ->map(fn (array $pair): ?string => $pair[0]->data()['round'] ?? null)
        ->values()
        ->all();
}

it('suggests three people under the fifth commit, each with why', function () {
    serveLogWithSuggestions([
        suggestedJson('ada', ['bio' => 'Compilers and coffee', 'followed_by_following_count' => 2]),
        suggestedJson('grace', ['followers_count' => 1200]),
        suggestedJson('linus', ['followers_count' => 1]),
        suggestedJson('spare'),
    ]);

    $screen = Native::visit('/log')
        ->assertSee('$ who --should-i-follow')
        ->assertSee('# Compilers and coffee')
        ->assertSee('Followed by 2 you follow')
        ->assertSee('1.2k followers')
        ->assertSee('1 follower')
        ->assertDontSee('Spare');

    $rows = logRowRefs($screen);

    expect(shownSuggestions($screen))->toBe(['ada', 'grace', 'linus'])
        ->and(array_search('who-to-follow', $rows, true))->toBe(array_search('commit-c5', $rows, true) + 1)
        ->and(suggestionRoundsAsked())->toBe(['0']);
});

it('sits under the last commit of a shorter log', function () {
    serveLogWithSuggestions([suggestedJson('ada')], count: 2);

    $rows = logRowRefs(Native::visit('/log'));

    expect(array_slice($rows, -2))->toBe(['who-to-follow', 'root-commit']);
});

it('leaves who to follow out while there is nobody to suggest', function () {
    serveLogWithSuggestions([]);

    Native::visit('/log')->assertDontSee('$ who --should-i-follow');
});

it('keeps nothing between visits, and asks again when you come back to the Log', function () {
    serveLogWithSuggestions([suggestedJson('ada')]);

    $screen = Native::visit('/log');

    expect(kept('me/suggested-users', ['round' => '0']))->toBeNull();

    serveLogWithSuggestions([suggestedJson('grace')]);
    $screen->call('onResume');

    expect(shownSuggestions($screen))->toBe(['grace'])
        ->and(suggestionRoundsAsked())->toBe(['0', '0']);
});

it('follows someone suggested in place, and unfollows them again', function () {
    serveLogWithSuggestions([suggestedJson('ada')], routes: [
        'POST /api/v1/users/ada/follow' => [null, 204],
        'DELETE /api/v1/users/ada/follow' => [null, 204],
    ]);

    $screen = Native::visit('/log');

    expect(nodeByRef($screen, 'suggested-follow-ada')['props']['a11y_label'])->toBe('Follow @ada');

    $screen->tap('suggested-follow-ada');

    expect($screen->instance()->suggestedUsers[0]->isFollowed)->toBeTrue()
        ->and(sentTo('POST', 'users/ada/follow'))->toHaveCount(1);

    $screen->tap('suggested-follow-ada');

    expect($screen->instance()->suggestedUsers[0]->isFollowed)->toBeFalse()
        ->and(sentTo('DELETE', 'users/ada/follow'))->toHaveCount(1);
});

it('says follow back for someone who follows you', function () {
    serveLogWithSuggestions([suggestedJson('ada', ['follows_you' => true])]);

    $screen = Native::visit('/log');

    expect(nodeByRef($screen, 'suggested-follow-ada')['props']['a11y_label'])->toBe('Follow back @ada');
});

it('puts the follow back when devnull refuses it', function () {
    serveLogWithSuggestions([suggestedJson('ada')], routes: [
        'POST /api/v1/users/ada/follow' => [['message' => 'Too many follows.'], 429],
    ]);

    $screen = Native::visit('/log')->tap('suggested-follow-ada');

    expect($screen->instance()->suggestedUsers[0]->isFollowed)->toBeFalse();
});

it('gives the place of someone not interesting to the first spare, then tops the spares up', function () {
    serveLogWithSuggestions([suggestedJson('ada'), suggestedJson('grace'), suggestedJson('linus'), suggestedJson('spare'), suggestedJson('other')], routes: [
        'POST /api/v1/users/grace/dismiss' => [null, 204],
    ]);

    $screen = Native::visit('/log');
    holdRefreshes();
    $screen->tap('suggested-dismiss-grace');

    expect(shownSuggestions($screen))->toBe(['ada', 'spare', 'linus'])
        ->and(sentTo('POST', 'users/grace/dismiss'))->toHaveCount(1);

    serveLogWithSuggestions([suggestedJson('ada'), suggestedJson('linus'), suggestedJson('spare'), suggestedJson('other'), suggestedJson('newcomer')]);
    finishRefreshes($screen);

    expect(array_map(fn ($suggested) => $suggested->user->username, $screen->instance()->suggestedUsers))->toBe(['ada', 'spare', 'linus', 'other', 'newcomer']);
});

it('keeps someone just followed in place when another is dismissed and devnull no longer suggests them', function () {
    serveLogWithSuggestions([suggestedJson('ada'), suggestedJson('grace'), suggestedJson('linus'), suggestedJson('spare')], routes: [
        'POST /api/v1/users/ada/follow' => [null, 204],
        'POST /api/v1/users/grace/dismiss' => [null, 204],
    ]);

    $screen = Native::visit('/log')->tap('suggested-follow-ada');

    serveLogWithSuggestions([suggestedJson('linus'), suggestedJson('spare'), suggestedJson('newcomer')], routes: [
        'POST /api/v1/users/grace/dismiss' => [null, 204],
    ]);
    $screen->tap('suggested-dismiss-grace');

    expect(shownSuggestions($screen))->toBe(['ada', 'spare', 'linus'])
        ->and($screen->instance()->suggestedUsers[0]->isFollowed)->toBeTrue()
        ->and(array_map(fn ($suggested) => $suggested->user->username, $screen->instance()->suggestedUsers))->toBe(['ada', 'spare', 'linus', 'newcomer']);
});

it('asks devnull again once the last one is dismissed, and shows whoever it still has', function () {
    serveLogWithSuggestions([suggestedJson('ada')], routes: [
        'POST /api/v1/users/ada/dismiss' => [null, 204],
    ]);

    $screen = Native::visit('/log');
    holdRefreshes();
    $screen->tap('suggested-dismiss-ada');
    serveLogWithSuggestions([suggestedJson('grace')]);
    finishRefreshes($screen);

    expect(shownSuggestions($screen))->toBe(['grace'])
        ->and($screen->instance()->whoToFollowNote)->toBeNull();
});

it('says nobody is left once everyone is dismissed', function () {
    serveLogWithSuggestions([suggestedJson('ada')], routes: [
        'POST /api/v1/users/ada/dismiss' => [null, 204],
    ]);

    Native::visit('/log')
        ->tap('suggested-dismiss-ada')
        ->assertSee('fatal: no one left to suggest');
});

it('brings back someone dismissed when devnull refuses it', function () {
    serveLogWithSuggestions([suggestedJson('ada'), suggestedJson('grace'), suggestedJson('linus'), suggestedJson('spare')], routes: [
        'POST /api/v1/users/grace/dismiss' => [['message' => 'Something went wrong.'], 500],
    ]);

    $screen = Native::visit('/log')->tap('suggested-dismiss-grace');

    expect(shownSuggestions($screen))->toBe(['ada', 'grace', 'linus']);
});

it('suggests other people from the next round, and keeps to it', function () {
    serveLogWithSuggestions([suggestedJson('ada')]);

    $screen = Native::visit('/log');

    serveLogWithSuggestions([suggestedJson('grace')]);

    $screen->tap('who-to-follow-refresh');

    expect(shownSuggestions($screen))->toBe(['grace'])
        ->and(suggestionRoundsAsked())->toBe(['0', '1'])
        ->and(app(WhoToFollow::class)->round('0199e5c4-0000-7000-8000-000000000001'))->toBe(1);
});

it('leaves an answer for the round before alone once the next is asked for', function () {
    holdRefreshes();
    serveApi(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/api/v1/me/suggested-users' => Http::response(['data' => [suggestedJson(($request->data()['round'] ?? '0') === '1' ? 'grace' : 'ada')]]),
            '/api/v1/log/following' => Http::response(pageJson([commitJson(['id' => 'c1'])])),
            default => Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]),
        };
    });
    keep('log/following', pageJson([commitJson(['id' => 'c1'])]));

    $screen = finishRefreshes(Native::visit('/log'));
    holdRefreshes();
    $screen->tap('who-to-follow-refresh');

    expect(nodeByRef($screen, 'who-to-follow-refresh')['props']['a11y_label'] ?? null)->toBe('Suggesting other people');

    finishRefreshes($screen);

    expect(shownSuggestions($screen))->toBe(['grace'])
        ->and($screen->instance()->suggestingOtherPeople)->toBeFalse()
        ->and(app(WhoToFollow::class)->round('0199e5c4-0000-7000-8000-000000000001'))->toBe(1);
});

it('keeps the round and who is showing when the next few fail to come', function () {
    serveLogWithSuggestions([suggestedJson('ada')]);

    $screen = Native::visit('/log');

    serveLogWithSuggestions([], routes: ['GET /api/v1/me/suggested-users' => [['message' => 'Server Error'], 500]]);

    $screen->tap('who-to-follow-refresh');

    expect(shownSuggestions($screen))->toBe(['ada'])
        ->and($screen->instance()->suggestingOtherPeople)->toBeFalse()
        ->and(app(WhoToFollow::class)->round('0199e5c4-0000-7000-8000-000000000001'))->toBe(0);
});

it('never brings back someone dismissed with an answer that crossed the dismissal', function () {
    serveLogWithSuggestions([suggestedJson('ada'), suggestedJson('grace')], routes: [
        'POST /api/v1/users/grace/dismiss' => [null, 204],
    ]);

    $screen = Native::visit('/log');
    holdRefreshes();
    // Coming back to the Log asks again, and grace is dismissed while that answer is on its way.
    $screen->call('onResume');
    $screen->tap('suggested-dismiss-grace');

    finishRefreshes($screen);

    expect(shownSuggestions($screen))->toBe(['ada']);
});

it('hides who to follow with an undo, and leaves it out from then on', function () {
    serveLogWithSuggestions([suggestedJson('ada')]);

    $screen = Native::visit('/log')
        ->tap('who-to-follow-hide')
        ->assertSee('$ who --should-i-follow > /dev/null')
        ->assertSee('Bring it back in Settings.');

    expect(shownSuggestions($screen))->toBe([]);

    $screen->tap('who-to-follow-undo');

    expect(shownSuggestions($screen))->toBe(['ada']);

    $screen->tap('who-to-follow-hide');

    $requestsBefore = count(suggestionRoundsAsked());

    Native::visit('/log')->assertDontSee('$ who --should-i-follow');

    expect(suggestionRoundsAsked())->toHaveCount($requestsBefore);
});

it('brings who to follow back from settings', function () {
    app(WhoToFollow::class)->show('0199e5c4-0000-7000-8000-000000000001', false);
    fakeApi(['GET /api/v1/me' => [['data' => currentUserJson()]]]);

    $settings = Native::visit('/profile/device');

    expect(nodeByRef($settings, 'who-to-follow-switch')['props']['value'] ?? null)->toBeFalse();

    $settings->toggle('who-to-follow-switch', true);

    expect(app(WhoToFollow::class)->shows('0199e5c4-0000-7000-8000-000000000001'))->toBeTrue()
        ->and(nodeByRef($settings, 'who-to-follow-switch')['props']['value'] ?? null)->toBeTrue();
});

it('only puts who to follow in the log you are on', function () {
    serveLogWithSuggestions([suggestedJson('ada')]);
    keep('log/latest', pageJson(array_map(fn (int $index): array => commitJson(['id' => "l{$index}"]), range(1, 6)), path: 'http://devnull.test/api/v1/log/latest'));

    $screen = Native::visit('/log');

    expect(findNodes(nodeByRef($screen, 'log-latest'), fn (array $node): bool => ($node['ref'] ?? null) === 'who-to-follow'))->toBe([])
        ->and(logRowRefs($screen))->toContain('who-to-follow');
});
