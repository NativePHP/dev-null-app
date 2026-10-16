<?php

use App\Devnull\Account;
use App\Devnull\DeviceKey;
use App\Devnull\GitHubStep;
use App\NativeComponents\Onboarding;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

const GitHubToken = 'gho_not-a-real-token-for-tests';

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test', 'devnull.github_client_id' => 'Ov23liDevnullTest']);
    $this->freezeTime();
});

/**
 * Fakes github.com, api.github.com and devnull's key-syncs. The token
 * endpoint and key-syncs answer in turn, repeating their last answer.
 *
 * @param  list<array<string, mixed>>  $tokenAnswers
 * @param  list<array{0: array<string, mixed>, 1?: int}>  $keySyncAnswers
 * @param  array{0: array<string, mixed>, 1: int}|null  $signingKeyAnswer
 */
function fakeGitHub(
    array $tokenAnswers = [['access_token' => GitHubToken, 'token_type' => 'bearer', 'scope' => '']],
    array $keySyncAnswers = [[['data' => ['username' => 'local_dev']]]],
    ?array $signingKeyAnswer = null,
    array $deviceCode = [],
): void {
    $tokenCalls = 0;
    $keySyncCalls = 0;

    Http::fake(function (Request $request) use ($tokenAnswers, $keySyncAnswers, $signingKeyAnswer, $deviceCode, &$tokenCalls, &$keySyncCalls) {
        return match ($request->url()) {
            'https://github.com/login/device/code' => Http::response(gitHubDeviceCodeJson($deviceCode)),
            'https://github.com/login/oauth/access_token' => Http::response($tokenAnswers[min($tokenCalls++, count($tokenAnswers) - 1)]),
            'https://api.github.com/user' => Http::response(['login' => 'local_dev', 'id' => 1]),
            'https://api.github.com/user/ssh_signing_keys' => Http::response(...($signingKeyAnswer ?? [['id' => 7, 'key' => testKey()->openSsh(), 'title' => 'devnull on iPhone'], 201])),
            'http://devnull.test/api/v1/key-syncs' => (function () use ($keySyncAnswers, &$keySyncCalls) {
                $answer = $keySyncAnswers[min($keySyncCalls++, count($keySyncAnswers) - 1)];

                return Http::response($answer[0], $answer[1] ?? 200);
            })(),
            default => Http::response(['message' => "No fake for {$request->url()}."], 404),
        };
    });
}

function gitHubKeychain(): FakeBridge
{
    return fakeKeychain(testSeed())->respondTo('Device.GetInfo', ['info' => json_encode(['model' => 'iPhone', 'platform' => 'ios'])]);
}

/**
 * Taps Connect GitHub and waits out GitHub's first interval.
 */
function startDeviceFlow(): TestableComponent
{
    $screen = Native::visit('/onboarding')->tap('connect-github')->assertSet('step', GitHubStep::Authorizing);

    test()->travel(5)->seconds();

    return $screen;
}

/**
 * @return list<Request>
 */
function requestsTo(string $url): array
{
    return Http::recorded(fn (Request $request): bool => $request->url() === $url)->map(fn (array $pair): Request => $pair[0])->values()->all();
}

it('offers GitHub first and keeps adding the key by hand below', function () {
    gitHubKeychain();

    Native::visit('/onboarding')
        ->assertSee("devnull asks GitHub for one thing: permission to add this phone's signing key. The token is dropped as soon as the key is added.")
        ->assertSee('Connect GitHub')
        ->assertSee('Add the key yourself')
        ->assertSee(testKey()->openSsh())
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'connect-github' && $node['props']['variant'] === 'primary')
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'connect' && $node['props']['variant'] === 'secondary')
        ->assertSet('step', GitHubStep::Start);
});

