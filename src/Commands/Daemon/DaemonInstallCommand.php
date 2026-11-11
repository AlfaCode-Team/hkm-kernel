<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Daemon;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonInstaller;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonRegistry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\EnvSeeder;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Platform;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\ServiceUnit;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * `daemon:install` — provision the servers this project's plugins need.
 *
 * Some plugins are CLIENTS for a server that has to exist. Installing one of
 * those leaves a deployment a process short, and the symptom is the one this
 * codebase keeps meeting: everything appears to work and nothing arrives. So a
 * plugin may declare, in its own `module.json`, exactly which daemon it needs —
 * pinned version, pinned checksum, config template, arguments — and this
 * command fetches it, verifies it, configures it for production and writes a
 * service unit for review.
 *
 * It holds no knowledge of any particular daemon.
 *
 * Usage:
 *   hkm cli daemon:install                # every plugin that declares one
 *   hkm cli daemon:install nats           # just that one
 *   hkm cli daemon:install --check        # report only; download nothing
 *   hkm cli daemon:install nats --force   # re-download even if current
 *   hkm cli daemon:install nats --user=hkm  # put this User= in the systemd unit
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not start anything, and it does not install the service unit. Both
 * are deliberate: a provisioning step that opened a listening socket would do
 * it on a developer's laptop as a side effect of enabling a plugin, and in CI
 * would leave a process running in the build container. The unit is written
 * into the project and the two commands to install it are printed.
 */
