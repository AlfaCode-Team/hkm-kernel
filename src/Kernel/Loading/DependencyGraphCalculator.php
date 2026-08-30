<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Loading;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\{CircularDependencyException, KernelException};

final class DependencyGraphCalculator
{
    /** @param array{services?: array<string, array<string, mixed>>} $manifest */
    public function __construct(
        private readonly array $manifest
    ) {}

    /**
     * The solves domains of a set of provider classes, read from a compiled
     * service manifest.
     *
     * Essential modules are configured as CLASSES but seeded into a graph as
     * DOMAINS, and resolving them through the graph (rather than only registering
     * them standalone) is what brings their transitive requires[] with them — an
     * essential like Tenancy gets its Database dependency bound instead of failing
     * with an unbound contract.
     *
     * Lives here, not on a pipeline, because BOTH the HTTP and worker surfaces
     * need the same mapping and a private copy in each is how they drifted apart
     * in the first place. A class absent from the manifest (not in withModules)
     * is skipped — OnDemandLoader still registers it standalone.
     *
     * @param array{services?: array<string, array<string, mixed>>} $manifest
     * @param list<class-string> $moduleClasses
     * @return list<string>
     */
    public static function domainsFor(array $manifest, array $moduleClasses): array
    {
        if ($moduleClasses === []) {
            return [];
        }

        $wanted  = array_flip($moduleClasses);
        $domains = [];

        foreach ($manifest['services'] ?? [] as $domain => $entry) {
            if (isset($wanted[$entry['module'] ?? ''])) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * Resolve the dependency graph for a service, optionally seeding extra
     * module domains the matched route declared in its own requires[].
     *
     * The synthetic '__project__' scope carries no requires of its own, so a
     * project route opts into specific plugins per-route via $additional rather
     * than loading them for every project page. Each extra domain is resolved
     * (with its transitive requires) into the same graph.
     *
     * STATELESS: all traversal state lives in locals passed by reference, never
     * on $this. One calculator instance is shared across every request (and,
     * under OpenSwoole, across concurrent coroutines) — keeping zero mutable
     * instance state makes concurrent resolve() calls provably non-interfering,
     * independent of whether any future edit introduces an I/O yield point.
     *
     * @param list<string> $additional extra module domains to pull into the graph
     * @throws CircularDependencyException|KernelException
     */
    public function resolve(string $service, array $additional = []): DependencyGraph
    {
        /** @var array<string, array<string, mixed>> $resolved */
        $resolved = [];
        /** @var array<string, true> $resolving */
        $resolving = [];

        $this->visit($service, $resolved, $resolving);
        foreach ($additional as $dep) {
            $this->visit($dep, $resolved, $resolving);
        }

        return new DependencyGraph($resolved);
    }

    /**
     * @param array<string, array<string, mixed>> $resolved
     * @param array<string, true>                 $resolving
     */
    private function visit(string $service, array &$resolved, array &$resolving): void
    {
        if (isset($resolved[$service])) {
            return;
        }
        if (isset($resolving[$service])) {
            $path = implode(' -> ', array_keys($resolving)) . ' -> ' . $service;
            throw new CircularDependencyException("Circular dependency detected: {$path}");
        }

        $resolving[$service] = true;

        $entry = $this->manifest['services'][$service]
            ?? throw new KernelException(
                "Service [{$service}] not found in service-manifest.php",
                layer: 'kernel.loading',
                context: ['service' => $service],
            );

        foreach ($entry['requires'] ?? [] as $dep) {
            $this->visit($dep, $resolved, $resolving);
        }

        unset($resolving[$service]);
        $resolved[$service] = $entry;
    }
}