it('hides Connect GitHub without a client id', function () {
    gitHubKeychain();
    config(['devnull.github_client_id' => null]);
    Http::fake();

    Native::visit('/onboarding')
        ->assertDontSee('Connect GitHub')
        ->assertDontSee('devnull asks GitHub')
        ->assertDontSee('Add the key yourself')
        ->assertSee('Add the public half to GitHub once, as a signing key.')
        ->assertSee(testKey()->openSsh())
        ->assertElement('button', fn (array $node): bool => ($node['ref'] ?? null) === 'connect' && $node['props']['variant'] === 'primary')
        ->call('connectGitHub')
        ->assertSet('step', GitHubStep::Start);

    Http::assertNothingSent();
});

it('adds the key through GitHub, forgets the token and signs in', function () {
    gitHubKeychain();
    fakeGitHub(tokenAnswers: [
        ['error' => 'authorization_pending', 'error_description' => 'The authorization request is still pending.'],
        ['access_token' => GitHubToken, 'token_type' => 'bearer', 'scope' => ''],
    ]);

    $screen = Native::visit('/onboarding')
        ->tap('connect-github')
        ->assertSet('step', GitHubStep::Authorizing)
        ->assertSee('WDJB-MJHT')
        ->assertSee('Waiting for GitHub…')
        ->tap('open-device-page')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/login/device')
        ->tap('share-code')
        ->assertNativeCalled('Share.File', fn (array $params): bool => $params['message'] === 'WDJB-MJHT' && $params['filePath'] === '');

    // GitHub's interval hasn't passed, so nothing is asked yet.
    $screen->firePoll('tick');
    expect(requestsTo('https://github.com/login/oauth/access_token'))->toBeEmpty();

    $this->travel(5)->seconds();
    $screen->firePoll('tick')->assertSet('step', GitHubStep::Authorizing)->assertNoNavigation();

    $this->travel(5)->seconds();
    $screen->firePoll('tick')->assertReplacedWith('/log');

    expect(app(Account::class)->user()?->username)->toBe('local_dev')
        ->and(requestsTo('https://github.com/login/oauth/access_token'))->toHaveCount(2);

    $signingKey = requestsTo('https://api.github.com/user/ssh_signing_keys')[0];
    expect($signingKey->data())->toBe(['title' => 'devnull on iPhone', 'key' => testKey()->openSsh()])
        ->and($signingKey->header('Authorization'))->toBe(['Bearer '.GitHubToken]);

    $keySync = requestsTo('http://devnull.test/api/v1/key-syncs')[0];
    expect($keySync->body())->toBe('{"username":"local_dev"}')
        ->and($keySync->hasHeader('X-SSH-Signature'))->toBeTrue()
        ->and($keySync->hasHeader('Authorization'))->toBeFalse();
});

it('opens the page with the code filled in when GitHub sends one', function () {
    gitHubKeychain();
    fakeGitHub(deviceCode: ['verification_uri_complete' => 'https://github.com/login/device?user_code=WDJB-MJHT']);

    Native::visit('/onboarding')
        ->tap('connect-github')
        ->tap('open-device-page')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/login/device?user_code=WDJB-MJHT');
});

it('says how long the code lasts', function () {
    gitHubKeychain();
    fakeGitHub(deviceCode: ['expires_in' => 60]);

    Native::visit('/onboarding')
        ->tap('connect-github')
        ->assertSee('It works for 1 minute, and this screen carries on by itself once GitHub has it.');
});

it('keeps asking while GitHub says the code is pending', function () {
    gitHubKeychain();
    fakeGitHub(tokenAnswers: [['error' => 'authorization_pending']]);

    $screen = startDeviceFlow();

    foreach (range(1, 3) as $poll) {
        $screen->firePoll('tick')->assertSet('step', GitHubStep::Authorizing)->assertSee('WDJB-MJHT');
        $this->travel(5)->seconds();
    }

    expect(requestsTo('https://github.com/login/oauth/access_token'))->toHaveCount(3);
});

