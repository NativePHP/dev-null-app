<?php

use App\Devnull\Client;
use App\Devnull\Data\DraftImage;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\RequestSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    fakeKeychain(testSeed());
});

/**
 * The one request devnull was sent.
 */
function sentGuildWrite(): Request
{
    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(1);

    return $recorded[0][0];
}

it('creates a guild as signed JSON', function () {
    fakeApi(['POST /api/v1/guilds' => [['data' => guildJson(['handle' => 'rustlang', 'name' => 'Rust Lang'])], 201]]);

    $saved = app(Client::class)->createGuild('Rust Lang', 'All about Rust.', '🦀');

    $request = sentGuildWrite();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('http://devnull.test/api/v1/guilds')
        ->and($request->data())->toBe(['name' => 'Rust Lang', 'description' => 'All about Rust.', 'emoji' => '🦀'])
        ->and($saved->guild?->handle)->toBe('rustlang')
        ->and($saved->isHeld())->toBeFalse();
});

it('creates a guild with its icon as multipart', function () {
    fakeApi(['POST /api/v1/guilds' => [['data' => guildJson()], 201]]);
    $icon = new DraftImage(testPhoto(), 'image/png', 40, 30);

    app(Client::class)->createGuild('Rust', 'All about Rust.', '🦀', $icon);

    $parts = multipartParts(sentGuildWrite());

    expect(array_column($parts, 'name'))->toBe(['payload', 'images[]'])
        ->and(json_decode($parts[0]['contents'], true))->toBe(['name' => 'Rust', 'description' => 'All about Rust.', 'emoji' => '🦀'])
        ->and($parts[1]['contents'])->toBe(file_get_contents($icon->path));
});

it('says a held guild is waiting for review', function () {
    fakeApi(['POST /api/v1/guilds' => [['message' => 'Your guild is waiting for review. It goes live once an admin approves it.'], 202]]);

    $saved = app(Client::class)->createGuild('Rust', 'All about Rust.', '🦀');

    expect($saved->isHeld())->toBeTrue()
        ->and($saved->heldMessage)->toBe('Your guild is waiting for review. It goes live once an admin approves it.')
        ->and($saved->guild)->toBeNull();
});

it('edits a guild as a signed JSON PUT', function () {
    fakeApi(['PUT /api/v1/guilds/rust' => [['data' => guildJson(['description' => 'Fearless.'])]]]);

    $saved = app(Client::class)->updateGuild('rust', 'Rust', 'Fearless.', '🦀', removeIcon: true);

    $request = sentGuildWrite();

    expect($request->method())->toBe('PUT')
        ->and($request->data())->toBe(['name' => 'Rust', 'description' => 'Fearless.', 'emoji' => '🦀', 'remove_icon' => true])
        ->and($saved->guild?->description)->toBe('Fearless.');
});

it('edits a guild with a new icon as a POST that overrides to PUT, signed as PUT', function () {
    fakeApi(['POST /api/v1/guilds/rust' => [['data' => guildJson()]]]);
    $icon = new DraftImage(testPhoto(), 'image/png', 40, 30);

    app(Client::class)->updateGuild('rust', 'Rust', 'Fearless.', '🦀', removeIcon: false, icon: $icon);

    $request = sentGuildWrite();
    $parts = multipartParts($request);
    $message = RequestSigner::message(
        $request->header('X-Devnull-Timestamp')[0],
        $request->header('X-Devnull-Nonce')[0],
        'PUT',
        '/api/v1/guilds/rust',
        hash_file('sha256', $icon->path)."\n".$parts[0]['contents'],
    );

    expect($request->method())->toBe('POST')
        ->and($request->header('X-HTTP-Method-Override'))->toBe(['PUT'])
        ->and(array_column($parts, 'name'))->toBe(['payload', 'images[]'])
        ->and(json_decode($parts[0]['contents'], true))->toBe(['name' => 'Rust', 'description' => 'Fearless.', 'emoji' => '🦀', 'remove_icon' => false])
        ->and($request->header('X-SSH-Signature')[0])->toBe(testKey()->sign($message, 'devnull-api-images'));
});

it('pins and unpins a commit on the board', function () {
    fakeApi([
        'PUT /api/v1/guilds/rust/commits/c1/pin' => [null, 204],
        'DELETE /api/v1/guilds/rust/commits/c1/pin' => [null, 204],
    ]);

    app(Client::class)->pinGuildCommit('rust', 'c1');
    app(Client::class)->unpinGuildCommit('rust', 'c1');

    expect(Http::recorded()->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())->all())->toBe([
        'PUT http://devnull.test/api/v1/guilds/rust/commits/c1/pin',
        'DELETE http://devnull.test/api/v1/guilds/rust/commits/c1/pin',
    ]);
});

it('throws the API error when the moderator was removed', function () {
    fakeApi(['PUT /api/v1/guilds/rust' => [['message' => 'Not Found.'], 404]]);

    app(Client::class)->updateGuild('rust', 'Rust', 'Fearless.', '🦀', removeIcon: false);
})->throws(ApiException::class);

it('throws the API error when an edit with an icon is refused', function () {
    fakeApi(['POST /api/v1/guilds/rust' => [['message' => 'The images.0 field must not be greater than 2048 kilobytes.', 'errors' => ['images.0' => ['The images.0 field must not be greater than 2048 kilobytes.']]], 422]]);

    app(Client::class)->updateGuild('rust', 'Rust', 'Fearless.', '🦀', removeIcon: false, icon: new DraftImage(testPhoto(), 'image/png', 40, 30));
})->throws(ApiException::class);
