<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server;

/**
 * ServerLimits — when this process should end, and why.
 *
 * WHY THE KERNEL HOLDS THE POLICY AND THE SERVER HOLDS THE POLL
 * ------------------------------------------------------------
 * A server's loop blocks inside the server. Nothing outside it gets a turn, so
 * nothing outside it can enforce a ceiling — {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerLoop}
 * can check memory between jobs precisely because it owns the loop, and here it
 * does not. The split is therefore unavoidable: this object decides WHETHER to
 * stop, the server decides WHEN to ask.
 *
 * That is also why {@see self::shouldStop()} is cheap and idempotent. It is
 * expected to be called on every tick of a loop that may run thousands of times
 * a second, and to keep returning true once it has.
 *
 * WHY A LONG-RUNNING PHP PROCESS IS EXPECTED TO EXIT
 * -------------------------------------------------
 * Not because of a leak — because of fragmentation and of every extension's
 * arena that no amount of correct code gives back. A supervised server is
 * EXPECTED to be restarted; the only question is whether it exits between units
 * of work, or is OOM-killed in the middle of one. A memory ceiling makes that a
 * decision instead of an accident, which is why `0` (unlimited) is the default
 * only for processes under a supervisor that already caps them.
 *
 * {@see self::reason()} exists for the same reason: "the server exited" is not
 * actionable, and "the server exited because it reached 512 MB after 4 hours"
 * is. A supervisor's log otherwise cannot tell a clean recycle from a crash.
 */
final class ServerLimits
{
    private bool $stopped = false;

    /** Why the process is stopping: 'signal' | 'memory' | 'runtime' | 'asked' | ''. */
    private string $reason = '';

    private readonly float $startedAt;

    public function __construct(
        /**
         * Resident size, in MEGABYTES, at which the server should exit so a
         * supervisor can start a fresh one. 0 = no ceiling.
         */
        public readonly int $memoryLimitMb = 0,
        /**
         * Wall-clock seconds after which the server should exit, for the same
         * reason. 0 = no ceiling.
         */
        public readonly int $maxSeconds = 0,
        ?float $startedAt = null,
    ) {
        $this->startedAt = $startedAt ?? microtime(true);
    }

    /**
     * Build from a compiled `servers[].restart` block.
     *
     * @param array<string, mixed>|null $restart
     */
    public static function fromManifest(?array $restart): self
    {
        $restart ??= [];

        return new self(
            memoryLimitMb: max(0, (int) ($restart['memoryMb'] ?? 0)),
            maxSeconds:    max(0, (int) ($restart['seconds'] ?? 0)),
        );
    }

    /**
     * Ask the server to stop at its next poll.
     *
     * SIGNAL-HANDLER SAFE by construction: it assigns two properties and
     * returns. The pipeline calls it from the SIGTERM handler, so anything more
     * than that would run between two arbitrary opcodes of the running loop.
     */
    public function stop(string $reason = 'asked'): void
    {
        if ($this->stopped) {
            // Keep the FIRST reason. A SIGTERM that arrives while the process is
            // already winding down for memory did not cause the shutdown, and
            // reporting it as the cause sends the operator after the wrong
            // thing.
            return;
        }

        $this->stopped = true;
        $this->reason  = $reason;
    }

    /**
     * Whether the loop should return now.
     *
     * Dispatches pending signals first. Without ext-pcntl's async delivery a
     * handler runs ONLY where the script asks for it, so a server that polls
     * this and nothing else would never see a SIGTERM at all — the dispatch
     * here is what makes the poll sufficient on every build.
     */
    public function shouldStop(): bool
    {
        // Dispatch BEFORE the check, not after it. The handler is what calls
        // stop(), so a dispatch that ran afterwards would report the state from
        // before the signal and the loop would take one more turn — which on an
        // idle bus is one more hour.
        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        if ($this->stopped) {
            return true;
        }

        // memory_get_usage(true) is the REAL allocation from the OS, not PHP's
        // accounting of what is currently in use. That is the number a
        // supervisor's memory limit and an OOM killer both look at, so it is the
        // number to compare against.
        if ($this->memoryLimitMb > 0 && memory_get_usage(true) >= $this->memoryLimitMb * 1048576) {
            $this->stop('memory');

            return true;
        }

        if ($this->maxSeconds > 0 && $this->elapsed() >= (float) $this->maxSeconds) {
            $this->stop('runtime');

            return true;
        }

        return false;
    }

    /** Why the process is stopping, or '' while it is still running. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** Seconds since this process started serving. */
    public function elapsed(): float
    {
        return microtime(true) - $this->startedAt;
    }

    /** One line for a log or a shutdown notice. Never empty. */
    public function describe(): string
    {
        return match ($this->reason) {
            'signal'  => \sprintf('stopped by a shutdown signal after %.0fs', $this->elapsed()),
            'memory'  => \sprintf(
                'reached its %d MB ceiling after %.0fs and exited so a fresh process can take over',
                $this->memoryLimitMb,
                $this->elapsed(),
            ),
            'runtime' => \sprintf(
                'reached its %ds lifetime and exited so a fresh process can take over',
                $this->maxSeconds,
            ),
            'asked'   => \sprintf('was asked to stop after %.0fs', $this->elapsed()),
            // NOT "returned on its own" — that is a claim, and for a forking
            // server it is a false one. Swoole handles SIGTERM natively, below
            // PHP's handler table, so the kernel's trap never fires and nothing
            // records `signal`; and a ceiling reached inside a worker is
            // recorded in that fork, not here. Both arrive as no reason at all,
            // and neither means the loop chose to return.
            default   => \sprintf('returned after %.0fs with no reason recorded', $this->elapsed()),
        };
    }

    /**
     * Whether this process ever learned WHY it is stopping.
     *
     * False after a forking server returns: see {@see self::describe()}. A
     * caller that reports the shutdown uses this to add the caveat rather than
     * inventing a cause.
     */
    public function hasReason(): bool
    {
        return $this->reason !== '';
    }
}
