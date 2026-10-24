<?php

use App\Devnull\Account;
use App\Devnull\Data\Commit;
use App\Devnull\ErrorCode;
use App\Devnull\LastVisit;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Log;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\System;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

it('shows the following log in tab chrome with a compose action', function () {
    Http::fake(['devnull.test/api/v1/log/following' => Http::response(pageJson([
        commitJson(['id' => 'c2', 'body' => 'Second commit', 'replies_count' => 2]),
        commitJson(['id' => 'c1', 'body' => 'First commit', 'author' => userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada'])]),
    ]))]);

    Native::visit('/log')
        ->assertHasTabBar()
        ->assertTabActive('Log')
        ->assertHasTab('Notifications')
        ->assertHasTab('Profile')
        // The bar wears the logo; the title names it for VoiceOver and the back menu.
        ->assertNavTitle('devnull')
        // The web's page tabs, which the pager draws: Following, Latest and Trending, on Following.
        ->assertElement('pager', fn (array $node): bool => ($node['props']['tabs'] ?? null) === ['Following', 'Latest', 'Trending'] && ($node['props']['page'] ?? null) === 0)
        ->assertSee('Second commit')
        ->assertSee('First commit')
        ->assertSee('@ada')
        ->assertSee('2 replies')
        ->assertSee('5m')
        ->assertSee("That's the root commit. There's nothing before it.");
});

it('colours each node and segment by the lane the author picked', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([
        commitJson(['id' => 'c2', 'author' => userJson(['id' => 'local_dev', 'lane' => 2])]),
        commitJson(['id' => 'c1', 'author' => userJson(['id' => '1', 'lane' => 4])]),
    ]))]);

    $light = config('native-ui.theme.light');
    $dark = config('native-ui.theme.dark');

    // Hashing those ids would give lanes 5 and 1, so these come from user.lane.
    Native::visit('/log')
        ->assertElement('stack', fn (array $node): bool => ($node['style']['bg_color'] ?? null) === $light['lane-2']
            && ($node['props']['dark_bg_color'] ?? null) === $dark['lane-2'])
        ->assertElement('stack', fn (array $node): bool => ($node['style']['bg_color'] ?? null) === $light['lane-4'])
        ->assertMissingElement('stack', fn (array $node): bool => ($node['style']['bg_color'] ?? null) === $light['lane-5']);
});

it('falls back to hashing the id when the server sends no lane', function () {
    $user = userJson(['id' => 'local_dev']);
    unset($user['lane']);

    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['author' => $user])]))]);

    // local_dev hashes to lane 5, as on the web before lanes could be picked.
    Native::visit('/log')
        ->assertElement('stack', fn (array $node): bool => ($node['style']['bg_color'] ?? null) === config('native-ui.theme.light.lane-5'));
});

it('switches to Latest', function () {
    Http::fake([
        'devnull.test/api/v1/log/following' => Http::response(pageJson([])),
        'devnull.test/api/v1/log/latest' => Http::response(pageJson([commitJson(['body' => 'Hello from everyone'])])),
    ]);

    tapLogTab(Native::visit('/log')->assertSee('fatal: your log does not have any commits yet'), 'latest')
        ->assertSet('log', 'latest')
        ->assertSee('Hello from everyone');
});

it('uses the git voice when Latest or Trending is empty', function (string $log) {
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    Native::test(Log::class)
        ->call('show', $log)
        ->assertSee('fatal: no commits yet')
        ->assertSee("Nobody has committed anything. Be the first. It's just plain text; you'll be fine.");
})->with(['latest', 'trending']);

it('loads the next page by cursor', function () {
    Http::fake(function (Request $request) {
        return str_contains($request->url(), 'cursor=page2')
            ? Http::response(pageJson([commitJson(['id' => 'c1', 'body' => 'Older commit'])]))
            : Http::response(pageJson([commitJson(['id' => 'c2', 'body' => 'Newer commit'])], 'page2'));
    });

    Native::visit('/log')
        ->assertSee('Newer commit')
        ->assertDontSee('Older commit')
        ->assertElement('row', fn (array $node): bool => ($node['ref'] ?? null) === 'loading-more')
        ->call('loadMore')
        ->assertSee('Older commit')
        ->assertSet('nextCursor', null);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/log/following?cursor=page2');
});

