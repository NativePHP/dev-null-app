<?php

use App\Devnull\Account;
use App\NativeComponents\AcceptTerms;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    app(Account::class)->rememberTimezone('Europe/Lisbon');
});

it('says which terms you accepted and when, just before deleting the account', function (?string $acceptedAt, string $sentence) {
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(true, $acceptedAt)]]);

    $screen = Native::visit('/profile/device')
        ->assertNoNavigation()
        ->assertSee('Terms and privacy')
        ->assertSee($sentence);

    $sections = findNodes($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['sign-out', 'terms-section', 'delete-section'], true));

    expect(array_column($sections, 'ref'))->toBe(['sign-out', 'terms-section', 'delete-section']);
})->with([
    'with the day' => ['2026-11-02T09:00:00Z', 'You accepted the terms of Nov 1, 2026 on Nov 2, 2026.'],
    'without the day' => [null, 'You accepted the terms of Nov 1, 2026.'],
]);

it('says when you have not accepted the terms in force, until devnull sends you to accept them', function (?string $acceptedAt, string $sentence) {
    keep('me', currentUserTermsJson(false, $acceptedAt));
    holdRefreshes();
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(false, $acceptedAt)]]);

    $screen = Native::visit('/profile/device')->assertSee($sentence);

    finishRefreshes($screen)->assertReplacedWith(AcceptTerms::Path);
})->with([
    'an earlier version' => ['2026-10-05T10:00:00Z', 'You accepted an earlier version on Oct 5, 2026. The terms changed on Nov 1, 2026.'],
    'never accepted' => [null, "You haven't accepted the terms of Nov 1, 2026 yet."],
]);

it('shows only the links when devnull does not say which terms are in force', function () {
    fakeApi(['GET /api/v1/me' => [currentUserJson()]]);

    Native::visit('/profile/device')
        ->assertSee('Terms of service')
        ->assertSee('Privacy policy')
        ->assertDontSee('accepted');
});

it('opens the terms and privacy policy in the app', function () {
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(true, '2026-11-02T09:00:00Z')]]);

    Native::visit('/profile/device')
        ->tap('terms-link')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/terms')
        ->tap('privacy-link')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/privacy');
});
