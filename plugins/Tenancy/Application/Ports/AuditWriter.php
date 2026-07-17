<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

/**
 * Persistence seam for the append-only central `audit_log` trail (write side).
 *
 * Implemented by {@see \Plugins\Tenancy\Infrastructure\Persistence\AuditTrail},
 * consumed ONLY by {@see \Plugins\Tenancy\Application\Services\AuditService}.
 * The repository MAY throw (RepositoryException) — the best-effort policy lives
 * in the service, not here.
 */
interface AuditWriter
{
    /**
     * @param array<string, scalar|null> $meta
     */
    public function write(
        string $action,
        ?string $userId = null,
        ?string $tenantId = null,
        array $meta = [],
        ?string $ip = null,
    ): void;
}
