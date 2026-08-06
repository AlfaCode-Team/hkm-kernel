<?php

declare(strict_types=1);

namespace Plugins\Validation;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/**
 * Validation plugin — the shared request-validation engine.
 *
 * The Validator itself is a plain autoloaded class (validation is DI-free
 * boundary logic — it needs no container). This Provider exists only to load
 * config/validation.php ONCE at boot and register its custom rule-sets +
 * named rule-groups into the process-wide Validator registry (CodeIgniter's
 * `Config\Validation` wiring). No per-request cost, no bindings.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'validation.rules';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [];
    }

    public function register(ModuleContainer $container): void
    {
        // Nothing to bind — the Validator is used statically at the DTO boundary.
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        $config = $this->config();

        // CI $ruleSets — register every rule-provider class.
        foreach ($config['rulesets'] ?? [] as $ruleSet) {
            Validator::extendWith($ruleSet);
        }

        // CI rule groups — register each named {rules, messages} set.
        foreach ($config['groups'] ?? [] as $name => $group) {
            Validator::defineGroup($name, $group['rules'] ?? [], $group['messages'] ?? []);
        }
    }

    /** @return array{rulesets?: list<object|class-string>, groups?: array<string,array{rules?:array,messages?:array}>} */
    private function config(): array
    {
        // From the compiled config manifest, where the project's config/validation.php
        // is DEEP-MERGED over this plugin's — so a project adding one rule group
        // keeps the shipped rulesets instead of replacing the file wholesale.
        $config = \function_exists('config') ? config('validation') : null;

        if (\is_array($config) && $config !== []) {
            return $config;
        }

        // No manifest (e.g. a unit test that never ran the BootPipeline).
        /** @var array $fallback */
        $fallback = require __DIR__ . '/config/validation.php';

        return \is_array($fallback) ? $fallback : [];
    }
}
