<?php

use App\Devnull\Client;
use App\Devnull\Display;
use App\NativeComponents\Notifications;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * The avatars stacked on a notification row, as the image URLs drawn bottom to top.
 *
 * @return list<string>
 */
function stackedAvatars(TestableComponent $screen, string $id): array
{
    $images = findNodes(nodeByRef($screen, 'actors-'.$id), fn (array $node): bool => ($node['type'] ?? null) === 'image');

    return array_map(fn (array $image): string => $image['props']['src'] ?? '', $images);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function personJson(string $username, string $name, int $lane = 2, array $overrides = []): array
{
    return userJson(['id' => 'u-'.$username, 'handle' => $username, 'name' => $name, 'avatar_url' => "https://avatars.test/{$username}", 'lane' => $lane, ...$overrides]);
}

/**
 * A page of notifications as devnull lists them: filtered, and marked read as it sends them.
 *
 * @param  list<array<string, mixed>>  $groups
 * @return array<string, mixed>
 */
function inboxJson(array $groups, string $type = 'all', ?string $readAt = null, ?string $cursor = null): array
{
    return [
        ...pageJson($groups, $cursor, path: 'http://devnull.test/api/v1/notifications'),
        'filters' => ['type' => $type],
        'read_at' => $readAt ?? now()->toIso8601ZuluString(),
    ];
}

/**
 * The colour a node's ring is drawn in.
 */
function ringColour(TestableComponent $screen, string $id): ?string
{
    $node = nodeByRef($screen, 'node-'.$id);

    return $node['style']['border_color'] ?? $node['style']['borderColor'] ?? null;
}

it('opens notification actors independently of the commit the row opens', function (string $ref, string $username) {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('starred', [
        'actor' => personJson('grace', 'Grace'),
        'actors' => [personJson('grace', 'Grace'), personJson('ada', 'Ada')],
        'actors_count' => 2,
        'event_count' => 2,
    ])])]]);

    $screen = Native::visit('/notifications')->tap($ref)->assertNavigatedTo('/notifications/users/'.$username);
    expect($screen->navigationIntent()->data['user']->username)->toBe($username);
})->with([
    'name' => ['actor-name-n-starred', 'grace'],
    'primary avatar' => ['actor-avatar-n-starred-grace', 'grace'],
    'other stacked actor' => ['actor-avatar-n-starred-ada', 'ada'],
]);

it('opens an expanded notification actor from their own profile link', function () {
    fakeApi([
        'GET /api/v1/notifications' => [inboxJson([notificationJson('starred', ['event_count' => 2])])],
        'GET /api/v1/notifications/n-starred/group' => [pageJson([notificationJson('starred', ['id' => 'older', 'actor' => personJson('linus', 'Linus'), 'actors' => [personJson('linus', 'Linus')]])])],
    ]);

    Native::visit('/notifications')->tap('group-n-starred')->tap('group-actor-older')->assertNavigatedTo('/notifications/users/linus');
});

it('does not navigate to an actor absent from the displayed notifications', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([])]]);
    Native::visit('/notifications')->call('openActor', 'nobody')->assertNoNavigation();
});

it('reads a group with its people, how many events there are and how many are unread', function () {
    Http::fake(['devnull.test/*' => Http::response(inboxJson([
        notificationJson('starred', [
            'actor' => personJson('grace', 'Grace'),
            'actors' => [personJson('grace', 'Grace'), personJson('ada', 'Ada'), personJson('linus', 'Linus')],
            'actors_count' => 4,
            'event_count' => 4,
            'group_id' => 'g-stars',
            'unread_count' => 2,
        ]),
        notificationJson('followed', ['read_at' => now()->toIso8601ZuluString(), 'unread_count' => 0, 'actor' => personJson('linus', 'Linus', overrides: ['is_followed' => false])]),
    ]))]);

    [$stars, $follow] = app(Client::class)->notifications()->items;

    expect($stars->isStar())->toBeTrue()
        ->and($stars->groupId)->toBe('g-stars')
        ->and($stars->eventCount)->toBe(4)
        ->and($stars->isGrouped())->toBeTrue()
        ->and($stars->groupNoun())->toBe('stars')
        ->and(array_map(fn ($actor) => $actor->username, $stars->actors))->toBe(['grace', 'ada', 'linus'])
        ->and($stars->others())->toBe('and 3 others')
        ->and($stars->isUnread)->toBeTrue()
        ->and($follow->isUnread)->toBeFalse()
        ->and($follow->actorIsFollowed)->toBeFalse()
        ->and($follow->isGrouped())->toBeFalse();
});

