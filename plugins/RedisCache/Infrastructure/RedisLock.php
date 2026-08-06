<?php

declare(strict_types=1);

namespace Plugins\RedisCache\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\AbstractLock;

/**
 * Redis-backed Lock — the only implementation that is genuinely safe across
 * processes, machines and containers.
 *
 * ACQUIRE  SET key <owner> NX PX <ttl>   — atomic test-and-set in one round trip.
 * RELEASE  Lua compare-and-delete        — see below; this is the important part.
 *
 * WHY RELEASE IS A LUA SCRIPT
 * ---------------------------
 * The obvious implementation is wrong:
 *
 *     if ($redis->get($key) === $owner) {   // (1) we still own it
 *         $redis->del($key);                // (2) ...delete it
 *     }
 *
 * Between (1) and (2) our TTL can expire and ANOTHER worker can acquire the same
 * lock. Our del() then deletes THEIR lock, and two workers run the critical
 * section simultaneously — the exact failure the lock existed to prevent. It is
 * rare, load-dependent, and effectively undebuggable in production.
 *
 * EVAL runs the compare and the delete as one atomic Redis operation, so the
 * window does not exist. Never replace this with GET + DEL.
 */
final class RedisLock extends AbstractLock
{
    /** KEYS[1] = lock key, ARGV[1] = owner token. Returns 1 when released. */
    private const RELEASE_SCRIPT = <<<'LUA'
    if redis.call("GET", KEYS[1]) == ARGV[1] then
        return redis.call("DEL", KEYS[1])
    end
    return 0
    LUA;

    public function __construct(
        private readonly RedisConnection $connection,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner ?? self::randomOwner());
    }

    public function acquire(): bool
    {
        $key    = $this->key();
        $client = $this->connection->client();

        // NX = only set when absent; PX = TTL in milliseconds.
        // A zero/negative TTL means "hold until released" — supported, but the
        // holder dying then strands the lock forever, so prefer a real TTL.
        $options = $this->seconds > 0
            ? ['NX', 'PX' => $this->seconds * 1000]
            : ['NX'];

        return (bool) $client->set($key, $this->owner, $options);
    }

    public function release(): bool
    {
        return (int) $this->connection->client()->eval(
            self::RELEASE_SCRIPT,
            [$this->key(), $this->owner],
            1, // the first argument is a KEY, the rest are ARGV
        ) === 1;
    }

    public function forceRelease(): void
    {
        $this->connection->client()->del($this->key());
    }

    private function key(): string
    {
        return $this->connection->prefix('lock:' . $this->name);
    }
}
