<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\{CoreContainer, ModuleContainer};
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\{ErrorContext, ErrorPipeline};
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Loading\{DependencyGraphCalculator, OnDemandLoader};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\Contracts\ServerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;

/**
 * ServerPipeline — the fourth surface: long-running processes.
 *
 * The other three are request-shaped. HTTP (PHP-FPM or OpenSwoole) answers a
 * request, the CLI runs a command, the worker takes a job — each ends by
 * finishing. A server does not: it owns its own loop and stops only when told.
 * This pipeline is the part of that which is the same every time —
 *
 *   - **resolving the server**, from `servers[]` in a module.json, so the
 *     process a deployment needs is DECLARED rather than remembered;
 *   - **a scoped container**, built from the server's module domain plus the
 *     project's essentials, so a server has the same world as a request;
 *   - **trapping SIGTERM/SIGINT/SIGQUIT** and turning them into one `stop()`,
 *     because a process supervisor's only way to say "wind down" is a signal
 *     and a `kill -9` three seconds later;
 *   - **a memory ceiling and a lifetime**, so a recycle is a decision rather
 *     than an OOM kill in the middle of a message;
 *   - **the error pipeline**, so a server that dies at 3am leaves the same
 *     record as a failed request.
 *
 * Every one of those was re-implemented, differently, by each plugin that
 * needed a server. Three of them got the signal trap subtly wrong.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not fork, write a pidfile, or restart anything. A server runs in the
 * FOREGROUND and exits; the supervisor restarts it. A process that daemonises
 * away from its supervisor cannot be supervised, and one that restarts itself
 * in place keeps whatever it leaked.
 *
 * It also does not know what any server DOES. Which class, which module, which
 * ceiling are all declared by the plugin, in the plugin's own manifest,
 * versioned with the plugin — the same seam as `daemon[]`, which declares the
 * EXTERNAL processes a plugin needs. A second server is a manifest block in a
 * second plugin and no kernel change.
 */
final class ServerPipeline
{
    /**
     * Servers registered in code rather than declared in a manifest.
     *
     * The escape hatch for a project's own server and for tests. Manifest
     * entries are the documented path; these take precedence so a test can
     * stand in for one.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $registered = [];

    /**
     * The compiled `server-manifest.php`, read on FIRST USE.
     *
     * Deferred for the same reason every other manifest-backed pipeline defers
     * its read: module boot() runs on every surface, so an HTTP process that
     * never serves must not pay disk I/O for a manifest it will never look at.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $manifest = null;

    private ?DependencyGraphCalculator $calculator = null;
    private ?OnDemandLoader $loader = null;

    /** @var list<string> solves domains of $essentialModules, mapped once */
    private array $essentialDomains = [];

    /** The server currently inside serve(), so a signal can reach its stop(). */
    private ?ServerContract $running = null;

    /** The limits of the current run, so a signal can set the stop flag. */
    private ?ServerLimits $limits = null;

    /** @var array<int, callable|int> handlers displaced by trapSignals() */
    private array $previousHandlers = [];

    public function __construct(
        private readonly CoreContainer $core,
        private readonly ErrorPipeline $errorPipeline,
        /**
         * Provider classes marked ESSENTIAL — registered into the server's
         * container regardless of its own dependency graph, exactly as
         * HttpPipeline does per request and WorkerLoop does per job.
         *
         * A surface that quietly disagreed about what "essential" means is how
         * a tenancy module ends up absent from one process and present in the
         * other two, which does not fail — it just serves the wrong tenant.
         *
         * @var list<class-string<\AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract>>
         */
        private readonly array $essentialModules = [],
        /**
         * Called when a run starts, so the kernel can record that this process
         * has become a server.
         *
         * `server:run` arrives through the CLI, which materializes the kernel
         * for RuntimeMode::Cli — correct at the moment it is typed and wrong
         * for the next three weeks. Without this, `mode()` would report `cli`
         * for a process whose whole job is to be a server.
         */
        private readonly ?\Closure $enterSurface = null,
    ) {
    }

    /**
     * Register a server in code.
     *
     * For a project's own server, and for tests. A plugin declares its servers
     * in module.json instead — that is what makes them listable without
     * loading a single server class.
     *
     * @param class-string<ServerContract> $class
     * @param array<string, mixed>         $restart `{memoryMb, seconds}`
     */
    public function server(string $name, string $class, array $restart = [], string $why = ''): void
    {
        $this->registered[$name] = [
            'class'    => $class,
            'module'   => '',
            'solves'   => '',
            'requires' => [],
            'why'      => $why,
            'restart'  => $restart,
        ];
    }

