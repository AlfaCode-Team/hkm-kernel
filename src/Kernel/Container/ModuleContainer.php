<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Container;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ScopeViolationException;
use Closure;
use PHPShots\Common\Container;
use PHPShots\Common\Interfaces\ContainerInterface as BindItContainerInterface;

/**
 * ModuleContainer — per-request, per-module scoped DI container.
 *
 * Built on the bind-it container ({@see \PHPShots\Common\Container}) for
 * reflection-based autowiring, with the GDA scope-isolation rules layered on
 * top. Scope isolation is real and enforced:
 *   - Every binding records the scope (module domain) that registered it.
 *   - bindInternal() bindings are resolvable ONLY from within their owning scope.
 *   - Resolving an internal binding from another scope throws ScopeViolationException.
 *
 * Caller scope is threaded automatically: while a binding is being resolved its
 * owning scope is pushed onto a resolution stack so nested make() calls inherit
 * it. ExecuteStage resolves the controller with makeInScope() using the route's
 * owning scope, so a module's controller can reach its own internal bindings,
 * but no other module can.
 *
 * The container is created fresh per request and discarded at the end — no state
 * leaks across requests, which is required for OpenSwoole worker safety.
 */
final class ModuleContainer extends Container
{
    /** @var array<string, string> abstract => owning scope (module domain) */
    private array $bindingScope = [];

    /** @var array<string, bool> abstract => is it an internal binding */
    private array $internal = [];

    /** Scope currently registering bindings (set by OnDemandLoader). */
    private string $scope = '';

    /** @var list<string> active resolution scope stack for nested make() calls */
    private array $resolutionStack = [];

    public function __construct(
        private readonly CoreContainer $core
    ) {}

    // ── Registration ─────────────────────────────────────────────────────────

