<?php

use App\Devnull\Account;
use App\Devnull\Data\CurrentUser;
use App\NativeComponents\AcceptTerms;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    app(Account::class)->rememberTimezone('Europe/Lisbon');
});

/**
 * devnull said, the last time it was asked, that the terms in force since Nov 1, 2026 are still to be accepted.
 */
function oweTerms(?string $acceptedAt = '2026-10-05T10:00:00Z'): void
{
    keep('me', currentUserTermsJson(false, $acceptedAt));
    app(Account::class)->rememberTermsToAccept('2026-11-01T14:35:00Z');
}

/**
 * devnull's answer to a version that isn't the one in force.
 *
 * @return array{0: array<string, mixed>, 1: int}
 */
function staleTermsVersion(): array
{
    return [['message' => 'The selected version is invalid.', 'errors' => ['version' => ['The selected version is invalid.']]], 422];
}

it('sends you to accept the terms the moment devnull says you owe them', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson()])],
        'GET /api/v1/me' => [currentUserTermsJson(false)],
    ]);

    Native::visit('/log')->assertReplacedWith(AcceptTerms::Path);

    expect(app(Account::class)->termsToAccept())->toBe('2026-11-01T14:35:00Z');
});

it('sends you to accept them from your account too', function () {
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(false)]]);

    Native::visit('/profile/device')->assertReplacedWith(AcceptTerms::Path);
});

it('lets you carry on, and forgets terms owed before, once devnull says you accepted them', function () {
    app(Account::class)->rememberTermsToAccept('2026-10-01T00:00:00Z');
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson()])],
        'GET /api/v1/me' => [currentUserTermsJson(true, '2026-11-02T09:00:00Z')],
    ]);

    Native::visit('/log')->assertNoNavigation();

    expect(app(Account::class)->termsToAccept())->toBeNull();
});

it('asks for nothing when devnull does not say which terms are in force', function () {
    fakeApi([
        'GET /api/v1/log/following' => [pageJson([commitJson()])],
        'GET /api/v1/me' => [currentUserJson()],
    ]);

    Native::visit('/log')->assertNoNavigation();

    expect(app(Account::class)->termsToAccept())->toBeNull();
});

it('says the terms changed, and when, to someone who accepted them before', function () {
    oweTerms();
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(false)]]);

    Native::visit(AcceptTerms::Path)
        ->assertNoNavigation()
        ->assertSee('We updated our terms')
        ->assertSee('Our terms of service changed on Nov 1, 2026. Read them, and accept them to keep using /dev/null.')
        ->assertSee("There's no tolerance for objectionable content or abusive users.")
        ->assertSee("If you don't accept them, you can sign out, or delete your account.")
        ->assertSee('Accept the terms')
        ->assertSee('Sign out')
        ->assertSee('Delete account');
});

it('only asks someone who never accepted any', function () {
    oweTerms(acceptedAt: null);
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(false, null)]]);

    Native::visit(AcceptTerms::Path)
        ->assertSee('Accept our terms')
        ->assertSee('Read the terms of service and privacy policy, and accept them to keep using /dev/null.')
        ->assertDontSee('We updated our terms');
});

it('accepts the version devnull said, then carries on to the log', function () {
    oweTerms();
    fakeApi([
        'GET /api/v1/me' => [currentUserTermsJson(false)],
        'PUT /api/v1/me/terms' => [currentUserTermsJson(true, '2026-11-03T12:00:00Z')],
    ]);

    Native::visit(AcceptTerms::Path)->tap('accept')->assertReplacedWith('/log');

    expect(sentTo('PUT', 'me/terms'))->toBe([json_encode(['version' => '2026-11-01T14:35:00Z'])])
        ->and(app(Account::class)->termsToAccept())->toBeNull()
        ->and(CurrentUser::fromArray(kept('me'))->termsAccepted)->toBeTrue();
});

it('asks again when the terms changed while it was open, then accepts the new ones', function () {
    oweTerms();
    serveApi(['GET /api/v1/me' => [currentUserTermsJson(false)], 'PUT /api/v1/me/terms' => staleTermsVersion()]);

    $screen = Native::visit(AcceptTerms::Path);

    serveApi(['GET /api/v1/me' => [currentUserTermsJson(false, version: '2026-12-01T09:00:00Z')], 'PUT /api/v1/me/terms' => staleTermsVersion()]);

    $screen->tap('accept')
        ->assertNoNavigation()
        ->assertSee(AcceptTerms::ChangedAgain)
        ->assertSet('version', '2026-12-01T09:00:00Z')
        ->assertSee('Our terms of service changed on Dec 1, 2026. Read them, and accept them to keep using /dev/null.');

    serveApi(['GET /api/v1/me' => [currentUserTermsJson(false, version: '2026-12-01T09:00:00Z')], 'PUT /api/v1/me/terms' => [currentUserTermsJson(true, '2026-12-02T09:00:00Z', '2026-12-01T09:00:00Z')]]);

    $screen->tap('accept')->assertReplacedWith('/log');

    expect(sentTo('PUT', 'me/terms'))->toBe([
        json_encode(['version' => '2026-11-01T14:35:00Z']),
        json_encode(['version' => '2026-12-01T09:00:00Z']),
    ]);
});

it('says why when devnull cannot take the yes, and stays', function () {
    oweTerms();
    serveApi(['GET /api/v1/me' => [currentUserTermsJson(false)], 'PUT /api/v1/me/terms' => [['message' => 'Server Error'], 500]]);

    Native::visit(AcceptTerms::Path)
        ->tap('accept')
        ->assertNoNavigation()
        ->assertSet('error', 'Server Error')
        ->assertSee('Server Error');

    expect(app(Account::class)->termsToAccept())->toBe('2026-11-01T14:35:00Z');
});

it('lets you straight through when you accepted them on the web since', function () {
    oweTerms();
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(true, '2026-11-02T09:00:00Z')]]);

    Native::visit(AcceptTerms::Path)->assertReplacedWith('/log');

    expect(app(Account::class)->termsToAccept())->toBeNull();
});

it('goes to the log when there are no terms to accept', function () {
    fakeApi([]);

    Native::visit(AcceptTerms::Path)->assertReplacedWith('/log');

    Http::assertNothingSent();
});

it('opens the terms and privacy policy in the app', function () {
    oweTerms();
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(false)]]);

    Native::visit(AcceptTerms::Path)
        ->tap('terms-link')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/terms')
        ->tap('privacy-link')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/privacy');
});

it('signs out after a confirm, and forgets the terms owed', function () {
    oweTerms();
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(false)]]);

    Native::visit(AcceptTerms::Path)
        ->tap('sign-out')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Sign out?')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Sign out', 'id' => AcceptTerms::SignOutAlert])
        ->assertReplacedWith('/onboarding');

    expect(app(Account::class)->user())->toBeNull()
        ->and(app(Account::class)->termsToAccept())->toBeNull();
});

it('deletes the account after a confirm', function () {
    oweTerms();
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(false)], 'DELETE /api/v1/me' => [null, 204]]);

    Native::visit(AcceptTerms::Path)
        ->press('delete-account')
        ->assertNativeCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Delete your account?')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => AcceptTerms::DeleteAccountAlert])
        ->assertReplacedWith('/onboarding');

    expect(sentTo('DELETE', 'me'))->toHaveCount(1)
        ->and(app(Account::class)->user())->toBeNull();
});
