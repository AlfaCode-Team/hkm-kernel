<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use PHPUnit\Framework\TestCase;
use Plugins\Tenancy\API\Contracts\TenantHostRegistryContract;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\Application\Ports\DnsResolver;
use Plugins\Tenancy\Application\Ports\TenantHostStore;
use Plugins\Tenancy\Application\Services\TenantHostService;
use Plugins\Tenancy\Domain\Entities\TenantHost;
use Plugins\Tenancy\Domain\Exceptions\HostConflictException;
use Plugins\Tenancy\Domain\Exceptions\HostNotFoundException;
use Plugins\Tenancy\Domain\Exceptions\InvalidHostnameException;
use Plugins\Tenancy\Domain\ValueObjects\HostStatus;

final class TenantHostServiceTest extends TestCase
{
    private const PREFIX = '_psp-verify';
    private const VALUE  = 'psp-verify=';

    /** In-memory TenantHostStore. */
    private function store(): TenantHostStore
    {
        return new class implements TenantHostStore {
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            private int $seq = 0;

            public function allForTenant(string $tenantId): array
            {
                $out = [];
                foreach ($this->rows as $r) {
                    if ($r['tenant_id'] === $tenantId && $r['deleted_at'] === null) {
                        $out[] = TenantHost::fromRow($r);
                    }
                }
                return $out;
            }

            public function find(string $tenantId, int $hostId): ?TenantHost
            {
                $r = $this->rows[$hostId] ?? null;
                if ($r === null || $r['tenant_id'] !== $tenantId || $r['deleted_at'] !== null) {
                    return null;
                }
                return TenantHost::fromRow($r);
            }

            public function hostnameTaken(string $hostname): bool
            {
                foreach ($this->rows as $r) {
                    if ($r['hostname'] === $hostname && $r['deleted_at'] === null) {
                        return true;
                    }
                }
                return false;
            }

            public function insert(string $tenantId, string $hostname, ?string $ipAddress, string $verificationToken): int
            {
                $id = ++$this->seq;
                $this->rows[$id] = [
                    'host_id' => $id, 'tenant_id' => $tenantId, 'hostname' => $hostname,
                    'ip_address' => $ipAddress, 'status' => 0, 'verification_token' => $verificationToken,
                    'is_primary' => false, 'verified_at' => null, 'created_at' => 'now', 'updated_at' => 'now',
                    'deleted_at' => null,
                ];
                return $id;
            }

            public function markStatus(string $tenantId, int $hostId, int $status, ?string $verifiedAt): void
            {
                $this->rows[$hostId]['status'] = $status;
                $this->rows[$hostId]['verified_at'] = $verifiedAt;
            }

            public function setPrimary(string $tenantId, int $hostId): void
            {
                foreach ($this->rows as $id => $r) {
                    if ($r['tenant_id'] === $tenantId) {
                        $this->rows[$id]['is_primary'] = ($id === $hostId);
                    }
                }
            }

            public function softDelete(string $tenantId, int $hostId): void
            {
                $this->rows[$hostId]['deleted_at'] = 'now';
                $this->rows[$hostId]['is_primary'] = false;
            }
        };
    }

    /** DnsResolver returning canned records. */
    private function dns(array $txt = [], array $ips = []): DnsResolver
    {
        return new class($txt, $ips) implements DnsResolver {
            public function __construct(private array $txtMap, private array $ipMap) {}
            public function txt(string $name): array { return $this->txtMap[$name] ?? []; }
            public function ips(string $hostname): array { return $this->ipMap[$hostname] ?? []; }
        };
    }

    private function registry(): TenantHostRegistryContract
    {
        return new class implements TenantHostRegistryContract {
            public array $forgotten = [];
            public function tenantForHost(string $hostname): ?string { return null; }
            public function forget(string $hostname): void { $this->forgotten[] = $hostname; }
        };
    }

    private function audit(\ArrayObject $log): AuditServiceContract
    {
        return new class($log) implements AuditServiceContract {
            public function __construct(private \ArrayObject $log) {}
            public function record(string $action, ?string $userId = null, ?string $tenantId = null, array $meta = [], ?string $ip = null): void
            {
                $this->log->append(['action' => $action, 'tenant' => $tenantId, 'meta' => $meta]);
            }
        };
    }

