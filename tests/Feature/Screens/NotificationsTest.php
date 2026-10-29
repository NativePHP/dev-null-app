<?php

use App\Devnull\Client;
use App\Devnull\Display;
use App\Devnull\ImageCache;
use App\Devnull\NotificationFilters;
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

/**
 * The text a single node shows, as one string.
 */
function nodeText(array $node): string
{
    return (string) ($node['props']['text'] ?? '');
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

it('opens a reply through each of its targets: the person from their avatar and name, the commit from what they did', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('replied', [
        'actor' => personJson('grace', 'Grace', 5),
        'actors' => [personJson('grace', 'Grace', 5)],
    ])])]]);

    Native::visit('/notifications')->tap('node-avatar-n-replied')->assertNavigatedTo('/notifications/users/grace');

    Native::visit('/notifications')->tap('actor-name-n-replied')->assertNavigatedTo('/notifications/users/grace');

    Native::visit('/notifications')->tap('action-n-replied')->assertNavigatedTo('/notifications/commits/c-replied');
});

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

it('lays a reply, a quote and a mention out as the web does: the name and handle heading the row, what they did under it, then their words', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([
        notificationJson('replied', ['commit' => notificationCommitJson(['id' => 'r1', 'parent_id' => 'c-yours', 'body' => 'Nice work.'])]),
        notificationJson('quoted', ['id' => 'n-quoted', 'commit' => notificationCommitJson(['id' => 'q1', 'body' => 'Quoting this one.'])]),
        notificationJson('mentioned', ['actor' => personJson('grace', 'Grace'), 'actors' => [personJson('grace', 'Grace')], 'commit' => notificationCommitJson(['id' => 'm1', 'body' => 'Ask @local_dev about it'])]),
        notificationJson('followed', ['created_at' => now()->subDays(2)->toIso8601ZuluString(), 'actor' => personJson('linus', 'Linus'), 'actors' => [personJson('linus', 'Linus')]]),
    ])]]);

    $screen = Native::visit('/notifications', platform: 'ios')
        ->assertNavTitle('Notifications')
        ->assertTabActive('Notifications')
        ->assertSee('Nice work.')
        ->assertSee('Quoting this one.')
        ->assertSee('Ask @local_dev about it')
        ->assertSee('2d');

    // Their name heads the row with their handle, and what they did runs under it in the web's words,
    // with the type's icon beside the time, in their lane, as the web draws it.
    $iconName = ['replied' => 'message-square-reply', 'quoted' => 'quote', 'mentioned' => 'at-sign'];

    foreach (['replied' => 'Replied to your commit', 'quoted' => 'Quoted your commit', 'mentioned' => 'Mentioned you'] as $type => $action) {
        expect(nodeText(nodeByRef($screen, 'actor-name-n-'.$type)))->toBe($type === 'mentioned' ? 'Grace' : 'Ada')
            ->and(nodeText(nodeByRef($screen, 'action-n-'.$type)))->toBe($action)
            ->and(findNode(nodeByRef($screen, 'notification-n-'.$type), fn (array $node): bool => ($node['type'] ?? null) === 'icon' && ($node['props']['name'] ?? null) === 'custom:'.$iconName[$type]))->not->toBeNull();
    }

    expect(paragraphRuns($screen, 'followed you'))->toBe([['Linus', 'link'], [' '], ['@linus', 'mention'], [' followed you']])
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

it('words a star of an image-only commit as yours, and shows its image beside it, and nothing of the commit', function () {
    $commit = [...notificationCommitJson(['id' => 'c-pictures', 'body' => ''], withParent: false), 'images' => [
        ['id' => 'img-1', 'url' => 'https://images.test/cat.jpg', 'width' => 640, 'height' => 480],
        ['id' => 'img-2', 'url' => 'https://images.test/dog.jpg', 'width' => 640, 'height' => 640],
    ]];

    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('starred', ['commit' => $commit])])]]);

    $screen = Native::visit('/notifications');

    expect(nodeText(nodeByRef($screen, 'actor-name-n-starred')))->toBe('Ada')
        ->and(paragraphRuns($screen, 'starred your images'))->toBe([['Ada', 'link'], [' '], ['@ada', 'mention'], [' starred your images']])
        // devnull's signed API loads no images today, so the thumbnail lights up only when it does.
        ->and(nodeByRef($screen, 'starred-image-n-starred')['props']['src'])->toBe(ImageCache::src('https://images.test/cat.jpg'))
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'your-commit-n-starred'))->toBeNull();
});

