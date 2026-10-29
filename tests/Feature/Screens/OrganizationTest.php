<?php

use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

it('shows an organization as its page on the web does, and opens its links in the browser', function () {
    Http::fake(['devnull.test/api/v1/organizations/laravel' => Http::response(['data' => [
        'handle' => 'laravel',
        'name' => 'Laravel',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/958072',
        'bio' => 'The PHP framework for web artisans.',
        'location' => null,
        'website' => 'https://laravel.com',
    ]])]);

    $screen = finishRefreshes(Native::visit('/log/organizations/laravel'));

    expect(findNode($screen->tree(), fn (array $node): bool => ($node['ref'] ?? null) === 'profile-location'))->toBeNull();

    $screen->assertNavTitle('Laravel')
        ->assertSee('@laravel')
        ->assertSee('Organization')
        ->assertSee('The PHP framework for web artisans.')
        ->assertSee('laravel.com')
        ->assertSee('github.com/laravel')
        ->assertSee('$ git log --grep=@laravel')
        ->tap('profile-website')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://laravel.com')
        ->tap('profile-github')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'https://github.com/laravel');
});

it("says the page didn't load when devnull can't send the organization, and offers its page on the web", function () {
    Http::fake(['devnull.test/api/v1/organizations/*' => Http::response(['message' => 'Not Found.'], 404)]);

    $screen = finishRefreshes(Native::visit('/log/organizations/nobody'))->assertNavTitle('nobody');

    $screen->assertSee("This organization's page didn't load here.");
    expect(findNode($screen->tree(), fn (array $node): bool => in_array($node['ref'] ?? null, ['error', 'organization-header'], true)))->toBeNull();

    $screen->tap('empty-link')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/@nobody');
});
