<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use PHPUnit\Framework\TestCase;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\Application\Ports\MembershipReader;
use Plugins\Tenancy\Application\Services\MembershipService;
use Plugins\Tenancy\Domain\Entities\Membership;
use Plugins\Tenancy\Domain\Exceptions\NotAMemberException;
use Plugins\Tenancy\Domain\ValueObjects\MembershipStatus;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

final class MembershipServiceTest extends TestCase
{
    private function membership(
        string $userId,
        string $tenantId,
        string $role = 'member',
        MembershipStatus $status = MembershipStatus::Active,
        TenantStatus $tenantStatus = TenantStatus::Active,
    ): Membership {
        return Membership::of(
            userId: $userId, tenantId: $tenantId, tenantName: 'Acme ' . $tenantId,
            tenantSlug: 'acme-' . $tenantId, role: $role, status: $status, tenantStatus: $tenantStatus,
        );
    }

    /** @param list<Membership> $seed */
    private function service(array $seed, ?\ArrayObject $audit = null): MembershipService
    {
        $audit ??= new \ArrayObject();

        $reader = new class($seed) implements MembershipReader {
            /** @param list<Membership> $rows */
            public function __construct(private array $rows) {}
            public function activeForUser(string $userId): array
            {
                return array_values(array_filter(
                    $this->rows,
                    static fn (Membership $m) => $m->userId === $userId && $m->isRoutable(),
                ));
            }
            public function find(string $userId, string $tenantId): ?Membership
            {
                foreach ($this->rows as $m) {
                    if ($m->userId === $userId && $m->tenantId === $tenantId) {
                        return $m;
                    }
                }
                return null;
            }
        };

        $sink = new class($audit) implements AuditServiceContract {
            public function __construct(private \ArrayObject $log) {}
            public function record(string $action, ?string $userId = null, ?string $tenantId = null, array $meta = [], ?string $ip = null): void
            {
                $this->log->append(['action' => $action, 'user' => $userId, 'tenant' => $tenantId, 'meta' => $meta]);
            }
        };

        return new MembershipService($reader, $sink);
    }

    public function test_my_tenants_lists_only_active_routable_memberships(): void
    {
        $svc = $this->service([
            $this->membership('u1', 't1'),
            $this->membership('u1', 't2', status: MembershipStatus::Suspended),
            $this->membership('u1', 't3', tenantStatus: TenantStatus::Suspended),
            $this->membership('u2', 't9'),
        ]);

        $list = $svc->myTenants('u1');

        $this->assertCount(1, $list);
        $this->assertSame('t1', $list[0]->tenantId);
        $this->assertSame('member', $list[0]->role);
    }

    public function test_select_tenant_returns_verified_seat_and_audits(): void
    {
        $audit = new \ArrayObject();
        $svc = $this->service([$this->membership('u1', 't1', role: 'admin')], $audit);

        $seat = $svc->selectTenant('u1', 't1', '203.0.113.5');

        $this->assertSame('t1', $seat->tenantId);
        $this->assertSame('admin', $seat->role);

        $this->assertCount(1, $audit);
        $this->assertSame('tenant.switch', $audit[0]['action']);
        $this->assertSame('t1', $audit[0]['tenant']);
        $this->assertSame(['role' => 'admin'], $audit[0]['meta']);
    }

    public function test_select_tenant_rejects_non_member_and_audits_denial(): void
    {
        $audit = new \ArrayObject();
        $svc = $this->service([$this->membership('u1', 't1')], $audit);

        try {
            $svc->selectTenant('u1', 'tX');
            $this->fail('Expected NotAMemberException');
        } catch (NotAMemberException) {
            // expected
        }

        $this->assertSame('tenant.switch_denied', $audit[0]['action']);
    }

    public function test_select_tenant_rejects_suspended_seat(): void
    {
        $svc = $this->service([$this->membership('u1', 't1', status: MembershipStatus::Suspended)]);

        $this->expectException(NotAMemberException::class);
        $svc->selectTenant('u1', 't1');
    }

    public function test_is_active_member(): void
    {
        $svc = $this->service([
            $this->membership('u1', 't1'),
            $this->membership('u1', 't2', tenantStatus: TenantStatus::Suspended),
        ]);

        $this->assertTrue($svc->isActiveMember('u1', 't1'));
        $this->assertFalse($svc->isActiveMember('u1', 't2'));
        $this->assertFalse($svc->isActiveMember('u1', 'tX'));
    }
}
