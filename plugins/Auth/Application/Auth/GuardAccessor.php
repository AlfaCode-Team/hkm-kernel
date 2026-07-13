<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\GuardContext;
use Plugins\Auth\Application\Ports\GuardDriver;
use Plugins\Auth\Application\Ports\StatefulGuard;

/**
 * GuardAccessor — the per-guard facade AuthManager::guard($name) returns,
 * preserving the old `$manager->guard('web')->user()` ergonomics. Resolves the
 * user lazily once per accessor and caches it (request-scoped).
 *
 * Stateful guards ('session' driver) also carry the WRITE-side guard, so the
 * full old flow works through one handle:
 *
 *   $manager->guard('web')->attempt(['email' => …, 'password' => …], remember: true);
 *   $manager->guard('web')->logout();
 *   $manager->guard('web')->logoutOtherDevices($password);
 *
 * Write calls are forwarded via __call; a stateless guard (api/jwt/request)
 * throws a descriptive ServiceException instead of silently no-opping.
 */
final class GuardAccessor
{
    private bool $resolved = false;
    private ?Authenticatable $user = null;

    public function __construct(
        private readonly string $name,
        private readonly GuardDriver $driver,
        private readonly GuardContext $context,
        private readonly Request $request,
        private readonly ?StatefulGuard $stateful = null,
    ) {}

    /**
     * The WRITE-side guard (attempt/login/logout/…), when this guard is
     * stateful. Null for token-style guards.
     */
    public function stateful(): ?StatefulGuard
    {
        return $this->stateful;
    } 

    /** Forward write operations (attempt/login/logout/…) to the stateful guard. */
    public function __call(string $method, array $parameters): mixed
    {
        if ($this->stateful === null) {
            throw new ServiceException(
                "Auth guard [{$this->name}] is stateless — [{$method}] requires a session guard.",
                layer: 'service.auth',
            );
        }

        $result = $this->stateful->{$method}(...$parameters);

        // A write may have changed who is logged in — drop the cached read.
        $this->resolved = false;
        $this->user     = null;

        return $result;
    }

    /** Guard name (e.g. 'web', 'api'). */
    public function name(): string
    {
        return $this->name;
    }

    /** The authenticated user, or null. Resolved once and cached. */
    public function user(): ?Authenticatable
    {
        if (!$this->resolved) {
            $this->user     = $this->driver->resolve($this->request, $this->context);
            $this->resolved = true;
        }

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return $this->user() === null;
    }

    /** The current user's id, or '' when unauthenticated. */
    public function id(): string
    {
        return $this->user()?->getAuthIdentifier() ?? '';
    }

    /** Kernel Identity for the current user (guest Identity when unauthenticated). */
    public function identity(): Identity
    {
        return $this->user()?->identity() ?? Identity::guest();
    }
}
