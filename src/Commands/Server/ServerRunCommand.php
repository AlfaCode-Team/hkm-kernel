<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Server;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\ServerLimits;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\ServerPipeline;

/**
 * `server:run <name>` — run a declared server in the FOREGROUND.
 *
 * It blocks for as long as the server runs, which may be weeks, and it dies
 * with its terminal. Both are deliberate: a process that forks away from its
 * supervisor cannot be supervised, and a process that survives Ctrl-C leaves a
 * listening socket bound so the next run fails with "address already in use" —
 * a confusing error whose cause is two commands back.
 *
 * WHY THE SERVER'S OWN SETTINGS ARE NOT FLAGS HERE
 * ------------------------------------------------
 * The only options are the two the KERNEL owns — the memory ceiling and the
 * lifetime, both of which only exist so a supervisor gets a clean exit instead
 * of an OOM kill. Host, port, workers and credentials come from the
 * environment, because an environment variable declared in the module's
 * `config[]` is validated at boot and a flag is validated by nobody. A server
 * that takes its listen port from argv starts happily on the wrong one.
 */
final class ServerRunCommand extends AbstractCommand
{
    public function __construct(private readonly ?ServerPipeline $servers = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'server:run';
        $this->description = 'Run a declared long-running server in the foreground';

        $this->addArgument('name', 'The server to run, as `server:list` names it', required: true);

        $this->addOption(
            'memory',
            'm',
            'Exit once the process exceeds this many MB, so a supervisor starts a fresh one',
            acceptsValue: true,
        );
        $this->addOption(
            'max-seconds',
            '',
            'Exit after this many seconds, for the same reason',
            acceptsValue: true,
        );
    }

    protected function handle(): int
    {
        $name = trim((string) $this->argument('name', ''));

        if ($this->servers === null) {
            $this->error('The server surface is unavailable: no ServerPipeline is bound.');

            return self::FAILURE;
        }

        $entry = $this->servers->describe($name);

        if ($entry === null) {
            $known = array_keys($this->servers->all());

            $this->error("No server named [{$name}] is declared.");
            $this->muted($known === []
                ? 'No installed module declares a servers[] entry. A plugin that needs a long-running '
                  . 'process declares one in its module.json; `server:list` shows them.'
                : 'Declared servers: ' . implode(', ', $known) . '.');

            return self::INVALID;
        }

        // Only override the declaration when a flag was actually given — an
        // absent flag must leave the module's own policy in force rather than
        // silently resetting it to "no ceiling".
        $limits = $this->limitsFromFlags($entry);

        $this->section('server:run ' . $name);
        $this->muted('Class    : ' . (\is_string($entry['class'] ?? null) ? $entry['class'] : '(none)'));

        $module = \is_string($entry['module'] ?? null) ? $entry['module'] : '';
        $this->muted('Declared : ' . ($module !== '' ? $module : 'registered in code'));

        if ($limits !== null) {
            $this->muted('Recycle  : ' . $this->describeLimits($limits));
        }

        $this->muted(
            'This process runs in the FOREGROUND until it is stopped. SIGTERM, SIGINT and SIGQUIT are '
            . 'trapped and turned into a graceful stop, so a supervisor can wind it down without '
            . 'cutting a message in half.',
        );
        $this->newLine();

        try {
            $code = $this->servers->run($name, limits: $limits);
        } catch (\Throwable $e) {
            // The pipeline already routed this through the ErrorPipeline with
            // the server's name attached, so this only PRINTS it. Letting it
            // propagate would have CliPipeline report the same failure a second
            // time, under `requestPath: cli`.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        $ran = $this->servers->limits();
        $this->info('Server [' . $name . '] ' . ($ran?->describe() ?? 'returned.'));

        if ($ran !== null && !$ran->hasReason()) {
            // Measured on Swoole 6.2.5: the kernel's SIGTERM handler is still
            // the one pcntl_signal_get_handler() reports, in the manager and in
            // every worker, and it never runs — Swoole takes the signal
            // natively, below PHP's handler table. The shutdown is still clean
            // and leaves no orphan; what is lost is knowing why, and that is
            // worth saying out loud rather than guessing.
            $this->muted(
                'No reason was recorded in THIS process. That is expected of a server that forks: '
                . 'Swoole handles SIGTERM itself, so the kernel trap and ServerContract::stop() do not '
                . 'run under it, and a ceiling reached inside a worker is recorded in that fork. The '
                . 'shutdown is still graceful — see docs/ai-context/31_SERVERS.md §"Servers that fork".',
            );
        }

        if ($code !== self::SUCCESS) {
            $this->warning(
                'It exited with code ' . $code . ', so it gave up rather than being asked to stop.',
            );
        }

        return $code;
    }

    /**
     * Limits from the flags, or null to keep the declared policy.
     *
     * @param array<string, mixed> $entry
     */
    private function limitsFromFlags(array $entry): ?ServerLimits
    {
        $memory  = $this->option('memory');
        $seconds = $this->option('max-seconds');

        if ($memory === null && $seconds === null) {
            return null;
        }

        $restart = \is_array($entry['restart'] ?? null) ? $entry['restart'] : [];

        return new ServerLimits(
            memoryLimitMb: max(0, $memory !== null ? (int) $memory : (int) ($restart['memoryMb'] ?? 0)),
            maxSeconds:    max(0, $seconds !== null ? (int) $seconds : (int) ($restart['seconds'] ?? 0)),
        );
    }

    private function describeLimits(ServerLimits $limits): string
    {
        return match (true) {
            $limits->memoryLimitMb > 0 && $limits->maxSeconds > 0
                => "at {$limits->memoryLimitMb} MB or after {$limits->maxSeconds}s",
            $limits->memoryLimitMb > 0 => "at {$limits->memoryLimitMb} MB",
            $limits->maxSeconds > 0    => "after {$limits->maxSeconds}s",
            default                    => 'never — it runs until stopped',
        };
    }
}
