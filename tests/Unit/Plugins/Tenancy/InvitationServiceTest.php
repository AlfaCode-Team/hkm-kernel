<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\Application\Ports\InvitationStore;
use Plugins\Tenancy\Application\Ports\MembershipWriter;
use Plugins\Tenancy\Application\Services\InvitationService;
use Plugins\Tenancy\Domain\Entities\Invitation;
use Plugins\Tenancy\Domain\Exceptions\InvalidInvitationException;
use Plugins\Tenancy\Domain\ValueObjects\InvitationStatus;

final class InvitationServiceTest extends TestCase
{
    private function store(): InvitationStore
    {
        return new class implements InvitationStore {
            /** @var array<string, array<string, mixed>> keyed by token hash */
            public array $rows = [];
            public function create(string $inviteId, string $tenantId, string $email, string $role, string $tokenHash, string $invitedBy, \DateTimeImmutable $expiresAt): void
            {
                $this->rows[$tokenHash] = [
                    'invite_id' => $inviteId, 'tenant_id' => $tenantId, 'email' => $email,
                    'role' => $role, 'status' => InvitationStatus::Pending->value,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s'), 'invited_by' => $invitedBy,
                ];
            }
            public function findByTokenHash(string $tokenHash): ?Invitation
            {
                return isset($this->rows[$tokenHash]) ? Invitation::fromRow($this->rows[$tokenHash]) : null;
            }
            public function pendingExists(string $tenantId, string $email): bool
            {
                foreach ($this->rows as $r) {
                    if ($r['tenant_id'] === $tenantId && $r['email'] === $email && $r['status'] === InvitationStatus::Pending->value) {
                        return true;
                    }
                }
                return false;
            }
            public function markAccepted(string $inviteId): void { $this->setStatus($inviteId, InvitationStatus::Accepted); }
            public function markRevoked(string $inviteId): void { $this->setStatus($inviteId, InvitationStatus::Revoked); }
            private function setStatus(string $inviteId, InvitationStatus $s): void
            {
                foreach ($this->rows as $h => $r) {
                    if ($r['invite_id'] === $inviteId) { $this->rows[$h]['status'] = $s->value; }
                }
            }
        };
    }

    private function writer(): MembershipWriter
    {
        return new class implements MembershipWriter {
            /** @var list<array{0:string,1:string,2:string}> */
            public array $added = [];
            public function upsertActive(string $userId, string $tenantId, string $role): void
            {
                $this->added[] = [$userId, $tenantId, $role];
            }
        };
    }

    private function audit(): AuditServiceContract
    {
        return new class implements AuditServiceContract {
            /** @var list<string> */
            public array $actions = [];
            public function record(string $action, ?string $userId = null, ?string $tenantId = null, array $meta = [], ?string $ip = null): void
            {
                $this->actions[] = $action;
            }
        };
    }

    public function test_invite_returns_token_and_blocks_duplicates(): void
    {
        $store = $this->store();
        $svc = new InvitationService($store, $this->writer(), $this->audit());

        $res = $svc->invite('t1', 'Alice@Example.com', 'admin', 'inviter-1');

        $this->assertNotSame('', $res->token);
        $this->assertSame('alice@example.com', $res->email); // normalised
        $this->assertSame('admin', $res->role);

        $this->expectException(ValidationException::class);
        $svc->invite('t1', 'alice@example.com', 'member', 'inviter-1');
    }

    public function test_accept_creates_membership_when_email_matches(): void
    {
        $store = $this->store();
        $writer = $this->writer();
        $audit = $this->audit();
        $svc = new InvitationService($store, $writer, $audit);

        $res = $svc->invite('t1', 'bob@example.com', 'member', 'inviter-1');

        $tenant = $svc->accept($res->token, 'user-bob', 'BOB@example.com');

        $this->assertSame('t1', $tenant);
        $this->assertSame([['user-bob', 't1', 'member']], $writer->added);
        $this->assertContains('member.join', $audit->actions);
    }

    public function test_accept_rejects_email_mismatch(): void
    {
        $store = $this->store();
        $svc = new InvitationService($store, $this->writer(), $this->audit());
        $res = $svc->invite('t1', 'carol@example.com', 'member', 'inviter-1');

        $this->expectException(InvalidInvitationException::class);
        $svc->accept($res->token, 'user-x', 'mallory@example.com');
    }

    public function test_accept_rejects_unknown_token(): void
    {
        $svc = new InvitationService($this->store(), $this->writer(), $this->audit());

        $this->expectException(InvalidInvitationException::class);
        $svc->accept('deadbeef', 'user-x', 'x@example.com');
    }

    public function test_accept_rejects_expired_invitation(): void
    {
        $store = $this->store();
        $svc = new InvitationService($store, $writer = $this->writer(), $this->audit());

        // Seed an already-expired pending invite directly.
        $raw = 'rawtoken123';
        $store->create('inv-exp', 't1', 'dan@example.com', 'member', hash('sha256', $raw), 'inviter-1',
            (new \DateTimeImmutable())->modify('-1 hour'));

        try {
            $svc->accept($raw, 'user-dan', 'dan@example.com');
            $this->fail('Expected InvalidInvitationException');
        } catch (InvalidInvitationException) {
            // expected
        }
        $this->assertSame([], $writer->added);
    }
}
