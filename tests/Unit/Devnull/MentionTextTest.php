<?php

use App\Devnull\Data\Mention;

it('finds GitHub mentions using the web bio rules', function (string $text, array $usernames) {
    expect(array_map(fn (Mention $mention): string => $mention->username, Mention::inText($text)))->toBe($usernames);
})->with([
    ['Builds @laravel with @nunomaduro.', ['laravel', 'nunomaduro']],
    ['@nativephp-mobile', ['nativephp-mobile']],
    ['mail@example.com @inertiajs/react @vue.js', []],
    ['path/@owner @@owner prefix@owner @invalid--name @invalid-', []],
    ['@'.str_repeat('a', 40), []],
    ['No tags here', []],
]);

it('counts mention positions in Unicode code points', function () {
    $mention = Mention::inText('🚀 @laravel')[0];
    expect($mention->offset)->toBe(2)->and($mention->length)->toBe(8);
});
