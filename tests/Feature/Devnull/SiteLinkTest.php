<?php

use App\Devnull\SiteLink;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
});

it('finds the app screen for a page on dev/null', function (string $url, string $screen) {
    expect(SiteLink::screen($url))->toBe($screen);
})->with([
    'a profile' => ['https://dev-null.sh/@ada', '/users/ada'],
    'a profile on www' => ['https://www.dev-null.sh/@ada', '/users/ada'],
    'a profile over http' => ['http://dev-null.sh/@ada', '/users/ada'],
    'a host in capitals, keeping the username as written' => ['HTTPS://DEV-NULL.SH/@Hacksore', '/users/Hacksore'],
    'the longest username GitHub allows' => ['https://dev-null.sh/@nuno-maduro-'.str_repeat('a', 27), '/users/nuno-maduro-'.str_repeat('a', 27)],
    'followers' => ['https://dev-null.sh/@ada/followers', '/users/ada/followers'],
    'following' => ['https://dev-null.sh/@ada/following', '/users/ada/following'],
    'a commit' => ['https://dev-null.sh/@ada/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8', '/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8'],
    'a commit id in capitals' => ['https://dev-null.sh/@ada/commits/01A14A9D-7971-73EA-8F35-135FE5149CA8', '/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8'],
    "a commit's photo" => ['https://dev-null.sh/@ada/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8/photos/2', '/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8'],
    'a trailing slash' => ['https://dev-null.sh/@ada/followers/', '/users/ada/followers'],
    'a query and a fragment' => ['https://dev-null.sh/@ada/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8/?ref=share#replies', '/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8'],
    'a Remotes conversation' => ['https://dev-null.sh/remotes/@ada', '/conversations/ada'],
    'an org' => ['https://dev-null.sh/remotes/orgs/0199E5C4-0000-7000-8000-000000000002', '/orgs/0199e5c4-0000-7000-8000-000000000002'],
    'the host the app talks to' => ['http://devnull.test/@ada', '/users/ada'],
]);

it('finds no app screen for anything else', function (?string $url) {
    expect(SiteLink::screen($url))->toBeNull();
})->with([
    'nothing' => [null],
    'a host ending like dev/null' => ['https://dev-null.sh.evil.com/@ada'],
    'a subdomain' => ['https://api.dev-null.sh/@ada'],
    'another site' => ['https://evil.com/@ada'],
    'dev/null as the user before another host' => ['https://dev-null.sh@evil.com/@ada'],
    'another host before a backslash' => ['https://evil.com\@dev-null.sh/@ada'],
    'no scheme' => ['dev-null.sh/@ada'],
    'another scheme' => ['javascript:alert(1)//dev-null.sh/@ada'],
    'ftp' => ['ftp://dev-null.sh/@ada'],
    'the home page' => ['https://dev-null.sh/'],
    "a profile's image" => ['https://dev-null.sh/@ada/og.png'],
    "a profile's feed" => ['https://dev-null.sh/@ada/feed.atom'],
    "a profile's preview" => ['https://dev-null.sh/@ada/preview'],
    "a profile's activity graph" => ['https://dev-null.sh/@ada/graph.svg'],
    "a commit's image" => ['https://dev-null.sh/@ada/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8/og.png'],
    'a commit id that is not a UUID' => ['https://dev-null.sh/@ada/commits/c1'],
    'an org id that is not a UUID' => ['https://dev-null.sh/remotes/orgs/core'],
    'the settings' => ['https://dev-null.sh/settings/profile'],
    'the Remotes inbox' => ['https://dev-null.sh/remotes'],
    'the notifications' => ['https://dev-null.sh/notifications'],
    'two trailing slashes' => ['https://dev-null.sh/@ada//'],
    'a username starting with a hyphen' => ['https://dev-null.sh/@-ada'],
    'a username ending with a hyphen' => ['https://dev-null.sh/@ada-'],
    'a username with two hyphens in a row' => ['https://dev-null.sh/@a--da'],
    'a username longer than GitHub allows' => ['https://dev-null.sh/@'.str_repeat('a', 40)],
]);
