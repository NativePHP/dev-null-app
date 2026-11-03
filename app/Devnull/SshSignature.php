<?php

namespace App\Devnull;

/**
 * Checks an SSHSIG blob like `ssh-keygen -Y check-novalidate`: the signature is
 * good for the key inside it. Whose key that is, it can't say.
 *
 * @see https://github.com/openssh/openssh-portable/blob/master/PROTOCOL.sshsig
 */
final class SshSignature
{
    private const string Magic = 'SSHSIG';

    /**
     * The fingerprint of the key that signed the message, or null when the
     * blob is malformed, for another namespace, or doesn't match the message.
     */
    public static function verify(string $signature, string $message, string $namespace): ?string
    {
        $blob = base64_decode($signature, true);

        if ($blob === false || ! str_starts_with($blob, self::Magic)) {
            return null;
        }

        $offset = strlen(self::Magic);
        $version = self::uint32($blob, $offset);
        $publicKey = self::string($blob, $offset);
        $signedNamespace = self::string($blob, $offset);
        $reserved = self::string($blob, $offset);
        $hashAlgorithm = self::string($blob, $offset);
        $wrapped = self::string($blob, $offset);

        if ($version !== 1 || $wrapped === null || $offset !== strlen($blob) || $signedNamespace !== $namespace) {
            return null;
        }

        if (! in_array($hashAlgorithm, ['sha256', 'sha512'], true)) {
            return null;
        }

        $keyOffset = 0;
        $keyType = self::string((string) $publicKey, $keyOffset);
        $key = self::string((string) $publicKey, $keyOffset);

        $signatureOffset = 0;
        $signatureType = self::string($wrapped, $signatureOffset);
        $bytes = self::string($wrapped, $signatureOffset);

        if ($keyType !== 'ssh-ed25519' || $signatureType !== 'ssh-ed25519' || strlen((string) $key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen((string) $bytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        $signedData = self::Magic
            .self::pack($namespace)
            .self::pack((string) $reserved)
            .self::pack($hashAlgorithm)
            .self::pack(hash($hashAlgorithm, $message, true));

        if (! sodium_crypto_sign_verify_detached((string) $bytes, $signedData, (string) $key)) {
            return null;
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) $publicKey, true)), '=');
    }

    private static function uint32(string $blob, int &$offset): ?int
    {
        if (strlen($blob) < $offset + 4) {
            return null;
        }

        $value = unpack('N', $blob, $offset)[1];
        $offset += 4;

        return $value;
    }

    /**
     * Reads an SSH wire string, or null when the blob runs short.
     */
    private static function string(string $blob, int &$offset): ?string
    {
        $length = self::uint32($blob, $offset);

        if ($length === null || strlen($blob) < $offset + $length) {
            return null;
        }

        $value = substr($blob, $offset, $length);
        $offset += $length;

        return $value;
    }

    private static function pack(string $bytes): string
    {
        return pack('N', strlen($bytes)).$bytes;
    }
}