    private function service(
        TenantHostStore $store,
        DnsResolver $dns,
        ?TenantHostRegistryContract $registry = null,
        ?\ArrayObject $audit = null,
    ): TenantHostService {
        return new TenantHostService(
            $store, $dns, $this->audit($audit ?? new \ArrayObject()),
            $registry ?? $this->registry(), self::PREFIX, self::VALUE,
        );
    }

    public function test_add_registers_pending_host_and_returns_dns_challenge(): void
    {
        $store = $this->store();
        $audit = new \ArrayObject();
        $svc = $this->service($store, $this->dns(), audit: $audit);

        $instructions = $svc->add('t1', 'Shop.Acme.COM');

        $this->assertSame('shop.acme.com', $instructions->hostname);
        $this->assertSame('_psp-verify.shop.acme.com', $instructions->txtRecordName);
        $this->assertStringStartsWith('psp-verify=', $instructions->txtRecordValue);

        $hosts = $svc->list('t1');
        $this->assertCount(1, $hosts);
        $this->assertSame(HostStatus::Pending, $hosts[0]->status);
        $this->assertSame('tenant_host.added', $audit[0]['action']);
    }

    public function test_add_rejects_invalid_hostname(): void
    {
        $svc = $this->service($this->store(), $this->dns());
        $this->expectException(InvalidHostnameException::class);
        $svc->add('t1', 'not a host');
    }

    public function test_add_rejects_duplicate_hostname(): void
    {
        $store = $this->store();
        $svc = $this->service($store, $this->dns());
        $svc->add('t1', 'acme.com');

        $this->expectException(HostConflictException::class);
        $svc->add('t2', 'acme.com');
    }

    public function test_verify_promotes_host_when_txt_matches(): void
    {
        $store = $this->store();
        $registry = $this->registry();
        $audit = new \ArrayObject();

        // First register to learn the token, then point DNS at it.
        $svc = $this->service($store, $this->dns(), $registry, $audit);
        $instr = $svc->add('t1', 'acme.com');
        $hostId = $svc->list('t1')[0]->hostId;

        $svc = $this->service(
            $store,
            $this->dns(txt: ['_psp-verify.acme.com' => [$instr->txtRecordValue]]),
            $registry,
            $audit,
        );

        $result = $svc->verify('t1', $hostId);

        $this->assertTrue($result->verified);
        $this->assertSame(HostStatus::Verified, $svc->list('t1')[0]->status);
        $this->assertContains('acme.com', $registry->forgotten);
    }

    public function test_verify_keeps_pending_when_txt_not_yet_present(): void
    {
        // A first-time check that does not find the record stays Pending (DNS
        // propagation lag is not a hard failure) so the owner can keep retrying.
        $store = $this->store();
        $svc = $this->service($store, $this->dns(txt: ['_psp-verify.acme.com' => ['psp-verify=wrong']]));
        $svc->add('t1', 'acme.com');
        $hostId = $svc->list('t1')[0]->hostId;

        $result = $svc->verify('t1', $hostId);

        $this->assertFalse($result->verified);
        $this->assertSame(HostStatus::Pending, $svc->list('t1')[0]->status);
    }

    public function test_reverify_of_verified_host_demotes_to_failed_on_revocation(): void
    {
        // A host that WAS verified but no longer proves ownership = takeover/
        // revocation -> demote to Failed and stop routing.
        $store = $this->store();
        $registry = $this->registry();
        $svc = $this->service($store, $this->dns(), $registry);
        $instr = $svc->add('t1', 'acme.com');
        $hostId = $svc->list('t1')[0]->hostId;

        $live = $this->service($store, $this->dns(txt: ['_psp-verify.acme.com' => [$instr->txtRecordValue]]), $registry);
        $this->assertTrue($live->verify('t1', $hostId)->verified);

        // DNS record pulled — re-verify finds nothing.
        $gone = $this->service($store, $this->dns(), $registry);
        $this->assertFalse($gone->verify('t1', $hostId)->verified);
        $this->assertSame(HostStatus::Failed, $gone->list('t1')[0]->status);
    }

