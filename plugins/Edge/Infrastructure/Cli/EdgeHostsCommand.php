<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Edge\API\Contracts\EdgeServiceContract;

/**
 * edge:hosts — sync the platform's LOCAL domains (.local / .test / …) into
 * /etc/hosts, pointing them at the loopback so they resolve on this machine.
 * Only a marked block is managed; the rest of the hosts file is untouched.
 *
 *   hkm edge:hosts              # add/update the managed block (needs sudo to edit /etc/hosts)
 *   hkm edge:hosts --dry-run    # show what would change; write nothing
 *   hkm edge:hosts --remove     # remove the managed block entirely
 */
final class EdgeHostsCommand extends AbstractCommand
{
    public function __construct(private readonly EdgeServiceContract $edge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'edge:hosts';
        $this->description = 'Sync local (.local/.test) platform domains into /etc/hosts';

        $this->addOption('dry-run', '', 'Show what would change; write nothing');
        $this->addOption('remove', '', 'Remove the HKM-managed block from the hosts file');
    }

    protected function handle(): int
    {
        $result = $this->edge->syncHosts(
            remove: $this->hasOption('remove'),
            dryRun: $this->hasOption('dry-run'),
        );

        $count = (int) ($result['count'] ?? 0);
        $path  = (string) ($result['path'] ?? '/etc/hosts');

        if (($result['ok'] ?? false) !== true) {
            $this->error('hosts sync failed: ' . ($result['message'] ?? 'unknown error'));
            return self::FAILURE;
        }

        if (($result['dry_run'] ?? false) === true) {
            $this->info("Would write {$count} local domain(s) to {$path}:");
            $this->newLine();
            $this->muted(($result['block'] ?? '') === '' ? '(managed block would be removed)' : (string) $result['block']);
            return self::SUCCESS;
        }

        $verb = ($result['changed'] ?? false) ? 'Synced' : 'Already current —';
        $this->success("{$verb} {$count} local domain(s) in {$path}.");

        return self::SUCCESS;
    }
}
