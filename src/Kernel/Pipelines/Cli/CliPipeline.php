<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\{ErrorPipeline, ErrorContext};
use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\CLIApplication;

/**
 * CliPipeline — kernel CLI engine, powered by php-io-cli.
 *
 * Wraps an {@see CLIApplication} (the php-io-cli runtime). Modules register
 * their commands ONCE during boot():
 *
 *   $cli->command(GenerateInvoiceCommand::class); // extends AbstractCommand
 *
 * The kernel runs them:
 *
 *   exit($kernel->cli()->run($argv));
 *
 * Commands are instantiated through the CoreContainer when bound there
 * (allowing constructor injection of ports/services), otherwise built directly.
 * Uncaught failures are routed through the same ErrorPipeline as HTTP, so
 * observability is uniform across entry points.
 */
final class CliPipeline
{
    private CLIApplication $app;

    /** @var list<class-string<AbstractCommand>> registered command classes */
    private array $commandClasses = [];

    /** @var list<AbstractCommand> pre-constructed commands registered as-is */
    private array $eagerCommands = [];

    /**
     * @var list<\Closure(CliPipeline):void> deferred registration callbacks.
     * Run only when the CLI materializes, so a module that builds heavy
     * (e.g. DB-backed) command instances does that work lazily — never on the
     * HTTP/worker path.
     */
    private array $deferred = [];

    /**
     * Whether registered commands have been instantiated and added to the
     * underlying CLIApplication. Deferred until the CLI is actually used so
     * that HTTP/worker builds — which still run every module's boot() and
     * therefore still call command() — pay zero command-construction cost.
     */
    private bool $materialized = false;

    public function __construct(
        private readonly CoreContainer $core,
        private readonly ErrorPipeline $errorPipeline,
        string $name = 'PhpServicePlatform',
        string $version = '1.0.0',
    ) {
        $this->app = new CLIApplication($name, $version);
    }

    /**
     * Register a module command. Accepts either:
     *   • a class-string extending {@see AbstractCommand} — instantiated through
     *     the CoreContainer (autowiring ports/app-lifetime services), or
     *   • an already-constructed AbstractCommand — used as-is, which lets a
     *     module build a command with its OWN request/command-scoped services
     *     (resolved from its ModuleContainer) without leaking them into the
     *     CoreContainer.
     *
     * @param class-string<AbstractCommand>|AbstractCommand $command
     */
    public function command(string|AbstractCommand $command): void
    {
        if ($command instanceof AbstractCommand) {
            $this->eagerCommands[] = $command;
            return;
        }

        $this->commandClasses[] = $command;
    }

    /**
     * Whether a command with this name has already been queued (as an eager
     * instance) or added to the underlying application.
     *
     * Lets a generic command provider (e.g. the migration factory) yield to a
     * plugin that already claimed the name, so a plugin-owned command is never
     * silently overwritten by a later last-wins registration. Only eager
     * instances expose a name here; class-string registrations do not.
     */
    public function hasQueued(string $name): bool
    {
        if ($this->app->has($name)) {
            return true;
        }
        foreach ($this->eagerCommands as $command) {
            if ($command->getName() === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Defer command registration until the CLI is actually used.
     *
     * The callback receives this pipeline and registers its commands via
     * command(). Use this when building the command instances is expensive
     * (scoped containers, DB-backed services, factories) so that work happens
     * ONLY on the CLI path — never during an HTTP/worker build, even though
     * every module's boot() still runs there.
     *
     * @param \Closure(CliPipeline):void $register
     */
    public function defer(\Closure $register): void
    {
        if ($this->materialized) {
            // CLI already started — register immediately so late callers still work.
            $register($this);
            return;
        }
        $this->deferred[] = $register;
    }

    /**
     * Instantiate every registered command and add it to the CLIApplication.
     *
     * Idempotent and lazy: nothing is constructed until the CLI is actually
     * invoked (run()) or the underlying application is introspected
     * (application()). On HTTP/worker entry points this is never called, so no
     * command — and none of its (often DB-backed) dependency graph — is built.
     */
    private function materialize(): void
    {
        if ($this->materialized) {
            return;
        }
        $this->materialized = true;

        // Run deferred callbacks first — they populate eagerCommands /
        // commandClasses with instances that were too costly to build at boot.
        foreach ($this->deferred as $register) {
            $register($this);
        }
        $this->deferred = [];

        foreach ($this->eagerCommands as $command) {
            $this->app->add($command);
        }
        foreach ($this->commandClasses as $commandClass) {
            $command = $this->instantiate($commandClass);
            if ($command !== null) {
                $this->app->add($command);
            }
        }
    }

    /** @return list<class-string<AbstractCommand>> */
    public function commands(): array
    {
        return [
            ...array_map(static fn (AbstractCommand $c): string => $c::class, $this->eagerCommands),
            ...$this->commandClasses,
        ];
    }

    /** Access the underlying php-io-cli application (e.g. to discoverCommands()). */
    public function application(): CLIApplication
    {
        $this->materialize();
        return $this->app;
    }

    /**
     * The app-lifetime CoreContainer (ports + kernel services).
     *
     * Exposed so a module's boot() can build a scoped ModuleContainer to
     * resolve commands whose dependencies are module-scoped (mirroring how the
     * OnDemandLoader wires modules at request time). Read-only by intent — do
     * not mutate the container through this handle.
     */
    public function container(): CoreContainer
    {
        return $this->core;
    }

    /**
     * Parse argv and run the matching command.
     *
     * @param list<string> $argv full argv including the script name at [0].
     */
    public function run(array $argv): int
    {
        // Build commands now — deferred from registration so non-CLI entry
        // points never pay for them.
        $this->materialize();

        // We own error handling so failures reach the ErrorPipeline.
        $this->app->catchExceptions(false);

        try {
            return $this->app->run(array_slice($argv, 1));
        } catch (\Throwable $e) {
            $this->errorPipeline->consume(
                ErrorContext::fromThrowable($e, requestPath: 'cli', requestMethod: 'CLI')
            );
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return AbstractCommand::FAILURE;
        }
    }

    /**
     * @param class-string<AbstractCommand> $commandClass
     *
     * Returns null when the command's constructor dependencies cannot be
     * resolved — e.g. a plugin command whose owning module is disabled / not in
     * this request's graph. Such a command is simply skipped (not registered)
     * so an unrelated command (migrate:reset, plugins disable, …) still runs.
     * A single un-constructable command must NEVER crash the whole CLI.
     */
    private function instantiate(string $commandClass): ?AbstractCommand
    {
        // Prefer the container so commands with constructor dependencies are
        // autowired (bind-it reflects on concrete classes even when unbound).
        try {
            $command = $this->core->make($commandClass);
        } catch (\Throwable) {
            // The container could not build it. Only fall back to direct
            // construction when the constructor has NO required parameters —
            // otherwise `new $commandClass()` throws ArgumentCountError.
            if ($this->hasRequiredConstructorArgs($commandClass)) {
                return null;
            }
            $command = new $commandClass();
        }

        if (!$command instanceof AbstractCommand) {
            throw new \InvalidArgumentException(
                "CLI command [{$commandClass}] must extend " . AbstractCommand::class . '.'
            );
        }

        return $command;
    }

    /** @param class-string $commandClass */
    private function hasRequiredConstructorArgs(string $commandClass): bool
    {
        try {
            $ctor = (new \ReflectionClass($commandClass))->getConstructor();
        } catch (\ReflectionException) {
            return false;
        }

        return $ctor !== null && $ctor->getNumberOfRequiredParameters() > 0;
    }
}