it('boxes the commit of yours a quote quotes when devnull sends it, a tombstone once deleted, and nothing when it does not', function (array $commit, string $ref) {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('quoted', [
        'commit' => notificationCommitJson(['id' => 'q1', 'body' => 'Quoting this one.', 'quoted_commit' => $commit]),
    ])])]]);

    $screen = Native::visit('/notifications')->assertSee('Quoting this one.');

    expect(nodeByRef($screen, $ref))->not->toBeNull();
})->with([
    'live' => [commitJson(['id' => 'c-mine', 'body' => 'The commit of mine they quoted.']), 'quoted-q1'],
    'deleted' => [tombstoneJson(), 'quoted-q1'],
]);

it('opens the quoted commit of yours from its card, not the one quoting it, and names who wrote it and when', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('quoted', [
        'commit' => notificationCommitJson(['id' => 'q1', 'body' => 'Quoting this one.', 'quoted_commit' => commitJson(['id' => 'c-mine', 'body' => 'The commit of mine they quoted.'])]),
    ])])]]);

    $screen = Native::visit('/notifications')->assertSee('The commit of mine they quoted.');

    $card = nodeByRef($screen, 'quoted-q1');

    expect(shownText($screen))->toContain('Local Dev')
        ->and(nodeByRef($screen, 'quoted-q1'))->not->toBeNull();

    $screen->tap('quoted-q1')->assertNavigatedTo('/notifications/commits/c-mine');
});

it('shows no box under a quote whose commit devnull did not load', function () {
    // The signed API loads no quoted commits today: the key itself is absent, so nothing is boxed.
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('quoted', [
        'commit' => array_diff_key(notificationCommitJson(['id' => 'q1', 'body' => 'Quoting this one.']), ['quoted_commit' => '']),
    ])])]]);

    $screen = Native::visit('/notifications')->assertSee('Quoting this one.');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'quoted-q1'))->toBeNull();
});

it('words a level-up as safely as the API allows: no level it did not send, no reply action, and your profile to open', function (array $overrides) {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('leveled_up', $overrides)])]]);

    $screen = Native::visit('/notifications')->assertSee('You reached a new level.');

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'level-chip-n-leveled_up'))->toBeNull()
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'level-description-n-leveled_up'))->toBeNull()
        // Nothing but a follow or a level-up opens a profile, and no commit is named.
        ->and(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'action-n-leveled_up'))->toBeNull();

    $screen->tap('notification-n-leveled_up')->assertNavigatedTo('/notifications/users/local_dev');
})->with([
    'the API sends no level' => [['level' => null, 'rank' => null, 'rank_description' => null]],
    'an empty rank' => [['level' => 4, 'rank' => '  ', 'rank_description' => null]],
]);

it('chips a level-up with its level and rank when devnull actually sends them', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('leveled_up', [
        'level' => 4,
        'rank' => 'Wizard',
        'rank_description' => 'Shipped five days in a row.',
    ])])]]);

    $screen = Native::visit('/notifications', platform: 'ios')->assertSee('Lv. 4 · Wizard')->assertSee('Shipped five days in a row.');

    expect(nodeByRef($screen, 'level-chip-n-leveled_up'))->not->toBeNull()
        ->and(nodeByRef($screen, 'level-description-n-leveled_up'))->not->toBeNull()
        ->and(findNode(nodeByRef($screen, 'node-n-leveled_up'), fn (array $node): bool => ($node['props']['name'] ?? null) === 'custom:chevrons-up'))->not->toBeNull();
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

    $chip = findNode(nodeByRef($screen, 'level-chip-n-leveled_up'), fn (array $node): bool => ($node['type'] ?? null) === 'text');

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
        ->assertMissingElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'level-chip-n-leveled_up')
        ->assertMissingElement('text', fn (array $node): bool => ($node['ref'] ?? null) === 'level-description-n-leveled_up');
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

    // The new one says so to a screen reader, on the icon and the avatar in its node — the only place that
    // reaches one, a pressable's own label staying unannounced — and the one read before says nothing.
    $saysNew = fn (array $node): ?string => collect(findNodes($node, fn (array $child): bool => in_array($child['type'] ?? null, ['icon', 'image'], true)
        && (string) ($child['props']['a11y_label'] ?? $child['props']['alt'] ?? '') !== ''))->map(
            fn (array $child): string => (string) ($child['props']['a11y_label'] ?? $child['props']['alt']),
        )->first();

    expect($saysNew(nodeByRef($screen, 'notification-n-mentioned')))->toBe('New')
        ->and($saysNew(nodeByRef($screen, 'node-n-mentioned')))->toBe('New')
        ->and($saysNew(nodeByRef($screen, 'notification-n-followed')))->toBeNull()
        ->and($saysNew(nodeByRef($screen, 'node-n-followed')))->toBeNull();
});

