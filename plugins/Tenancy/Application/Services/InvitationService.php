<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Tenancy\API\Contracts\InvitationServiceContract;
use Plugins\Tenancy\API\DTOs\InvitationResult;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\Application\Ports\InvitationStore;
use Plugins\Tenancy\Application\Ports\MembershipWriter;
use Plugins\Tenancy\Domain\Exceptions\InvalidInvitationException;
use Plugins\Tenancy\Support\Token;

/**
 * InvitationService — create / accept / revoke tenant invitations.
 *
 * Security posture:
 *   - Only the SHA-256 of the token is persisted; the raw token is returned once.
 *   - Accept REQUIRES the authenticated user's verified email to match the
 *     invited email (an invite for alice@ cannot be claimed by bob@).
 *   - Accept is idempotent on the seat (upsert) so a double-click can't fail.
 *   - Every action is audited (member.invite / member.join / member.invite_revoked).
 */
final class InvitationService implements InvitationServiceContract
{
    public function __construct(
        private readonly InvitationStore $invitations,
        private readonly MembershipWriter $memberships,
        private readonly AuditServiceContract $audit,
    ) {}

    public function invite(
        string $tenantId,
        string $email,
        string $role,
        string $invitedBy,
        int $ttlSeconds = 604800,
    ): InvitationResult {
        $email = mb_strtolower(trim($email));

        if ($this->invitations->pendingExists($tenantId, $email)) {
            throw new ValidationException(['email' => 'A pending invitation already exists for this email.']);
        }

        $inviteId  = Token::ulid();
        $rawToken  = Token::random();
        $expiresAt = (new \DateTimeImmutable())->add(new \DateInterval('PT' . max(60, $ttlSeconds) . 'S'));

        $this->invitations->create(
            $inviteId, $tenantId, $email, $role, Token::hash($rawToken), $invitedBy, $expiresAt,
        );

        $this->audit->record('member.invite', $invitedBy, $tenantId, ['email' => $email, 'role' => $role]);

        return new InvitationResult(
            inviteId:  $inviteId,
            tenantId:  $tenantId,
            email:     $email,
            role:      $role,
            token:     $rawToken,
            expiresAt: $expiresAt->format(\DateTimeInterface::RFC3339),
        );
    }

    public function accept(string $rawToken, string $userId, string $userEmail, ?string $ip = null): string
    {
        $invitation = $this->invitations->findByTokenHash(Token::hash($rawToken));

        if ($invitation === null || !$invitation->isAcceptable()) {
            throw InvalidInvitationException::notUsable();
        }
        if (!hash_equals($invitation->email, mb_strtolower(trim($userEmail)))) {
            $this->audit->record('member.join_denied', $userId, $invitation->tenantId, ['reason' => 'email_mismatch'], $ip);
            throw InvalidInvitationException::emailMismatch();
        }

        $this->memberships->upsertActive($userId, $invitation->tenantId, $invitation->role);
        $this->invitations->markAccepted($invitation->inviteId);

        $this->audit->record('member.join', $userId, $invitation->tenantId, ['role' => $invitation->role], $ip);

        return $invitation->tenantId;
    }

    public function revoke(string $rawToken): void
    {
        $invitation = $this->invitations->findByTokenHash(Token::hash($rawToken));
        if ($invitation === null) {
            return;
        }

        $this->invitations->markRevoked($invitation->inviteId);
        $this->audit->record('member.invite_revoked', null, $invitation->tenantId, ['inviteId' => $invitation->inviteId]);
    }
}
