<?php

use App\Devnull\Data\AuthorizationStatus;
use App\Devnull\Exceptions\GitHubException;
use App\Devnull\Exceptions\GitHubUnreachable;
use App\Devnull\GitHub;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['devnull.github_client_id' => 'Ov23liDevnullTest']));

it('is only enabled with a client id', function () {
    expect(app(GitHub::class)->enabled())->toBeTrue()
        ->and((new GitHub(''))->enabled())->toBeFalse()
        ->and((new GitHub('  '))->enabled())->toBeFalse();

    config(['devnull.github_client_id' => null]);

    expect(app(GitHub::class)->enabled())->toBeFalse();
});

it('asks for a device code with just the client id', function () {
    Http::fake(['github.com/login/device/code' => Http::response(gitHubDeviceCodeJson())]);

    $code = app(GitHub::class)->requestDeviceCode();

    expect($code->deviceCode)->toBe('3584d83530557fdd1f46af8289938c8ef79f9dc5')
        ->and($code->userCode)->toBe('WDJB-MJHT')
        ->and($code->verificationUri)->toBe('https://github.com/login/device')
        ->and($code->verificationUriComplete)->toBeNull()
        ->and($code->pageToOpen())->toBe('https://github.com/login/device')
        ->and($code->expiresIn)->toBe(900)
        ->and($code->interval)->toBe(5);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://github.com/login/device/code'
        && $request->method() === 'POST'
        && $request->isForm()
        && $request->data() === ['client_id' => 'Ov23liDevnullTest']
        && $request->header('Accept') === ['application/json']);
});

it('opens the page with the code filled in when GitHub offers one', function () {
    Http::fake(['github.com/login/device/code' => Http::response(gitHubDeviceCodeJson([
        'verification_uri_complete' => 'https://github.com/login/device?user_code=WDJB-MJHT',
    ]))]);

    expect(app(GitHub::class)->requestDeviceCode()->pageToOpen())->toBe('https://github.com/login/device?user_code=WDJB-MJHT');
});

it('passes on why GitHub refused a device code', function () {
    Http::fake(['github.com/login/device/code' => Http::response([
        'error' => 'device_flow_disabled',
        'error_description' => 'Device Flow must be explicitly enabled for this App',
    ], 400)]);

    expect(fn () => app(GitHub::class)->requestDeviceCode())
        ->toThrow(GitHubException::class, 'fatal: github.com refused: Device Flow must be explicitly enabled for this App');
});

it('polls with the device code grant', function () {
    Http::fake(['github.com/login/oauth/access_token' => Http::response(['error' => 'authorization_pending'])]);

    expect(app(GitHub::class)->requestToken('3584d83530557fdd1f46af8289938c8ef79f9dc5')->status)->toBe(AuthorizationStatus::Pending);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://github.com/login/oauth/access_token'
        && $request->method() === 'POST'
        && $request->isForm()
        && $request->data() === [
            'client_id' => 'Ov23liDevnullTest',
            'device_code' => '3584d83530557fdd1f46af8289938c8ef79f9dc5',
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ]
        && $request->header('Accept') === ['application/json']);
});

it('reads each answer from the token endpoint', function (array $json, AuthorizationStatus $status, ?int $interval) {
    Http::fake(['github.com/login/oauth/access_token' => Http::response($json)]);

    $authorization = app(GitHub::class)->requestToken('device-code');

    expect($authorization->status)->toBe($status)
        ->and($authorization->interval)->toBe($interval)
        ->and($authorization->token)->toBeNull();
})->with([
    'pending' => [['error' => 'authorization_pending'], AuthorizationStatus::Pending, null],
    'slow down' => [['error' => 'slow_down', 'interval' => 10], AuthorizationStatus::SlowDown, 10],
    'expired' => [['error' => 'expired_token'], AuthorizationStatus::Expired, null],
    'expired, as the docs also spell it' => [['error' => 'token_expired'], AuthorizationStatus::Expired, null],
    'denied' => [['error' => 'access_denied'], AuthorizationStatus::Denied, null],
]);