it('words each kind like the web: the name, then the handle when they act alone, and what they did', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([
        notificationJson('replied', ['commit' => notificationCommitJson(['id' => 'r1', 'parent_id' => 'c-yours', 'body' => 'Nice work.'])]),
        notificationJson('quoted', ['id' => 'n-quoted', 'commit' => notificationCommitJson(['id' => 'q1', 'body' => 'Quoting this one.'])]),
        notificationJson('mentioned', ['actor' => personJson('grace', 'Grace'), 'actors' => [personJson('grace', 'Grace')], 'commit' => notificationCommitJson(['id' => 'm1', 'body' => 'Ask @local_dev about it'])]),
        notificationJson('followed', ['created_at' => now()->subDays(2)->toIso8601ZuluString(), 'actor' => personJson('linus', 'Linus'), 'actors' => [personJson('linus', 'Linus')]]),
    ])]]);

    $screen = Native::visit('/notifications')
        ->assertNavTitle('Notifications')
        ->assertTabActive('Notifications')
        ->assertSee('Nice work.')
        ->assertSee('Quoting this one.')
        ->assertSee('Ask @local_dev about it')
        ->assertSee('2d');

    expect(paragraphRuns($screen, 'replied to your commit'))->toBe([['Ada', 'link'], [' '], ['@ada', 'mention'], [' replied to your commit']])
        ->and(paragraphRuns($screen, 'quoted your commit'))->toBe([['Ada', 'link'], [' '], ['@ada', 'mention'], [' quoted your commit']])
        ->and(paragraphRuns($screen, 'mentioned you'))->toBe([['Grace', 'link'], [' '], ['@grace', 'mention'], [' mentioned you']])
        ->and(paragraphRuns($screen, 'followed you'))->toBe([['Linus', 'link'], [' '], ['@linus', 'mention'], [' followed you']])
        ->and(stackedAvatars($screen, 'n-followed'))->toBe(['https://avatars.test/linus']);
});

it('stacks the newest three people on a group of stars, with a +N for the rest, and sets your commit apart', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('starred', [
        'actor' => personJson('grace', 'Grace', 5),
        'actors' => [personJson('grace', 'Grace', 5), personJson('ada', 'Ada'), personJson('linus', 'Linus')],
        'actors_count' => 5,
        'event_count' => 5,
        'commit' => notificationCommitJson(['id' => 'c-starred', 'body' => 'Shipped the app.']),
    ])])]]);

    $screen = Native::visit('/notifications', platform: 'ios')
        ->assertSee('Shipped the app.')
        ->assertSee('+2')
        ->assertElement('icon', fn (array $node): bool => ($node['props']['name'] ?? null) === 'custom:star-filled' && ($node['props']['color'] ?? null) === config('native-ui.theme.light.lane-5'));

    expect(paragraphRuns($screen, 'starred your commit'))->toBe([['Grace', 'link'], [' '], ['and 4 others starred your commit']])
        ->and(stackedAvatars($screen, 'n-starred'))->toBe(['https://avatars.test/linus', 'https://avatars.test/ada', 'https://avatars.test/grace'])
        ->and(nodeByRef($screen, 'actors-n-starred')['layout']['width'] ?? null)->toEqual(108)
        // Your own commit sits in a muted hairline box, clamped to three lines.
        ->and(nodeByRef($screen, 'your-commit-n-starred'))->not->toBeNull();

    $screen->tap('notification-n-starred')->assertNavigatedTo('/notifications/commits/c-starred');
});

