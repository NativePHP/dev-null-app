<?php

use App\Devnull\Account;
use App\NativeComponents\AcceptTerms;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    app(Account::class)->rememberTimezone('Europe/Lisbon');
});

function termsRow(TestableComponent $screen, string $ref): ?string
{
    $node = nodeByRef($screen, $ref);

    return $node['text'] ?? $node['props']['text'] ?? null;
}

it('shows the terms in force beside the version you agreed to, just before deleting the account', function () {
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(true, '2026-11-02T09:00:00Z')]]);

    $screen = Native::visit('/profile/device')
        ->assertNoNavigation()
        ->assertSee('Terms and privacy')
        ->assertSee('current')
        ->assertSee('agreed to')
        ->assertSee('accepted');

    $sections = findNodes($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['sign-out', 'terms-section', 'delete-section'], true));

    expect(termsRow($screen, 'terms-current'))->toBe('Nov 1, 2026')
        ->and(termsRow($screen, 'terms-agreed'))->toBe('Nov 1, 2026')
        ->and(termsRow($screen, 'terms-accepted'))->toBe('Nov 2, 2026')
        ->and(array_column($sections, 'ref'))->toBe(['sign-out', 'terms-section', 'delete-section']);
});

it('says when what you agreed to is not the version in force, until devnull sends you to accept it', function (?string $acceptedAt, string $agreed, ?string $accepted) {
    keep('me', currentUserTermsJson(false, $acceptedAt));
    holdRefreshes();
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(false, $acceptedAt)]]);

    $screen = Native::visit('/profile/device');

    expect(termsRow($screen, 'terms-current'))->toBe('Nov 1, 2026')
        ->and(termsRow($screen, 'terms-agreed'))->toBe($agreed)
        ->and(rendersRef($screen, 'terms-accepted') ? termsRow($screen, 'terms-accepted') : null)->toBe($accepted);

    finishRefreshes($screen)->assertReplacedWith(AcceptTerms::Path);
})->with([
    'an older version' => ['2026-10-05T10:00:00Z', 'an older version', 'Oct 5, 2026'],
    'never accepted' => [null, 'none yet', null],
]);

it('shows only the links when devnull does not say which terms are in force', function () {
    fakeApi(['GET /api/v1/me' => [currentUserJson()]]);

    $screen = Native::visit('/profile/device')->assertSee('Terms of service')->assertSee('Privacy policy');

    expect(rendersRef($screen, 'terms-current'))->toBeFalse();
});

it('opens the terms and privacy policy in the app', function () {
    fakeApi(['GET /api/v1/me' => [currentUserTermsJson(true, '2026-11-02T09:00:00Z')]]);

    Native::visit('/profile/device')
        ->tap('terms-link')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/terms')
        ->tap('privacy-link')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/privacy');
});
