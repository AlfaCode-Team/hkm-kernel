<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * Per-project OpenSwoole settings: where the server listens (the reverse-proxy
 * upstream), which extra routes nginx should forward, and how the process is
 * started when generating a systemd/supervisor unit.
 *
 * Only attached to a Site whose ServeModel is Swoole; FPM sites carry null.
 */
final readonly class SwooleOptions
{
    public function __construct(
        public string $host,
        public int $port,
        /** Dedicated WebSocket location, e.g. `/ws`. Null disables the extra block
         *  (the `/` proxy still carries the Upgrade headers). */
        public ?string $websocketPath = '/ws',
        /** Health-check location, e.g. `/health`. Null disables it. */
        public ?string $healthPath = null,
        /** PHP binary used by the generated service unit. */
        public string $php = '/usr/bin/php',
        /** Server entrypoint, relative to PROJECT_ROOT (or absolute). */
        public string $command = 'app/swoole/index.php',
        /** Worker count: a number, or `auto` to let the app decide. */
        public string $workers = 'auto',
        /**
         * Extra backends for load balancing, as `host:port` strings. The primary
         * host:port is always first; these are appended.
         * @var list<string>
         */
        public array $extraServers = [],
        /** Upstream balancing directive: least_conn | ip_hash | random | '' (round-robin). */
        public string $balance = 'least_conn',
        public int $maxFails = 3,
        public string $failTimeout = '10s',
        /** Idle upstream connections kept per worker. 0 disables the pool. */
        public int $keepalive = 64,
        public string $keepaliveTimeout = '',
        public int $keepaliveRequests = 0,
    ) {}

    /** `host:port` — the primary backend. */
    public function upstream(): string
    {
        return "{$this->host}:{$this->port}";
    }

    /**
     * Every backend in the pool: the primary first, then any extras (de-duped).
     * @return list<string>
     */
    public function servers(): array
    {
        return array_values(array_unique([$this->upstream(), ...$this->extraServers]));
    }
}
