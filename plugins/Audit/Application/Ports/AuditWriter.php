<?php

declare(strict_types=1);

namespace Plugins\Audit\Application\Ports;

/**
 * Persistence seam for the append-only central `audit_log` trail (write side).
 *
 * Implemented by {@see \Plugins\Audit\Infrastructure\Persistence\AuditTrail},
 * consumed ONLY by {@see \Plugins\Audit\Application\Services\AuditService}. The
 * repository MAY throw (RepositoryException) — the best-effort policy lives in
 * the service, not here.
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