it('words moderation updates like the web, with a verdict chip and the reason for a rejection', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([
        notificationJson('approved'),
        notificationJson('rejected', ['reason' => 'Please keep it about code.']),
    ])]]);

    $screen = Native::visit('/notifications')
        ->assertSee('Your commit was approved.')
        ->assertSee('Your commit was rejected.')
        ->assertSee('About rejected')
        ->assertSee('reason: ')
        ->assertSee('Please keep it about code.')
        ->assertSee('Only you can see this update and your rejected commit.')
        // devnull names you as the actor of your own moderation update, so no name or avatar is shown.
        ->assertDontSee('Local Dev')
        ->assertMissingElement('column', fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'actors-'));

    $verdict = fn (string $id): ?string => findNode(nodeByRef($screen, 'verdict-'.$id), fn (array $node): bool => ($node['type'] ?? null) === 'text')['props']['color'] ?? null;

    expect($verdict('n-rejected'))->toBe(config('native-ui.theme.light.destructive'))
        ->and($verdict('n-approved'))->toBe(config('native-ui.theme.light.ink-muted'));
});

it('words a level-up like the web: nobody named, the level and rank in your lane, and what the rank means', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('leveled_up')])]]);

    $screen = Native::visit('/notifications', platform: 'ios')
        ->assertSee('You reached level 5: wheel.')
        ->assertSee('Member of the trusted wheel administrative group')
        ->assertDontSee('Local Dev')
        ->assertDontSee('replied to your commit')
        ->assertMissingElement('column', fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'actors-'))
        ->assertElement('icon', fn (array $node): bool => ($node['props']['name'] ?? null) === 'custom:chevrons-up' && ($node['props']['color'] ?? null) === config('native-ui.theme.light.lane-3'));

    $chip = findNode(nodeByRef($screen, 'level-n-leveled_up'), fn (array $node): bool => ($node['type'] ?? null) === 'text');

    expect($chip['props']['text'] ?? null)->toBe('Lv. 5 · wheel')
        ->and($chip['props']['color'] ?? null)->toBe(config('native-ui.theme.light.lane-3'))
        ->and(nodeByRef($screen, 'notification-n-leveled_up')['props']['a11y_label'] ?? null)->toBe('New. You reached level 5: wheel');
});

it('says only that you reached a new level when devnull does not say which', function () {
    $levelUp = array_diff_key(notificationJson('leveled_up'), array_flip(['level', 'rank', 'rank_description']));

    fakeApi(['GET /api/v1/notifications' => [inboxJson([$levelUp])]]);

    Native::visit('/notifications')
        ->assertSee('You reached a new level.')
        ->assertDontSee('replied to your commit')
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'level-n-leveled_up')
        ->assertMissingElement('text', fn (array $node): bool => ($node['ref'] ?? null) === 'rank-description-n-leveled_up');
});

it('leaves out an empty reason', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('rejected', ['reason' => null])])]]);

    Native::visit('/notifications')
        ->assertDontSee('reason: ')
        ->assertSee('Only you can see this update and your rejected commit.');
});

it('rings what arrived since your last visit in blue, above the last-visit marker, with day markers between days', function () {
    // At midday, so a notification from a few hours ago is always today's.
    $this->travelTo(now()->setTime(12, 0));
    $this->freezeTime();

    $readAt = now()->subSecond();

    fakeApi(['GET /api/v1/notifications' => [inboxJson([
        // Marked read just now, by this very listing.
        notificationJson('mentioned', ['read_at' => $readAt->toIso8601ZuluString()]),
        // Read on an earlier visit.
        notificationJson('followed', ['read_at' => now()->subDay()->toIso8601ZuluString(), 'created_at' => now()->subDays(3)->toIso8601ZuluString()]),
    ], readAt: $readAt->toIso8601ZuluString())]]);

    $screen = Native::visit('/notifications');

    expect(ringColour($screen, 'n-mentioned'))->toBe(config('native-ui.theme.light.primary'))
        ->and(ringColour($screen, 'n-followed'))->toBe(config('native-ui.theme.light.rail'));

    $refs = array_values(array_filter(array_map(
        fn (array $node): ?string => $node['ref'] ?? null,
        findNodes($screen->tree(), fn (array $node): bool => preg_match('/^(day-|last-visit$|notification-)/', (string) ($node['ref'] ?? '')) === 1),
    )));

    expect($refs)->toBe(['day-Today', 'notification-n-mentioned', 'last-visit', 'day-'.Display::dayLabel(now()->subDays(3)), 'notification-n-followed']);
});