it('reloads on pull to refresh', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([]))]);

    Native::visit('/log')->call('load');

    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'http://devnull.test/api/v1/log/following'))->toHaveCount(2);
});

it('marks pending commits', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['status' => 'pending', 'body' => 'Waiting on a mod'])]))]);

    Native::visit('/log')->assertSee('Waiting on a mod')->assertSee('pending');
});

it('opens commits and profiles', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1'])]))]);

    Native::visit('/log')->tap('commit-c1')->assertNavigatedTo('/log/commits/c1');
    Native::visit('/log')->call('openProfile', 'ada')->assertNavigatedTo('/log/users/ada');
});

it('sends you back to onboarding with the server message on a 401', function () {
    Http::fake(['devnull.test/*' => Http::response(['message' => 'This key is not on any devnull account.', 'code' => 'key_not_on_github'], 401)]);

    $screen = Native::visit('/log')->assertReplacedWith('/onboarding');

    expect($screen->navigationIntent()->data)->toBe(['message' => 'This key is not on any devnull account.'])
        ->and(app(Account::class)->user())->toBeNull();
});

it('words a bad signature by its code when it signs you out', function () {
    Http::fake(['devnull.test/*' => Http::response(['message' => 'The /dev/null signature is invalid.', 'code' => 'invalid_signature'], 401)]);

    $screen = Native::visit('/log')->assertReplacedWith('/onboarding');

    expect($screen->navigationIntent()->data)->toBe(['message' => ErrorCode::InvalidSignature->message()]);
});

it('sends an unapproved phone back to onboarding to sign in and approve it', function () {
    Http::fake(['devnull.test/*' => Http::response(['message' => "This key isn't approved for the devnull app yet.", 'code' => 'device_not_approved'], 401)]);

    $screen = Native::visit('/log')->assertReplacedWith('/onboarding');

    expect($screen->navigationIntent()->data)->toBe(['message' => ErrorCode::DeviceNotApproved->message()])
        ->and(app(Account::class)->user())->toBeNull();
});

it('prints other errors and keeps the screen', function () {
    Http::fake(['devnull.test/*' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

    Native::visit('/log')->assertSee('Too Many Attempts.')->assertNoNavigation();
});

it('links mentions to profiles, counting code points so emoji before them do not shift them', function () {
    // Each "🚀" is one code point, two UTF-16 units and four bytes.
    $body = '🚀🚀 pairing with @ada and @Grace-H today';

    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'body' => $body,
        'mentions' => [
            ['username' => 'ada', 'offset' => 16, 'length' => 4],
            ['username' => 'Grace-H', 'offset' => 25, 'length' => 8],
        ],
    ])]))]);

    $screen = Native::visit('/log');

    expect(paragraphRuns($screen, 'pairing with'))->toBe([
        ['🚀🚀 pairing with '],
        ['@ada', 'mention'],
        [' and '],
        ['@Grace-H', 'mention'],
        [' today'],
    ]);

    $screen->press("openProfile('Grace-H')")->assertNavigatedTo('/log/users/Grace-H');
});

it('shows inline code on a wash and keeps mentions on both sides of it', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'body' => '@ada ran `composer test` for @bob',
        'body_segments' => [
            ['type' => 'text', 'text' => '@ada ran '],
            ['type' => 'code', 'code' => 'composer test'],
            ['type' => 'text', 'text' => ' for @bob'],
        ],
        'mentions' => [['username' => 'ada', 'offset' => 0, 'length' => 4], ['username' => 'bob', 'offset' => 29, 'length' => 4]],
    ])]))]);

    expect(paragraphRuns(Native::visit('/log'), 'composer test'))->toBe([
        ['@ada', 'mention'],
        [' ran '],
        ['composer test', 'code'],
        [' for '],
        ['@bob', 'mention'],
    ]);
});

