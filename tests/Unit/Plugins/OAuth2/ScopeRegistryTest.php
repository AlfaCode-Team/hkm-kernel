<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\OAuth2\Application\Ports\ScopeStore;
use Plugins\OAuth2\Application\Services\ScopeRegistry;

#[CoversClass(ScopeRegistry::class)]
final class ScopeRegistryTest extends TestCase
{
    private function registry(): ScopeRegistry
    {
        $store = new class implements ScopeStore {
            private array $map = ['openid' => 'Sign you in', 'profile' => 'Your profile', 'email' => ''];
            public function exists(string $scope): bool { return array_key_exists($scope, $this->map); }
            public function all(): array { return array_keys($this->map); }
            public function describe(): array { return $this->map; }
            public function put(string $id, string $description): void { $this->map[$id] = $description; }
            public function delete(string $id): bool
            {
                if (!array_key_exists($id, $this->map)) {
                    return false;
                }
                unset($this->map[$id]);

                return true;
            }
        };

        return new ScopeRegistry($store);
    }

    public function test_scopes_returns_id_description_rows(): void
    {
        $scopes = $this->registry()->scopes();

        self::assertContains(['id' => 'openid', 'description' => 'Sign you in'], $scopes);
        self::assertContains(['id' => 'email', 'description' => ''], $scopes);
        self::assertCount(3, $scopes);
    }

    public function test_scopes_for_filters_and_drops_unknown(): void
    {
        $rows = $this->registry()->scopesFor(['profile', 'ghost']);

        self::assertSame([['id' => 'profile', 'description' => 'Your profile']], $rows);
    }

    public function test_has_scope_and_tokens_can(): void
    {
        $r = $this->registry();

        self::assertTrue($r->hasScope('openid'));
        self::assertFalse($r->hasScope('ghost'));
        self::assertTrue($r->tokensCan(['openid', 'email']));
        self::assertFalse($r->tokensCan(['openid', 'ghost']));
        self::assertTrue($r->tokensCan([])); // empty request always allowed
    }
}
