<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;

/**
 * ServerContext — everything a {@see Contracts\ServerContract} is handed.
 *
 * TWO CONTAINERS, AND THE DIFFERENCE IS THE WHOLE POINT
 * ----------------------------------------------------
 * {@see self::container()} is built ONCE, before the loop starts, and lives as
 * long as the process. It is for START-UP: resolving the authorizer, the bus,
 * the repositories the loop will use. It is NOT a request container, and using
 * it as one is the mistake this class exists to make hard — a module that
 * rebinds a port per scope (tenancy rebinding DatabasePort) would be rebound
 * once, at boot, and every message for the next three weeks would be served
 * against whichever connection happened to be current then.
 *
 * {@see self::newScope()} is the other half: a FRESH ModuleContainer, built
 * from the same dependency graph, for one unit of work. A server that handles
 * messages or connections on behalf of different tenants, users or requests
 * calls it per unit and lets the result be garbage-collected, exactly as
 * LoadStage does per request and WorkerLoop does per job.
 *
 * EVERYTHING YOU DO WITH THIS IS A METHOD
 * ---------------------------------------
 * Only {@see self::$name} and {@see self::$limits} are properties, because they
 * are plain facts about this run. The container, the logger and the options are
 * reached through methods. That uniformity is not taste: the container started
 * out as a public property beside `newScope()`, and the first server written
 * against it called `$context->container()` — on the author's own API, within
 * minutes. A surface where half the members need parentheses teaches nobody.
 *
 * WHY CONFIGURATION IS NOT IN ARGV
 * --------------------------------
 * {@see self::$options} carries what a caller passed programmatically, and
 * `server:run` fills only the two things the KERNEL owns (the memory ceiling
 * and the lifetime). A server's own settings — host, port, workers — come from
 * the environment, because a variable declared in the module's `config[]` is
 * validated at boot and a flag is validated by nobody. A server that reads its
 * listen port from argv starts happily on the wrong one.
 */
final class ServerContext
{
    /**
     * @param array<string, string|bool> $options    values a caller passed to
     *                                               ServerPipeline::run()
     * @param (\Closure(): ?ModuleContainer)|null $scopeFactory builds a fresh
     *                                               per-unit container; null
     *                                               when there is no graph to
     *                                               build one from
     */
    public function __construct(
        public readonly string $name,
        public readonly ServerLimits $limits,
        private readonly array $options = [],
        private readonly ?ModuleContainer $container = null,
        private readonly ?LoggerPort $logger = null,
        private readonly ?\Closure $scopeFactory = null,
    ) {
    }

    /**
     * Whether the loop should return now — signal, memory ceiling or lifetime.
     *
     * Poll this wherever the loop is between units of work. It is cheap and it
     * stays true once it is true.
     */
    public function shouldStop(): bool
    {
        return $this->limits->shouldStop();
    }

    /** Why the loop is being asked to stop, or '' while it should keep going. */
    public function stopReason(): string
    {
        return $this->limits->reason();
    }

    /**
     * The container built ONCE for this process, for start-up resolution.
     *
     * Null when the server's module declares no domain and the project declares
     * no essentials — the server then resolves from the CoreContainer, which is
     * all a server with no module dependencies needs.
     *
     * Do NOT use it per message. {@see self::newScope()} is that.
     */
    public function container(): ?ModuleContainer
    {
        return $this->container;
    }

    /** The bound app logger, or null when the project wired none. */
    public function logger(): ?LoggerPort
    {
        return $this->logger;
    }

    /** Every caller-supplied option. @return array<string, string|bool> */
    public function options(): array
    {
        return $this->options;
    }

    /** A caller-supplied option, as a string. */
    public function option(string $key, string $default = ''): string
    {
        $value = $this->options[$key] ?? null;

        if (\is_bool($value)) {
            // A bare flag is "present", which as a string is the flag's own
            // name, not "1" — returning "1" for `--host` would be a listen
            // address of 1.
            return $value ? $key : $default;
        }

        return $value ?? $default;
    }

    /** A caller-supplied option, as a flag. */
    public function flag(string $key, bool $default = false): bool
    {
        $value = $this->options[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return \is_bool($value)
            ? $value
            : (bool) filter_var($value, \FILTER_VALIDATE_BOOL);
    }

    /**
     * A FRESH module-scoped container for ONE unit of work.
     *
     * Returns null when the server's module declares no domain and the project
     * declares no essentials — there is nothing to build, and the server should
     * use {@see self::container()} or resolve from its own constructor instead.
     *
     * Call it per message/connection and let the result go. Holding one for the
     * life of the process is what {@see self::container()} already is.
     */
    public function newScope(): ?ModuleContainer
    {
        if ($this->scopeFactory === null) {
            return null;
        }

        return ($this->scopeFactory)();
    }

    /**
     * Log at info level when the project bound a logger; otherwise do nothing.
     *
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger?->info($message, ['server' => $this->name, ...$context]);
    }
}