it('draws a standalone /dev/null as the wordmark: semibold, tight, its middle slash a lane-0 outline, held on one line', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['body' => 'Piped to /dev/null, not /dev/nullable (/dev/null)'])]))]);

    $screen = Native::visit('/log');
    $wordmarks = findNodes($screen->tree(), fn (array $node): bool => ($node['props']['letter_spacing'] ?? null) === -0.025);
    $slash = $wordmarks[0]['children'][1]['props'];

    expect(paragraphRuns($screen, 'Piped to'))->toBe([['Piped to '], ['/dev/null', 'wordmark'], [', not /dev/nullable ('], ['/dev/null', 'wordmark'], [')']])
        ->and(array_map(fn (array $wordmark): array => array_map(fn (array $run): string => $run['props']['text'], $wordmark['children']), $wordmarks))->toBe([
            ["\u{200B}/\u{2060}dev", '/', "\u{2060}null"],
            ["/\u{2060}dev", '/', "\u{2060}null"],
        ])
        ->and($wordmarks[0]['props'])->toMatchArray(['font_name' => 'semibold', 'font_weight' => 5])
        ->and($slash)->toMatchArray(['text_outline' => 1.0])->not->toHaveKey('font_name')
        ->and(strtolower($slash['color']))->toBe(strtolower(config('native-ui.theme.light.lane-0')))
        ->and(strtolower($slash['dark_color']))->toBe(strtolower(config('native-ui.theme.dark.lane-0')));
});

it('links URLs in Commit Blue, opening a dev/null commit here and anything else in the browser', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'body' => 'Read https://www.example.com/a-long-path-to-the-release-notes, then https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8 and github.com/laravel/framework/pull/42',
    ])]))]);

    $screen = Native::visit('/log');
    $primary = strtolower(config('native-ui.theme.light.primary'));
    $link = findNode($screen->tree(), fn (array $node): bool => ($node['props']['text'] ?? null) === 'example.com/a-long-path-to-the…');
    $repository = findNode($screen->tree(), fn (array $node): bool => ($node['children'][0]['props']['text'] ?? null) === 'laravel/');

    expect(paragraphRuns($screen, 'Read'))->toBe([
        ['Read '],
        ['example.com/a-long-path-to-the…', 'link'],
        [', then '],
        ['Hacksore/5149ca8', 'link'],
        [' and '],
        ['laravel/framework#42', 'link'],
    ])
        ->and(strtolower($link['props']['color']))->toBe($primary)
        ->and($repository['props']['font_name'])->toBe('mono')
        ->and(strtolower($repository['props']['color']))->toBe($primary)
        ->and(strtolower($repository['children'][0]['props']['color']))->toBe(strtolower(config('native-ui.theme.light.ink-muted')));

    $screen->press("openLink('c1', 0)")
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://www.example.com/a-long-path-to-the-release-notes')
        ->press("openLink('c1', 2)")
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/laravel/framework/pull/42')
        ->assertNoNavigation()
        ->press("openLink('c1', 1)")
        ->assertNavigatedTo('/log/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8');
});

it('shows a link preview as a compact row and opens it in the browser', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'body' => 'Read https://laravel.com/docs?a=1&b=2',
        'link_preview' => [
            'url' => 'https://laravel.com/docs?a=1&b=2',
            'title' => 'Laravel - The PHP Framework',
            'description' => 'Laravel is a web application framework.',
            'image_url' => 'https://laravel.com/og.png',
            'site_name' => null,
        ],
    ])]))]);

    Native::visit('/log')
        ->assertSee('laravel.com')
        ->assertSee('Laravel - The PHP Framework')
        ->assertSee('Laravel is a web application framework.')
        ->assertElement('image', fn (array $node): bool => ($node['props']['src'] ?? null) === 'https://laravel.com/og.png')
        ->tap('link-preview-c1')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://laravel.com/docs?a=1&b=2')
        ->assertNoNavigation();
});

it('waits for a link preview to have a title, as the web does', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'link_preview' => ['url' => 'https://example.com', 'title' => null, 'description' => null, 'image_url' => null, 'site_name' => null],
    ])]))]);

    Native::visit('/log')->assertMissingElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'link-preview-c1');
});

