<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\Application\Auth\ModelUserProvider;
use Tests\Unit\Plugins\Auth\Support\FakeUserService;

/**
 * The tenant-membership gate: membership is part of the FETCH — id lookups pass
 * checkMembership=true to the User contract, so a user without an active seat
 * in the request's tenant is indistinguishable from an unknown user (null).
 */
#[CoversClass(ModelUserProvider::class)]
final class ModelUserProviderTenantGateTest extends TestCase
{
    private FakeUserService $users;

    protected function setUp(): void
    {
        $this->users = new FakeUserService();
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $this->users->credentials['jane@example.com:pw'] = 'u1';
        $this->users->rememberTokens['tok'] = 'u1';
    }

    private function provider(): ModelUserProvider
    {
        return new ModelUserProvider($this->users);
    }

    public function test_id_lookup_requests_the_membership_check(): void
    {
        $p = $this->provider();

        self::assertNotNull($p->retrieveById('u1'));
        self::assertSame([['u1', true]], $this->users->findCalls);
    }

    public function test_member_passes_all_lookups(): void
    {
        $p = $this->provider();

        self::assertNotNull($p->retrieveById('u1'));
        self::assertNotNull($p->retrieveByToken('tok'));
        self::assertNotNull($p->retrieveByCredentials(['email' => 'jane@example.com', 'password' => 'pw']));
    }

    public function test_non_member_does_not_exist(): void
    {
        $this->users->nonMembers = ['u1'];
        $p = $this->provider();

        self::assertNull($p->retrieveById('u1'));
    }

    public function test_unknown_user_and_wrong_credentials_return_null(): void
    {
        $p = $this->provider();

        self::assertNull($p->retrieveById('nope'));
        self::assertNull($p->retrieveByCredentials(['email' => 'jane@example.com', 'password' => 'wrong']));
    }
}
