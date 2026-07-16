<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Loading;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\{CoreContainer, ModuleContainer};
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\{CircularDependencyException, KernelException};
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;


// ─── OnDemandLoader ──────────────────────────────────────────────────────────

/**
 * Per-request module loader.
 *
 * Builds a fresh request-scoped ModuleContainer and runs each module's
 * register() in dependency order, with the correct scope set so bindInternal()
 * isolation is enforced. The container also exposes the request-scoped kernel
 * services every Service depends on (Identity, TransactionManager,
 * DomainEventCollector).
 *
 * Hooks and event subscriptions are NOT wired here — they are registered once at
 * kernel build (Provider::boot), keeping per-request work minimal and the
 * pipeline/event routing tables stable under OpenSwoole.
 *
 * The container is discarded when the request ends — zero state leaks.
 */
final class OnDemandLoader
{
    /**
     * @param list<class-string<ModuleContract>> $essentialModules
     *   Cross-cutting modules registered into EVERY request-scoped container,
     *   regardless of the route's dependency graph. Use sparingly — each one is
     *   pure per-request cost. Intended for request-scoped infrastructure that
     *   must be available app-wide (sessions, cookies) and therefore cannot be
     *   an app-lifetime CoreContainer port. Their boot() hooks must already be
     *   registered (i.e. they are also in withModules()).
     */
    /**
     * Provider instances cached per worker. Providers are stateless by contract
     * (all state lives in the bindings they register into the request-scoped
     * container), so one instance can safely serve every request — this only
     * skips the per-request `new $providerClass()`.
     *
     * @var array<class-string, ModuleContract>
     */
    private array $providers = [];

    public function __construct(
        private readonly CoreContainer $core,
        private readonly array $essentialModules = [],
    ) {}

    public function load(DependencyGraph $graph, Request $request): ModuleContainer
    {
        $container = $this->loadWithIdentity($graph, $request->identity());

        // Expose the client IP for request-scoped services (e.g. the audit trail)
        // to attribute an action's origin without threading it through every
        // controller. Bound only on the HTTP path — worker jobs have no request.
        $ip = $request->ip();
        if ($ip !== null && $ip !== '') {
            $container->bind('client.ip', static fn (): string => $ip);
        }

        return $container;
    }

    /**
     * Build a request-scoped ModuleContainer with a pre-resolved Identity.
     * Used by WorkerLoop (jobs have no HTTP Request) and by load() above.
     * Passing null yields a guest Identity.
     */
    public function loadWithIdentity(DependencyGraph $graph, ?Identity $identity): ModuleContainer
    {
        $container = new ModuleContainer($this->core);

        // Request-scoped kernel services, available to every module (public scope).
        $container->setScope('');
        $resolvedIdentity = $identity ?? Identity::guest();
        $container->singleton(Identity::class, static fn() => $resolvedIdentity);
        $container->singleton(DomainEventCollector::class, static fn() => new DomainEventCollector());
        $container->singleton(
            TransactionManager::class,
            fn() => new TransactionManager($this->core->make(DatabasePort::class)),
        );

        // Register each module under its own scope so internal bindings are isolated.
        $registered = [];
        foreach ($graph->moduleNames() as $domain) {
            $entry         = $graph->entry($domain);
            $providerClass = $entry['module'] ?? null;

            // A scope with no module provider (e.g. the synthetic '__project__'
            // scope backing project-layer routes) contributes only its scope —
            // there is nothing to register. The controller autowires directly.
            if ($providerClass === null && array_key_exists('module', $entry)) {
                continue;
            }

            if ($providerClass === null || !class_exists($providerClass)) {
                throw new KernelException(
                    "Module provider for [{$domain}] could not be loaded.",
                    layer: 'kernel.loading',
                    context: ['domain' => $domain, 'provider' => $providerClass],
                );
            }

            $provider = $this->providers[$providerClass] ??= new $providerClass();
            $container->setScope($domain);
            $provider->register($container);
            $registered[$providerClass] = true;
        }

        // Register essential cross-cutting modules that the route graph did not
        // already pull in (deduped by provider class), each under its own solve
        // domain so internal-binding isolation still holds.
        foreach ($this->essentialModules as $providerClass) {
            if (isset($registered[$providerClass]) || !class_exists($providerClass)) {
                continue;
            }
            $provider = $this->providers[$providerClass] ??= new $providerClass();
            $container->setScope($provider->solves());
            $provider->register($container);
            $registered[$providerClass] = true;
        }

        $container->setScope('');

        return $container;
    }
}
