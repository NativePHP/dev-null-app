<?php

use App\Devnull\SshKey;
use App\Devnull\SshSignature;

it('returns the fingerprint of the key that signed the message', function () {
    $key = SshKey::fromSeed(testSeed());

    expect(SshSignature::verify($key->sign('a high score', 'devnull-nata-nullers'), 'a high score', 'devnull-nata-nullers'))
        ->toBe($key->fingerprint());
});

it('checks a signature ssh-keygen made', function () {
    // Produced by `ssh-keygen -Y sign -n devnull-api` (see SshKeyTest).
    $signature = 'U1NIU0lHAAAAAQAAADMAAAALc3NoLWVkMjU1MTkAAAAgA6EHv/POEL4dcN0Y50vAmWfk1jCbpQ1fHdyGZBJVMbgAAAALZGV2bnVsbC1hcGkAAAAAAAAABnNoYTUxMgAAAFMAAAALc3NoLWVkMjU1MTkAAABAQU4un8l6tO+Bf0ZnHV7BgkPyTLCLyMJZUYIgFdVkoGuvh7GwuRRnDhISWyG2L9Uy74s0dxamphyE7cU0XPXyAA==';
    $message = "1760000000\n0123456789abcdef0123456789abcdef\nPOST\n/api/v1/commits\n{\"body\":\"Shipped the mobile app\"}";

    expect(SshSignature::verify($signature, $message, 'devnull-api'))->toBe(testKey()->fingerprint());
});

it('rejects a changed message, another namespace and garbage', function (string $signature, string $message, string $namespace) {
    expect(SshSignature::verify($signature, $message, $namespace))->toBeNull();
})->with([
    'changed message' => fn () => [testKey()->sign('score 10', 'devnull-nata-nullers'), 'score 99', 'devnull-nata-nullers'],
    'other namespace' => fn () => [testKey()->sign('score 10', 'devnull-api'), 'score 10', 'devnull-nata-nullers'],
    'not base64' => ['!!!', 'score 10', 'devnull-nata-nullers'],
    'truncated' => fn () => [substr(testKey()->sign('score 10', 'devnull-nata-nullers'), 0, 60), 'score 10', 'devnull-nata-nullers'],
]);