it('opens what the web opens for each kind', function (string $type, string $destination) {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson($type)])]]);

    Native::visit('/notifications')->tap('notification-n-'.$type)->assertNavigatedTo($destination);
})->with([
    ['replied', '/notifications/commits/c-replied'],
    ['starred', '/notifications/commits/c-starred'],
    ['mentioned', '/notifications/commits/c-mentioned'],
    ['approved', '/notifications/commits/c-approved'],
    ['rejected', '/notifications/commits/c-rejected'],
    ['followed', '/notifications/users/ada'],
    ['leveled_up', '/notifications/users/local_dev'],
]);

it("has the web's filters, each asked for by its type, with All asked for plainly", function () {
    serveApi(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $type = $query['type'] ?? 'all';

        return str_starts_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/notifications')
            ? Http::response(inboxJson($type === 'stars' ? [notificationJson('starred')] : [notificationJson('followed')], $type))
            : Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]);
    });

    $screen = Native::visit('/notifications')->assertSee('followed you');

    // The pager draws the filters along the top, in a row that scrolls, on All.
    expect(nodeByRef($screen, 'notifications-pager')['props'])->toMatchArray([
        'tabs' => ['All', 'Replies', 'Quotes', 'Stars', 'Mentions', 'Follows', 'Moderation'],
        'tabs_scrollable' => true,
        'page' => 0,
    ]);

    tapFilter($screen, 'stars')
        ->assertSet('type', 'stars')
        ->assertSee('starred your commit')
        ->assertDontSee('followed you');

    $urls = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/notifications'))->map(fn (array $pair): string => $pair[0]->url())->values()->all();

    expect($urls)->toContain('http://devnull.test/api/v1/notifications', 'http://devnull.test/api/v1/notifications?type=stars');
});

it("says what each filter shows when it's empty, in the web's words, with a way back to all of them", function (string $type) {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([])]]);

    $screen = Native::test(Notifications::class)->call('show', $type)
        ->assertSee($type === 'all' ? '$ cat /dev/notifications' : '$ cat /dev/notifications | grep '.$type)
        ->assertSee(Notifications::Types[$type][1]);

    if ($type !== 'all') {
        $screen->assertSee('Show all notifications')->tap('show-all')->assertSet('type', 'all');
    }
})->with(array_keys(Notifications::Types));

it('keeps each filter on its own, and opens one kept before at once', function () {
    holdRefreshes();
    keep('notifications', inboxJson([notificationJson('followed')]), ['type' => null]);
    keep('notifications', inboxJson([notificationJson('mentioned')], 'mentions'), ['type' => 'mentions']);
    serveApi(fn () => Http::response(inboxJson([])));

    $screen = Native::visit('/notifications')->assertSee('followed you');
    $requests = count(Http::recorded());

    tapFilter($screen, 'mentions')
        ->assertSee('mentioned you')
        ->assertDontSee('followed you');

    expect(showsLoading(showingNotifications($screen)))->toBeFalse()
        ->and(count(Http::recorded()))->toBe($requests)
        ->and(kept('notifications', ['type' => 'mentions']))->not->toBeNull()
        ->and(kept('notifications', ['type' => 'stars']))->toBeNull();
});