it('opens what the web opens for each kind', function (string $type, string $destination) {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson($type)])]]);

    Native::visit('/notifications')->tap('notification-n-'.$type)->assertNavigatedTo($destination);
})->with([
    ['replied', '/notifications/commits/c-replied'],
    ['quoted', '/notifications/commits/c-quoted'],
    ['starred', '/notifications/commits/c-starred'],
    ['mentioned', '/notifications/commits/c-mentioned'],
    ['approved', '/notifications/commits/c-approved'],
    ['rejected', '/notifications/commits/c-rejected'],
    ['followed', '/notifications/users/ada'],
    ['leveled_up', '/notifications/users/local_dev'],
]);

it("has the web's filters, several on at once, each asked for by its type with all asked for plainly", function () {
    serveApi(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $type = $query['type'] ?? 'all';

        return str_starts_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/notifications')
            ? Http::response(inboxJson($type === 'none' ? [] : (str_contains($type, 'follows') ? [notificationJson('followed')] : [notificationJson('starred')]), $type))
            : Http::response(['data' => userWithCountsJson(), 'unread_notifications_count' => 0]);
    });

    $screen = Native::visit('/notifications')->assertSee('starred your commit');

    // The three chips the web's phone layout shows, and its More button for the rest.
    foreach ([...Notifications::QuickFilters, 'more'] as $ref) {
        expect(nodeByRef($screen, 'filter-'.$ref))->not->toBeNull();
    }

    // Several on at once, tapped in any order, sent as one canonical comma-separated type.
    tapFilter($screen, 'replies');
    tapFilter($screen, 'quotes');
    $screen->assertSet('type', 'stars,mentions,follows,moderation')->assertSee('followed you')->assertDontSee('starred your commit');

    // The More sheet carries the rest, and stays open across a tap, as the web's menu does.
    $screen->tap('filter-more');
    expect(nodeByRef($screen, 'filters-sheet')['props'])->toMatchArray(['visible' => true]);
    tapFilter($screen, 'follows');
    $screen->assertSet('type', 'stars,mentions,moderation')->assertDontSee('followed you');

    // Every one off asks for none of them.
    tapFilter($screen, 'stars')->tap('filter-mentions')->tap('filter-moderation')->assertSet('type', 'none');

    $urls = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/notifications'))->map(fn (array $pair): string => $pair[0]->url())->values()->all();

    expect($urls)->toContain(
        'http://devnull.test/api/v1/notifications',
        'http://devnull.test/api/v1/notifications?type=stars%2Cmentions%2Cfollows%2Cmoderation',
        'http://devnull.test/api/v1/notifications?type=stars%2Cmentions%2Cmoderation',
        'http://devnull.test/api/v1/notifications?type=none',
    );
});

