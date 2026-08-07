<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Container;

use Closure;
use PHPShots\Common\Container;
use PHPShots\Common\Interfaces\ContainerInterface as BindItContainerInterface;

/**
 * CoreContainer — shared, application-lifetime DI container.
 *
 * Built on the bind-it container ({@see \PHPShots\Common\Container}), so it is a
 * fully PSR-11 ({@see \Psr\Container\ContainerInterface}) implementation with
 * reflection-based autowiring, contextual bindings and extenders.
 *
 * Holds port → adapter bindings registered in bootstrap/app.php plus kernel
 * services (EventBus, ErrorPipeline, pipelines). Shared across all request-scoped
 * {@see ModuleContainer}s via delegation.
 *
 * ── Swoole / OpenSwoole safety rules (also enforced under FPM) ───────────────
 *
 *  1. FROZEN after MATERIALIZE — not after build(). build() is compile-only;
 *     the kernel calls freeze() at the end of materialize(), which runs on the
 *     first http()/cli()/workerLoop() call, once every module's boot() has had
 *     its chance to register. After that, bind(), singleton(), instance() and
 *     extend() all throw LogicException. Request code must NOT mutate this
 *     container.
 *
 *  2. getInstance() disabled — global container singletons are unsafe under
 *     OpenSwoole (shared across coroutines) and wrong under FPM. Inject the
 *     container from Kernel::container() or Provider::register($container).
 *
 *  3. Only stateless, app-lifetime objects belong here — adapters, immutable
 *     config, shared kernel services. Request-scoped state (Identity,
 *     TransactionManager, DomainEventCollector, module services) goes in
 *     ModuleContainer, which is created fresh per request.
 */
final class CoreContainer extends Container
{
    private bool $frozen = false;

    // ── Registration (freeze-guarded) ─────────────────────────────────────────

    /**
     * Bind a pre-built instance directly (used for port adapters and kernel
     * services). Must be called before the kernel materializes.
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->assertNotFrozen('instance');
        $this->store($abstract, $instance);
    }

    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->assertNotFrozen('bind');
        parent::bind($abstract, $concrete, $shared);
    }

    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->assertNotFrozen('singleton');
        parent::singleton($abstract, $concrete);
    }

    public function extend($abstract, Closure $closure): void
    {
        $this->assertNotFrozen('extend');
        parent::extend($abstract, $closure);
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    /**
     * Lock the container against further registration.
     * Called automatically by Kernel at the end of materialize() — after this
     * point only reads (make / has / get) are permitted. Any write throws
     * LogicException.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    // ── Disabled global singleton ─────────────────────────────────────────────

    /**
     * Disabled. Retrieve the container via Kernel::container() or Provider::register($c).
     *
     * @throws \LogicException always.
     */
    public static function getInstance(): never
    {
        throw new \LogicException(
            'CoreContainer::getInstance() is disabled. '
            . 'Get the container from Kernel::container() or receive it via '
            . 'Provider::register(ModuleContainer $c). '
            . 'A global container singleton is unsafe under OpenSwoole (shared across '
            . 'coroutines/workers) and unnecessary under FPM (each request is a fresh process).'
        );
    }

    /**
     * Disabled. The kernel owns the container lifecycle.
     *
     * @throws \LogicException always.
     */
    public static function setInstance(?BindItContainerInterface $container = null): never
    {
        throw new \LogicException(
            'CoreContainer::setInstance() is disabled. '
            . 'The kernel manages the container lifecycle; never replace it with a global singleton.'
        );
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function assertNotFrozen(string $method): void
    {
        if ($this->frozen) {
            throw new \LogicException(
                "CoreContainer::{$method}() was called after the kernel froze the container. "
                . 'Do not register bindings into the core (app-lifetime) container during a request. '
                . 'This would create state shared across ALL requests — unsafe under OpenSwoole, '
                . 'incorrect under FPM. '
                . 'Register request-scoped services inside Provider::register(ModuleContainer $c) instead.'
            );
        }
    }
}
