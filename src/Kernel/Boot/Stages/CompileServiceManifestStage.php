<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};

/**
 * Compiles the service graph the OnDemandLoader uses at runtime.
 * service-manifest.php: ['services' => [domain => {module, requires, exposes, name}]].
 *
 * Every requires[] entry MUST be the solves domain of a registered module.
 * An entry that matches nothing FAILS the boot with a descriptive message —
 * silently dropping it (the old behaviour) meant a typo'd domain, or a plugin
 * missing from withModules([...]), surfaced only as an unbound-contract error
 * deep at request time. Port dependencies (DatabasePort, MailPort, …) resolve
 * via CoreContainer and do not belong in requires[].
 */
final class CompileServiceManifestStage implements BootStageContract
{
    /**
     * @param list<class-string> $moduleClasses
     * @param list<array{method: string, path: string, handler: string}> $projectRoutes
     */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly array $projectRoutes = [],
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $manifests = [];
        $domains = [];
        foreach ($this->moduleClasses as $class) {
            $m = $this->reader->read($class);
            $manifests[$class] = $m;
            $domains[$m['solves']] = true;
        }

        $services = [];
        $unknown = [];
        foreach ($this->moduleClasses as $class) {
            $m = $manifests[$class];
            $domain = $m['solves'];

            $moduleRequires = [];
            foreach ($m['requires'] ?? [] as $dep) {
                if (isset($domains[$dep])) {
                    $moduleRequires[] = $dep;
                } else {
                    $unknown[] = "[{$class}] requires '{$dep}'";
                }
            }

            $services[$domain] = [
                'name' => $m['name'] ?? $domain,
                'module' => $class,
                'requires' => $moduleRequires,
                'exposes' => $m['exposes'] ?? [],
            ];
        }

        if ($unknown !== []) {
            throw new BootException(
                "Unknown module.json requires[] entries — no registered module solves them:\n  "
                . implode("\n  ", $unknown)
                . "\nFix the spelling, or add the plugin that solves the domain to withModules([...]) "
                . "in the project bootstrap. Registered domains: " . implode(', ', array_keys($domains))
            );
        }

        // Synthetic project scope — present only when the project declares its own
        // routes (Kernel::withRoutes). It has no module and no requires, so its
        // dependency graph is empty: the controller autowires from the request
        // container without running any module register().
        if ($this->projectRoutes !== []) {
            $services[CompileRouteManifestStage::PROJECT_SCOPE] = [
                'name' => CompileRouteManifestStage::PROJECT_SCOPE,
                'module' => null,
                'requires' => [],
                'exposes' => [],
            ];
        }

        ManifestWriter::write('service-manifest.php', ['services' => $services]);
    }
}