it('keeps the selection on for the account, and opens on it at once', function () {
    holdRefreshes();
    keep('notifications', inboxJson([notificationJson('followed')]), ['type' => null]);
    keep('notifications', inboxJson([notificationJson('mentioned')], 'mentions'), ['type' => 'mentions']);
    serveApi(fn () => Http::response(inboxJson([])));

    $screen = Native::visit('/notifications')->assertSee('followed you');
    $requests = count(Http::recorded());

    // Mentions alone, as a link from another screen would ask for it.
    $screen->call('show', 'mentions')
        ->assertSee('mentioned you')
        ->assertDontSee('followed you');

    expect(showsLoading(showingNotifications($screen)))->toBeFalse()
        ->and(count(Http::recorded()))->toBe($requests)
        ->and(kept('notifications', ['type' => 'mentions']))->not->toBeNull()
        ->and(kept('notifications', ['type' => 'stars']))->toBeNull();

    // Opening again is on what was kept, with nothing asked yet.
    holdRefreshes();

    $screen = Native::visit('/notifications')->assertSee('mentioned you')->assertSet('enabled', ['mentions']);

    expect(showsLoading(showingNotifications($screen)))->toBeFalse()
        ->and(count(Http::recorded()))->toBe(0);
});

it("says what each filter shows when it's empty, in the web's words, with a way back to all of them", function (string $type) {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([])]]);

    $screen = Native::test(Notifications::class)->call('show', $type)
        ->assertSee($type === 'all' ? '$ cat /dev/notifications' : '$ cat /dev/notifications | grep -E \''.$type.'\'')
        ->assertSee($type === 'all' ? Notifications::AllEmpty : Notifications::Categories[$type][1]);

    if ($type !== 'all') {
        $screen->assertSee('Show all notifications')->tap('show-all')->assertSet('type', 'all');
    }
})->with(['all', ...array_keys(Notifications::Categories)]);

it("says what's on when several are and neither is everything, and when nothing is", function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([])]]);

    Native::test(Notifications::class)
        ->call('show', 'replies,quotes')
        ->assertSee('$ cat /dev/notifications | grep -E \'replies|quotes\'')
        ->assertSee(Notifications::CombinedEmpty)
        ->assertSee('Show all notifications');

    Native::test(Notifications::class)
        ->call('show', 'none')
        ->assertSee('$ cat /dev/notifications | grep -E \'^$\'')
        ->assertSee(Notifications::NoneEmpty)
        ->assertSee('Show all notifications')
        ->tap('show-all')
        ->assertSee('$ cat /dev/notifications')
        ->assertSee(Notifications::AllEmpty)
        ->assertDontSee('Show all notifications');
});

it('starts the list over when the selection changes, and holds it while the list refreshes and pages', function () {
    holdRefreshes();
    keep('notifications', inboxJson([notificationJson('followed')], cursor: 'next'), ['type' => null]);
    keep('notifications', inboxJson([notificationJson('replied')], 'replies'), ['type' => 'replies']);
    serveApi(function (Request $request) {
        return str_contains($request->url(), 'cursor=next')
            ? Http::response(inboxJson([notificationJson('starred')]))
            : Http::response(inboxJson([notificationJson('followed')], cursor: 'next'));
    });

    $screen = Native::visit('/notifications');
    $all = showingNotifications($screen)['id'];

    // A page under the list keeps its key, so its rows keep their identity.
    finishRefreshes($screen);
    $screen->call('loadMore');
    expect(showingNotifications($screen)['id'])->toBe($all)
        ->and($screen->instance()->notifications)->toHaveCount(2);

    // A new selection starts a fresh list at the top.
    tapFilter($screen, 'replies');
    expect(showingNotifications($screen)['id'])->not->toBe($all)
        ->and($screen->instance()->listKey)->toBeGreaterThan(0);
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

    // A star in the list opens the person who gave it, as on the web; a reply would open the commit.
    $screen->tap('group-item-n-star-1')->assertNavigatedTo('/notifications/users/ada');

    Native::visit('/notifications')->tap('group-n-starred')->tap('group-n-starred')->assertSee('2 stars')->assertDontSee('Hide stars');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/notifications/n-starred/group');
});

