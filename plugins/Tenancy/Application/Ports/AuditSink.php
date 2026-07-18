<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

/**
 * Internal port for the append-only central audit trail (DIP seam — lets the
 * service be tested with a no-op/in-memory sink).
 */
interface AuditSink
{
    /**
     * @param array<string, scalar|null> $meta
     */
    public function record(
        string $action,
        ?string $userId = null,
        ?string $tenantId = null,
        array $meta = [],
        ?string $ip = null,
    ): void;
}