it('waits 5 seconds longer after slow_down', function () {
    gitHubKeychain();
    fakeGitHub(tokenAnswers: [
        ['error' => 'slow_down', 'error_description' => 'Too many requests have been made in the same timeframe.'],
        ['error' => 'authorization_pending'],
    ]);

    $screen = startDeviceFlow()->firePoll('tick');

    $this->travel(5)->seconds();
    $screen->firePoll('tick');
    expect(requestsTo('https://github.com/login/oauth/access_token'))->toHaveCount(1);

    $this->travel(5)->seconds();
    $screen->firePoll('tick')->assertSet('step', GitHubStep::Authorizing);
    expect(requestsTo('https://github.com/login/oauth/access_token'))->toHaveCount(2);

    // Still 10 seconds from here on.
    $this->travel(5)->seconds();
    $screen->firePoll('tick');
    expect(requestsTo('https://github.com/login/oauth/access_token'))->toHaveCount(2);
});

it('uses the interval slow_down sends when it is longer', function () {
    gitHubKeychain();
    fakeGitHub(tokenAnswers: [['error' => 'slow_down', 'interval' => 15], ['error' => 'authorization_pending']]);

    $screen = startDeviceFlow()->firePoll('tick');

    $this->travel(10)->seconds();
    $screen->firePoll('tick');
    expect(requestsTo('https://github.com/login/oauth/access_token'))->toHaveCount(1);

    $this->travel(5)->seconds();
    $screen->firePoll('tick');
    expect(requestsTo('https://github.com/login/oauth/access_token'))->toHaveCount(2);
});

it('offers to start again when the code expires', function () {
    gitHubKeychain();
    fakeGitHub(tokenAnswers: [['error' => 'expired_token', 'error_description' => 'This device code has expired.']]);

    startDeviceFlow()
        ->firePoll('tick')
        ->assertSet('step', GitHubStep::Expired)
        ->assertSee('fatal: the code expired before GitHub saw it.')
        ->assertDontSee('WDJB-MJHT')
        ->tap('start-again')
        ->assertSet('step', GitHubStep::Authorizing)
        ->assertSee('WDJB-MJHT');

    expect(requestsTo('https://github.com/login/device/code'))->toHaveCount(2);
});

it('stops asking once the code has run out', function () {
    gitHubKeychain();
    fakeGitHub(tokenAnswers: [['error' => 'authorization_pending']]);

    $screen = startDeviceFlow();
    $this->travel(900)->seconds();

    $screen->firePoll('tick')->assertSet('step', GitHubStep::Expired);

    expect(requestsTo('https://github.com/login/oauth/access_token'))->toBeEmpty();
});

it('goes back to the start, calmly, when the person cancels on GitHub', function () {
    gitHubKeychain();
    fakeGitHub(tokenAnswers: [['error' => 'access_denied', 'error_description' => 'The authorization request was denied.']]);

    $screen = startDeviceFlow()
        ->firePoll('tick')
        ->assertSet('step', GitHubStep::Start)
        ->assertSee('You cancelled on GitHub, so nothing was added. Connect again whenever you like.')
        ->assertSee('Connect GitHub')
        ->assertDontSee('WDJB-MJHT')
        ->assertSet('githubError', null);

    $this->travel(30)->seconds();
    $screen->firePoll('tick');

    expect(requestsTo('https://github.com/login/oauth/access_token'))->toHaveCount(1);
});

it('keeps asking when GitHub cannot be reached for a moment', function () {
    gitHubKeychain();
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        return match ($request->url()) {
            'https://github.com/login/device/code' => Http::response(gitHubDeviceCodeJson()),
            default => $calls++ === 0 ? throw new ConnectionException('Could not resolve host') : Http::response(['error' => 'authorization_pending']),
        };
    });

    $screen = startDeviceFlow()
        ->firePoll('tick')
        ->assertSet('step', GitHubStep::Authorizing)
        ->assertSee("fatal: unable to access 'https://github.com': could not connect to the server");

    $this->travel(5)->seconds();

    $screen->firePoll('tick')
        ->assertSet('step', GitHubStep::Authorizing)
        ->assertDontSee('could not connect to the server');
});

