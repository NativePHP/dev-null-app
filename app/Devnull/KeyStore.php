<?php

namespace App\Devnull;

use Native\Mobile\SecureStorageResult;

/**
 * Where the device key's seed is kept between launches.
 */
interface KeyStore
{
    /**
     * Reads a value, saying why it isn't there when it isn't.
     */
    public function read(string $key): SecureStorageResult;

    public function set(string $key, string $value): bool;

    public function delete(string $key): void;
}
