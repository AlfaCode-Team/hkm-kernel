<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityGateway;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pre-bootstrap gate.
 *
 * The gateway skips the request clone when the identity-resolving layer is the
 * LAST one — the clone existed only so allow() could read the identity back off
 * it, and SecurityStage clones again anyway. These tests pin the behaviour that
 * shortcut must not change: a later layer still SEES an earlier layer's
 * identity, and the identity still reaches the verdict either way.
 */
#[CoversClass(SecurityGateway::class)]
final class SecurityGatewayTest extends TestCase
{
    private static function request(): Request
    {
        return Request::build('GET', '/dashboard');
    }

    /** A layer that allows, optionally resolving an identity. */
    private static function allows(?Identity $identity = null): SecurityLayerContract
    {
        return new class ($identity) implements SecurityLayerContract {
            public function __construct(private readonly ?Identity $identity) {}

            public function check(Request $request): SecurityVerdict
            {
                return $this->identity === null
                    ? SecurityVerdict::allow($request)
                    : SecurityVerdict::allowWithIdentity($this->identity);
            }
        };
    }

    /** A layer that records what identity it was handed, then allows. */
    private static function spy(?Identity &$seen): SecurityLayerContract
    {
        return new class ($seen) implements SecurityLayerContract {
            public function __construct(private ?Identity &$seen) {}

            public function check(Request $request): SecurityVerdict
            {
                $this->seen = $request->identity();

                return SecurityVerdict::allow($request);
            }
        };
    }

    private static function denies(int $code, string $reason): SecurityLayerContract
    {
        return new class ($code, $reason) implements SecurityLayerContract {
            public function __construct(private readonly int $code, private readonly string $reason) {}

            public function check(Request $request): SecurityVerdict
            {
                return SecurityVerdict::deny($this->code, $this->reason);
            }
        };
    }

    // ── Identity reaches the verdict ────────────────────────────────────────

    public function test_the_last_layers_identity_reaches_the_verdict(): void
    {
        $identity = Identity::asUser('user-7', 'tenant-a');

        $verdict = (new SecurityGateway([self::allows(), self::allows($identity)]))
            ->inspect(self::request());

        self::assertTrue($verdict->isAllowed());
        self::assertSame($identity, $verdict->identity());
    }

    public function test_a_sole_layers_identity_reaches_the_verdict(): void
    {
        $identity = Identity::asUser('user-1');

        $verdict = (new SecurityGateway([self::allows($identity)]))->inspect(self::request());

        self::assertSame($identity, $verdict->identity());
    }

    public function test_no_layer_resolving_an_identity_yields_none(): void
    {
        $verdict = (new SecurityGateway([self::allows(), self::allows()]))->inspect(self::request());

        self::assertTrue($verdict->isAllowed());
        self::assertNull($verdict->identity());
    }

    // ── A later layer must still SEE an earlier one's identity ──────────────

    public function test_a_later_layer_sees_an_earlier_layers_identity(): void
    {
        $identity = Identity::asAdmin('tenant-b');
        $seen     = null;

        // This is the whole reason the clone exists on a non-final layer: a role
        // check that runs after authentication must be able to read the user.
        (new SecurityGateway([self::allows($identity), self::spy($seen)]))
            ->inspect(self::request());

        self::assertSame($identity, $seen);
    }

    public function test_the_first_layer_sees_no_identity(): void
    {
        $seen = null;

        (new SecurityGateway([self::spy($seen), self::allows(Identity::asUser('u'))]))
            ->inspect(self::request());

        self::assertNull($seen);
    }

    // ── Denial short-circuits ───────────────────────────────────────────────

    public function test_a_denial_stops_every_later_layer(): void
    {
        $seen  = null;
        $ran   = false;
        $after = new class ($ran) implements SecurityLayerContract {
            public function __construct(private bool &$ran) {}

            public function check(Request $request): SecurityVerdict
            {
                $this->ran = true;

                return SecurityVerdict::allow($request);
            }
        };

        $verdict = (new SecurityGateway([self::denies(403, 'nope'), $after]))
            ->inspect(self::request());

        self::assertTrue($verdict->isDenied());
        self::assertSame(403, $verdict->statusCode());
        self::assertSame('nope', $verdict->reason());
        self::assertFalse($ran, 'a denied request must never reach a later layer');
        self::assertNull($seen);
    }

    public function test_an_empty_stack_allows(): void
    {
        // BindSecurityStage refuses this at boot; the gateway itself must still
        // behave rather than index past the end of an empty list.
        $verdict = (new SecurityGateway([]))->inspect(self::request());

        self::assertTrue($verdict->isAllowed());
    }
}