it('swipes between the filters both ways under the filters the pager draws, and stops at the ends', function () {
    holdRefreshes();
    keep('notifications', inboxJson([notificationJson('followed')]), ['type' => null]);
    keep('notifications', inboxJson([notificationJson('replied')], 'replies'), ['type' => 'replies']);
    serveApi(fn () => Http::response(inboxJson([])));

    $screen = Native::visit('/notifications');
    $pager = fn (): array => nodeByRef($screen, 'notifications-pager');

    // All is first: it and Replies are shipped, nothing before it. The pager says where it is once a swipe has settled.
    expect($pager()['props'])->toMatchArray(['horizontal' => true, 'count' => 7, 'page' => 0, 'window_from' => 0, 'window_to' => 1, 'report_on_settle' => true])
        ->and($pager()['props'])->toHaveKey('on_tab_reselect')
        ->and(treeShows(nodeByRef($screen, 'notifications-replies'), 'replied'))->toBeTrue();

    $screen->changeTab('showPage', 1)->assertSet('type', 'replies');

    expect($pager()['props'])->toMatchArray(['page' => 1, 'window_from' => 0, 'window_to' => 2])
        ->and(treeShows(showingNotifications($screen), 'replied'))->toBeTrue()
        ->and(showsLoading(showingNotifications($screen)))->toBeFalse();

    $screen->changeTab('showPage', 0)->assertSet('type', 'all');

    expect($pager()['props']['page'])->toBe(0);

    // Nothing before All, and no wrapping round from Moderation to All.
    $screen->changeTab('showPage', -1)->assertSet('type', 'all');
    $screen->changeTab('showPage', 6)->assertSet('type', 'moderation');

    expect($pager()['props'])->toMatchArray(['page' => 6, 'window_from' => 5, 'window_to' => 6]);

    $screen->changeTab('showPage', 7)->assertSet('type', 'moderation');
});

it('gives each filter its own list, starts the one you leave again in a fresh one, and only lets the one you are on page', function () {
    holdRefreshes();
    keep('notifications', inboxJson([notificationJson('followed')]), ['type' => null]);
    keep('notifications', inboxJson(array_map(fn (int $n): array => notificationJson('replied', ['id' => "r{$n}"]), range(1, 10)), 'replies', cursor: 'next'), ['type' => 'replies']);
    serveApi(fn () => Http::response(inboxJson([])));

    $screen = Native::visit('/notifications');
    $all = showingNotifications($screen)['id'];
    $replies = nodeByRef($screen, 'notifications-replies');

    // Beside All, Replies shows the first few groups kept for it, with nothing under them yet.
    expect(count(findNodes($replies, fn (array $node): bool => str_starts_with((string) ($node['ref'] ?? ''), 'notification-'))))->toBe(Notifications::PreviewGroups)
        ->and(showsLoading($replies))->toBeFalse()
        ->and($replies['props'])->toHaveKey('on_refresh')
        ->and($replies['props'])->not->toHaveKey('on_end_reached')
        ->and(showingNotifications($screen)['props'])->toHaveKey('on_end_reached');

    $screen->changeTab('showPage', 1);

    // Swiped to, Replies keeps its list and fills it. All, left behind, starts again at the top of a fresh one.
    expect(showingNotifications($screen)['id'])->toBe($replies['id'])
        ->and(nodeByRef($screen, 'notifications-all')['id'])->not->toBe($all);

    // The filter you're on takes you back to its top.
    tapFilter($screen, 'replies');

    expect(showingNotifications($screen)['id'])->not->toBe($replies['id']);
});

it('opens a group in place, as the web expands it, and folds it away again', function () {
    fakeApi([
        'GET /api/v1/notifications' => [inboxJson([notificationJson('starred', [
            'actor' => personJson('grace', 'Grace', 5),
            'actors' => [personJson('grace', 'Grace', 5), personJson('ada', 'Ada')],
            'actors_count' => 2,
            'event_count' => 2,
        ])])],
        'GET /api/v1/notifications/n-starred/group' => [pageJson([
            notificationJson('starred', ['id' => 'n-star-2', 'actor' => personJson('grace', 'Grace', 5)]),
            notificationJson('starred', ['id' => 'n-star-1', 'actor' => personJson('ada', 'Ada')]),
        ])],
    ]);

    $screen = Native::visit('/notifications')
        ->assertSee('2 stars')
        ->tap('group-n-starred')
        ->assertNoNavigation()
        ->assertSee('Hide stars');

    expect(array_map(fn ($item): string => $item->id, $screen->instance()->expanded['n-starred']))->toBe(['n-star-2', 'n-star-1'])
        ->and(childRefs($screen, 'group-items-n-starred'))->toContain('group-item-n-star-2', 'group-item-n-star-1');

    $screen->tap('group-item-n-star-1')->assertNavigatedTo('/notifications/commits/c-starred');

    Native::visit('/notifications')->tap('group-n-starred')->tap('group-n-starred')->assertSee('2 stars')->assertDontSee('Hide stars');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/notifications/n-starred/group');
});

