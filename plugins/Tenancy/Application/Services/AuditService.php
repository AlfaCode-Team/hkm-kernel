<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use Plugins\Tenancy\Application\Ports\AuditSink;
use Plugins\Tenancy\Application\Ports\AuditWriter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AuditService — application service for the append-only central audit trail.
 *
 * Sits between the domain services (Membership/Invitation/RefreshToken/…) and
 * the {@see AuditWriter} persistence seam. Owns the BEST-EFFORT policy: an audit
 * write must never break the action it records, so a persistence failure is
 * caught here and logged (PSR-3) rather than propagated. The security-critical
 * path is the membership check, not the log.
 */
final class AuditService implements AuditSink
{
    public function __construct(
        private readonly AuditWriter $writer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function record(
        string $action,
        ?string $userId = null,
        ?string $tenantId = null,
        array $meta = [],
        ?string $ip = null,
    ): void {
        try {
            $this->writer->write($action, $userId, $tenantId, $meta, $ip);
        } catch (\Throwable $e) {
            // Best-effort: never let an audit write fail the action it records —
            // but surface the failure to the log instead of discarding it.
            $this->logger->error('Audit trail write failed', [
                'action'    => $action,
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }
}