it('holds images at their aspect ratio, so one that never loads leaves the row intact', function () {
    // Your own pending commit's images 404 for now. The space is held on a wash either way.
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'status' => 'pending',
        'body' => 'Look at this',
        'images' => [['id' => 'i1', 'url' => 'http://devnull.test/commit-images/i1', 'width' => 640, 'height' => 480]],
    ])]))]);

    Native::visit('/log')
        ->assertSee('Look at this')
        ->assertSee('pending')
        ->assertElement('column', fn (array $node): bool => abs(($node['layout']['aspect_ratio'] ?? 0) - 640 / 480) < 0.001
            && ($node['style']['bg_color'] ?? null) === config('native-ui.theme.light.wash'))
        ->assertElement('image', fn (array $node): bool => ($node['props']['src'] ?? null) === 'http://devnull.test/commit-images/i1')
        ->tap('image-i1')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/commit-images/i1');
});

it('lays out several images in the web grid', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson([
        'id' => 'c1',
        'images' => [
            ['id' => 'i1', 'url' => 'https://img.test/1', 'width' => 10, 'height' => 10],
            ['id' => 'i2', 'url' => 'https://img.test/2', 'width' => 10, 'height' => 10],
            ['id' => 'i3', 'url' => 'https://img.test/3', 'width' => 10, 'height' => 10],
        ],
    ])]))]);

    Native::visit('/log')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'image-i3' && ($node['a11y']['label'] ?? $node['props']['a11y_label'] ?? null) !== null)
        ->tap('image-i3')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://img.test/3');
});

it('reads each image out by the description its author gave it, as the web does with alt text', function (array $images, array $labels) {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1', 'images' => $images])]))]);

    $screen = Native::visit('/log');

    foreach ($labels as $id => $label) {
        $image = findNode(nodeByRef($screen, "image-{$id}"), fn (array $node): bool => ($node['type'] ?? null) === 'image');

        expect(nodeByRef($screen, "image-{$id}")['props']['a11y_label'])->toBe($label)
            ->and($image['props']['alt'] ?? $image['a11y']['label'] ?? $image['props']['a11y_label'] ?? null)->toBe($label);
    }
})->with([
    'one, described' => [
        [['id' => 'i1', 'url' => 'https://img.test/1', 'description' => '  A cat on a keyboard  ']],
        ['i1' => 'A cat on a keyboard'],
    ],
    'one, not described' => [
        [['id' => 'i1', 'url' => 'https://img.test/1', 'description' => null]],
        ['i1' => 'Attached image'],
    ],
    'several, some described' => [
        [
            ['id' => 'i1', 'url' => 'https://img.test/1', 'description' => 'The before'],
            ['id' => 'i2', 'url' => 'https://img.test/2', 'description' => ''],
        ],
        ['i1' => 'The before, image 1 of 2', 'i2' => 'Attached image 2 of 2'],
    ],
]);

