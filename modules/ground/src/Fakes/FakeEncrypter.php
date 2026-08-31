<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;

/**
 * An EncryptionPort that ENCODES rather than encrypts.
 *
 * Reversible base64 with a marker prefix. This is not confidentiality and is
 * never safe outside a test — but it is the right double, because the property a
 * test needs is that the round trip is faithful and that ciphertext is visibly
 * not plaintext (so a cookie assertion catches a value that was never encrypted
 * at all). Asserting on real ciphertext would only assert that OpenSSL works.
 *
 * decrypt() rejects a payload this class did not produce, which is what catches
 * the genuinely interesting bug: code that hands the encrypter something already
 * decrypted, or decrypts twice.
 */
final class FakeEncrypter implements EncryptionPort
{
    private const PREFIX = 'enc:';

    public function encrypt(mixed $value, bool $serialize = true): string
    {
        $payload = $serialize ? serialize($value) : (string) $value;

        return self::PREFIX . base64_encode($payload);
    }

    public function decrypt(string $payload, bool $unserialize = true): mixed
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new \RuntimeException('FakeEncrypter: payload was not produced by this encrypter.');
        }

        $decoded = base64_decode(substr($payload, \strlen(self::PREFIX)), true);
        if ($decoded === false) {
            throw new \RuntimeException('FakeEncrypter: payload is not valid base64.');
        }

        if (!$unserialize) {
            return $decoded;
        }

        // allowed_classes: false — an unserialize() that instantiates whatever
        // the payload names is the vulnerability class this port exists around,
        // and a permissive double would hide it.
        $value = @unserialize($decoded, ['allowed_classes' => false]);
        if ($value === false && $decoded !== serialize(false)) {
            throw new \RuntimeException('FakeEncrypter: payload could not be unserialized.');
        }

        return $value;
    }

    public function encryptString(string $value): string
    {
        return $this->encrypt($value, serialize: false);
    }

    public function decryptString(string $payload): string
    {
        return (string) $this->decrypt($payload, unserialize: false);
    }

    /** True when a value looks like it came out of this encrypter. */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }
}
