<?php

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\DeviceKey;
use App\Devnull\ErrorCode;
use App\Devnull\SignIn;
use App\Devnull\SignInAttempt;
use App\NativeComponents\AuthCallback;
use App\NativeComponents\Launch;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Onboarding;
use App\Providers\NativeServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\Edge\Enums\AlignItems;
use Native\Mobile\Edge\Enums\JustifyContent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\System;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    $this->freezeTime();
});

/**
 * A phone with the given key in its Keychain, whose auth browser opens and
 * which says it's an iPhone.
 */
function signInBridge(?string $seed = null): FakeBridge
{
    return fakeKeychain($seed)
        ->respondTo('Browser.OpenAuth', ['success' => true])
        ->respondTo('Device.GetInfo', ['info' => json_encode(['model' => 'iPhone'])]);
}

/**
 * The query of the last URL opened in the auth browser.
 *
 * @return array<string, string>
 */
function openedAuthQuery(FakeBridge $bridge): array
{
    $calls = $bridge->callsTo('Browser.OpenAuth');

    expect($calls)->not->toBeEmpty('Nothing was opened in the auth browser.');

    parse_str((string) parse_url(end($calls)['params']['url'], PHP_URL_QUERY), $query);

    return $query;
}

/**
 * Taps Sign in on dev-null.sh and returns the state the browser was given.
 */
function tapSignIn(TestableComponent $screen, FakeBridge $bridge): string
{
    $screen->tap('sign-in');

    return openedAuthQuery($bridge)['state'];
}

/**
 * The attempt the sign-in under way holds in memory.
 */
function pendingAttempt(): ?SignInAttempt
{
    return (fn () => $this->attempt)->call(app(SignIn::class));
}

/**
 * dev-null.sh sending the browser back, delivered as NativePHP delivers a deep
 * link to the running screen, then followed to the screen that handles it.
 *
 * @param  array<string, string>  $query
 */
function sendBack(TestableComponent $screen, array $query): TestableComponent
{
    $uri = '/auth/callback?'.http_build_query($query);

    return $screen->emitNative('__deeplink', ['uri' => $uri])
        ->assertNavigatedTo($uri)
        ->follow()
        ->assertScreen(AuthCallback::class)
        ->assertReplacedWith('/onboarding')
        ->follow()
        ->assertScreen(Onboarding::class);
}

it('makes a key on first launch and shows only the logo and the button', function () {
    $bridge = signInBridge();

    $screen = Native::visit('/onboarding');

    expect(shownText($screen))->toBe([]);

    $screen->assertDontSee('This phone makes its own key')
        ->assertSet('fingerprint', app(DeviceKey::class)->current()->fingerprint())
        // Large, in Commit Blue as the web's primary button is, with no mark: it's dev-null.sh, not GitHub.
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'sign-in'
            && $node['props']['label'] === 'Sign in on dev-null.sh'
            && ($node['props']['variant'] ?? null) === 'primary'
            && ($node['props']['size'] ?? null) === 'lg'
            && ! isset($node['props']['leading_icon']));

    expect(findNodes($screen->tree(), fn (array $node): bool => ($node['type'] ?? null) === 'button'))->toHaveCount(1)
        ->and(findNodes($screen->tree(), fn (array $node): bool => str_contains((string) ($node['type'] ?? ''), 'input')))->toBe([]);

    $screen->assertDontSee('Copy key')->assertDontSee('Connect GitHub')->assertDontSee('ssh-ed25519');
    $bridge->assertCalledTimes('SecureStorage.Set', 1);
});

it("centres the Log header's logo, two thirds of the screen wide, above the buttons at the bottom", function () {
    signInBridge(testSeed());

    $screen = Native::visit('/onboarding');
    $logo = nodeByRef($screen, 'logo');
    $space = nodeByRef($screen, 'logo-space');
    [$width, $height] = getimagesize(public_path(TabsLayout::LightLogo));

    expect($logo['type'])->toBe('image')
        ->and($logo['props'])->toMatchArray(['src' => TabsLayout::LightLogo, 'alt' => 'devnull'])
        ->and($logo['layout'])->toEqual(['width' => '67%', 'aspect_ratio' => 3.75])
        ->and($space['layout'])->toMatchArray(['width' => 'fill', 'flex_grow' => 1, 'align_items' => AlignItems::Center->value, 'justify_content' => JustifyContent::Center->value])
        ->and(array_column($screen->tree()['children'], 'ref'))->toBe(['logo-space', 'actions'])
        ->and($screen->tree()['layout'])->toMatchArray(['height' => 'fill', 'safe_area' => 1])
        ->and(childRefs($screen, 'actions'))->toBe(['sign-in'])
        // Drawn at up to 293pt on the widest iPhone, so it's rendered 300pt wide at @3x, at the lockup's own 15:4.
        ->and([$width, $height])->toBe([900, 240])
        ->and(getimagesize(public_path(TabsLayout::DarkLogo))[0])->toBe(900);

    System::rememberAppearance('dark');

    try {
        expect(nodeByRef(Native::visit('/onboarding'), 'logo')['props']['src'])->toBe(TabsLayout::DarkLogo);
    } finally {
        System::rememberAppearance('light');
    }
});

