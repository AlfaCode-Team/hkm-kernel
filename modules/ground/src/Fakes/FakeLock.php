<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\AbstractLock;

/**
 * The Lock a {@see FakeCache} hands out.
 *
 * Only the three store-specific operations are implemented. block()'s
 * timeout and always-release semantics come from AbstractLock, which is where
 * the contract puts them — reimplementing them here is precisely the drift
 * between backing stores that the base class exists to prevent.
 */
final class FakeLock extends AbstractLock
{
    public function __construct(
        private readonly FakeCache $cache,
        string $name,
        int $seconds,
        string $owner,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        return $this->cache->acquireLock($this->name, $this->owner);
    }

    public function release(): bool
    {
        return $this->cache->releaseLock($this->name, $this->owner);
    }

    public function forceRelease(): void
    {
        $this->cache->forceReleaseLock($this->name);
    }
}
