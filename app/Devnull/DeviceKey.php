<?php

namespace App\Devnull;

use App\Devnull\Exceptions\KeychainUnavailable;

/**
 * This device's SSH key. Only the seed is stored, and only in the Keychain.
 *
 * One per app process (see AppServiceProvider), and it holds the key once it
 * has read or made it: every signed request needs the key, and reading it
 * from Android's Keystore takes about 150ms, several times per screen. It's
 * the same key for the life of the install, so there's nothing per request in
 * it, and forget() drops it with the stored seed.
 */
final class DeviceKey
{
    public const string StorageKey = 'devnull.device-key';

    private ?SshKey $key = null;

    public function __construct(private KeyStore $store) {}

    /**
     * Loads the key, making one the first time it's asked for.
     *
     * A locked or failing Keychain is an error, never a reason to make a new
     * key: that would orphan the key devnull already approved for this phone.
     */
    public function current(): SshKey
    {
        $existing = $this->existing();

        if ($existing !== null) {
            return $existing;
        }

        $key = SshKey::generate();

        if (! $this->store->set(self::StorageKey, base64_encode($key->seed()))) {
            throw new KeychainUnavailable('fatal: could not save a new device key to the Keychain.');
        }

        return $this->key = $key;
    }

    /**
     * Loads the key without making one: null when there isn't one yet.
     */
    public function existing(): ?SshKey
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $stored = $this->store->read(self::StorageKey);

        if ($stored->found()) {
            $seed = base64_decode((string) $stored->value, true);

            if ($seed === false) {
                throw new KeychainUnavailable('fatal: the device key in the Keychain is unreadable.');
            }

            return $this->key = SshKey::fromSeed($seed);
        }

        if (! $stored->missing()) {
            throw new KeychainUnavailable('fatal: could not read the device key from the Keychain. '.($stored->message ?? 'Unlock the phone and try again.'));
        }

        return null;
    }

    public function forget(): void
    {
        $this->key = null;
        $this->store->delete(self::StorageKey);
    }
}