    public function test_add_enforces_host_quota(): void
    {
        $store = $this->store();
        $svc = new TenantHostService(
            $store, $this->dns(), $this->audit(new \ArrayObject()),
            $this->registry(), self::PREFIX, self::VALUE, maxHostsPerTenant: 2,
        );

        $svc->add('t1', 'a.com');
        $svc->add('t1', 'b.com');

        $this->expectException(\Plugins\Tenancy\Domain\Exceptions\HostQuotaExceededException::class);
        $svc->add('t1', 'c.com');
    }

    public function test_verify_requires_matching_ip_when_pinned(): void
    {
        $store = $this->store();
        $svc = $this->service($store, $this->dns());
        $instr = $svc->add('t1', 'acme.com', '203.0.113.10');
        $hostId = $svc->list('t1')[0]->hostId;

        // TXT correct but A record points elsewhere -> fail.
        $svc = $this->service($store, $this->dns(
            txt: ['_psp-verify.acme.com' => [$instr->txtRecordValue]],
            ips: ['acme.com' => ['198.51.100.1']],
        ));
        $this->assertFalse($svc->verify('t1', $hostId)->verified);

        // Correct A record -> pass.
        $svc = $this->service($store, $this->dns(
            txt: ['_psp-verify.acme.com' => [$instr->txtRecordValue]],
            ips: ['acme.com' => ['203.0.113.10']],
        ));
        $this->assertTrue($svc->verify('t1', $hostId)->verified);
    }

    public function test_verify_accepts_apex_txt_fallback(): void
    {
        $store = $this->store();
        $svc = $this->service($store, $this->dns());
        $instr = $svc->add('t1', 'acme.com');
        $hostId = $svc->list('t1')[0]->hostId;

        // Published on the apex, not the _psp-verify sublabel.
        $svc = $this->service($store, $this->dns(txt: ['acme.com' => [$instr->txtRecordValue]]));

        $this->assertTrue($svc->verify('t1', $hostId)->verified);
    }

    public function test_make_primary_requires_verified_host(): void
    {
        $store = $this->store();
        $svc = $this->service($store, $this->dns());
        $svc->add('t1', 'acme.com');
        $hostId = $svc->list('t1')[0]->hostId;

        $this->expectException(HostNotFoundException::class);
        $svc->makePrimary('t1', $hostId);
    }

    public function test_make_primary_demotes_previous_primary(): void
    {
        $store = $this->store();
        $svc = $this->service($store, $this->dns(), null, new \ArrayObject());

        // Two verified hosts.
        $a = $svc->add('t1', 'a.com');
        $b = $svc->add('t1', 'b.com');
        $idA = $svc->list('t1')[0]->hostId;
        // Force both verified directly through the store for the test.
        foreach ($svc->list('t1') as $h) {
            $store->markStatus('t1', $h->hostId, HostStatus::Verified->value, 'now');
        }

        $ids = array_map(static fn ($h) => $h->hostId, $svc->list('t1'));
        $svc->makePrimary('t1', $ids[0]);
        $svc->makePrimary('t1', $ids[1]);

        $primaries = array_filter($svc->list('t1'), static fn ($h) => $h->isPrimary);
        $this->assertCount(1, $primaries);
    }

    public function test_remove_soft_deletes_and_forgets_cache(): void
    {
        $store = $this->store();
        $registry = $this->registry();
        $svc = $this->service($store, $this->dns(), $registry);
        $svc->add('t1', 'acme.com');
        $hostId = $svc->list('t1')[0]->hostId;

        $svc->remove('t1', $hostId);

        $this->assertCount(0, $svc->list('t1'));
        $this->assertContains('acme.com', $registry->forgotten);
    }

    public function test_operations_are_tenant_scoped(): void
    {
        $store = $this->store();
        $svc = $this->service($store, $this->dns());
        $svc->add('t1', 'acme.com');
        $hostId = $svc->list('t1')[0]->hostId;

        // t2 cannot see or verify t1's host.
        $this->assertCount(0, $svc->list('t2'));
        $this->expectException(HostNotFoundException::class);
        $svc->verify('t2', $hostId);
    }
}
