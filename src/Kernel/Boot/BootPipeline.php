<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\BootFailureException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\{
    BootStageContract,
    ValidateConfigStage,
    DetectConflictsStage,
    DetectCyclesStage,
    CompileServiceManifestStage,
    CompileRouteManifestStage,
    CompileViewManifestStage,
    CompileJobManifestStage,
    CompileCommandManifestStage,
    RegisterPortsStage,
    BindSecurityStage
};

/**
 * BootPipeline — runs ONCE at startup.
 *
 * Stages run in fixed order. Any failure = immediate shutdown with a
 * descriptive BootFailureException listing exactly what is wrong.
 * The application never starts with missing config, conflicting modules,
 * circular dependencies, or unbound ports.
 */
final class BootPipeline
{
    /** @var list<BootStageContract> Ordered boot stages — order is fixed and meaningful. */
    private array $stages;

    /**
     * @param list<class-string> $moduleClasses
     * @param array<int, \AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract> $securityLayers
     * @param list<array{method: string, path: string, handler: string}> $projectRoutes
     *   Project-layer routes (declared via Kernel::withRoutes), compiled into the
     *   route manifest under the synthetic '__project__' scope with no module graph.
     * @param list<string> $disabledRoutes
     *   Project route-disable policy (Kernel::withRoutePolicy). "METHOD /path" or a
     *   module domain; applied to plugin routes before project routes are compiled.
     */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly CoreContainer $core,
        array $securityLayers = [],
        array $projectRoutes = [],
        array $disabledRoutes = [],
    ) {
        // Single reader shared across every manifest-reading stage: each module.json
        // (the single source of truth) is read + JSON-decoded ONCE and cached, instead
        // of once per stage. The cache populates on the first stage to touch a module
        // and every later stage hits it.
        $reader = new ManifestReader();

        $this->stages = [
            new ValidateConfigStage($moduleClasses, reader: $reader),         // 1. env vars present + typed
            new DetectConflictsStage($moduleClasses, reader: $reader),        // 2. no two modules share solves()
            new DetectCyclesStage($moduleClasses, reader: $reader),           // 3. no circular requires[] chains
            new CompileServiceManifestStage($moduleClasses, projectRoutes: $projectRoutes, reader: $reader), // 4. dep graph → service-manifest.php
            new CompileRouteManifestStage($moduleClasses, projectRoutes: $projectRoutes, disabledRoutes: $disabledRoutes, reader: $reader),   // 5. routes[] → route-manifest.php
            new CompileViewManifestStage($moduleClasses, reader: $reader),    // 6. views[] → view-manifest.php (project-first cascade)
            new CompileJobManifestStage($moduleClasses, reader: $reader),     // 7. jobs[] → job-manifest.php
            new CompileCommandManifestStage($moduleClasses, reader: $reader), // 8. commands[] → command-manifest.php
            new RegisterPortsStage($core),                   // 9. Port → Adapter bindings validated
            new BindSecurityStage($securityLayers),          // 10. SecurityGateway layers validated
        ];
    }

    /**
     * Run all stages in order. Fail fast on any error.
     *
     * @throws BootFailureException with the stage name and reason
     */
    public function run(): void
    {
        foreach ($this->stages as $stage) {
            try {
                $stage->run();
            } catch (BootException $e) {
                throw new BootFailureException(
                    "Boot failed at [" . $stage::class . "]: " . $e->getMessage(),
                    previous: $e,
                );
            }
        }
    }
}
