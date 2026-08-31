<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;

/**
 * A HashingPort that is deliberately cheap.
 *
 * bcrypt/argon are slow BY DESIGN — that is the whole security property — and a
 * suite that hashes a password per test pays it hundreds of times. This produces
 * a stable, verifiable digest in microseconds.
 *
 * It is NOT a password hash and must never be bound outside a test: the digest
 * is unsalted and fast, which is the exact combination a real hasher exists to
 * avoid. `needsRehash()` is controllable so the rehash-on-login branch, which
 * otherwise never runs in tests, can be exercised.
 */
final class FakeHasher implements HashingPort
{
    private const PREFIX = 'ground$';

    public bool $needsRehash = false;

    /** @var list<string> plaintexts passed to make(), in order */
    public array $hashed = [];

    public function make(string $value, array $options = []): string
    {
        $this->hashed[] = $value;

        return $this->digest($value);
    }

    public function check(string $value, string $hashedValue): bool
    {
        // digest(), not make(): verifying a password is not hashing one, and
        // recording it as such would corrupt `$hashed` for any test asserting
        // how many times the code under test actually hashed something.
        return hash_equals($this->digest($value), $hashedValue);
    }

    private function digest(string $value): string
    {
        return self::PREFIX . hash('sha256', $value);
    }

    public function needsRehash(string $hashedValue, array $options = []): bool
    {
        // A digest this fake did not produce always needs rehashing — that is
        // what a real hasher reports when the algorithm changed under it.
        if (!str_starts_with($hashedValue, self::PREFIX)) {
            return true;
        }

        return $this->needsRehash;
    }

    /** Force the rehash-on-login branch for the next check. */
    public function shouldNeedRehash(bool $needs = true): self
    {
        $this->needsRehash = $needs;

        return $this;
    }
}
