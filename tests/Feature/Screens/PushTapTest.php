<?php

use App\Devnull\Account;
use App\NativeComponents\AcceptTerms;
use App\NativeComponents\Notifications;
use App\NativeComponents\Remotes;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    fakeApi([
        'GET /api/v1/remotes' => [remotesInboxJson([])],
        'GET /api/v1/notifications' => [['data' => [], 'meta' => ['next_cursor' => null]]],
    ]);
});

it('opens the tab a push belongs to with its screen to open on top', function (string $query, string $tab, ?array $data) {
    signIn();

    $screen = Native::visit('/push?'.$query)->assertReplacedWith($tab);

    expect($screen->navigationIntent()->data)->toBe($data ?? []);
})->with([
    'a reply' => ['type=replied&notification_id=n1&commit_id=c1&handle=ada', '/notifications', ['open' => '/commits/c1']],
    'a mention' => ['type=mentioned&notification_id=n1&commit_id=c1&handle=ada', '/notifications', ['open' => '/commits/c1']],
    'a follow' => ['type=followed&notification_id=n1&handle=ada', '/notifications', ['open' => '/users/ada']],
    'a direct message' => ['type=message&conversation_id=v1&kind=direct&handle=ada', '/remotes', ['open' => '/conversations/ada']],
    'a ping in an org' => ['type=ping&conversation_id=v1&kind=org', '/remotes', ['open' => '/orgs/v1']],
    'a message with nothing to open' => ['type=message&conversation_id=v1', '/remotes', null],
    'a push of a kind this build does not know' => ['type=starred', '/notifications', null],
]);

it('checks the sign-in before opening a push, as the first screen does', function () {
    fakeKeychain();

    Native::visit('/push?type=replied&commit_id=c1')->assertReplacedWith('/onboarding');
});

it('puts owed terms in front of a push', function () {
    signIn();
    app(Account::class)->rememberTermsToAccept('2026-11-01T14:35:00Z');

    Native::visit('/push?type=replied&commit_id=c1')->assertReplacedWith(AcceptTerms::Path);
});

it('opens the pushed screen within the tab, so back lands on the tab', function (string $tab, string $uri, string $open) {
    signIn();

    Native::visit($uri, ['open' => $open])->assertNavigatedTo($uri.$open);
})->with([
    [Notifications::class, '/notifications', '/commits/c1'],
    [Remotes::class, '/remotes', '/conversations/ada'],
]);

it('opens nothing on top of a tab opened the usual way', function (string $uri) {
    signIn();

    expect(Native::visit($uri)->navigationIntent())->toBeNull();
})->with(['/notifications', '/remotes']);

it('fills a tab in at once when it opens a pushed screen, so back never lands on skeleton rows', function (string $uri) {
    signIn();
    config(['devnull.skeleton_first' => true]);

    Native::visit($uri, ['open' => '/users/ada'], platform: 'android')->assertSet('skeletonFrame', false);
})->with(['/notifications', '/remotes']);