it('opens a group with the filter it was listed under, and pages its older ones', function () {
    serveApi(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/group') && str_contains($url, 'cursor=older') => Http::response(pageJson([notificationJson('followed', ['id' => 'n-follow-1', 'actor' => personJson('linus', 'Linus')])])),
            str_contains($url, '/group') => Http::response(pageJson([notificationJson('followed', ['id' => 'n-follow-2', 'actor' => personJson('grace', 'Grace', overrides: ['is_followed' => false])])], 'older')),
            default => Http::response(inboxJson([notificationJson('followed', ['event_count' => 2, 'actors_count' => 2])], 'follows')),
        };
    });

    Native::test(Notifications::class)->call('show', 'follows')
        ->tap('group-n-followed')
        ->assertSee('Show older followers')
        ->tap('group-older-n-followed')
        ->assertSee('Linus')
        ->assertDontSee('Show older followers');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/notifications/n-followed/group?type=follows');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/notifications/n-followed/group?type=follows&cursor=older');
});

it('says when a group would not open, and tries again', function () {
    $fails = true;

    serveApi(function (Request $request) use (&$fails) {
        return match (true) {
            str_contains($request->url(), '/group') => $fails ? Http::response(['message' => 'Server Error'], 500) : Http::response(pageJson([notificationJson('starred', ['id' => 'n-star-1'])])),
            default => Http::response(inboxJson([notificationJson('starred', ['event_count' => 3])])),
        };
    });

    $screen = Native::visit('/notifications')->tap('group-n-starred')->assertSee('fatal: could not load stars');

    $fails = false;

    $screen->tap('group-retry-n-starred')->assertDontSee('fatal: could not load stars');

    expect($screen->instance()->expanded['n-starred'][0]->id)->toBe('n-star-1');
});

it('opens a group kept before at once, and keeps groups per filter', function () {
    holdRefreshes();
    keep('notifications', inboxJson([notificationJson('starred', ['event_count' => 2])]), ['type' => null]);
    keep('notifications/n-starred/group', pageJson([notificationJson('starred', ['id' => 'n-star-kept', 'actor' => personJson('grace', 'Grace')])]), ['type' => null]);
    serveApi(fn () => Http::response(pageJson([])));

    $screen = Native::visit('/notifications');
    $requests = count(Http::recorded());

    $screen->tap('group-n-starred')->assertSee('Grace');

    expect(count(Http::recorded()))->toBe($requests)
        ->and(kept('notifications/n-starred/group', ['type' => 'stars']))->toBeNull();
});

it('follows back from a follow, as the web does, and puts it back if devnull says no', function () {
    fakeApi([
        'GET /api/v1/notifications' => [inboxJson([notificationJson('followed', ['actor' => personJson('linus', 'Linus', overrides: ['is_followed' => false])])])],
        'POST /api/v1/users/linus/follow' => [null, 204],
        'DELETE /api/v1/users/linus/follow' => [['message' => 'Too Many Attempts.', 'code' => 'rate_limited'], 429],
    ]);

    // The web's icon: a muted user-plus to follow back, a user-check in Commit Blue once you do.
    $followBack = fn (TestableComponent $screen): array => [
        nodeByRef($screen, 'follow-back-linus')['props']['a11y_label'],
        findNode(nodeByRef($screen, 'follow-back-linus'), fn (array $node): bool => ($node['type'] ?? null) === 'icon')['props']['name'],
    ];

    $screen = Native::visit('/notifications', platform: 'ios');

    expect($followBack($screen))->toBe(['Follow @linus', 'custom:user-plus']);

    $screen->tap('follow-back-linus');

    expect($followBack($screen))->toBe(['Unfollow @linus', 'custom:user-check']);

    $screen->tap('follow-back-linus')->assertSee('Too Many Attempts.');

    expect($followBack($screen))->toBe(['Unfollow @linus', 'custom:user-check']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://devnull.test/api/v1/users/linus/follow');
});

it('ends the list with the oldest notification, on a hollow root node', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('followed')])]]);

    Native::visit('/notifications')->assertSee("That's the oldest notification. There's nothing before it.");
});

