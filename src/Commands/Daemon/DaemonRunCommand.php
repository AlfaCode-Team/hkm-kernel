<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Daemon;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonPaths;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonRegistry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Platform;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * `daemon:run` — run a provisioned daemon in the FOREGROUND, for development.
 *
 * The counterpart to the service unit, not a replacement for it. In the
 * foreground it dies with the terminal, which is exactly what a developer wants
 * and exactly what production must not have — so the unit written by
 * `daemon:install` stays the answer for a server, and this is the answer for a
 * laptop.
 *
 * It REPLACES this process rather than forking one. That matters more than it
 * looks: a child would survive Ctrl-C in some shells and be orphaned, leaving a
 * listening socket bound and the next run failing with "address already in
 * use" — a confusing error whose cause is two commands back. Replacing the
 * process means the daemon IS this process, so the terminal's signals reach it
 * directly and nothing can be left behind.
 *
 * Usage:
 *   hkm cli daemon:run nats
 *   hkm cli daemon:run nats --print     # just show the command
 */
final class DaemonRunCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'daemon:run';
        $this->description = 'Run a provisioned daemon in the foreground (development)';

        $this->addArgument('plugin', 'The plugin whose daemon to run', required: true);
        $this->addOption('print', 'p', 'Print the command instead of running it');
    }

    protected function handle(): int
    {
        $root   = rtrim(Paths::base(), '/');
        $plugin = (string) $this->argument('plugin', '');

        try {
            $entry    = DaemonRegistry::find($root, $plugin);
            $platform = Platform::current();
        } catch (DaemonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($entry === null) {
            $this->error("No installed plugin named [{$plugin}] declares a daemon.");

            return self::INVALID;
        }

        $spec  = $entry['spec'];
        $paths = DaemonPaths::for($root, $spec, $platform->supervisor());

        if (!$paths->isInstalled()) {
            $this->error(
                "{$spec->name} is not provisioned yet. Run `hkm cli daemon:install {$plugin}` first.",
            );

            return self::FAILURE;
        }

        if ($spec->template !== null && !$paths->hasConfig()) {
            // Starting without the generated config would start the daemon on
            // ITS defaults, and for a message bus that means every interface
            // with no authentication. Refusing is the only safe answer.
            $this->error(
                "{$spec->name} has no generated config at {$paths->config}, and starting it without "
                . 'one would run it on its own defaults — which for a bus means listening on every '
                . "interface with no authentication. Run `hkm cli daemon:install {$plugin}` first.",
            );

            return self::FAILURE;
        }

        // Rebuilt rather than re-provisioned: the tokens come from the secret
        // already on disk, so this neither downloads nor rotates anything.
        $secret = is_file($paths->secretFile) ? trim((string) @file_get_contents($paths->secretFile)) : '';

        $result = new DaemonResult(
            spec: $spec,
            platform: $platform,
            paths: $paths,
            tokens: array_merge($spec->defaults, [
                'name'    => $spec->name,
                'version' => $spec->version,
                'os'      => $platform->os,
                'arch'    => $platform->arch,
                'dir'     => $paths->dir,
                'binary'  => $paths->binary,
                'config'  => $paths->config,
                'logfile' => $paths->logFile,
                'pidfile' => $paths->pidFile,
                'store'   => $paths->storeDir,
                'secret'  => $secret,
                'project' => basename($root),
                'root'    => $root,
            ]),
            binaryInstalled: false,
            configWritten: $paths->hasConfig() ? $paths->config : null,
            notes: [],
        );

        $argv = $result->command();

        if ($this->hasOption('print')) {
            $this->muted($result->commandLine());

            return self::SUCCESS;
        }

        $this->muted('exec ' . $result->commandLine());
        $this->muted('Ctrl-C to stop. For a server, use the unit `daemon:install` wrote instead.');
        $this->newLine();

        // pcntl_exec replaces this process, so signals reach the daemon
        // directly. Without the extension there is no way to do that, and a
        // forked child would be orphaned by Ctrl-C in some shells — so the
        // command is printed rather than half-done.
        if (\function_exists('pcntl_exec')) {
            pcntl_exec($argv[0], \array_slice($argv, 1));

            // Only reached when exec itself failed.
            $this->error('Could not exec ' . $argv[0]);

            return self::FAILURE;
        }

        $this->warning(
            'ext-pcntl is not loaded, so this process cannot be replaced by the daemon. Run the '
            . 'command above directly — a forked child would survive Ctrl-C in some shells and leave '
            . 'the port bound.',
        );

        return self::FAILURE;
    }
}