it('puts the web action row under each commit: the star, Quote, Reply and replies, then More', function () {
    $ada = userJson(['id' => 'u2', 'username' => 'ada']);

    Http::fake(['devnull.test/*' => Http::response(pageJson([
        commitJson(['id' => 'c2', 'stars_count' => 3, 'starred' => true, 'replies_count' => 1, 'author' => $ada]),
        commitJson(['id' => 'c1', 'stars_count' => 1, 'starred' => false, 'author' => $ada]),
        commitJson(['id' => 'c0', 'status' => 'pending', 'author' => $ada]),
    ]))]);

    $primary = config('native-ui.theme.light.primary');
    $muted = config('native-ui.theme.light.ink-muted');
    $pressable = fn (string $ref, string $label): Closure => fn (array $node): bool => ($node['ref'] ?? null) === $ref && ($node['props']['a11y_label'] ?? null) === $label;

    $screen = Native::visit('/log', platform: 'ios')
        ->assertSee('1 reply')
        ->assertElement('pressable', $pressable('star-c2', 'Unstar commit, 3 stars'))
        ->assertElement('pressable', $pressable('star-c1', 'Star commit, 1 star'))
        ->assertElement('pressable', $pressable('more-c2', 'More'))
        // Someone else's pending commit can't be starred or quoted, so the web leaves them off.
        ->assertMissingElement('pressable', fn (array $node): bool => in_array($node['ref'] ?? null, ['star-c0', 'quote-c0'], true));

    // The web's order: star, Quote, Reply and replies on the left, then More for Share link and Report.
    expect(array_values(array_filter(childRefs($screen, 'actions-c2'))))->toBe(['star-c2', 'quote-c2', 'reply-to-c2', 'replies-c2', 'more-c2'])
        // Nor can it be reported, so all its menu has is the link.
        ->and(menuItems($screen, 'more-c0'))->toBe([['share-c0', 'Share link', 'custom:link']]);

    $icon = fn (string $ref): ?array => findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['type'] ?? null) === 'icon');
    $text = fn (string $ref, string $text): ?array => findNode(nodeByRef($screen, $ref), fn (array $node): bool => ($node['props']['text'] ?? null) === $text);

    expect($icon('star-c2')['props']['name'] ?? null)->toBe('custom:star-filled')
        ->and($icon('star-c2')['props']['color'] ?? null)->toBe($primary)
        ->and($text('star-c2', '3')['props']['color'] ?? null)->toBe($primary)
        ->and($icon('star-c1')['props']['name'] ?? null)->toBe('custom:star')
        ->and($icon('star-c1')['props']['color'] ?? null)->toBe($muted)
        ->and($text('star-c1', '1')['props']['color'] ?? null)->toBe($muted)
        ->and($icon('reply-to-c1')['props']['name'] ?? null)->toBe('custom:message-square-reply')
        ->and($icon('replies-c2')['props']['name'] ?? null)->toBe('chevron.right')
        ->and($text('replies-c2', '1 reply')['props']['color'] ?? null)->toBe($primary)
        ->and($icon('more-c1')['props']['name'] ?? null)->toBe('custom:ellipsis')
        ->and($icon('more-c1')['props']['color'] ?? null)->toBe($muted);

    // Reply opens the commit with its reply composer focused.
    $screen->tap('reply-to-c1')->assertNavigatedTo('/log/commits/c1');
    expect($screen->navigationIntent()->data['reply'])->toBeTrue()
        ->and($screen->navigationIntent()->data['commit']->id)->toBe('c1');
});

it('makes Quote a button, which opens the quote sheet', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'username' => 'ada'])])]))]);

    $screen = Native::visit('/log', platform: 'ios');
    $quote = nodeByRef($screen, 'quote-c1');

    expect($quote['type'])->toBe('pressable')
        ->and($quote['style']['opacity'] ?? $quote['props']['opacity'] ?? null)->toBeNull()
        ->and($quote['props']['a11y_label'] ?? null)->toBe('Quote')
        ->and(findNode($quote, fn (array $node): bool => ($node['type'] ?? null) === 'icon')['props']['name'] ?? null)->toBe('custom:quote');

    $screen->tap('quote-c1')->assertSee('Quote @ada');
});

it('lets you star your own commits including those waiting for approval', function (string $status, string $platform) {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([
            commitJson(['id' => 'c2', 'status' => $status, 'stars_count' => 2]),
            commitJson(['id' => 'c1', 'status' => $status, 'stars_count' => 0]),
        ])],
        'POST /api/v1/commits/c2/star' => [['data' => commitJson(['id' => 'c2', 'status' => $status, 'stars_count' => 3, 'starred' => true])]],
    ]);

    $screen = Native::visit('/log', platform: $platform)
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'star-c2' && ($node['props']['a11y_label'] ?? null) === 'Star commit, 2 stars')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'star-c1' && ($node['props']['a11y_label'] ?? null) === 'Star commit')
        ->tap('star-c2');

    expect($screen->instance()->commits[0]->starred)->toBeTrue()
        ->and($screen->instance()->commits[0]->starsCount)->toBe(3);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/c2/star'));
})->with(['approved', 'pending'])->with(['ios', 'android']);

