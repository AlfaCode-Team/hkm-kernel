<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\OAuth2;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\OAuth2\Application\Ports\RefreshTokenStore;
use Plugins\OAuth2\Domain\Entities\RefreshToken;
use Plugins\OAuth2\Infrastructure\Http\Controllers\AuthorizedTokenController;

#[CoversClass(AuthorizedTokenController::class)]
final class AuthorizedTokenManagementTest extends TestCase
{
    private function store(): RefreshTokenStore
    {
        return new class implements RefreshTokenStore {
            /** @var list<RefreshToken> */
            public array $tokens = [];
            /** @var list<string> */
            public array $revokedFamilies = [];
            public function store(RefreshToken $token, string $tokenHash): void {}
            public function findByHash(string $tokenHash): ?RefreshToken { return null; }
            public function findByUser(string $userId): array
            {
                return array_values(array_filter($this->tokens, static fn(RefreshToken $t) => $t->userId === $userId));
            }
            public function allActive(): array
            {
                return array_values(array_filter($this->tokens, static fn(RefreshToken $t) => !$t->isExpired()));
            }
            public function revokeIfActive(string $tokenId): bool { return true; }
            public function revokeFamily(string $familyId): int { $this->revokedFamilies[] = $familyId; return 1; }
            public function deleteExpired(?\DateTimeImmutable $now = null): int { return 0; }
        };
    }

    private function token(string $id, string $family, string $user): RefreshToken
    {
        return RefreshToken::of($id, $family, 'client-1', $user, ['read'], new \DateTimeImmutable('+1 day'));
    }

    private function controller(RefreshTokenStore $store, Identity $identity): AuthorizedTokenController
    {
        $request = Request::build(method: 'GET', path: '/oauth/authorized-tokens')->withIdentity($identity);

        return (new AuthorizedTokenController($store))->setRequest($request);
    }

    public function test_for_user_lists_only_my_grants(): void
    {
        $store = $this->store();
        $store->tokens = [$this->token('t1', 'f1', 'u1'), $this->token('t2', 'f2', 'u2')];

        $response = $this->controller($store, Identity::asUser('u1', ''))->forUser();
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_destroy_revokes_the_family_for_my_grant(): void
    {
        $store = $this->store();
        $store->tokens = [$this->token('t1', 'fam-1', 'u1')];

        $ctrl = $this->controller($store, Identity::asUser('u1', ''));
        self::assertSame(204, $ctrl->destroy('t1')->getStatusCode());
        self::assertSame(['fam-1'], $store->revokedFamilies);
    }

    public function test_destroy_of_another_users_grant_is_not_found(): void
    {
        $store = $this->store();
        $store->tokens = [$this->token('t1', 'fam-1', 'u2')];

        self::assertSame(404, $this->controller($store, Identity::asUser('u1', ''))->destroy('t1')->getStatusCode());
        self::assertSame([], $store->revokedFamilies);
    }
}