it('hands back the token once granted, without showing it in dumps', function () {
    Http::fake(['github.com/login/oauth/access_token' => Http::response([
        'access_token' => 'gho_not-a-real-token-for-tests',
        'token_type' => 'bearer',
        'scope' => '',
    ])]);

    $authorization = app(GitHub::class)->requestToken('device-code');

    expect($authorization->status)->toBe(AuthorizationStatus::Granted)
        ->and($authorization->token)->toBe('gho_not-a-real-token-for-tests')
        ->and(print_r($authorization, true))->not->toContain('gho_not-a-real-token-for-tests');
});

it('treats other OAuth errors as a refusal', function () {
    Http::fake(['github.com/login/oauth/access_token' => Http::response([
        'error' => 'incorrect_client_credentials',
        'error_description' => 'The client_id and/or client_secret passed are incorrect.',
    ])]);

    expect(fn () => app(GitHub::class)->requestToken('device-code'))
        ->toThrow(GitHubException::class, 'fatal: github.com refused: The client_id and/or client_secret passed are incorrect.');
});

it('says when GitHub cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    expect(fn () => app(GitHub::class)->requestToken('device-code'))
        ->toThrow(GitHubUnreachable::class, "fatal: unable to access 'https://github.com': could not connect to the server");
});

it('counts a server error as unreachable', function () {
    Http::fake(['github.com/*' => Http::response('Bad gateway', 502)]);

    expect(fn () => app(GitHub::class)->requestToken('device-code'))
        ->toThrow(GitHubUnreachable::class, "fatal: unable to access 'https://github.com': the server answered HTTP 502");
});

it('adds the signing key with the token and returns the account', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'local_dev', 'id' => 1]),
        'api.github.com/user/ssh_signing_keys' => Http::response(['id' => 7, 'key' => testKey()->openSsh(), 'title' => 'devnull on iPhone', 'created_at' => '2026-10-15T12:00:00Z'], 201),
    ]);

    $login = app(GitHub::class)->addSigningKey('gho_token', 'devnull on iPhone', testKey()->openSsh());

    expect($login)->toBe('local_dev');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'https://api.github.com/user'
            && $request->method() === 'GET'
            && $request->header('Authorization') === ['Bearer gho_token']
            && $request->header('Accept') === ['application/vnd.github+json']
            && $request->header('X-GitHub-Api-Version') === ['2022-11-28'],
        fn (Request $request): bool => $request->url() === 'https://api.github.com/user/ssh_signing_keys'
            && $request->method() === 'POST'
            && $request->isJson()
            && $request->data() === ['title' => 'devnull on iPhone', 'key' => testKey()->openSsh()]
            && $request->header('Authorization') === ['Bearer gho_token']
            && $request->header('Accept') === ['application/vnd.github+json']
            && $request->header('X-GitHub-Api-Version') === ['2022-11-28'],
    ]);
});

it('counts a key that is already on GitHub as added', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'local_dev']),
        'api.github.com/user/ssh_signing_keys' => Http::response(gitHubKeyInUseJson(), 422),
    ]);

    expect(app(GitHub::class)->addSigningKey('gho_token', 'devnull on iPhone', testKey()->openSsh()))->toBe('local_dev');
});

it('passes on any other validation error', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'local_dev']),
        'api.github.com/user/ssh_signing_keys' => Http::response([
            'message' => 'Validation Failed',
            'errors' => [['resource' => 'SshSigningKey', 'code' => 'custom', 'field' => 'key', 'message' => 'key is invalid. You must supply a key in OpenSSH public key format']],
        ], 422),
    ]);

    expect(fn () => app(GitHub::class)->addSigningKey('gho_token', 'devnull on iPhone', 'nonsense'))
        ->toThrow(GitHubException::class, "fatal: could not add the signing key to local_dev's GitHub account: Validation Failed: key is invalid. You must supply a key in OpenSSH public key format (HTTP 422)");
});

it('stops when the token does not work', function () {
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    expect(fn () => app(GitHub::class)->addSigningKey('gho_token', 'devnull on iPhone', testKey()->openSsh()))
        ->toThrow(GitHubException::class, 'fatal: could not read your GitHub account: Bad credentials (HTTP 401)');

    Http::assertSentCount(1);
});
