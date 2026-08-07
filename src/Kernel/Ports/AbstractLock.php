<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\LockTimeoutException;

/**
 * AbstractLock — the Lock contract's shared semantics.
 *
 * Lives in Ports (alongside {@see HttpClientResponse}) because block()'s
 * behaviour is part of the CONTRACT, not an adapter detail: every backing store
 * must time out the same way, always release after a callback, and never block a
 * coroutine worker while waiting. Subclasses supply only the three store-specific
 * operations — acquire(), release(), forceRelease().
 *
 * Adapters MUST NOT reimplement block(); if they do, timeout semantics drift
 * between Redis, file and in-memory stores and callers cannot reason about them.
 */
abstract class AbstractLock implements Lock
{
    /** How long to wait between acquisition attempts inside block(). */
    private const RETRY_MICROSECONDS = 100_000; // 100ms

    /**
     * @param string $name    logical lock name (subclasses apply their own prefixing)
     * @param int    $seconds TTL in seconds; 0 = no expiry (discouraged)
     * @param string $owner   this instance's ownership token
     */
    public function __construct(
        protected readonly string $name,
        protected readonly int $seconds,
        protected readonly string $owner,
    ) {}

    public function owner(): string
    {
        return $this->owner;
    }

    public function block(int $seconds, ?callable $callback = null): mixed
    {
        $deadline = \microtime(true) + $seconds;

        while (!$this->acquire()) {
            if (\microtime(true) >= $deadline) {
                throw LockTimeoutException::for($this->name, $seconds);
            }
            $this->sleep(self::RETRY_MICROSECONDS);
        }

        if ($callback === null) {
            return true;
        }

        // ALWAYS release — a throwing callback must not strand the lock until TTL.
        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Generate an unguessable ownership token. Owner tokens are what make
     * release() safe: without one, any process could delete any lock.
     */
    protected static function randomOwner(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    /**
     * Coroutine-aware sleep — yields the coroutine under OpenSwoole/Swoole so a
     * waiting lock never stalls the whole worker; falls back to usleep() under
     * PHP-FPM/CLI. Mirrors the retry backoff in plugins/HttpClient.
     */
    protected function sleep(int $micros): void
    {
        if (\class_exists('\\OpenSwoole\\Coroutine') && \OpenSwoole\Coroutine::getCid() > 0) {
            \OpenSwoole\Coroutine::usleep($micros);
            return;
        }
        if (\class_exists('\\Swoole\\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::usleep($micros);
            return;
        }
        \usleep($micros);
    }
}
