<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\OAuth2\Infrastructure\Cli\Concerns\TargetsTenant;
use Plugins\OAuth2\Infrastructure\Persistence\AuthCodeRepository;
use Plugins\OAuth2\Infrastructure\Persistence\DeviceCodeRepository;
use Plugins\OAuth2\Infrastructure\Persistence\RefreshTokenRepository;

/**
 * oauth:prune — delete expired authorization codes, refresh tokens and device
 * codes. Run on a schedule (cron) or via `--watch` for a supervised loop.
 *
 *   hkm oauth:prune --tenant=acme-inc   # one tenant
 *   hkm oauth:prune --all               # every active tenant
 *   hkm oauth:prune --watch=300         # loop forever
 */
final class PruneCommand extends AbstractCommand
{
    use TargetsTenant;

    public function __construct(private readonly TenantConnections $connections)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'oauth:prune';
        $this->description = 'Delete expired OAuth2 authorization codes, refresh tokens and device codes';

        $this->addTenantOptions();
        $this->addOption('watch', '', 'Run forever, pruning every N seconds', acceptsValue: true, default: '');
    }

    protected function handle(): int
    {
        $watch = (int) $this->option('watch');
        if ($watch <= 0) {
            return $this->pruneAll();
        }

        $interval = max(60, $watch);
        $this->info("Watching: pruning every {$interval}s. Ctrl-C to stop.");
        while (true) {
            $this->pruneAll();
            sleep($interval);
        }
    }

    private function pruneAll(): int
    {
        $targets = $this->hasOption('all')
            ? $this->connections->each()
            : [[$this->tenantArg() ?? 'central', $this->connections->resolve($this->tenantArg())]];

        $labelled = count($targets) > 1 || $this->hasOption('all');

        foreach ($targets as [$label, $db]) {
            $codes   = (new AuthCodeRepository($db))->deleteExpired();
            $tokens  = (new RefreshTokenRepository($db))->deleteExpired();
            $devices = (new DeviceCodeRepository($db))->deleteExpired();

            $prefix = $labelled ? "{$label}: " : '';
            $this->info("{$prefix}Pruned {$codes} auth code(s), {$tokens} refresh token(s), {$devices} device code(s).");
        }

        return self::SUCCESS;
    }
}