final class DaemonInstallCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'daemon:install';
        $this->description = 'Download, verify and configure the daemons this project\'s plugins declare';

        $this->addArgument('plugin', 'Only this plugin (default: all that declare a daemon)', required: false);

        $this->addOption('check', 'c', 'Report what would happen; download nothing, write nothing');
        $this->addOption('force', 'f', 'Re-download even when the recorded version already matches');
        $this->addOption('user', '', 'The account to put in a systemd unit\'s User=', acceptsValue: true);
    }

    protected function handle(): int
    {
        $root  = rtrim(Paths::base(), '/');
        $only  = $this->argument('plugin');
        $check = $this->hasOption('check');

        try {
            $declared = DaemonRegistry::all($root);
        } catch (DaemonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($declared === []) {
            $this->section('Daemons');
            $this->muted('No installed plugin declares a `daemon` block, so there is nothing to provision.');
            $this->muted(
                'That is the normal case. Only a plugin that is a CLIENT for a separate server — a '
                . 'message bus, a broker, a search engine — needs one.',
            );

            return self::SUCCESS;
        }

        if (\is_string($only) && trim($only) !== '') {
            $one = DaemonRegistry::find($root, $only);

            if ($one === null) {
                $this->error("No installed plugin named [{$only}] declares a daemon.");
                $this->muted('Declared by: ' . implode(', ', array_keys($declared)));

                return self::INVALID;
            }

            $declared = [$only => $one];
        }

        $platform = null;

        try {
            $platform = Platform::current();
        } catch (DaemonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->section('Daemons');
        $this->muted("Project  : {$root}");
        $this->muted("Platform : {$platform->key()} (supervised by {$platform->supervisor()})");
        $this->newLine();

        $installer = new DaemonInstaller();
        $failures  = 0;

        foreach ($declared as $plugin => $entry) {
            $spec = $entry['spec'];

            $this->info("{$plugin} → {$spec->name} {$spec->version}");

            if ($spec->why !== '') {
                $this->muted('  ' . $spec->why);
            }

            if (!$spec->supports($platform)) {
                $this->error('  ' . DaemonException::platformNotPinned(
                    $spec->name,
                    $platform->key(),
                    array_keys($spec->platforms),
                )->getMessage());
                ++$failures;

                continue;
            }

            if ($check) {
                $this->reportPlanned($spec, $platform, $root);

                continue;
            }

            try {
                $result = $installer->install($root, $spec, $platform, $this->hasOption('force'));
                $this->reportInstalled($result, $root, (string) $plugin);
            } catch (DaemonException $e) {
                $this->error('  ' . $e->getMessage());
                ++$failures;
            }

            $this->newLine();
        }

        if ($failures > 0) {
            $this->error("{$failures} daemon(s) could not be provisioned.");

            return self::FAILURE;
        }

        if ($check) {
            $this->muted('Nothing was downloaded or written — remove --check to apply.');
        }

        return self::SUCCESS;
    }

    private function reportPlanned(
        \AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonSpec $spec,
        Platform $platform,
        string $root,
    ): void {
        $this->muted('  would download ' . $spec->urlFor($platform));
        $this->muted('  would verify   sha256 ' . substr($spec->digestFor($platform), 0, 16) . '…');
        $this->muted('  would install  ' . $root . '/var/daemon/' . $spec->name . '/bin/' . $spec->name);

        if ($spec->env !== []) {
            $this->muted('  would seed     ' . implode(', ', array_keys($spec->env)) . ' into .env (only if absent)');
        }
    }

    /**
     * @param string $plugin the PLUGIN's name, not the daemon's — every command
     *                       printed here takes the same argument the operator
     *                       just typed, and `daemon:install nats` followed by
     *                       `daemon:run nats-server` reads like a mistake even
     *                       though both resolve
     */
    private function reportInstalled(DaemonResult $result, string $root, string $plugin): void
    {
        $paths = $result->paths;

        if ($result->binaryInstalled) {
            $this->success('  installed ' . $this->relative($paths->binary, $root));
        }

        foreach ($result->notes as $note) {
            $this->muted('  ' . $note);
        }

        if ($result->configWritten !== null) {
            $this->muted('  config    ' . $this->relative($result->configWritten, $root));
        }

        // ── .env ────────────────────────────────────────────────────────────
        $env = $result->env();

        if ($env !== []) {
            $outcome = EnvSeeder::seed($root, 'daemon: ' . $result->spec->name, $env);

            foreach ($outcome as $key => $what) {
                match ($what) {
                    EnvSeeder::ADDED     => $this->success("  .env      {$key} added"),
                    // Already correct. The common case on a repeat deploy, so
                    // it is quiet: a warning per key per deploy for a file that
                    // did not change is how people learn to scroll past
                    // warnings.
                    EnvSeeder::UNCHANGED => $this->muted("  .env      {$key} already correct"),
                    // Present and DIFFERENT. Never overwritten — a deploy that
                    // silently replaced a credential is how an application
                    // stops authenticating with nothing in the log to explain
                    // it — but it does mean these two now disagree.
                    default              => $this->warning(
                        "  .env      {$key} is set to something ELSE and was left alone — the "
                        . 'application and the daemon no longer agree on it',
                    ),
                };
            }
        }

        // ── The service unit, written but NOT installed ──────────────────────
        $user = $this->option('user');
        $unit = ServiceUnit::render($result, \is_string($user) ? trim($user) : '');

        if (@file_put_contents($paths->serviceFile, $unit . "\n") !== false) {
            @chmod($paths->serviceFile, 0o640);
            $this->muted('  service   ' . $this->relative($paths->serviceFile, $root) . ' (written, NOT installed)');
        }

        $this->newLine();
        $this->muted('  Run it in the foreground now:');
        $this->muted('    hkm cli daemon:run ' . $plugin);
        $this->muted('  Or supervise it — read the unit first, it starts a listening process at boot:');

        if ($result->platform->supervisor() === 'systemd') {
            $label = ServiceUnit::label($result);
            $this->muted("    sudo cp {$paths->serviceFile} /etc/systemd/system/{$label}.service");
            $this->muted("    sudo systemctl daemon-reload && sudo systemctl enable --now {$label}");
        } else {
            $label = ServiceUnit::label($result);
            $this->muted("    cp {$paths->serviceFile} ~/Library/LaunchAgents/{$label}.plist");
            $this->muted("    launchctl bootstrap gui/\$(id -u) ~/Library/LaunchAgents/{$label}.plist");
        }
    }

    private function relative(string $path, string $root): string
    {
        return str_starts_with($path, $root . '/') ? substr($path, \strlen($root) + 1) : $path;
    }
}
