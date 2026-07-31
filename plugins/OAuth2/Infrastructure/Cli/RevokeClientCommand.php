<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\OAuth2\Infrastructure\Cli\Concerns\TargetsTenant;
use Plugins\OAuth2\Infrastructure\Persistence\ClientRepository;

/** oauth:client:revoke — revoke an OAuth2 client (stops all its tokens). */
final class RevokeClientCommand extends AbstractCommand
{
    use TargetsTenant;

    public function __construct(private readonly TenantConnections $connections)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'oauth:client:revoke';
        $this->description = 'Revoke an OAuth2 client by id';

        $this->addTenantOptions();
        $this->addOption('client', 'c', 'Client id to revoke', acceptsValue: true);
    }

    protected function handle(): int
    {
        $id = trim((string) $this->option('client'));
        if ($id === '') {
            $this->error('Provide --client <id>.');
            return self::FAILURE;
        }

        $targets  = $this->tenantTargets($this->connections);
        $labelled = $this->tenantLabelled($targets);
        $revoked  = 0;
        foreach ($targets as [$label, $db]) {
            $ok = (new ClientRepository($db))->revoke($id);
            $revoked += $ok ? 1 : 0;
            if ($labelled) {
                $this->info(($ok ? '✓ revoked in ' : '· not found in ') . $label);
            }
        }

        if ($revoked === 0) {
            $this->error("Client not found: {$id}");
            return self::FAILURE;
        }

        $this->success($labelled ? "Client {$id} revoked in {$revoked} database(s)." : "Client {$id} revoked.");
        return self::SUCCESS;
    }
}