it('goes back to the start when GitHub refuses the flow', function () {
    gitHubKeychain();
    fakeGitHub(tokenAnswers: [['error' => 'incorrect_device_code', 'error_description' => 'The device_code provided is not valid.']]);

    startDeviceFlow()
        ->firePoll('tick')
        ->assertSet('step', GitHubStep::Start)
        ->assertSee('fatal: github.com refused: The device_code provided is not valid.');
});

it('says so when GitHub has the device flow turned off', function () {
    gitHubKeychain();
    Http::fake(['github.com/login/device/code' => Http::response([
        'error' => 'device_flow_disabled',
        'error_description' => 'Device Flow must be explicitly enabled for this App',
    ], 400)]);

    Native::visit('/onboarding')
        ->tap('connect-github')
        ->assertSet('step', GitHubStep::Start)
        ->assertSee('fatal: github.com refused: Device Flow must be explicitly enabled for this App');
});

it('lets the person cancel while the code is showing', function () {
    gitHubKeychain();
    fakeGitHub(tokenAnswers: [['error' => 'authorization_pending']]);

    $screen = startDeviceFlow()
        ->tap('cancel-github')
        ->assertSet('step', GitHubStep::Start)
        ->assertDontSee('WDJB-MJHT');

    $screen->firePoll('tick');

    expect(requestsTo('https://github.com/login/oauth/access_token'))->toBeEmpty();
});

it('counts a key GitHub already has as added', function () {
    gitHubKeychain();
    fakeGitHub(signingKeyAnswer: [gitHubKeyInUseJson(), 422]);

    startDeviceFlow()->firePoll('tick')->assertReplacedWith('/log');

    expect(app(Account::class)->user()?->username)->toBe('local_dev');
});

it('stops when GitHub will not take the key', function () {
    gitHubKeychain();
    fakeGitHub(signingKeyAnswer: [['message' => 'Validation Failed', 'errors' => [['message' => 'key is invalid']]], 422]);

    startDeviceFlow()
        ->firePoll('tick')
        ->assertNoNavigation()
        ->assertSet('step', GitHubStep::Start)
        ->assertSee("fatal: could not add the signing key to local_dev's GitHub account: Validation Failed: key is invalid (HTTP 422)");

    expect(requestsTo('http://devnull.test/api/v1/key-syncs'))->toBeEmpty();
});

it('waits for GitHub to list the key, then signs in', function () {
    gitHubKeychain();
    $notListed = [['message' => "This SSH key isn't on @local_dev's GitHub account yet."], 403];
    fakeGitHub(keySyncAnswers: [$notListed, $notListed, [['data' => ['username' => 'local_dev']]]]);

    $screen = startDeviceFlow()
        ->firePoll('tick')
        ->assertNoNavigation()
        ->assertSet('step', GitHubStep::Listing)
        ->assertSee('Waiting for GitHub to list the key…')
        ->assertSee("The key is on @local_dev's GitHub account.");

    // devnull only re-reads GitHub every 10 seconds, so the retry waits 11.
    $this->travel(10)->seconds();
    $screen->firePoll('tick');
    expect(requestsTo('http://devnull.test/api/v1/key-syncs'))->toHaveCount(1);

    $this->travel(1)->seconds();
    $screen->firePoll('tick')->assertSee('Waiting for GitHub to list the key…');
    expect(requestsTo('http://devnull.test/api/v1/key-syncs'))->toHaveCount(2);

    $this->travel(11)->seconds();
    $screen->firePoll('tick')->assertReplacedWith('/log');

    expect(requestsTo('http://devnull.test/api/v1/key-syncs'))->toHaveCount(3)
        ->and(requestsTo('https://api.github.com/user/ssh_signing_keys'))->toHaveCount(1)
        ->and(app(Account::class)->user()?->username)->toBe('local_dev');
});

