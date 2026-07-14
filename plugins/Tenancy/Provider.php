<?php

declare(strict_types=1);

namespace Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Tenancy\API\Contracts\InvitationServiceContract;
use Plugins\Tenancy\API\Contracts\MembershipServiceContract;
use Plugins\Tenancy\API\Contracts\TenantAdminServiceContract;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\API\Contracts\TenantHostRegistryContract;
use Plugins\Tenancy\API\Contracts\TenantHostServiceContract;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\Application\Listeners\AssignTenantMembershipOnUserRegistered;
use Plugins\Tenancy\Application\Ports\InvitationStore;
use Plugins\Tenancy\Application\Ports\MembershipReader;
use Plugins\Tenancy\Application\Ports\MembershipWriter;
use Plugins\Tenancy\Application\Ports\DnsResolver;
use Plugins\Tenancy\Application\Ports\TenantProvisioner;
use Plugins\Tenancy\Application\Ports\TenantWriteStore;
use Plugins\Tenancy\Application\Ports\TenantHostStore;
use Plugins\Tenancy\Application\Services\InvitationService;
use Plugins\Tenancy\Application\Services\MembershipService;
use Plugins\Tenancy\Application\Services\TenantAdminService;
use Plugins\Tenancy\Application\Services\TenantHostService;
use Plugins\Tenancy\Infrastructure\Dns\SystemDnsResolver;
use Plugins\Tenancy\Infrastructure\Http\Controllers\InvitationController;
use Plugins\Tenancy\Infrastructure\Http\Controllers\TenantAdminController;
use Plugins\Tenancy\Infrastructure\Http\Controllers\TenantController;
use Plugins\Tenancy\Infrastructure\Http\Controllers\TenantHostController;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\Tenancy\Infrastructure\Http\Identification\ClaimTenantIdentifier;
use Plugins\Tenancy\Infrastructure\Http\Identification\DomainTenantIdentifier;
use Plugins\Tenancy\Infrastructure\Http\Identification\HostTenantIdentifier;
use Plugins\Tenancy\Infrastructure\Http\Identification\TenantIdentifier;
use Plugins\Tenancy\Infrastructure\Http\Stages\TenantContextStage;
use Plugins\Tenancy\Infrastructure\Persistence\InvitationRepository;
use Plugins\Tenancy\Infrastructure\Persistence\MembershipRepository;
use Plugins\Tenancy\Infrastructure\Persistence\TenantAdminRepository;
use Plugins\Tenancy\Infrastructure\Persistence\TenantHostRegistry;
use Plugins\Tenancy\Infrastructure\Provisioning\DdlTenantProvisioner;
use Plugins\Tenancy\Infrastructure\Persistence\TenantHostRepository;
use Plugins\Tenancy\Infrastructure\Persistence\TenantRegistry;
use Plugins\Tenancy\Infrastructure\TenantConnectionResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Provider — wires the Tenancy control plane.
 *
 * Solves: tenancy.routing  (requires: database.management)
 *
 * Essential module: register it via withEssentialModules([...]) so the
 * after.load hook routes EVERY authenticated request to its tenant database.
 * The registry reads the CENTRAL connection (ConnectionManager default); the
 * resolver hands per-request tenant DatabasePorts to TenantContextStage, which
 * rebinds DatabasePort in the request container.
 *
 * Swoole note: for cross-request tenant connection pooling, bind the
 * ConnectionManager and resolver into the CoreContainer in bootstrap instead of
 * the per-request container — see README.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'tenancy.routing';
    }

    public function requires(): array
    {
        return ['database.management', 'auth.identity', 'user.management', 'audit.trail'];
    }

    public function exposes(): array
    {
        return [
            TenantRegistryContract::class,
            TenantHostRegistryContract::class,
            TenantHostServiceContract::class,
            TenantConnectionResolverContract::class,
            MembershipServiceContract::class,
            InvitationServiceContract::class,
            TenantAdminServiceContract::class,
        ];
    }

    public function register(ModuleContainer $container): void
    {
        $container->singleton(TenantRegistryContract::class, static function ($c): TenantRegistryContract {
            $manager = $c->make(DatabaseConnectionManagerContract::class);

            return new TenantRegistry(
                central: $manager->default(),          // central connection — never a tenant one
                cache: $c->make(CachePort::class),
                ttl: self::intEnv('TENANCY_REGISTRY_TTL', 60),
            );
        });

        $container->singleton(TenantConnectionResolverContract::class, static function ($c): TenantConnectionResolverContract {
            return new TenantConnectionResolver(
                connections: $c->make(DatabaseConnectionManagerContract::class),
                registry: $c->make(TenantRegistryContract::class),
                crypto: $c->make(EncryptionPort::class),
                cache: $c->make(CachePort::class),
                logger: self::optionalLogger($c),
                breakerThreshold: self::intEnv('TENANCY_BREAKER_THRESHOLD', 5),
                breakerCooldown: self::intEnv('TENANCY_BREAKER_COOLDOWN', 30),
                breakerWindow: self::intEnv('TENANCY_BREAKER_WINDOW', 60),
            );
        });

        // Reads the central `tenant_hosts` table (hostname -> tenant_id) for the
        // custom-domain identification mode. Pinned to the central connection.
        $container->singleton(TenantHostRegistryContract::class, static function ($c): TenantHostRegistryContract {
            $manager = $c->make(DatabaseConnectionManagerContract::class);

            return new TenantHostRegistry(
                central: $manager->default(),          // central connection — never a tenant one
                cache: $c->make(CachePort::class),
                ttl: self::intEnv('TENANCY_REGISTRY_TTL', 60),
            );
        });

        // Tenant identification strategy, selected by TENANCY_MODE:
        //   'host'   -> full Host header mapped via the tenant_hosts registry
        //               (custom/bring-your-own domains)
        //   'domain' -> Host sub-domain label under a configured base domain
        //   default  -> the authenticated Identity claim (SaaS/JWT model)
        // The connection routing below is identical for all; only WHO the tenant
        // is differs.
        $container->singleton(TenantIdentifier::class, static function ($c): TenantIdentifier {
            return match (self::mode()) {
                'host' => new HostTenantIdentifier($c->make(TenantHostRegistryContract::class)),
                'domain' => new DomainTenantIdentifier(self::baseDomains(), self::reservedSubdomains()),
                default => new ClaimTenantIdentifier(),
            };
        });

        // ── custom-domain management (UI-driven) ─────────────────────────────
        // Writes the central tenant_hosts table; DNS adapter scans live records
        // to prove ownership of a domain by the verification token.
        $container->bindInternal(TenantHostStore::class, static fn($c): TenantHostStore =>
            new TenantHostRepository($c->make(DatabaseConnectionManagerContract::class)->default()));

        $container->bindInternal(DnsResolver::class, static fn(): DnsResolver => new SystemDnsResolver());

        $container->bind(TenantHostServiceContract::class, static fn($c): TenantHostServiceContract =>
            new TenantHostService(
                hosts: $c->make(TenantHostStore::class),
                dns: $c->make(DnsResolver::class),
                audit: $c->make(AuditServiceContract::class),
                registry: $c->make(TenantHostRegistryContract::class),
                challengePrefix: (string) (env('TENANCY_DNS_CHALLENGE_PREFIX') ?: '_psp-verify'),
                valuePrefix: (string) (env('TENANCY_DNS_VALUE_PREFIX') ?: 'psp-verify='),
                maxHostsPerTenant: self::intEnv('TENANCY_MAX_HOSTS_PER_TENANT', 25),
            ));

        $container->bindInternal(TenantHostController::class, static fn($c): TenantHostController =>
            new TenantHostController($c->make(TenantHostServiceContract::class)));

        $container->bind(
            TenantContextStage::class,
            static fn($c): TenantContextStage =>
            new TenantContextStage(
                $c->make(TenantConnectionResolverContract::class),
                $c->make(TenantIdentifier::class),
            )
        );

        // ── tenant-selection flow (central control plane) ────────────────────
        // Membership + audit read/write the CENTRAL connection (user_tenants /
        // audit_log live in the control-plane DB), pinned via the manager default.
        $container->bindInternal(MembershipReader::class, static fn($c): MembershipReader =>
            new MembershipRepository($c->make(DatabaseConnectionManagerContract::class)->default()));

        // Audit is now owned by the shared Audit plugin (solves audit.trail).
        // Tenancy records through its published AuditServiceContract instead of
        // writing `audit_log` itself — see requires: ["audit.trail"].

        // Control plane only — verifies seats + audits. No Auth dependency:
        // token minting lives in TenantController, which keeps the container
        // graph acyclic (AuthService → UserService → MembershipService).
        $container->bind(MembershipServiceContract::class, static fn($c): MembershipServiceContract =>
            new MembershipService(
                memberships: $c->make(MembershipReader::class),
                audit: $c->make(AuditServiceContract::class),
            ));

        // The HTTP boundary composes the verified seat with the Auth module
        // (token mint) and User's tenant-profile reader (the `name` claim from
        // the tenant user_profiles row — the User plugin owns that table's SQL).
        $container->bindInternal(TenantController::class, static fn($c): TenantController =>
            new TenantController(
                memberships: $c->make(MembershipServiceContract::class),
                auth: $c->make(AuthServiceContract::class),
                profiles: $c->has(\Plugins\User\API\Contracts\TenantProfileReaderContract::class)
                    ? $c->make(\Plugins\User\API\Contracts\TenantProfileReaderContract::class)
                    : null,
                tokenTtl: self::intEnv('TENANCY_TOKEN_TTL', 3600),
            ));

        // ── tenant administration (control-plane CRUD) ───────────────────────
        // Provisions/updates/de-provisions tenants over HTTP — the JSON twin of
        // the tenant:create / tenant:delete CLI commands. The service orchestrates
        // two internal ports: persistence (DatabasePort only) and provisioning
        // (DDL + template migrations). Both pin to the CENTRAL connection.
        $container->bindInternal(TenantWriteStore::class, static fn($c): TenantWriteStore =>
            new TenantAdminRepository($c->make(DatabaseConnectionManagerContract::class)->default()));

        $container->bindInternal(TenantProvisioner::class, static function ($c): TenantProvisioner {
            $template = env('TENANCY_TEMPLATE_PATH');

            return new DdlTenantProvisioner(
                central: $c->make(DatabaseConnectionManagerContract::class)->default(),
                templatePath: (is_string($template) && $template !== '')
                ? $template
                : __DIR__ . '/database/tenant-template',
            );
        });

        $container->bind(TenantAdminServiceContract::class, static fn($c): TenantAdminServiceContract =>
            new TenantAdminService(
                store: $c->make(TenantWriteStore::class),
                provisioner: $c->make(TenantProvisioner::class),
                registry: $c->make(TenantRegistryContract::class),
                crypto: $c->make(EncryptionPort::class),
                identity: $c->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity::class),
            ));

        $container->bindInternal(TenantAdminController::class, static fn($c): TenantAdminController =>
            new TenantAdminController($c->make(TenantAdminServiceContract::class)));

        // ── invitations (email onboarding) ───────────────────────────────────
        $container->bindInternal(InvitationStore::class, static fn($c): InvitationStore =>
            new InvitationRepository($c->make(DatabaseConnectionManagerContract::class)->default()));

        $container->bindInternal(MembershipWriter::class, static fn($c): MembershipWriter =>
            new MembershipRepository($c->make(DatabaseConnectionManagerContract::class)->default()));


        $container->bindInternal(AssignTenantMembershipOnUserRegistered::class, static fn($c): AssignTenantMembershipOnUserRegistered =>
            new AssignTenantMembershipOnUserRegistered($c->make(MembershipWriter::class)));

        $container->bind(InvitationServiceContract::class, static fn($c): InvitationServiceContract =>
            new InvitationService(
                invitations: $c->make(InvitationStore::class),
                memberships: $c->make(MembershipWriter::class),
                audit: $c->make(AuditServiceContract::class),
            ));

        // ── HTTP boundary for the invitation flow ────────────────────────────
        $container->bindInternal(InvitationController::class, static fn($c): InvitationController =>
            new InvitationController(
                $c->make(InvitationServiceContract::class),
                $c->make(UserServiceContract::class),
            ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Route to the tenant DB before RouteFilterStage / ExecuteStage touch
        // tenant data. Ordering among after.load hooks (lower = outer):
        //   StartSessionStage (20) → SessionAuthStage (22) → TenantContextStage (23)
        //   → QueuedCookiesStage (25).
        // Running AFTER session auth lets a SESSION-scoped tenant (Identity.tenantId
        // populated from the session) route the connection, not just JWT/Host. It
        // stays OUTER of the cookie-flush stage so the remembered-tenant cookie it
        // queues is still written to the response. All after.load hooks run before
        // the dedicated RouteFilterStage regardless of priority.
        $http->hook('after.load', TenantContextStage::class, priority: 10);

        // Reusable declarative guard: a route that touches tenant-only tables
        // opts in with "filters": ["auth", "tenant"] to fail clean (409) when no
        // tenant is active, instead of hitting the central DB and 500-ing.
        $http->filter('tenant', \Plugins\Tenancy\Infrastructure\Http\Stages\RequireTenantStage::class);

        // Assign a self-signup user to their originating tenant. The User plugin
        // emits `user.registered` (via its outbox); the tenant rides on the event
        // payload. The project binds the listener in the CoreContainer with a
        // central-connection MembershipWriter (EventBus resolves listeners there).
        $events->subscribe('user.registered', \Plugins\Tenancy\Application\Listeners\AssignTenantMembershipOnUserRegistered::class);

        // The tenant:create / tenants:migrate provisioning commands are SaaS
        // control-plane tools (they CREATE DATABASE, encrypt credentials, drive
        // the central registry) and depend on the Auth/Database control plane.
        // They are irrelevant in domain mode — where tenants are provisioned by
        // the project's own tooling — so only register them in claim mode.
        //
        // Their constructors need MODULE-SCOPED contracts (DatabaseConnectionManager,
        // TenantRegistry) that the CoreContainer cannot autowire — so the bare
        // class-string path is silently dropped by CliPipeline::instantiate().
        // Build a scoped ModuleContainer on the CLI path (deferred so HTTP/worker
        // builds never pay for it) and register the commands as ready instances.
        if (self::mode() !== 'domain') {
            $cli->defer(static function (CliPipeline $cli): void {
                $c = new ModuleContainer($cli->container());

                // Register the providers whose public contracts the commands need.
                $c->setScope('database.management');
                (new \Plugins\Database\Provider())->register($c);
                $c->setScope((new \Plugins\Crypto\Provider())->solves());
                (new \Plugins\Crypto\Provider())->register($c);
                // Audit publishes AuditServiceContract, which the Tenancy services
                // built below (TenantHostService, …) now depend on.
                $c->setScope((new \Plugins\Audit\Provider())->solves());
                (new \Plugins\Audit\Provider())->register($c);
                $c->setScope('tenancy.routing');
                (new self())->register($c);

                $connections = $c->make(DatabaseConnectionManagerContract::class);
                $crypto = $c->make(EncryptionPort::class);

                $cli->command(new \Plugins\Tenancy\Infrastructure\Cli\CreateTenantCommand($connections, $crypto));
                $cli->command(new \Plugins\Tenancy\Infrastructure\Cli\MigrateTenantsCommand(
                    $c->make(TenantRegistryContract::class),
                    $connections,
                    $crypto,
                ));
                $cli->command(new \Plugins\Tenancy\Infrastructure\Cli\DeleteTenantCommand($connections));
                $cli->command(new \Plugins\Tenancy\Infrastructure\Cli\RememberTenantCommand($connections));
                $cli->command(new \Plugins\Tenancy\Infrastructure\Cli\AddTenantHostCommand(
                    $c->make(TenantHostServiceContract::class),
                    $connections,
                ));
            });
        }
    }

    private static function optionalLogger(mixed $container): LoggerInterface
    {
        try {
            $logger = $container->make(LoggerInterface::class);

            return $logger instanceof LoggerInterface ? $logger : new NullLogger();
        } catch (\Throwable) {
            return new NullLogger();
        }
    }

    private static function intEnv(string $key, int $default): int
    {
        $value = env($key);

        return ($value === false || $value === null || $value === '') ? $default : (int) $value;
    }

    /** Tenant identification mode: 'domain' (Host sub-domain) or 'claim' (default). */
    private static function mode(): string
    {
        return strtolower((string) (env('TENANCY_MODE') ?: 'claim'));
    }

    /**
     * Base domains a tenant sub-domain hangs off, from TENANCY_BASE_DOMAINS
     * (comma-separated). Only consulted in domain mode.
     *
     * @return string[]
     */
    private static function baseDomains(): array
    {
        $raw = (string) (env('TENANCY_BASE_DOMAINS') ?: '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Sub-domain labels that are NEVER tenants (map to central) in domain mode,
     * from TENANCY_RESERVED_SUBDOMAINS. Sensible infra/marketing defaults apply
     * when unset so hosts like www/api do not 404 as unknown tenants.
     *
     * @return string[]
     */
    private static function reservedSubdomains(): array
    {
        $raw = env('TENANCY_RESERVED_SUBDOMAINS');
        if ($raw === false || $raw === null || trim((string) $raw) === '') {
            return ['www', 'api', 'admin', 'app', 'cdn', 'static', 'assets', 'mail'];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }
}
