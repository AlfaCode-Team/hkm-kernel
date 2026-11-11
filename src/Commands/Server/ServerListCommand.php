<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Server;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\ServerPipeline;

/**
 * `server:list` — the long-running processes this deployment needs.
 *
 * The question this answers is the one nothing else does: a deployment missing
 * a server has no failing request, no lost job and no log line — the realtime
 * clients simply receive nothing. So "what else has to be running" has to be
 * answerable from the deployment itself, not from each plugin's README.
 *
 * It reads the compiled manifest and loads NO server class, which is what keeps
 * it usable as a check on a machine where a server cannot start at all.
 */
final class ServerListCommand extends AbstractCommand
{
    public function __construct(private readonly ?ServerPipeline $servers = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'server:list';
        $this->description = 'List the long-running servers this deployment declares';
    }

    protected function handle(): int
    {
        $this->section('Servers');

        if ($this->servers === null) {
            $this->error('The server surface is unavailable: no ServerPipeline is bound.');

            return self::FAILURE;
        }

        $servers = $this->servers->all();

        if ($servers === []) {
            $this->muted(
                'No module declares a servers[] entry, so this deployment needs no long-running PHP '
                . 'process. Plugins that hold connections (a realtime server) or consume a bus declare '
                . 'one in their module.json.',
            );

            return self::SUCCESS;
        }

        foreach ($servers as $name => $entry) {
            $this->info($name);

            $class = \is_string($entry['class'] ?? null) ? $entry['class'] : '(none)';
            $this->muted('  class   : ' . $class);

            $module = \is_string($entry['module'] ?? null) ? $entry['module'] : '';
            $this->muted('  declared: ' . ($module !== '' ? $module : 'registered in code'));

            $solves = \is_string($entry['solves'] ?? null) ? $entry['solves'] : '';

            if ($solves !== '') {
                $requires = \is_array($entry['requires'] ?? null) ? $entry['requires'] : [];
                $this->muted(
                    '  modules : ' . implode(', ', [$solves, ...array_map('strval', $requires)]),
                );
            }

            $why = \is_string($entry['why'] ?? null) ? $entry['why'] : '';

            if ($why !== '') {
                $this->muted('  why     : ' . $why);
            }

            $restart = \is_array($entry['restart'] ?? null) ? $entry['restart'] : [];
            $memory  = (int) ($restart['memoryMb'] ?? 0);
            $seconds = (int) ($restart['seconds'] ?? 0);

            $this->muted('  recycle : ' . match (true) {
                $memory > 0 && $seconds > 0 => "at {$memory} MB or after {$seconds}s",
                $memory > 0                 => "at {$memory} MB",
                $seconds > 0                => "after {$seconds}s",
                default                     => 'never — it runs until stopped',
            });

            $this->muted('  start   : hkm cli server:run ' . $name);
        }

        $this->newLine();
        $this->muted(
            'Each of these is a FOREGROUND process that exits when stopped — a supervisor restarts '
            . 'it. Nothing here starts anything, and a declared server that is not running is exactly '
            . 'the state in which an application looks healthy and delivers nothing.',
        );

        return self::SUCCESS;
    }
}