    /** Bind a public contract — resolvable by any module that requires it. */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->bindingScope[$abstract] = $this->scope;
        $this->internal[$abstract]     = false;
        parent::bind($abstract, $concrete, $shared);
    }

    /** Bind a singleton public contract — resolved once, shared within the request. */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bindingScope[$abstract] = $this->scope;
        $this->internal[$abstract]     = false;
        parent::bind($abstract, $concrete, true);
    }

    /**
     * Bind a pre-built instance directly — shared for the rest of the request.
     *
     * Mirrors CoreContainer::instance() but threads the current scope so the
     * resolver's bindingScope branch reaches the stored object (a plain store()
     * is unreachable for an interface abstract, which is never class_exists()).
     * Used to override a port for one request — e.g. rebinding DatabasePort to a
     * tenant connection in an after.load stage.
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->bindingScope[$abstract] = $this->scope;
        $this->internal[$abstract]     = false;
        $this->store($abstract, $instance);
    }

    /**
     * Bind an INTERNAL binding — only resolvable from within the owning scope.
     * Resolving from any other scope throws ScopeViolationException. Internal
     * bindings are singletons within the request.
     */
    public function bindInternal(string $abstract, Closure $factory): void
    {
        $this->bindingScope[$abstract] = $this->scope;
        $this->internal[$abstract]     = true;
        parent::bind($abstract, $factory, true);
    }

    /** Set the current module scope. Called by OnDemandLoader before each register(). */
    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Resolve a binding, enforcing scope isolation.
     *
     * @param  array<string, mixed>  $parameters
     * @throws ScopeViolationException if an internal binding is resolved cross-scope
     */
    public function make($abstract, array $parameters = []): mixed
    {
        $resolved = $this->getAlias($abstract);
        $caller   = end($this->resolutionStack) ?: '';

        // Enforce internal-binding scope isolation.
        if (($this->internal[$resolved] ?? false) && $caller !== ($this->bindingScope[$resolved] ?? '')) {
            throw new ScopeViolationException(
                "Cannot resolve internal [{$abstract}] from scope [" . ($caller ?: 'kernel') . "].\n"
                . 'It is internal to scope [' . ($this->bindingScope[$resolved] ?? '') . "].\n"
                . 'Use the module\'s published contract from API/Contracts/ instead.'
            );
        }

        // Module-registered binding: thread its owning scope while it resolves so
        // nested make() calls (its dependencies) inherit the correct caller scope.
        if (isset($this->bindingScope[$resolved])) {
            $this->resolutionStack[] = $this->bindingScope[$resolved];
            try {
                return parent::make($resolved, $parameters);
            } finally {
                array_pop($this->resolutionStack);
            }
        }

        // Delegate ports / shared kernel services to the CoreContainer.
        if ($this->core->has($resolved)) {
            return $this->core->make($resolved);
        }

        // Autowire a concrete class via bind-it reflection, within the current scope.
        if (class_exists($resolved)) {
            return parent::make($resolved, $parameters);
        }

        throw new EntryNotFoundException(
            "No binding found for [{$abstract}] in scope [" . ($this->scope ?: 'kernel') . "]. "
            . 'Did you forget to bind it in register()?'
        );
    }

    /**
     * Resolve an entry on behalf of an explicit caller scope (e.g. a controller
     * owned by a module). Used by ExecuteStage so a module entry point can reach
     * its own internal bindings.
     */
    public function makeInScope(string $abstract, string $scope): mixed
    {
        $this->resolutionStack[] = $scope;
        try {
            return $this->make($abstract);
        } finally {
            array_pop($this->resolutionStack);
        }
    }

    /**
     * Honour a constructor parameter's DEFAULT when its type is unbound here.
     *
     * bind-it falls back to `$parameter->getDefaultValue()` only when resolution
     * throws its own BindingResolutionException. This container signals
     * "nothing bound" with EntryNotFoundException instead, which bind-it does
     * not catch — so `?SomeContract $x = null`, the idiomatic way to declare an
     * optional collaborator, threw rather than receiving null whenever the
     * module providing the contract was not in this request's graph.
     *
     * A parameter with no default still throws, unchanged: a required
     * dependency that is missing must fail loudly. No `with` stack to unwind —
     * the EntryNotFoundException is raised in make() before bind-it pushes one.
     */
    protected function resolveClass(\ReflectionParameter $parameter): mixed
    {
        try {
            return parent::resolveClass($parameter);
        } catch (EntryNotFoundException $e) {
            if ($parameter->isDefaultValueAvailable() && !$parameter->isVariadic()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }

    public function has(string $id): bool
    {
        $resolved = $this->getAlias($id);
        return isset($this->bindingScope[$resolved]) || $this->core->has($resolved);
    }

    // ── PSR-11 ────────────────────────────────────────────────────────────────

    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    // ── Disabled global singleton ─────────────────────────────────────────────
    // ModuleContainer is request-scoped — it must NEVER be stored as a global
    // singleton. Under OpenSwoole that would leak every request's Identity,
    // transaction state, and module bindings into subsequent requests.

    /**
     * @throws \LogicException always.
     */
    public static function getInstance(): never
    {
        throw new \LogicException(
            'ModuleContainer::getInstance() is disabled. '
            . 'ModuleContainer is request-scoped and must never be stored as a global singleton. '
            . 'Under OpenSwoole it would leak Identity, transaction state, and module bindings '
            . 'across requests. Receive the container in Provider::register($container) or from '
            . 'the Request object inside pipeline stages.'
        );
    }

    /**
     * @throws \LogicException always.
     */
    public static function setInstance(?BindItContainerInterface $container = null): never
    {
        throw new \LogicException(
            'ModuleContainer::setInstance() is disabled. '
            . 'ModuleContainer is request-scoped; the kernel creates a fresh one per request.'
        );
    }

    // ── Lifecycle / Swoole teardown ───────────────────────────────────────────

    /**
     * Fully reset this container to a blank state.
     *
     * Under standard PHP-FPM/CLI this is never needed — the container is
     * garbage-collected when the request ends. Under OpenSwoole/Swoole, if
     * you pool or reuse ModuleContainer instances across requests you MUST
     * call this before reuse to prevent Identity, transaction state, and
     * module bindings leaking between requests.
     *
     * The kernel's default design creates a fresh ModuleContainer per request
     * (via OnDemandLoader), so this method is a safety escape-hatch rather
     * than a required call in normal usage.
     */
    public function reset(): void
    {
        // Container
        $this->forgetAllStore();    // clears $this->store[]
        $this->resolved  = [];
        $this->extenders = [];
        // BindIt
        $this->bindings         = [];
        $this->methodBindings   = [];
        $this->reboundCallbacks = [];
        // TypeAlias
        $this->aliases         = [];
        $this->abstractAliases = [];
        // Build trait
        $this->with = [];
        // Contextual trait
        $this->buildStack = [];
        $this->contextual = [];
        // CallBacks trait
        $this->globalBeforeResolvingCallbacks = [];
        $this->globalResolvingCallbacks       = [];
        $this->globalAfterResolvingCallbacks  = [];
        $this->beforeResolvingCallbacks       = [];
        $this->resolvingCallbacks             = [];
        $this->afterResolvingCallbacks        = [];
        // Own state
        $this->bindingScope    = [];
        $this->internal        = [];
        $this->scope           = '';
        $this->resolutionStack = [];
    }
}
