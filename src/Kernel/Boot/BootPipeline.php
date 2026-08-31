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
    CompileLangManifestStage,
    CompileJobManifestStage,
    CompileCommandManifestStage,
    CompileConfigManifestStage,
    CompileModuleFilesStage,
    LoadModuleFilesStage,
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
    /**
     * Stages that COMPILE — they read module.json / config and write manifests.
     * Skippable when BootStamp says the manifests are already current.
     *
     * @var list<BootStageContract>
     */
    private array $compileStages;

    /**
     * Stages that affect the PROCESS rather than a manifest, and so must run on
     * every build even when the compilation is skipped. Loading a module's
     * helper FILES defines global functions, which live in the process and not
     * on disk: a cached boot that skipped them would leave BOOT_CACHE silently
     * changing behaviour, surfacing as an undefined function deep inside a
     * plugin. They run after the compilation that produces their manifest and
     * before the validate stages, which are the first to touch live module
     * objects; on a cached boot they run first of all.
     *
     * @var list<BootStageContract>
     */
    private array $alwaysStages;

    /**
     * Stages that VALIDATE live objects (port bindings, security layers). They
     * touch no disk, produce no manifest and cost nothing, so they run on EVERY
     * build — a cached boot must still refuse a missing port.
     *
     * @var list<BootStageContract>
     */
    private array $validateStages;

    private ManifestReader $reader;

    /**
     * @param list<class-string> $moduleClasses
     * @param array<int, \AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract> $securityLayers
     * @param list<array{method: string, path: string, handler: string}> $projectRoutes
     *   Project-layer routes (declared via Kernel::withRoutes), compiled into the
     *   route manifest under the synthetic '__project__' scope with no module graph.
     * @param list<string> $disabledRoutes
     *   Project route-disable policy (Kernel::withRoutePolicy). "METHOD /path" or a
     *   module domain; applied to plugin routes before project routes are compiled.
     * @param array<string, mixed> $projectGroups
     *   Project route GROUPS + source-wide route defaults (Kernel::withRouteGroups).
     *   Expanded into flat routes by the route-manifest compiler.
     * @param list<string> $projectDomains
     *   Hosts this project serves (proj.json "domains"). A route grouped under a
     *   host that is not registered fails the boot — it could never be reached.
     */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly CoreContainer $core,
        array $securityLayers = [],
        array $projectRoutes = [],
        array $disabledRoutes = [],
        array $projectGroups = [],
        array $projectDomains = [],
        ?ManifestReader $reader = null,
        /**
         * Plugin-route ALLOWLIST (Kernel::withRouteAllowPolicy / proj.json
         * routePolicy.only). Non-empty means a plugin route must match a spec or
         * it is dropped; empty means no allowlist.
         *
         * @var list<string>
         */
        array $allowedRoutes = [],
    ) {
        // Single reader shared across every manifest-reading stage: each module.json
        // (the single source of truth) is read + JSON-decoded ONCE and cached, instead
        // of once per stage. The cache populates on the first stage to touch a module
        // and every later stage hits it. The caller may pass its own so it can reuse
        // the same cache (and ask which files were read) afterwards.
        $this->reader = $reader ??= new ManifestReader();

        $this->compileStages = [
            new ValidateConfigStage($moduleClasses, reader: $reader),         // 1. env vars present + typed
            new DetectConflictsStage($moduleClasses, reader: $reader),        // 2. no two modules share solves()
            new DetectCyclesStage($moduleClasses, reader: $reader),           // 3. no circular requires[] chains
            new CompileServiceManifestStage($moduleClasses, projectRoutes: $projectRoutes, reader: $reader, projectGroups: $projectGroups), // 4. dep graph → service-manifest.php
            new CompileRouteManifestStage($moduleClasses, projectRoutes: $projectRoutes, disabledRoutes: $disabledRoutes, reader: $reader, projectGroups: $projectGroups, projectDomains: $projectDomains, allowedRoutes: $allowedRoutes),   // 5. routes[] → route-manifest.php
            new CompileViewManifestStage($moduleClasses, reader: $reader),    // 6. views[] → view-manifest.php (project-first cascade)
            new CompileLangManifestStage($moduleClasses, reader: $reader),    // 7. lang[] → lang-manifest.php (project-first cascade)
            new CompileJobManifestStage($moduleClasses, reader: $reader),     // 8. jobs[] → job-manifest.php
            new CompileCommandManifestStage($moduleClasses, reader: $reader), // 9. commands[] → command-manifest.php
            new CompileConfigManifestStage($moduleClasses),                   // 10. config/*.php → config-manifest.php (project over plugin)
            new CompileModuleFilesStage($moduleClasses, reader: $reader),     // 11. files[] / composer autoload.files → files-manifest.php
        ];

        $this->alwaysStages = [
            new LoadModuleFilesStage(),                      // require_once module helper files
        ];

        $this->validateStages = [
            new RegisterPortsStage($core),                   // 12. Port → Adapter bindings validated
            new BindSecurityStage($securityLayers),          // 13. SecurityGateway layers validated
        ];
    }

    /** The reader the compile stages used — ask it which files they read. */
    public function reader(): ManifestReader
    {
        return $this->reader;
    }

    /**
     * Run all stages in order. Fail fast on any error.
     *
     * @throws BootFailureException with the stage name and reason
     */
    public function run(): void
    {
        // Compile first (it writes the manifest the always-stages read), then
        // the process-level stages, then validation of the live objects.
        $this->runStages([...$this->compileStages, ...$this->alwaysStages, ...$this->validateStages]);
    }

    /**
     * Run ONLY the stages that validate live objects.
     *
     * Used when BootStamp reports the compiled manifests are already current: the
     * compilation is skipped, but a missing port binding or an unusable security
     * layer must still fail the boot.
     *
     * The always-stages run here too. Skipping them would mean BOOT_CACHE=1
     * quietly stopped loading module helper files, so a flag documented as a
     * pure optimisation would change what functions exist at runtime.
     */
    public function runValidationOnly(): void
    {
        $this->runStages([...$this->alwaysStages, ...$this->validateStages]);
    }

    /** @param list<BootStageContract> $stages */
    private function runStages(array $stages): void
    {
        foreach ($stages as $stage) {
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
