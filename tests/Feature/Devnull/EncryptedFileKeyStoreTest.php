<?php

use App\Devnull\DeviceKey;
use App\Devnull\KeyStore;
use App\Devnull\KeyStores\EncryptedFile;
use App\Devnull\KeyStores\Keychain;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    Storage::fake('local');
});

it('keeps the device key in an encrypted file', function () {
    $key = (new DeviceKey(new EncryptedFile))->current();

    $stored = Storage::disk('local')->get('keys/'.DeviceKey::StorageKey);

    expect($stored)->not->toBeNull()
        ->not->toContain(base64_encode($key->seed()))
        ->and((new DeviceKey(new EncryptedFile))->current()->seed())->toBe($key->seed());
});

it('makes a new key once the old one is forgotten', function () {
    $deviceKey = new DeviceKey(new EncryptedFile);
    $first = $deviceKey->current();

    $deviceKey->forget();

    expect(Storage::disk('local')->exists('keys/'.DeviceKey::StorageKey))->toBeFalse()
        ->and($deviceKey->current()->seed())->not->toBe($first->seed());
});

it('reports a file it cannot decrypt instead of making a new key', function () {
    Storage::disk('local')->put('keys/'.DeviceKey::StorageKey, 'not encrypted');

    $result = (new EncryptedFile)->read(DeviceKey::StorageKey);

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe('UNREADABLE');
});

it('uses the file store only when asked to', function (string $store, string $expected) {
    config(['devnull.key_store' => $store]);

    expect(app(KeyStore::class))->toBeInstanceOf($expected);
})->with([
    'file' => ['file', EncryptedFile::class],
    'keychain' => ['keychain', Keychain::class],
]);

it('never calls the Keychain when using the file store', function () {
    config(['devnull.key_store' => 'file']);
    $bridge = Native::fakeBridge();

    app(DeviceKey::class)->current();

    $bridge->assertNotCalled('SecureStorage.Get');
    $bridge->assertNotCalled('SecureStorage.Set');
});
