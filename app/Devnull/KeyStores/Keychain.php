<?php

namespace App\Devnull\KeyStores;

use App\Devnull\KeyStore;
use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\SecureStorageResult;

/**
 * The iOS Keychain or Android Keystore, through the Secure Storage plugin.
 */
final class Keychain implements KeyStore
{
    public function read(string $key): SecureStorageResult
    {
        return SecureStorage::read($key);
    }

    public function set(string $key, string $value): bool
    {
        return SecureStorage::set($key, $value);
    }

    public function delete(string $key): void
    {
        SecureStorage::delete($key);
    }
}