it('stars and unstars from the row, taking the count devnull sends back', function () {
    $ada = userJson(['id' => 'u2', 'username' => 'ada']);

    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'stars_count' => 1, 'author' => $ada])])],
        'POST /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'stars_count' => 5, 'starred' => true, 'author' => $ada])]],
        'DELETE /api/v1/commits/c1/star' => [['data' => commitJson(['id' => 'c1', 'stars_count' => 4, 'starred' => false, 'author' => $ada])]],
    ]);

    $screen = Native::visit('/log')->tap('star-c1');

    expect($screen->instance()->commits[0]->starred)->toBeTrue()
        ->and($screen->instance()->commits[0]->starsCount)->toBe(5);

    $screen->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'star-c1' && ($node['props']['a11y_label'] ?? null) === 'Unstar commit, 5 stars')
        ->tap('star-c1');

    expect($screen->instance()->commits[0]->starred)->toBeFalse()
        ->and($screen->instance()->commits[0]->starsCount)->toBe(4);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://devnull.test/api/v1/commits/c1/star');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://devnull.test/api/v1/commits/c1/star');
});

it('puts the star back when devnull says no', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'stars_count' => 1, 'author' => userJson(['id' => 'u2', 'username' => 'ada'])])])],
        'POST /api/v1/commits/c1/star' => [['message' => 'Too Many Attempts.'], 429],
    ]);

    $screen = Native::visit('/log')->tap('star-c1')->assertSee('Too Many Attempts.');

    expect($screen->instance()->commits[0]->starred)->toBeFalse()
        ->and($screen->instance()->commits[0]->starsCount)->toBe(1);
});

it('leaves bookmarks out of More even when an old cached commit has bookmark state', function () {
    fakeApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'is_saved' => true])])]]);

    $screen = Native::visit('/log', platform: 'ios');
    expect(menuItems($screen, 'more-c1'))->toBe([
        ['share-c1', 'Share link', 'custom:link'],
        ['pin-c1', 'Pin to profile', 'pin'],
        'divider',
        ['delete-c1', 'Delete commit', 'custom:trash-2'],
    ])
        ->and(method_exists($screen->instance(), 'toggleBookmark'))->toBeFalse();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/bookmark'));
});

it("leaves the star alone on someone else's pending commit", function () {
    fakeApi(['GET /api/v1/log/following' => [pageJson([commitJson(['id' => 'c1', 'status' => 'pending', 'author' => userJson(['id' => 'u2', 'username' => 'ada'])])])]]);

    Native::visit('/log')->call('toggleStar', 'c1');

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('opens a thread in place, as the web does, and closes it again', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([
            commitJson(['id' => 'c2', 'replies_count' => 2]),
            commitJson(['id' => 'c1']),
        ])],
        'GET /api/v1/commits/c2/replies' => [pageJson([
            replyJson(['id' => 'r1', 'parent_id' => 'c2', 'body' => 'First reply in place', 'author' => userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada'])]),
            tombstoneJson(['id' => 'r2', 'parent_id' => 'c2', 'replies_count' => 0]),
        ])],
        'POST /api/v1/commits/r1/star' => [['data' => commitJson(['id' => 'r1', 'stars_count' => 1, 'starred' => true])]],
    ]);

    $screen = Native::visit('/log', platform: 'ios')
        ->assertDontSee('First reply in place')
        ->tap('replies-c2')
        ->assertNoNavigation()
        ->assertSee('First reply in place')
        ->assertSee('This commit was deleted.')
        ->assertSee('Hide replies');

    expect(findNode(nodeByRef($screen, 'replies-c2'), fn (array $node): bool => ($node['type'] ?? null) === 'icon')['props']['name'] ?? null)->toBe('chevron.down');

    // Its replies get the same actions, and open their own page.
    $screen->tap('star-r1');
    expect($screen->instance()->threads['c2'][0]->starred)->toBeTrue();

    $screen->tap('replies-c2')->assertDontSee('First reply in place')->assertSee('2 replies');

    Native::visit('/log')->tap('replies-c2')->tap('thread-reply-r1')->assertNavigatedTo('/log/commits/r1');
});

