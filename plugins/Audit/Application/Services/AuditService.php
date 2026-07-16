<?php

declare(strict_types=1);

namespace Plugins\Audit\Application\Services;

use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Audit\Application\Ports\AuditWriter;

/**
 * AuditService — the single implementation of the shared audit trail.
 *
 * Two durable sinks per entry:
 *   1. a structured JSON log line (tagged source=audit) routed to the platform
 *      log stream, so the trail survives even with no DB; and
 *   2. a best-effort row in the central `audit_log` table via {@see AuditWriter}.
 *
 * Best-effort policy lives HERE: an audit write must never break the action it
 * records, so a persistence failure is swallowed (the log line is the durable
 * fallback). Records identifiers + structured meta only — never PII.
 *
 * Attribution defaults: when a caller omits userId / tenantId, the request
 * actor (Identity id) and active tenant (Tenancy's `tenant.current`) are used.
 */
final class AuditService implements AuditServiceContract
{
    /** @var callable(string):void */
    private $sink;

    /**
     * @param AuditWriter|null $writer Central `audit_log` persistence. Null →
     *        log-only (tests / no DB available).
     * @param (callable(string):void)|null $sink Where each JSON line goes.
     *        Defaults to error_log(); inject a file/SIEM sink or a no-op in tests.
     * @param string|null $actorId Request Identity id — fallback for userId.
     * @param string|null $currentTenant Active tenant — fallback for tenantId.
     * @param string|null $clientIp Request origin IP — fallback for ip.
     */
    public function __construct(
        private readonly ?AuditWriter $writer = null,
        ?callable $sink = null,
        private readonly ?string $actorId = null,
        private readonly ?string $currentTenant = null,
        private readonly ?string $clientIp = null,
    ) {
        $this->sink = $sink ?? static fn (string $line) => error_log($line);
    }

    public function record(
        string $action,
        ?string $userId = null,
        ?string $tenantId = null,
        array $meta = [],
        ?string $ip = null,
    ): void {
        $userId   ??= ($this->actorId ?: null);
        $tenantId ??= ($this->currentTenant !== null && $this->currentTenant !== '' ? $this->currentTenant : null);
        $ip       ??= ($this->clientIp !== null && $this->clientIp !== '' ? $this->clientIp : null);

        $line = json_encode([
            'source'    => 'audit',
            'action'    => $action,
            'user'      => $userId,
            'tenant'    => $tenantId,
            'ip'        => $ip,
            'meta'      => $meta,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        ], JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            ($this->sink)($line);
        }

        if ($this->writer === null) {
            return;
        }

        try {
            $this->writer->write($action, $userId, $tenantId, $meta, $ip);
        } catch (\Throwable) {
            // Best-effort — the log line above is the durable fallback.
        }
    }
}