    /**
     * Every known server, declared or registered, keyed by name.
     *
     * Reads the manifest but loads NO server class — so `server:list` on a
     * deployment with a Swoole server and a bus bridge costs one file read,
     * not the autoloading of both servers and everything they inject.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $this->manifest ??= ManifestReader::readCompiled('server-manifest.php');

        // Registered last so a programmatic registration wins — that is what
        // makes it usable as a test double for a declared server.
        return [...$this->manifest, ...$this->registered];
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** The declaration for one server, or null. @return array<string, mixed>|null */
    public function describe(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * The limits of the current — or most recent — run.
     *
     * Deliberately NOT cleared when a run ends: {@see ServerLimits::reason()}
     * is how a caller learns WHY the server returned, and that is only
     * available after it has. "The server exited" is not actionable; "it
     * reached its 512 MB ceiling after four hours" is.
     */
    public function limits(): ?ServerLimits
    {
        return $this->limits;
    }

    /**
     * Ask the running server to stop.
     *
     * Safe to call from a signal handler: it sets the stop flag and calls the
     * server's own {@see ServerContract::stop()}, which is contractually
     * allowed to do nothing but the same.
     */
    public function stop(string $reason = 'asked'): void
    {
        $this->limits?->stop($reason);
        $this->running?->stop();
    }

    /**
     * Resolve, start and supervise one server. BLOCKS until it returns.
     *
     * @param array<string, string|bool> $options passed through to the context
     * @param ServerLimits|null          $limits  overrides the declared
     *                                            `restart` block — this is how
     *                                            `server:run --memory` works
     * @return int the process exit code
     *
     * @throws KernelException when the name is unknown, the class is missing,
     *         or the class is not a ServerContract. All three are deployment
     *         mistakes that must stop the process rather than produce a server
     *         that is up and does nothing.
     */
    public function run(string $name, array $options = [], ?ServerLimits $limits = null): int
    {
        // Reported HERE rather than left to the caller, because a server is
        // started by a supervisor and the only thing watching its output may be
        // nothing at all. That covers failing to START as well as dying: a
        // realtime server that never came up delivers exactly as much as one
        // that crashed, and "the class in my manifest is misspelled" is the
        // more likely of the two. The CLI command catches the rethrow and
        // prints it WITHOUT reporting it again, so there is one record either
        // way.
        try {
            return $this->start($name, $options, $limits);
        } catch (\Throwable $e) {
            $this->errorPipeline->consume(ErrorContext::fromThrowable(
                $e,
                requestPath:   'server:' . $name,
                requestMethod: 'SERVER',
            ));

            throw $e;
        }
    }

    /**
     * @param array<string, string|bool> $options
     * @throws KernelException
     */
    private function start(string $name, array $options, ?ServerLimits $limits): int
    {
        $entry = $this->describe($name);

        if ($entry === null) {
            throw new KernelException(
                \sprintf(
                    'Unknown server [%s]. %s',
                    $name,
                    $this->all() === []
                        ? 'No installed module declares a servers[] entry, and none was registered in '
                          . 'code. A plugin declares one in its module.json; see `server:list`.'
                        : 'Declared servers: ' . implode(', ', array_keys($this->all())) . '.',
                ),
                layer: 'kernel.server',
                context: ['server' => $name],
            );
        }

        $class  = \is_string($entry['class'] ?? null) ? $entry['class'] : '';
        $module = \is_string($entry['module'] ?? null) ? $entry['module'] : '';

        // Checked HERE and not at boot, on purpose. class_exists() autoloads,
        // so validating every server class at boot would load each one — and
        // everything it imports — on every HTTP request, to guard against a
        // typo that fails loudly the first time anyone starts the server.
        if ($class === '' || !class_exists($class)) {
            throw new KernelException(
                \sprintf(
                    'Server [%s] declares class [%s], which does not exist. %s',
                    $name,
                    $class === '' ? '(empty)' : $class,
                    $module !== ''
                        ? 'It is declared by ' . $module . ' — check the servers[] entry in that '
                          . 'module.json, including the doubled backslashes JSON needs for a namespace.'
                        : 'It was registered in code.',
                ),
                layer: 'kernel.server',
                context: ['server' => $name, 'class' => $class],
            );
        }

        $this->limits = $limits ?? ServerLimits::fromManifest(
            \is_array($entry['restart'] ?? null) ? $entry['restart'] : null,
        );

        $container = $this->resolveContainer($entry);
        $server    = $this->instantiate($class, $container);

        if (!$server instanceof ServerContract) {
            throw new KernelException(
                \sprintf(
                    'Server [%s] is [%s], which does not implement %s. A server needs serve() and '
                    . 'stop(): without stop() there is no way to shut it down except SIGKILL, which is '
                    . 'the failure the contract exists to prevent.',
                    $name,
                    $class,
                    ServerContract::class,
                ),
                layer: 'kernel.server',
                context: ['server' => $name, 'class' => $class],
            );
        }

        $context = new ServerContext(
            name:         $name,
            limits:       $this->limits,
            options:      $options,
            container:    $container,
            logger:       $this->logger(),
            scopeFactory: fn (): ?ModuleContainer => $this->resolveContainer($entry),
        );

        // Announce the surface only once the server is known to be startable —
        // a kernel that recorded itself as a server and then threw on an
        // unknown class would be lying about a process that never ran.
        if ($this->enterSurface !== null) {
            ($this->enterSurface)();
        }

        $this->running = $server;
        $this->trapSignals();

        try {
            return $server->serve($context);
        } finally {
            // Attribute a ceiling that has ALREADY passed but was never polled
            // in THIS process. A server that forks — Swoole, and anything built
            // like it — takes that decision in a child, and ServerLimits is
            // process-local state that does not come back across the fork. Wall
            // clock IS shared, so a `seconds` ceiling can be attributed here
            // soundly; a `memoryMb` one cannot, because the child's resident
            // size was never this process's.
            $this->limits->shouldStop();

            $this->running = null;
            $this->restoreSignals();
        }
    }

    /**
     * Build a module-scoped container for the server's domain plus every
     * essential — the same world an HTTP request or a job is given.
     *
     * Returns null only when there is nothing to build: the server's module
     * declares no `solves` domain AND the project declares no essentials. The
     * server then resolves from the CoreContainer, which is all a server with
     * no module dependencies needs.
     *
     * A server always gets a GUEST identity. There is no request and no user —
     * a server acting as somebody must be handed that somebody per unit of
     * work, from whatever authenticated the unit, not from the process.
     *
     * @param array<string, mixed> $entry
     */
    private function resolveContainer(array $entry): ?ModuleContainer
    {
        if ($this->calculator === null) {
            $manifest               = ManifestReader::readCompiled('service-manifest.php', ['services' => []]);
            $this->calculator       = new DependencyGraphCalculator($manifest);
            $this->essentialDomains = DependencyGraphCalculator::domainsFor($manifest, $this->essentialModules);
        }

        $this->loader ??= new OnDemandLoader($this->core, $this->essentialModules);

        $domain   = (string) ($entry['solves'] ?? '');
        $declared = \is_array($entry['requires'] ?? null) ? array_values(array_filter(
            $entry['requires'],
            static fn (mixed $d): bool => \is_string($d) && $d !== '',
        )) : [];

        $targets = array_values(array_unique([
            ...($domain !== '' ? [$domain] : []),
            ...$declared,
            ...$this->essentialDomains,
        ]));

        if ($targets === []) {
            return null;
        }

        try {
            return $this->loader->loadWithIdentity(
                $this->calculator->resolve($targets[0], array_slice($targets, 1)),
                null,
            );
        } catch (\Throwable) {
            // Fall back to the CoreContainer rather than refusing to start. A
            // graph that cannot be resolved is a boot-time problem the boot
            // pipeline already reports; refusing here as well would take a
            // running server down for something it cannot fix.
            return null;
        }
    }

    /** Build the server, preferring its module's container so DI applies. */
    private function instantiate(string $class, ?ModuleContainer $container): object
    {
        if ($container !== null) {
            try {
                return $container->make($class);
            } catch (\Throwable) {
                // fall through
            }
        }

        try {
            return $this->core->make($class);
        } catch (\Throwable) {
            return new $class();
        }
    }

    /** The bound app logger, or null when the project wired none. */
    private function logger(): ?LoggerPort
    {
        try {
            return $this->core->has(LoggerPort::class) && $this->core->make(LoggerPort::class) instanceof LoggerPort
                ? $this->core->make(LoggerPort::class)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Turn a shutdown signal into one stop() call.
     *
     * SIGTERM is how every supervisor — systemd, launchd, Docker, Kubernetes —
     * says "wind down", and it is followed by SIGKILL after a grace period. A
     * server that does not trap it is killed outright, mid-message, with its
     * connections dropped and anything buffered lost. Trapping it turns a
     * graceful shutdown from luck into a guarantee.
     *
     * The displaced handlers are remembered and restored in {@see
     * self::restoreSignals()}, because this pipeline can be run more than once
     * in one process (a test, a supervisor script that serves two things in
     * sequence) and a handler left behind would belong to a closure over a
     * ServerLimits that no longer means anything.
     *
     * ext-pcntl is optional. Without it a server can still be stopped by
     * whatever its transport offers and otherwise by SIGKILL — the same
     * position WorkerLoop takes, rather than refusing to start.
     */
    private function trapSignals(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }

        // Async delivery means a handler runs between opcodes instead of only
        // where the script calls pcntl_signal_dispatch(). That matters here
        // more than in the worker: a server blocked in a socket read reaches no
        // dispatch point of its own, so without this the signal would wait for
        // the next message — which on an idle bus may be hours.
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $stop = function (): void {
            $this->stop('signal');
        };

        foreach ([\SIGTERM, \SIGINT, \SIGQUIT] as $signal) {
            // Measured on this build: SIG_DFL comes back as int(0), SIG_IGN as
            // int(1), and an installed handler as the callable itself — which
            // is exactly what pcntl_signal() accepts back, so the round trip
            // needs no translation.
            $this->previousHandlers[$signal] = \function_exists('pcntl_signal_get_handler')
                ? pcntl_signal_get_handler($signal)
                : \SIG_DFL;

            pcntl_signal($signal, $stop);
        }
    }

    private function restoreSignals(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }

        foreach ($this->previousHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }

        $this->previousHandlers = [];
    }
}
