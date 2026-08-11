<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;


/**
 * Single entry-point that constructs every let-migrate CLI command with a
 * shared, pre-loaded configuration.
 *
 * Usage in your application's bootstrap script:
 *
 *   $config = require __DIR__ . '/let-migrate.config.php';
 *
 *   $factory = CliCommandFactory::fromConfig($config);
 *
 *   (new CLIApplication('MyApp', '1.0.0'))
 *       ->add(...$factory->all())
 *       ->discoverCommands()
 *       ->run();
 *
 * Every command returned from all() (or any grouped method) has the config
 * pre-injected — users do NOT need to pass --config=<path> on the CLI.
 * Passing --config at runtime still works as an override (useful for
 * switching between dev/staging/prod configs without rebuilding the binary).
 *
 * Adding a new command: register it in the appropriate grouped method
 * below and it will appear in all() automatically.
 */
final class CliCommandFactory
{
    /**
     * @param array<string, mixed>|null $config Pre-loaded, NOT yet normalised
     *        (the commands' base class normalises on first access). Pass null
     *        to leave commands unconfigured — they will then require
     *        --config=<path> at invocation time.
     */
    private function __construct(
        private readonly ?array $config = null,
    ) {}
 
    /**
     * Build a factory from a raw config array (or null to defer config
     * loading entirely to the --config CLI flag).
     *
     * @param array<string, mixed>|null $config
     */
    public static function fromConfig(?array $config = null): self
    {
        return new self($config);
    }
 
    /**
     * Every command let-migrate exposes, ordered roughly by frequency of use.
     *
     * @return list<LetMigrateCommand>
     */
    public function all(): array
    {
        return [
            ...$this->migrate(),
            ...$this->generate(),
            // ...$this->tenant(),
            ...$this->seed(),
            ...$this->make(),
            ...$this->maintenance(),
        ];
    }
 
    /**
     * Core migrate:* commands.
     *
     * @return list<LetMigrateCommand>
     */
    public function migrate(): array
    {
        $c = $this->config;
        return [
            new MigrateRunCommand($c),
            new MigrateRollbackCommand($c),
            new MigrateResetCommand($c),
            new MigrateRefreshCommand($c),
            new MigrateFreshCommand($c),
            new MigrateStatusCommand($c),
            new MigratePendingCommand($c),
            new MigrateInstallCommand($c),
            new MigrateToCommand($c),
            new MigrateRedoCommand($c),
        ];
    }
 
    /**
     * Schema-introspection commands (generate / diff / check).
     *
     * @return list<LetMigrateCommand>
     */
    public function generate(): array
    {
        $c = $this->config;
        return [
            new MigrateGenerateCommand($c),
            new MigrateDiffCommand($c),
            new MigrateCheckCommand($c),
        ];
    }
 
    /**
     * Multi-tenant commands. Only useful when the config contains a
     * 'tenants' section with a resolver — otherwise these commands will
     * fail at runtime with a clear error from TenantCommand::tenantRunner().
     *
     * @return list<LetMigrateCommand>
     */
    // public function tenant(): array
    // {
    //     $c = $this->config;
    //     return [
    //         new TenantMigrateRunCommand($c),
    //         new TenantMigrateRollbackCommand($c),
    //         new TenantMigrateResetCommand($c),
    //         new TenantMigrateRefreshCommand($c),
    //         new TenantMigrateStatusCommand($c),
    //     ];
    // }
 
    /**
     * Seeder commands.
     *
     * @return list<LetMigrateCommand>
     */
    public function seed(): array
    {
        $c = $this->config;
        return [
            new DbSeedCommand($c),
        ];
    }
 
    /**
     * Stub-generator (make:*) commands.
     *
     * @return list<LetMigrateCommand>
     */
    public function make(): array
    {
        $c = $this->config;
        return [
            new \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MakeMigrationCommand($c),
            new \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MakeSeederCommand($c),
            new \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\MakeFactoryCommand($c),
        ];
    }
 
    /**
     * Quality / safety / breakpoint commands.
     *
     * @return list<LetMigrateCommand>
     */
    public function maintenance(): array
    {
        $c = $this->config;
        return [
            new MigrateLintCommand($c),
            new MigrateSquashCommand($c),
            new MigrateBreakpointCommand($c),
        ];
    }
 
    /**
     * Expose the underlying config for callers that want to introspect it
     * (e.g. building extra commands outside this factory but with the same
     * configuration). Null when the factory was built without config.
     *
     * @return array<string, mixed>|null
     */
    public function config(): ?array
    {
        return $this->config;
    }
}