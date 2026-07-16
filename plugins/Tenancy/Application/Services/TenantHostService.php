<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use Plugins\Tenancy\API\Contracts\TenantHostRegistryContract;
use Plugins\Tenancy\API\Contracts\TenantHostServiceContract;
use Plugins\Tenancy\API\DTOs\HostVerificationInstructions;
use Plugins\Tenancy\API\DTOs\HostVerificationResult;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\Application\Ports\DnsResolver;
use Plugins\Tenancy\Application\Ports\TenantHostStore;
use Plugins\Tenancy\Domain\Entities\TenantHost;
use Plugins\Tenancy\Domain\Exceptions\HostConflictException;
use Plugins\Tenancy\Domain\Exceptions\HostNotFoundException;
use Plugins\Tenancy\Domain\Exceptions\HostQuotaExceededException;
use Plugins\Tenancy\Domain\ValueObjects\Hostname;
use Plugins\Tenancy\Domain\ValueObjects\HostStatus;
use Plugins\Tenancy\Support\Token;

/**
 * TenantHostService — manages a tenant's custom domains and proves ownership by
 * SCANNING the live DNS records for a per-host verification token.
 *
 * Ownership model (DNS-01 style):
 *   1. add()    — store the host Pending with a random verification token and
 *                 hand back the TXT record the owner must publish.
 *   2. verify() — resolve the challenge TXT (and optional A target) from DNS; if
 *                 the published value matches the stored token, promote the host
 *                 to Verified (routable). Otherwise mark it Failed with the
 *                 observed records so the UI can explain why.
 *
 * Every method is scoped to a single tenant id (taken from the verified Identity
 * by the controller, never from the request body) so one tenant can never touch
 * another's hosts. The routing read-cache is invalidated on every mutation so a
 * newly-verified or removed host takes effect immediately.
 */
final class TenantHostService implements TenantHostServiceContract
{
    public function __construct(
        private readonly TenantHostStore $hosts,
        private readonly DnsResolver $dns,
        private readonly AuditServiceContract $audit,
        private readonly TenantHostRegistryContract $registry,
        /** DNS label the challenge TXT is published under, e.g. "_psp-verify". */
        private readonly string $challengePrefix = '_psp-verify',
        /** Value prefix so the TXT is self-describing, e.g. "psp-verify=". */
        private readonly string $valuePrefix = 'psp-verify=',
        /** Max hosts a single tenant may register (anti-abuse). 0 = unlimited. */
        private readonly int $maxHostsPerTenant = 25,
    ) {}

    public function list(string $tenantId): array
    {
        return $this->hosts->allForTenant($tenantId);
    }

    public function add(string $tenantId, string $hostname, ?string $expectedIp = null): HostVerificationInstructions
    {
        $host = Hostname::of($hostname);                 // validates + normalises (throws on bad input)
        $ip   = $this->normaliseIp($expectedIp);

        if ($this->maxHostsPerTenant > 0 && count($this->hosts->allForTenant($tenantId)) >= $this->maxHostsPerTenant) {
            throw new HostQuotaExceededException($tenantId, $this->maxHostsPerTenant);
        }

        if ($this->hosts->hostnameTaken($host->value)) {
            throw HostConflictException::for($host->value);
        }

        $token  = Token::random(32);
        $hostId = $this->hosts->insert($tenantId, $host->value, $ip, $token);

        $this->registry->forget($host->value);          // a MISS may have been cached
        $this->audit->record('tenant_host.added', null, $tenantId, ['host_id' => $hostId, 'hostname' => $host->value]);

        return $this->buildInstructions($host->value, $token, $ip);
    }

    public function instructions(string $tenantId, int $hostId): HostVerificationInstructions
    {
        $host = $this->require($tenantId, $hostId);

        return $this->buildInstructions($host->hostname, $host->verificationToken, $host->ipAddress);
    }

