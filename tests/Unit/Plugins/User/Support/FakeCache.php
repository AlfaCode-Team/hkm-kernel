<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;
use Project\Infrastructure\ProcessLocalLock;

/** In-memory CachePort for lockout tests. */
final class FakeCache implements CachePort
{
    /** @var array<string,mixed> */
    public array $store = [];

    public function get(string $key): mixed { return $this->store[$key] ?? null; }
    public function set(string $key, mixed $value, ?int $ttl = null): bool { $this->store[$key] = $value; return true; }
    public function delete(string $key): bool { unset($this->store[$key]); return true; }
    public function has(string $key): bool { return array_key_exists($key, $this->store); }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $this->store[$key] ??= $callback();
    }

    public function increment(string $key, int $by = 1): int
    {
        $this->store[$key] = (int) ($this->store[$key] ?? 0) + $by;
        return $this->store[$key];
    }

    public function deletePattern(string $pattern): int { return 0; }
    public function flush(): bool { $this->store = []; $this->locks = []; return true; }

    /**
     * Lock table shared with every ProcessLocalLock handed out — single-process
     * only, which is exactly what a test needs.
     *
     * @var array<string, array{owner: string, expires: int}>
     */
    public array $locks = [];

    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return new ProcessLocalLock($this, $name, $seconds, $owner);
    }

    public function restoreLock(string $name, string $owner): Lock
    {
        return new ProcessLocalLock($this, $name, 0, $owner);
    }
}
