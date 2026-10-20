<?php

use App\NativeComponents\WebLink;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
});

/**
 * Opens $uri as NativePHP does a link that arrives while the app is open: pushed over the screen at $below.
 */
function openLink(string $uri, string $below): WebLink
{
    $link = new WebLink;
    $router = new NativeRouter;
    (function () use ($link, $uri, $below): void {
        $this->stack[] = ['component' => null, 'uri' => $below, 'params' => []];
        $this->stack[] = ['component' => $link, 'uri' => $uri, 'params' => []];
    })->call($router);
    $link->setRouter($router);
    $link->mountComponent();

    return $link;
}

it('opens a link in the tab that is showing, in place of itself', function (string $uri, string $screen) {
    $link = openLink($uri, '/notifications/commits/c1');

    expect($link->getNavigationIntent()?->type)->toBe(NavigationIntent::REPLACE)
        ->and($link->getNavigationIntent()?->uri)->toBe('/notifications'.$screen);
})->with([
    'a profile' => ['/@linus', '/users/linus'],
    'a profile, with a query string' => ['/@linus?ref=share', '/users/linus'],
    'followers' => ['/@linus/followers', '/users/linus/followers'],
    'following' => ['/@linus/following', '/users/linus/following'],
    'a commit' => ['/@linus/commits/9f1c', '/commits/9f1c'],
    "a commit's photo" => ['/@linus/commits/9f1c/photos/a7e2', '/commits/9f1c'],
    'a remote' => ['/remotes/@linus', '/conversations/linus'],
]);

it('lands on a screen the app has', function (string $screen) {
    expect(NativeRouter::resolve($screen))->not->toBeNull();
})->with(['/log/users/linus', '/log/users/linus/followers', '/log/commits/9f1c', '/log/conversations/linus']);

it('hands a link the app has no screen for to the in-app browser and goes back', function () {
    $bridge = Native::fakeBridge();

    $link = openLink('/@linus/og.png', '/log');

    expect($link->getNavigationIntent()?->type)->toBe(NavigationIntent::BACK);
    $bridge->assertCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/@linus/og.png');
});

it('goes back to onboarding without opening a link on a phone that is not signed in', function () {
    $bridge = Native::fakeBridge();

    $link = openLink('/@linus', '/onboarding');

    expect($link->getNavigationIntent()?->type)->toBe(NavigationIntent::BACK);
    $bridge->assertNotCalled('Browser.OpenInApp');
});

it('starts the app through launch, and opens the link over the log once it has drawn', function () {
    signIn();
    Http::fake(['devnull.test/api/v1/log/following' => Http::response(pageJson([commitJson(['body' => 'First commit'])]))]);

    $launch = Native::visit('/@linus/commits/9f1c')->assertReplacedWith('/')->follow();

    $log = $launch->assertReplacedWith('/log')->follow();

    $log->assertSee('First commit')->assertNavigatedTo('/log/commits/9f1c');
});

it('starts a phone that is not signed in on onboarding, without the link', function () {
    fakeKeychain();

    $launch = Native::visit('/@linus')->assertReplacedWith('/')->follow();

    $launch->assertReplacedWith('/onboarding');
    expect($launch->navigationIntent()?->data)->toBe([]);
});

it('starts the app as usual for a link it has no screen for, which opens in the in-app browser', function () {
    signIn();

    $screen = Native::visit('/@linus/feed.atom')->assertReplacedWith('/');

    expect($screen->navigationIntent()?->data)->toBe([]);
    $screen->assertNativeCalled('Browser.OpenInApp', fn (array $params): bool => $params['url'] === 'http://devnull.test/@linus/feed.atom');
});

it('opens a starting link only once, not again when the log changes lanes', function () {
    signIn();
    Http::fake(['devnull.test/api/v1/log/*' => Http::response(pageJson([]))]);

    $log = Native::visit('/log', ['link' => '/log/commits/9f1c'])->assertNavigatedTo('/log/commits/9f1c');
    $log->instance()->resetNavigationIntent();

    tapLogTab($log, 'latest')->assertNoNavigation();
});
