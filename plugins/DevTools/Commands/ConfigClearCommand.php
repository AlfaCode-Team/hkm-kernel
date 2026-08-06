<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Delete the compiled config manifest so the next boot recompiles it.
 *
 * Usage: config:clear
 *
 * Rarely needed — the BootPipeline recompiles every manifest on each build(),
 * so config cannot go stale the way HKM 0.3's hand-managed config cache could
 * (there, a stale var/cache/config.php silently won over the source files, and
 * deleting it by hand did not clear a live tenant). This exists for the case
 * where a manifest was written by a different user or with wrong permissions.
 */
final class ConfigClearCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'config:clear';
        $this->description = 'Delete the compiled config manifest';
    }

    protected function handle(): int
    {
        $path = Paths::cache('manifests/config-manifest.php');

        if (!is_file($path)) {
            $this->info('No compiled config manifest — nothing to clear.');

            return self::SUCCESS;
        }

        if (!@unlink($path)) {
            $this->error("Could not delete {$path} — check file ownership.");

            return self::FAILURE;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $this->success('Config manifest cleared. It recompiles on the next boot.');

        return self::SUCCESS;
    }
}
