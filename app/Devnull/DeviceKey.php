<?php

namespace App\Devnull;

use App\Devnull\Exceptions\KeychainUnavailable;

/**
 * This device's SSH key. Only the seed is stored, and only in the Keychain.
 */
final class DeviceKey
{
    public const string StorageKey = 'devnull.device-key';

    public function __construct(private KeyStore $store) {}

    /**
     * Loads the key, making one the first time it's asked for.
     *
     * A locked or failing Keychain is an error, never a reason to make a new
     * key: that would orphan the key the user already added to GitHub.
     */
    public function current(): SshKey
    {
        $stored = $this->store->read(self::StorageKey);

        if ($stored->found()) {
            $seed = base64_decode((string) $stored->value, true);

            if ($seed === false) {
                throw new KeychainUnavailable('fatal: the device key in the Keychain is unreadable.');
            }

            return SshKey::fromSeed($seed);
        }

        if (! $stored->missing()) {
            throw new KeychainUnavailable('fatal: could not read the device key from the Keychain. '.($stored->message ?? 'Unlock the phone and try again.'));
        }

        $key = SshKey::generate();

        if (! $this->store->set(self::StorageKey, base64_encode($key->seed()))) {
            throw new KeychainUnavailable('fatal: could not save a new device key to the Keychain.');
        }

        return $key;
    }

    public function forget(): void
    {
        $this->store->delete(self::StorageKey);
    }
}
