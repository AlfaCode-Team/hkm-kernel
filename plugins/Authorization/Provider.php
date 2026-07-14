<?php

declare(strict_types=1);

namespace Plugins\Authorization;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Authorization\API\Contracts\AuthorizationServiceContract;
use Plugins\Authorization\Application\Services\AuthorizationService;
use Plugins\Authorization\Engine\Enforcer;
use Plugins\Authorization\Infrastructure\Persistence\DatabasePolicyAdapter;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;

/**
 * Authorization plugin — Casbin RBAC/ABAC policy engine.
 *
 * Ported from the 0.3 framework's Application\Casbin engine. The engine itself
 * lives untouched under Engine/; this Provider wires it into the GDA flow:
 *   - policy storage goes through DatabasePort (DatabasePolicyAdapter)
 *   - the Enforcer is an internal binding (never resolved cross-module)
 *   - only AuthorizationServiceContract is exposed
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'authorization.policy';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [DatabaseConnectionManagerContract::class];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [AuthorizationServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        // Casbin policy storage adapter. Policy rules are CONTROL-PLANE data
        // (roles/permissions are global, not tenant data), so pin to the central
        // connection — the same store the authz:seed CLI writes to, so seeded
        // policies are visible to runtime enforcement.
        $container->bindInternal(DatabasePolicyAdapter::class, static fn(ModuleContainer $c) =>
            new DatabasePolicyAdapter(
                $c->make(DatabasePort::class),
                env('AUTHZ_POLICY_TABLE') ?: 'casbin_rule',
            )
        );

        // The Casbin Enforcer — internal, built from the model config + DB adapter.
        $container->bindInternal(Enforcer::class, static function (ModuleContainer $c) {
            $modelPath = env('AUTHZ_MODEL_PATH') ?: __DIR__ . '/config/rbac_model.conf';
            return new Enforcer($modelPath, $c->make(DatabasePolicyAdapter::class));
        });

        // Published contract.
        $container->bind(AuthorizationServiceContract::class, static fn(ModuleContainer $c) =>
            new AuthorizationService($c->make(Enforcer::class))
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Declarative route filter: "filters": ["can:users,edit"] enforces the
        // Casbin policy for the route (the route must also carry
        // "requires": ["authorization.policy"] so this module is loaded).
        $http->filter('can', \Plugins\Authorization\Infrastructure\Http\Stages\PolicyFilterStage::class);

        // authz:seed — import a policy CSV into the DB policy table. Deferred so
        // only CLI processes pay for it; builds its own enforcer over the
        // central connection (policy rules are control-plane data).
        $cli->defer(static function (CliPipeline $cli): void {
            $c = new ModuleContainer($cli->container());
            $c->setScope('database.management');
            (new \Plugins\Database\Provider())->register($c);

            // Lazy: building an Enforcer loads policy from the DB, so defer it
            // until the command actually runs (not at CLI registration time).
            $enforcerFactory = static function () use ($c): Enforcer {
                $adapter = new DatabasePolicyAdapter(
                    $c->make(\Plugins\Database\API\Contracts\DatabaseConnectionManagerContract::class)->default(),
                    env('AUTHZ_POLICY_TABLE') ?: 'casbin_rule',
                );

                return new Enforcer(
                    env('AUTHZ_MODEL_PATH') ?: __DIR__ . '/config/rbac_model.conf',
                    $adapter,
                );
            };

            $cli->command(new \Plugins\Authorization\Infrastructure\Cli\SeedPolicyCommand(
                $enforcerFactory,
                __DIR__ . '/config/policy.seed.csv',
            ));
        });
    }
}
