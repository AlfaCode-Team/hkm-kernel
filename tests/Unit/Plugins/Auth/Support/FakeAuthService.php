<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\API\DTOs\TokenDTO;
use Plugins\Auth\API\Guard;

/**
 * AuthServiceContract double for HasApiTokens tests. Records minted tokens and
 * replays a canned tokensFor() list.
 */
final class FakeAuthService implements AuthServiceContract
{
    /** @var list<TokenDTO> */
    public array $tokens = [];
    /** @var list<array{userId:string,name:string,abilities:list<string>}> */
    public array $minted = [];

    public function guard(Request $request): Guard
    {
        return Guard::fromRequest($request);
    }

    public function tokensFor(string $userId): array
    {
        return $this->tokens;
    }

    public function createPersonalAccessToken(string $userId, string $name = 'default', array $abilities = [], ?int $ttlSeconds = null): array
    {
        $this->minted[] = ['userId' => $userId, 'name' => $name, 'abilities' => array_values($abilities)];

        return ['id' => 'tok-' . count($this->minted), 'token' => 'tok-' . count($this->minted) . '.secret'];
    }

    public function revokePersonalAccessToken(string $id): void {}
    public function issueJwt(string $userId, array $claims = [], int $ttlSeconds = 3600): string { return 'jwt'; }
    public function startSession(SessionPort $session, string $userId, array $roles = [], array $permissions = [], string $tenantId = '', string $username = '', string $email = '', string $fullName = '', ?string $avatarUrl = null): void {}
    public function endSession(SessionPort $session): void {}
    public function revokeJwt(string $jti, int $ttlSeconds = 3600): void {}
    public function hashPassword(string $plain): string { return 'hash'; }
    public function verifyPassword(string $plain, string $hash): bool { return true; }
}