    public function verify(string $tenantId, int $hostId): HostVerificationResult
    {
        $host = $this->require($tenantId, $hostId);

        $expectedValue = $this->valuePrefix . $host->verificationToken;
        $challengeName = $this->challengeName($host->hostname);

        // Scan the challenge sub-label first, then the apex as a fallback so an
        // owner who can only edit apex TXT records can still prove control.
        $foundTxt = array_values(array_unique(array_merge(
            $this->dns->txt($challengeName),
            $this->dns->txt($host->hostname),
        )));

        $txtMatches = $this->txtContains($foundTxt, $expectedValue);

        // Optional A-record pinning: only enforced when an expected IP was set.
        $foundIps = $host->ipAddress !== null ? $this->dns->ips($host->hostname) : [];
        $ipMatches = $host->ipAddress === null || in_array($host->ipAddress, $foundIps, true);

        if ($txtMatches && $ipMatches) {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->hosts->markStatus($tenantId, $hostId, HostStatus::Verified->value, $now);
            $this->registry->forget($host->hostname);
            $this->audit->record('tenant_host.verified', null, $tenantId, ['host_id' => $hostId, 'hostname' => $host->hostname]);

            return HostVerificationResult::ok($host->hostname, $foundTxt, $foundIps);
        }

        $reason = !$txtMatches
            ? 'TXT verification record not found or value mismatch.'
            : "Host does not resolve to the expected IP [{$host->ipAddress}].";

        // A host that was ALREADY verified but no longer proves ownership is a
        // revocation / potential takeover — demote to Failed and stop routing it
        // immediately. A host still awaiting its first proof simply stays Pending
        // (DNS propagation lag is not a failure), so the owner can keep retrying.
        if ($host->isVerified()) {
            $this->hosts->markStatus($tenantId, $hostId, HostStatus::Failed->value, null);
            $this->registry->forget($host->hostname);
            $this->audit->record('tenant_host.verification_revoked', null, $tenantId, ['host_id' => $hostId, 'reason' => $reason]);
        } else {
            $this->audit->record('tenant_host.verification_pending', null, $tenantId, ['host_id' => $hostId, 'reason' => $reason]);
        }

        return HostVerificationResult::fail($host->hostname, $reason, $foundTxt, $foundIps);
    }

    public function makePrimary(string $tenantId, int $hostId): TenantHost
    {
        $host = $this->require($tenantId, $hostId);

        if (!$host->isVerified()) {
            // Cannot canonicalise to an unproven host.
            throw HostNotFoundException::for($tenantId, (string) $hostId);
        }

        $this->hosts->setPrimary($tenantId, $hostId);
        $this->audit->record('tenant_host.primary_set', null, $tenantId, ['host_id' => $hostId, 'hostname' => $host->hostname]);

        return $this->require($tenantId, $hostId);
    }

    public function remove(string $tenantId, int $hostId): void
    {
        $host = $this->require($tenantId, $hostId);

        $this->hosts->softDelete($tenantId, $hostId);
        $this->registry->forget($host->hostname);
        $this->audit->record('tenant_host.removed', null, $tenantId, ['host_id' => $hostId, 'hostname' => $host->hostname]);
    }

    private function require(string $tenantId, int $hostId): TenantHost
    {
        $host = $this->hosts->find($tenantId, $hostId);
        if ($host === null) {
            throw HostNotFoundException::for($tenantId, (string) $hostId);
        }

        return $host;
    }

    private function buildInstructions(string $hostname, string $token, ?string $ip): HostVerificationInstructions
    {
        return new HostVerificationInstructions(
            hostname:       $hostname,
            txtRecordName:  $this->challengeName($hostname),
            txtRecordValue: $this->valuePrefix . $token,
            expectedIp:     $ip,
        );
    }

    private function challengeName(string $hostname): string
    {
        return $this->challengePrefix . '.' . $hostname;
    }

    /**
     * TXT values can be split into multiple strings or padded — compare both the
     * raw value and a whitespace-trimmed copy, case-insensitively on the prefix.
     *
     * @param string[] $found
     */
    private function txtContains(array $found, string $expected): bool
    {
        foreach ($found as $value) {
            if (hash_equals($expected, trim($value))) {
                return true;
            }
        }

        return false;
    }

    private function normaliseIp(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        $ip = trim($ip);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
