<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\ServerContext;

/**
 * ServerContract — a long-running process the kernel knows how to start.
 *
 * WHY THIS IS A FOURTH SURFACE AND NOT A COMMAND
 * ----------------------------------------------
 * HTTP, CLI and worker all share one shape: something arrives, it is handled,
 * the process is torn down. PHP-FPM and OpenSwoole differ in how long the
 * process lives, but both still answer requests, which is why they are one
 * surface ({@see \AlfacodeTeam\PhpServicePlatform\Kernel\RuntimeMode::Http}).
 *
 * A server is the shape that does not fit. It owns its own loop, holds state
 * for weeks, and nothing hands it a unit of work — it goes and gets one. That
 * inverts three habits the other surfaces rely on:
 *
 *   - **it must be asked to stop.** Every other surface ends by finishing. A
 *     server ends only when something tells it to, so SIGTERM is part of its
 *     contract rather than an interruption of it;
 *   - **nothing may leak.** A per-request allocation that is never freed is
 *     invisible under FPM and fatal here;
 *   - **a container cannot be request-scoped for a process lifetime.** The one
 *     in {@see ServerContext::$container} is for START-UP — resolving the
 *     services the loop will use. Per-message state belongs in a fresh scope
 *     from {@see ServerContext::newScope()}, not in that one.
 *
 * Writing it as a CLI command instead works right up to the first SIGTERM: a
 * command has nowhere to put "stop gracefully", so every plugin that needed a
 * server grew its own signal trap, its own memory ceiling and its own restart
 * story — three times, three ways. This contract is where that goes once.
 *
 * WHAT A SERVER IS NOT ASKED TO DO
 * --------------------------------
 * It is not asked to daemonise, write a pidfile, or restart itself. It runs in
 * the FOREGROUND and exits; a supervisor restarts it. That is deliberate — a
 * process that forks away from its supervisor cannot be supervised, and a
 * process that restarts itself in place keeps whatever leaked. {@see
 * ServerContext::shouldStop()} returning true for memory or elapsed time is an
 * instruction to exit cleanly so something else can start a fresh one.
 */
interface ServerContract
{
    /**
     * Run the loop. BLOCKS until the server is finished.
     *
     * The implementation is expected to poll {@see ServerContext::shouldStop()}
     * at every point where it is between units of work — after a message, after
     * an accept, on each tick of its own select/epoll loop. A loop that never
     * polls it can still be stopped by {@see self::stop()} if its transport can
     * be woken from a signal handler, and otherwise only by SIGKILL.
     *
     * @return int the process exit code. 0 means "finished, as asked"; anything
     *         else tells the supervisor the server gave up. A clean stop for
     *         memory or elapsed time is still 0 — it did what it was told.
     */
    public function serve(ServerContext $context): int;

    /**
     * Ask {@see self::serve()} to return.
     *
     * CALLED FROM A SIGNAL HANDLER, so it must do nothing but set a flag or
     * wake a transport that is safe to wake that way (a `PubSubPort::stop()`,
     * a pipe write, a Swoole `shutdown()`). Doing real work here — closing
     * connections, flushing to a database — runs it between two opcodes of
     * whatever the loop was doing, which is how a graceful shutdown becomes
     * the crash it was meant to prevent.
     *
     * It may be called more than once, and may be called before serve() has
     * started or after it has returned. All three must be harmless.
     */
    public function stop(): void;
}
