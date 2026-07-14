<?php

declare(strict_types=1);

namespace Plugins\Auth\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;

/**
 * Database-backed personal access token (PAT) authentication layer.
 *
 * Validates `Authorization: Bearer <id.secret>` tokens by hashing and matching
 * against personal_access_tokens. Wire it in a project bootstrap with the
 * DatabasePort instance:
 *
 *   ->withSecurity([ ..., new PersonalAccessTokenLayer($databasePortInstance) ])
 *
 * No header -> allow as guest. Bad token -> deny(401). Never throws.
 */
final class PersonalAccessTokenLayer implements SecurityLayerContract
{
    private readonly PersonalAccessTokenRepository $tokens;

    public function __construct(DatabasePort $db, string $table = 'personal_access_tokens')
    {
        $this->tokens = new PersonalAccessTokenRepository($db, $table);
    }

    public function check(Request $request): SecurityVerdict
    {
        $header = $request->header('Authorization') ?? '';
        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            return SecurityVerdict::allow($request);
        }

        $token = trim(substr($header, 7));
        // A PAT looks like "<32hex id>.<64hex secret>". Skip if it doesn't.
        if (!str_contains($token, '.')) {
            return SecurityVerdict::allow($request);
        }

        try {
            $record = $this->tokens->findByHash(hash('sha256', $token));
        } catch (\Throwable) {
            return SecurityVerdict::deny(401, 'Could not verify access token.');
        }

        if ($record === null) {
            return SecurityVerdict::deny(401, 'Access token is invalid, revoked, or expired.');
        }

        // Record last use — best-effort, never blocks the request.
        $this->tokens->touch($record['id']);

        // Empty tenant = unscoped (central connection), consistent with the JWT
        // layer and TenantContextStage. A PAT is a control-plane credential; it
        // does not silently bind to a tenant DB — so fullName stays empty (it
        // lives in the tenant user_profiles table). The repository's findByHash
        // joins the owner's username/email onto the record (all SQL stays there).
        $identity = new Identity(
            userId:      $record['user_id'],
            tenantId:    '',
            roles:       [],
            permissions: $record['abilities'],
            tokenType:   'api_key',
            username:    (string) ($record['username'] ?? ''),
            email:       (string) ($record['email'] ?? ''),
        );

        return SecurityVerdict::allow($request->withIdentity($identity));
    }
}
