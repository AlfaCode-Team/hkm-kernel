<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\OAuth2\Infrastructure\Cli\Concerns\TargetsTenant;
use Plugins\OAuth2\Infrastructure\Persistence\ClientRepository;

/**
 * oauth:client:list — list registered OAuth2 clients.
 *
 *   hkm oauth:client:list --tenant=acme-inc   # one tenant's clients
 *   hkm oauth:client:list --all               # every active tenant
 *   hkm oauth:client:list                      # central/default connection
 */
final class ListClientsCommand extends AbstractCommand
{
    use TargetsTenant;

    public function __construct(private readonly TenantConnections $connections)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'oauth:client:list';
        $this->description = 'List registered OAuth2 clients';

        $this->addTenantOptions();
    }

    protected function handle(): int
    {
        $targets  = $this->tenantTargets($this->connections);
        $labelled = $this->tenantLabelled($targets);

        foreach ($targets as [$label, $db]) {
            if ($labelled) {
                $this->info("── {$label} ──");
            }

            $clients = (new ClientRepository($db))->all();
            if ($clients === []) {
                $this->info('  No OAuth2 clients registered.');
                continue;
            }

            foreach ($clients as $c) {
                $type = $c->confidential ? 'confidential' : 'public';
                $flag = $c->revoked ? ' [REVOKED]' : '';
                $this->info(sprintf(
                    '%s  %-20s  %-12s  grants=%s%s',
                    $c->id,
                    $c->name,
                    $type,
                    implode(',', $c->grantTypes) ?: '-',
                    $flag,
                ));
            }
        }

        return self::SUCCESS;
    }
}