it('shows the server message after three retries', function () {
    gitHubKeychain();
    $message = "This SSH key (SHA256:abc) isn't on @local_dev's GitHub account yet. Add it on GitHub under Settings, then try again.";
    fakeGitHub(keySyncAnswers: [[['message' => $message], 403]]);

    $screen = startDeviceFlow()->firePoll('tick')->assertSet('step', GitHubStep::Listing);

    foreach (range(1, 3) as $retry) {
        $this->travel(11)->seconds();
        $screen->firePoll('tick');
    }

    $screen->assertNoNavigation()
        ->assertSet('step', GitHubStep::Start)
        ->assertSee($message)
        ->assertSet('username', 'local_dev')
        ->assertDontSee('Waiting for GitHub to list the key…');

    $this->travel(11)->seconds();
    $screen->firePoll('tick');

    expect(requestsTo('http://devnull.test/api/v1/key-syncs'))->toHaveCount(4)
        ->and(app(Account::class)->user())->toBeNull();
});

it('stops at once when the person has never signed in to devnull', function () {
    gitHubKeychain();
    $message = "@local_dev hasn't signed in to devnull yet. Sign in once on http://devnull.test, then try again.";
    fakeGitHub(keySyncAnswers: [[['message' => $message], 404]]);

    $screen = startDeviceFlow()
        ->firePoll('tick')
        ->assertNoNavigation()
        ->assertSet('step', GitHubStep::Start)
        ->assertSee($message)
        ->assertSet('username', 'local_dev')
        ->assertDontSee('Waiting for GitHub to list the key…');

    $this->travel(11)->seconds();
    $screen->firePoll('tick');

    expect(requestsTo('http://devnull.test/api/v1/key-syncs'))->toHaveCount(1)
        ->and(app(Account::class)->user())->toBeNull();
});

it('does not retry other key-sync errors', function () {
    gitHubKeychain();
    fakeGitHub(keySyncAnswers: [[['message' => 'GitHub is not answering right now. Try again in a minute.'], 503]]);

    startDeviceFlow()
        ->firePoll('tick')
        ->assertSet('step', GitHubStep::Start)
        ->assertSee('GitHub is not answering right now. Try again in a minute.');

    expect(requestsTo('http://devnull.test/api/v1/key-syncs'))->toHaveCount(1);
});

it('never keeps the token anywhere', function () {
    $this->artisan('migrate');
    config(['cache.default' => 'database', 'app.debug' => true]);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    // A first launch: the device key is made and written to the Keychain here.
    $bridge = fakeKeychain()->respondTo('Device.GetInfo', ['info' => json_encode(['model' => 'iPhone'])]);
    fakeGitHub();

    $screen = startDeviceFlow()->firePoll('tick')->assertReplacedWith('/log');

    // The account was written to the database, like on the phone, and only the account.
    $database = collect(DB::select("select name from sqlite_master where type = 'table'"))
        ->map(fn (object $table): string => json_encode(DB::table($table->name)->get()))
        ->implode("\n");

    expect($database)->toContain('local_dev')->not->toContain(GitHubToken);

    $keychainWrites = collect($bridge->callsTo('SecureStorage.Set'))->map(fn (array $call): string => json_encode($call['params']));
    expect($keychainWrites)->toHaveCount(1)
        ->and($keychainWrites->first())->toContain(DeviceKey::StorageKey)->not->toContain(GitHubToken)
        ->and(json_encode($bridge->calls))->not->toContain(GitHubToken);

    expect($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->not->toContain(GitHubToken)
        ->and(json_encode(session()->all()))->not->toContain(GitHubToken);

    $component = $screen->instance();
    $state = collect((new ReflectionObject($component))->getProperties())
        ->filter(fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === Onboarding::class)
        ->map(fn (ReflectionProperty $property): string => var_export($property->getValue($component), true))
        ->implode("\n");

    expect($state)->toContain('local_dev')->not->toContain(GitHubToken);

    Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), 'api.github.com')
        && str_contains($request->body().json_encode($request->headers()), GitHubToken));
});