it('opens a group with the selection it was listed under, and pages its older ones', function () {
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

it('opens a group kept before at once, and keeps groups per selection', function () {
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

it('draws the filters and rows on Android as on iOS', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([
        notificationJson('replied', ['actor' => personJson('grace', 'Grace', 5), 'actors' => [personJson('grace', 'Grace', 5)]]),
        notificationJson('starred', ['event_count' => 2, 'actor' => personJson('ada', 'Ada'), 'actors' => [personJson('ada', 'Ada'), personJson('linus', 'Linus')]]),
    ])]]);

    $screen = Native::visit('/notifications', platform: 'android');

    expect(nodeByRef($screen, 'filter-replies'))->not->toBeNull()
        ->and(nodeByRef($screen, 'action-n-replied'))->not->toBeNull()
        ->and(nodeByRef($screen, 'node-n-replied'))->not->toBeNull()
        ->and($screen->assertSee('Replied to your commit')->assertSee('2 stars'));
});

it('shows the first image beside any starred commit that has images, with its words still boxed as yours', function () {
    $commit = [...notificationCommitJson(['id' => 'c-shipped', 'body' => 'Shipped the pictures.'], withParent: false), 'images' => [
        ['id' => 'img-1', 'url' => 'https://images.test/cat.jpg', 'width' => 640, 'height' => 480],
    ]];

    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('starred', ['commit' => $commit])])]]);

    $screen = Native::visit('/notifications');

    expect(paragraphRuns($screen, 'starred your commit'))->toBe([['Ada', 'link'], [' '], ['@ada', 'mention'], [' starred your commit']])
        ->and(nodeByRef($screen, 'starred-image-n-starred')['props']['src'])->toBe(ImageCache::src('https://images.test/cat.jpg'))
        ->and(nodeByRef($screen, 'your-commit-n-starred'))->not->toBeNull();
});

it('draws the filters as chips that carry their own selected state, which a screen reader says', function () {
    fakeApi(['GET /api/v1/notifications' => [inboxJson([notificationJson('followed')])]]);

    $screen = Native::visit('/notifications', platform: 'ios');

    expect(nodeByRef($screen, 'filter-replies')['type'])->toBe('chip')
        ->and(nodeByRef($screen, 'filter-replies')['props'])->toMatchArray(['label' => 'Replies', 'value' => true, 'a11y_label' => 'Replies notifications'])
        // Everything is on at first, so the More chip's filters are on with it.
        ->and(nodeByRef($screen, 'filter-more')['props'])->toMatchArray(['a11y_label' => 'More notification filters', 'value' => true]);

    tapFilter($screen, 'replies');

    expect(nodeByRef($screen, 'filter-replies')['props']['value'])->toBeFalse();

    // The More sheet's lines are chips of their own, saying which are on.
    $screen->tap('filter-more');

    expect(nodeByRef($screen, 'filter-mentions')['props'])->toMatchArray(['label' => 'Mentions', 'value' => true, 'a11y_label' => 'Mentions notifications']);

    tapFilter($screen, 'mentions');

    expect(nodeByRef($screen, 'filter-mentions')['props']['value'])->toBeFalse()
        ->and(nodeByRef($screen, 'filter-more')['props']['value'])->toBeTrue();
});

it('changes nothing when asked to show something that names no selection', function (string $bogus) {
    holdRefreshes();
    keep('notifications', inboxJson([notificationJson('followed')]), ['type' => null]);
    serveApi(fn () => Http::response(inboxJson([notificationJson('starred')])));

    $screen = Native::visit('/notifications');
    $list = showingNotifications($screen)['id'];
    $requests = count(Http::recorded());

    $screen->call('show', $bogus);

    expect($screen->instance()->type)->toBe('all')
        ->and($screen->instance()->enabled)->toBe(array_keys(Notifications::Categories))
        ->and(showingNotifications($screen)['id'])->toBe($list)
        ->and($screen->instance()->listKey)->toBe(0)
        ->and(count(Http::recorded()))->toBe($requests)
        // Nothing was kept for the account, and nothing remembered as none of them.
        ->and(kept('notifications', ['type' => 'none']))->toBeNull()
        ->and(app(NotificationFilters::class)->type('0199e5c4-0000-7000-8000-000000000001'))->toBe('all');
})->with([
    'an unknown word' => ['bogus'],
    'nothing' => [''],
    'a list with an unknown word in it' => ['stars,bogus'],
    'a trailing comma' => ['stars,'],
    'all among the categories' => ['all,stars'],
]);