it('closes a thread whose replies have all gone', function () {
    $repliesGone = false;

    Http::fake(function (Request $request) use (&$repliesGone) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/v1/log/following' => Http::response(pageJson([commitJson(['id' => 'c1', 'replies_count' => $repliesGone ? 0 : 1])])),
            '/api/v1/commits/c1/replies' => Http::response(pageJson($repliesGone ? [] : [replyJson(['id' => 'r1', 'parent_id' => 'c1'])])),
            default => Http::response(['message' => 'No fake.'], 404),
        };
    });

    $screen = Native::visit('/log')->tap('replies-c1');
    expect($screen->instance()->threads)->toHaveKey('c1');

    $repliesGone = true;
    $screen->call('load');

    expect($screen->instance()->threads)->toBe([]);
});

it('shares the commit link devnull sends through the share sheet, which has Copy', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson(['id' => 'c1', 'html_url' => 'https://dev-null.sh/@local_dev/commits/c1'])]))]);

    Native::visit('/log')
        ->tap('share-c1')
        ->assertNativeCalled('Share.Url', fn (array $params): bool => $params['url'] === 'https://dev-null.sh/@local_dev/commits/c1')
        ->assertNoNavigation();
});

it('shows an author once per run, and starts a new run at each day', function () {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
    $ada = userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada', 'lane' => 1]);

    Http::fake(['devnull.test/*' => Http::response(pageJson([
        commitJson(['id' => 'c5', 'author' => $ada, 'created_at' => '2026-10-15T11:00:00Z']),
        commitJson(['id' => 'c4', 'author' => $ada, 'created_at' => '2026-10-15T10:00:00Z']),
        commitJson(['id' => 'c3', 'author' => $ada, 'created_at' => '2026-10-15T09:00:00Z']),
        commitJson(['id' => 'c2', 'created_at' => '2026-10-15T08:00:00Z']),
        commitJson(['id' => 'c1', 'created_at' => '2026-10-14T20:00:00Z']),
        commitJson(['id' => 'c0', 'created_at' => '2026-10-13T20:00:00Z']),
    ]))]);

    $screen = Native::visit('/log');

    expect(railNode($screen, 'commit-c5'))->toBe('avatar')
        ->and(railNode($screen, 'commit-c4'))->toBe('dot')
        ->and(railNode($screen, 'commit-c3'))->toBe('dot')
        ->and(railNode($screen, 'commit-c2'))->toBe('avatar')
        // Same author as the commit above it, but a new day.
        ->and(railNode($screen, 'commit-c1'))->toBe('avatar')
        ->and(railNode($screen, 'commit-c0'))->toBe('avatar')
        ->and(dayMarkers($screen))->toBe(['Today', 'Yesterday', 'Tue, Oct 13']);

    $json = json_encode($screen->tree());
    expect(strpos($json, 'day-Yesterday'))->toBeGreaterThan(strpos($json, 'commit-c2'))
        ->and(strpos($json, 'day-Yesterday'))->toBeLessThan(strpos($json, 'commit-c1'));
});