it('lets a drag that starts on a row scroll the list, with the list filling the screen under the bars', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([
        notificationJson('followed'),
        notificationJson('starred', ['event_count' => 2]),
        notificationJson('rejected'),
    ])]]);

    $screen = Native::visit('/notifications', platform: 'ios');
    $list = findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'list');

    // Same as the Log and Profile lists: no safe-area or bottom padding of its own, the bars inset it.
    expect($list['layout'])->toBe(['width' => 'fill', 'flex_grow' => 1.0, 'flex_shrink' => 1.0])
        ->and(pressFeedbackInScroller($screen))->toBe([]);
});

it('opens on skeleton rows, and fills in what it kept straight after, so a tab tap shows at once', function () {
    config(['devnull.skeleton_first' => true]);
    holdRefreshes();
    keep('notifications', inboxJson([notificationJson('followed')]), ['type' => null]);
    serveApi(fn () => Http::response(inboxJson([notificationJson('followed')])));

    $screen = Native::visit('/notifications')->assertSet('skeletonFrame', true)->assertDontSee('followed you');

    expect(showsLoading(showingNotifications($screen)))->toBeTrue();

    finishRefreshes($screen)->assertSet('skeletonFrame', false)->assertSee('followed you');
});

it('opens on what it kept on iOS, which puts a tab back as it was last drawn, with no spinner first', function () {
    config(['devnull.skeleton_first' => true]);
    holdRefreshes();
    keep('notifications', inboxJson([notificationJson('followed')]), ['type' => null]);

    $screen = Native::visit('/notifications', platform: 'ios');

    $screen->assertSet('skeletonFrame', false)->assertSee('followed you');
    expect(showsLoading(showingNotifications($screen)))->toBeFalse();
});

it('lists a group of followers when it is tapped, each with the same small follow back, and none for the group', function () {
    serveApi(function (Request $request) {
        return str_contains($request->url(), '/group')
            ? Http::response(pageJson([
                notificationJson('followed', ['id' => 'n-follow-1', 'actor' => personJson('linus', 'Linus', overrides: ['is_followed' => false])]),
                notificationJson('followed', ['id' => 'n-follow-2', 'actor' => personJson('grace', 'Grace Brewster Murray Hopper', overrides: ['is_followed' => true])]),
            ]))
            : Http::response(inboxJson([notificationJson('followed', ['event_count' => 2, 'actors_count' => 2, 'actor' => personJson('linus', 'Linus', overrides: ['is_followed' => false])])]));
    });

    $screen = Native::visit('/notifications', platform: 'ios');

    // The group could only follow one of them back, so it leaves it to the list, as the web does.
    $screen->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'follow-back-linus');

    // A tap on the group lists them rather than opening the latest follower.
    $screen->tap('notification-n-followed')->assertNoNavigation()->assertSee('Grace Brewster Murray Hopper');

    foreach (['linus' => 'custom:user-plus', 'grace' => 'custom:user-check'] as $username => $icon) {
        $row = nodeByRef($screen, 'group-item-n-follow-'.($username === 'linus' ? 1 : 2));
        $box = nodeByRef($screen, 'follow-back-icon-'.$username);

        expect([(float) $box['layout']['width'], (float) $box['layout']['height']])->toBe([32.0, 32.0])
            // The follow back is the row's only icon: a follower's row has no type icon, as on the web.
            ->and(array_map(fn (array $node): string => $node['props']['name'], findNodes($row, fn (array $node): bool => ($node['type'] ?? null) === 'icon')))->toBe([$icon]);
    }

    // A second tap folds it away.
    $screen->tap('notification-n-followed')->assertDontSee('Grace Brewster Murray Hopper');
});
