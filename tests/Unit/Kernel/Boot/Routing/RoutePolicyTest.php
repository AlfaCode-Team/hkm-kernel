<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing\RoutePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The project's authority over plugin routes, tested against a plain array
 * instead of a compiled manifest.
 */
#[CoversClass(RoutePolicy::class)]
final class RoutePolicyTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function routes(): array
    {
        return [
            'GET /register'       => ['solves' => 'auth.identity'],
            'POST /register'      => ['solves' => 'auth.identity'],
            'GET /login'          => ['solves' => 'auth.identity'],
            'GET /admin/stats'    => ['solves' => 'admin.panel'],
            'GET /admin/users'    => ['solves' => 'admin.panel'],
            'POST /admin/users'   => ['solves' => 'admin.panel'],
            'GET /admin/users/me' => ['solves' => 'admin.panel'],
            'GET /adminx'         => ['solves' => 'admin.panel'],
            'GET@shop.test /'     => ['solves' => 'shop.front'],
        ];
    }

    public function testAnEmptyPolicyChangesNothing(): void
    {
        $policy = new RoutePolicy();

        self::assertSame($this->routes(), $policy->disable($policy->allow($this->routes())));
    }

    public function testAnExactSpecDisablesExactlyOneRoute(): void
    {
        $kept = (new RoutePolicy(disabled: ['GET /register']))->disable($this->routes());

        self::assertArrayNotHasKey('GET /register', $kept);
        self::assertArrayHasKey('POST /register', $kept, 'the other method must survive');
    }

    public function testADomainSpecDisablesEveryRouteThatModuleSolves(): void
    {
        $kept = (new RoutePolicy(disabled: ['auth.identity']))->disable($this->routes());

        self::assertSame(
            ['GET /admin/stats', 'GET /admin/users', 'POST /admin/users', 'GET /admin/users/me', 'GET /adminx', 'GET@shop.test /'],
            array_keys($kept),
        );
    }

    /**
     * The prefix form is what keeps a policy correct across a dependency bump:
     * an exact list of five stays green when the plugin adds a sixth.
     */
    public function testAPrefixSpecCoversEverythingBeneathThePath(): void
    {
        $kept = (new RoutePolicy(disabled: ['GET /admin/*']))->disable($this->routes());

        self::assertArrayNotHasKey('GET /admin/stats', $kept);
        self::assertArrayNotHasKey('GET /admin/users', $kept);
        self::assertArrayNotHasKey('GET /admin/users/me', $kept);
    }

    /** …but never a sibling that merely shares the prefix as a substring. */
    public function testAPrefixSpecDoesNotCoverASiblingSharingTheStringPrefix(): void
    {
        $kept = (new RoutePolicy(disabled: ['GET /admin/*']))->disable($this->routes());

        self::assertArrayHasKey('GET /adminx', $kept);
    }

    /** The method is compared too — a GET prefix must not silently cover the POST. */
    public function testAPrefixSpecIsMethodSpecific(): void
    {
        $kept = (new RoutePolicy(disabled: ['GET /admin/*']))->disable($this->routes());

        self::assertArrayHasKey('POST /admin/users', $kept);
    }

    public function testADisableSpecMatchingNothingFailsTheBoot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/matched no plugin route/');

        (new RoutePolicy(disabled: ['GET /not-a-route']))->disable($this->routes());
    }

    public function testABlankDisableSpecIsIgnoredRatherThanFailing(): void
    {
        $kept = (new RoutePolicy(disabled: ['  ', 'GET /login']))->disable($this->routes());

        self::assertArrayNotHasKey('GET /login', $kept);
    }

    // ── allow ────────────────────────────────────────────────────────────────

    /** Empty means "no allowlist" — never "allow nothing". */
    public function testAnEmptyAllowlistKeepsEverything(): void
    {
        self::assertSame($this->routes(), (new RoutePolicy(allowed: []))->allow($this->routes()));
    }

    public function testAnAllowlistDropsEverythingItDoesNotName(): void
    {
        $kept = (new RoutePolicy(allowed: ['GET /login']))->allow($this->routes());

        self::assertSame(['GET /login'], array_keys($kept));
    }

    public function testAnAllowlistAcceptsADomainSpec(): void
    {
        $kept = (new RoutePolicy(allowed: ['auth.identity']))->allow($this->routes());

        self::assertSame(['GET /register', 'POST /register', 'GET /login'], array_keys($kept));
    }

    /** Unlike disable, an unmatched allow spec is NOT a boot failure. */
    public function testAnUnmatchedAllowSpecDoesNotFailTheBoot(): void
    {
        $kept = (new RoutePolicy(allowed: ['GET /login', 'plugin.not.installed']))->allow($this->routes());

        self::assertSame(['GET /login'], array_keys($kept));
    }

    /** The two verbs compose: allow a whole domain, then subtract from it. */
    public function testAllowThenDisableCompose(): void
    {
        $policy = new RoutePolicy(disabled: ['POST /register'], allowed: ['auth.identity']);

        $kept = $policy->disable($policy->allow($this->routes()));

        self::assertSame(['GET /register', 'GET /login'], array_keys($kept));
    }

    public function testADomainGroupedRouteIsAddressableByItsFullKey(): void
    {
        $kept = (new RoutePolicy(disabled: ['GET@shop.test /']))->disable($this->routes());

        self::assertArrayNotHasKey('GET@shop.test /', $kept);
    }
}