it('keeps the same key across launches', function () {
    signInBridge(testSeed());

    Native::visit('/onboarding')->assertSet('fingerprint', testKey()->fingerprint());
});

it('opens dev-null.sh in the auth browser with the key, the device and an S256 challenge', function () {
    $bridge = signInBridge(testSeed());
    $screen = Native::visit('/onboarding');

    $screen->tap('sign-in')->assertNoNavigation();

    $url = $bridge->callsTo('Browser.OpenAuth')[0]['params']['url'];
    $query = openedAuthQuery($bridge);
    $attempt = pendingAttempt();

    expect($url)->toStartWith('http://devnull.test/mobile/authorize?')
        ->and(array_keys($query))->toBe(['fingerprint', 'device_name', 'code_challenge', 'code_challenge_method', 'state', 'redirect_uri'])
        ->and($query['fingerprint'])->toBe(testKey()->fingerprint())
        ->and($query['device_name'])->toBe('devnull on iPhone')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['redirect_uri'])->toBe('devnull://auth/callback')
        ->and($query['state'])->toBe($attempt->state)->toMatch('/^[A-Za-z0-9_-]{22}$/')
        ->and($attempt->verifier())->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $attempt->verifier(), true)), '+/', '-_'), '='))
        ->and($url)->toContain('device_name=devnull%20on%20iPhone')
        ->and($url)->toContain('redirect_uri=devnull%3A%2F%2Fauth%2Fcallback');

    $bridge->assertOpenedAuth();
    $bridge->assertNotCalled('Browser.OpenInApp');
    Http::assertNothingSent();
});

it('makes a fresh verifier and state for every attempt', function () {
    $bridge = signInBridge(testSeed());
    $screen = Native::visit('/onboarding');

    $first = tapSignIn($screen, $bridge);
    $firstChallenge = openedAuthQuery($bridge)['code_challenge'];
    $second = tapSignIn($screen, $bridge);

    expect($second)->not->toBe($first)
        ->and(openedAuthQuery($bridge)['code_challenge'])->not->toBe($firstChallenge);
});

it('follows the deep link back, redeems the code with the verifier and signs in', function () {
    $bridge = signInBridge(testSeed());
    fakeApi([
        'POST /api/v1/devices' => [['data' => userWithCountsJson()]],
        'GET /api/v1/users/local_dev' => [['data' => userWithCountsJson()]],
    ]);
    $screen = Native::visit('/onboarding');
    $state = tapSignIn($screen, $bridge);
    $verifier = pendingAttempt()->verifier();

    sendBack($screen, ['code' => 'the-code', 'state' => $state])->assertReplacedWith('/log');

    // Your profile is kept as you sign in, so it shows when devnull can't be reached.
    expect(app(Account::class)->user()?->username)->toBe('local_dev')
        ->and(app(Account::class)->profile()['data']['handle'] ?? null)->toBe('local_dev');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://devnull.test/api/v1/'.Client::SignInPath
        && $request->body() === json_encode(['code' => 'the-code', 'code_verifier' => $verifier])
        && $request->hasHeader('X-SSH-Signature'));
});

it('finishes when the callback screen pops back to onboarding', function () {
    $bridge = signInBridge(testSeed());
    fakeApi(['POST /api/v1/devices' => [['data' => userWithCountsJson()]]]);
    $screen = Native::visit('/onboarding');
    $state = tapSignIn($screen, $bridge);

    // On a phone the callback screen is pushed over onboarding, so it pops back rather than replacing.
    $callback = new AuthCallback;
    $router = new NativeRouter;
    (function () use ($callback, $state): void {
        $this->stack[] = ['component' => null, 'uri' => '/onboarding', 'params' => []];
        $this->stack[] = ['component' => $callback, 'uri' => "/auth/callback?code=c&state={$state}", 'params' => []];
    })->call($router);
    $callback->setRouter($router);
    $callback->mountComponent();

    expect($callback->getNavigationIntent()?->type)->toBe(NavigationIntent::BACK);

    $screen->call('onResume')->assertReplacedWith('/log');
});