it('marks where your last visit ended, which also starts a new run', function () {
    // At midday, so a commit from a couple of hours ago is always today's and no
    // day marker splits the run. The fixtures and the assertions both use now(),
    // so it mustn't tick between them.
    $this->travelTo(now()->setTime(12, 0));
    $this->freezeSecond();

    $ada = userJson(['id' => 'u2', 'username' => 'ada', 'name' => 'Ada']);
    app(LastVisit::class)->remember(userJson()['id'], 'following', [Commit::fromArray(commitJson(['created_at' => now()->subHour()->toIso8601String()]))]);

    Http::fake(['devnull.test/*' => Http::response(pageJson([
        commitJson(['id' => 'c3', 'author' => $ada, 'created_at' => now()->subMinutes(10)->toIso8601String()]),
        commitJson(['id' => 'c2', 'author' => $ada, 'created_at' => now()->subHour()->toIso8601String()]),
        commitJson(['id' => 'c1', 'author' => $ada, 'created_at' => now()->subHours(2)->toIso8601String()]),
    ]))]);

    $screen = Native::visit('/log')
        ->assertSee('HEAD@{last visit}')
        ->assertSet('lastVisitAt', now()->subHour()->toIso8601String());

    $json = json_encode($screen->tree());
    expect(strpos($json, '"last-visit"'))->toBeGreaterThan(strpos($json, 'commit-c3'))
        ->and(strpos($json, '"last-visit"'))->toBeLessThan(strpos($json, 'commit-c2'))
        ->and(railNode($screen, 'commit-c2'))->toBe('avatar')
        ->and(railNode($screen, 'commit-c1'))->toBe('dot');

    // The newest commit is remembered for next time, and the marker stays put until then.
    expect(app(LastVisit::class)->seenAt(userJson()['id'], 'following'))->toBe(now()->subMinutes(10)->toIso8601String());
    $screen->call('load')->assertSee('HEAD@{last visit}');
});

it('leaves out the last-visit marker on a first visit, or when only you committed since', function () {
    $this->freezeSecond();

    Http::fake(['devnull.test/*' => Http::response(pageJson([
        commitJson(['id' => 'c2', 'created_at' => now()->subMinutes(10)->toIso8601String()]),
        commitJson(['id' => 'c1', 'author' => userJson(['id' => 'u2', 'username' => 'ada']), 'created_at' => now()->subHour()->toIso8601String()]),
    ]))]);

    Native::visit('/log')->assertDontSee('HEAD@{last visit}');

    app(LastVisit::class)->remember(userJson()['id'], 'following', [Commit::fromArray(commitJson(['created_at' => now()->subHour()->toIso8601String()]))]);

    Native::visit('/log')->assertDontSee('HEAD@{last visit}');
});

it('is accessible', function () {
    Http::fake(['devnull.test/*' => Http::response(pageJson([commitJson()]))]);

    Native::visit('/log')->assertAccessible();
});

it('lets a drag that starts on a commit, a link preview or a thread reply scroll the log', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([
            commitJson(['id' => 'c2', 'replies_count' => 1, 'link_preview' => ['url' => 'https://laravel.com', 'title' => 'Laravel', 'description' => null, 'image_url' => null, 'site_name' => null]]),
            commitJson(['id' => 'c1']),
        ])],
        'GET /api/v1/commits/c2/replies' => [pageJson([replyJson(['id' => 'r1', 'parent_id' => 'c2'])])],
    ]);

    $screen = Native::visit('/log', platform: 'ios')->tap('replies-c2');

    nodeByRef($screen, 'link-preview-c2');
    nodeByRef($screen, 'thread-reply-r1');

    expect(pressFeedbackInScroller($screen))->toBe([]);
});

it("wears the web header's logo in place of its title, in the system appearance", function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson()])],
        'GET /api/v1/notifications' => [pageJson([], path: 'http://devnull.test/api/v1/notifications')],
    ]);

    $titleView = fn (TestableComponent $screen): ?array => findNode($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'top_bar_title');

    $logo = $titleView(Native::visit('/log'))['children'][0] ?? null;

    expect($logo['type'] ?? null)->toBe('image')
        ->and($logo['props'])->toMatchArray(['src' => TabsLayout::LightLogo, 'alt' => 'devnull'])
        ->and($logo['layout']['height'] ?? null)->toBe(28.0)
        ->and(public_path(TabsLayout::LightLogo))->toBeFile()
        ->and(public_path(TabsLayout::DarkLogo))->toBeFile();

    System::rememberAppearance('dark');

    try {
        expect($titleView(Native::visit('/log'))['children'][0]['props']['src'] ?? null)->toBe(TabsLayout::DarkLogo);
    } finally {
        System::rememberAppearance('light');
    }

    // Other screens keep their text titles.
    expect($titleView(Native::visit('/notifications')->assertNavTitle('Notifications')))->toBeNull();
});