it('rejects a callback whose state is not the one it sent, and redeems nothing', function () {
    $bridge = signInBridge(testSeed());
    fakeApi([]);
    $screen = Native::visit('/onboarding');
    tapSignIn($screen, $bridge);

    sendBack($screen, ['code' => 'stolen', 'state' => 'not-the-state'])
        ->assertNoNavigation()
        ->assertSee(Onboarding::NotStartedHere)
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'sign-in' && $node['props']['label'] === 'Try again');

    Http::assertNothingSent();
    expect(app(Account::class)->user())->toBeNull();
});

it('rejects a callback it never asked for', function () {
    signInBridge(testSeed());
    fakeApi([]);

    Native::visit('/auth/callback?code=x&state=y')
        ->assertReplacedWith('/onboarding')
        ->follow()
        ->assertSee(Onboarding::NotStartedHere);

    Http::assertNothingSent();
});

it('ignores a callback on a phone that is already signed in', function () {
    signIn();

    Native::visit('/auth/callback?code=x&state=y');

    expect(app(SignIn::class)->hasCallback())->toBeFalse();
});

it('says nothing when you cancel on dev-null.sh', function () {
    $bridge = signInBridge(testSeed());
    fakeApi([]);
    $screen = Native::visit('/onboarding');
    $state = tapSignIn($screen, $bridge);

    $screen = sendBack($screen, ['error' => 'access_denied', 'state' => $state])
        ->assertNoNavigation()
        ->assertSet('error', null);

    expect(shownText($screen))->toBe([]);

    $screen
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'sign-in' && $node['props']['label'] === 'Sign in on dev-null.sh');

    Http::assertNothingSent();
});

it("shows dev-null.sh's error and offers to try again with a new attempt", function () {
    $bridge = signInBridge(testSeed());
    fakeApi([]);
    $screen = Native::visit('/onboarding');
    $state = tapSignIn($screen, $bridge);

    $screen = sendBack($screen, ['error' => 'server_error', 'error_description' => 'Something broke on our side.', 'state' => $state])
        ->assertSee('Something broke on our side.')
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'sign-in' && $node['props']['label'] === 'Try again');

    // Just above the buttons, and the only text on the screen.
    expect(childRefs($screen, 'actions'))->toBe(['error', 'sign-in'])
        ->and(shownText($screen))->toBe(['Something broke on our side.']);

    $retry = tapSignIn($screen, $bridge);

    expect($retry)->not->toBe($state);
    $screen->assertSet('error', null);
    Http::assertNothingSent();
});

it("shows the server's message when the code won't redeem", function (int $status, string $message) {
    $bridge = signInBridge(testSeed());
    fakeApi(['POST /api/v1/devices' => [['message' => $message, 'code' => 'invalid_code'], $status]]);
    $screen = Native::visit('/onboarding');
    $state = tapSignIn($screen, $bridge);

    sendBack($screen, ['code' => 'expired', 'state' => $state])
        ->assertNoNavigation()
        ->assertSee($message)
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'sign-in' && $node['props']['label'] === 'Try again');

    expect(app(Account::class)->user())->toBeNull();
})->with([
    'an expired code' => [422, 'That code has expired. Sign in again.'],
    'a failing server' => [503, 'GitHub is not answering right now. Try again in a minute.'],
]);

it("says a spent or expired sign-in by devnull's invalid_grant code, not its message", function () {
    $bridge = signInBridge(testSeed());
    fakeApi(['POST /api/v1/devices' => [['message' => 'This sign-in has expired or was already used. Sign in again in the app.', 'code' => 'invalid_grant'], 400]]);
    $screen = Native::visit('/onboarding');
    $state = tapSignIn($screen, $bridge);

    sendBack($screen, ['code' => 'spent', 'state' => $state])
        ->assertNoNavigation()
        ->assertSet('error', ErrorCode::InvalidGrant->message())
        ->assertSee('fatal: that sign-in expired or was already used. Sign in again.')
        ->assertDontSee('in the app')
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'sign-in' && $node['props']['label'] === 'Try again');

    expect(app(Account::class)->user())->toBeNull();
});

it('spends an attempt on its first answer, so a replayed callback is rejected', function () {
    $bridge = signInBridge(testSeed());
    fakeApi(['POST /api/v1/devices' => [['message' => 'That code has expired.'], 422]]);
    $screen = Native::visit('/onboarding');
    $state = tapSignIn($screen, $bridge);

    $screen = sendBack($screen, ['code' => 'c', 'state' => $state]);
    sendBack($screen, ['code' => 'c', 'state' => $state])->assertSee(Onboarding::NotStartedHere);

    Http::assertSentCount(1);
});

it('never writes the verifier or the state down', function () {
    Storage::fake('local');
    $bridge = signInBridge(testSeed());
    fakeApi(['POST /api/v1/devices' => [['data' => userWithCountsJson()]]]);
    $screen = Native::visit('/onboarding');
    $state = tapSignIn($screen, $bridge);
    $attempt = pendingAttempt();
    $verifier = $attempt->verifier();

    expect(json_encode(get_object_vars($screen->instance())))->not->toContain($verifier)->not->toContain($state)
        ->and(json_encode($screen->tree()))->not->toContain($verifier)->not->toContain($state)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(fn () => serialize($attempt))->toThrow(Exception::class)
        ->and(print_r($attempt, true))->not->toContain($verifier);

    $bridge->assertNotCalled('SecureStorage.Set');

    sendBack($screen, ['code' => 'c', 'state' => $state])->assertReplacedWith('/log');

    expect(app(SignIn::class)->take())->toBeNull()
        ->and(pendingAttempt())->toBeNull()
        ->and(json_encode(app(Account::class)->user()?->toArray()))->not->toContain($verifier)->not->toContain($state);
});

it('says so when the auth browser does not open', function () {
    fakeKeychain(testSeed())->respondTo('Browser.OpenAuth', ['success' => false]);

    Native::visit('/onboarding')->tap('sign-in')->assertSee('fatal: could not open dev-null.sh to sign in.');
});

it("doesn't say why a 401 sent it here: the buttons are enough", function (string $message) {
    signInBridge(testSeed());

    $screen = Native::visit('/onboarding', ['message' => $message])->assertDontSee($message);

    expect(shownText($screen))->toBe([])
        ->and(childRefs($screen, 'actions'))->toBe(['sign-in']);
})->with([
    'a key devnull no longer takes' => ['This key is no longer accepted.'],
    'a phone to approve' => [ErrorCode::DeviceNotApproved->message()],
    'a lost key' => [Launch::KeyGone],
]);

it('says so when the Keychain is locked, and cannot start a sign-in', function () {
    $bridge = Native::fakeBridge()
        ->respondTo('SecureStorage.Get', ['status' => 'unavailable', 'message' => 'Device is locked.'])
        ->respondTo('Browser.OpenAuth', ['success' => true]);

    Native::visit('/onboarding')
        ->assertSee('fatal: could not read the device key from the Keychain. Device is locked.')
        ->assertSet('fingerprint', '')
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'sign-in' && ($node['props']['disabled'] ?? false) === true)
        ->call('signIn');

    $bridge->assertNotCalled('SecureStorage.Set')->assertNotCalled('Browser.OpenAuth');
});

it('builds the authorize URL and checks state on its own', function () {
    $attempt = SignInAttempt::start();
    $url = $attempt->authorizeUrl('https://dev-null.sh/', 'SHA256:a+b/c', 'devnull on iPhone');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://dev-null.sh/mobile/authorize?')
        ->and($query['fingerprint'])->toBe('SHA256:a+b/c')
        ->and($query['code_challenge'])->toBe($attempt->challenge())
        ->and($attempt->answers($attempt->state))->toBeTrue()
        ->and($attempt->answers(''))->toBeFalse()
        ->and($attempt->answers(SignInAttempt::start()->state))->toBeFalse();

    // RFC 7636's own example: this verifier has this challenge.
    $known = (new ReflectionClass(SignInAttempt::class))->newInstanceWithoutConstructor();
    (function (): void {
        $this->verifier = new SensitiveParameterValue('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');
        $this->state = 's';
    })->call($known);

    expect($known->challenge())->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

it('has no GitHub device flow, pairing codes or copy-and-paste path left', function () {
    foreach ([
        'App\Devnull\GitHub',
        'App\Devnull\GitHubToken',
        'App\Devnull\GitHubStep',
        'App\Devnull\Data\PairingCode',
        'App\Devnull\Data\DeviceCode',
        'App\Devnull\Data\DeviceAuthorization',
        'App\Devnull\Data\AuthorizationStatus',
        'App\Devnull\Exceptions\GitHubException',
        'App\Devnull\Exceptions\GitHubUnreachable',
    ] as $class) {
        expect(class_exists($class) || enum_exists($class))->toBeFalse("{$class} should be gone.");
    }

    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect(method_exists(Client::class, 'syncKey'))->toBeFalse()
        ->and(config('devnull'))->not->toHaveKey('github_client_id')
        ->and($composer['require'])->not->toHaveKey('nativephp/mobile-clipboard')
        ->and((new NativeServiceProvider(app()))->plugins())->not->toContain('NativePHP\Clipboard\ClipboardServiceProvider')
        ->and(file_get_contents(base_path('.env.example')))->not->toContain('DEVNULL_GITHUB_CLIENT_ID')->toContain("NATIVEPHP_DEEPLINK_SCHEME=devnull\n")
        ->and(file_get_contents(base_path('README.md')))->not->toContain('DEVNULL_GITHUB_CLIENT_ID')->not->toContain('device flow');
});
