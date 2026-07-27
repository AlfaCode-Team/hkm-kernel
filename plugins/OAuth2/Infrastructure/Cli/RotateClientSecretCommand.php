<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\OAuth2\Infrastructure\Cli\Concerns\TargetsTenant;
use Plugins\OAuth2\Infrastructure\Persistence\ClientRepository;

/** oauth:client:rotate — issue a new secret for a confidential client. */
final class RotateClientSecretCommand extends AbstractCommand
{
    use TargetsTenant;

    public function __construct(
        private readonly TenantConnections $connections,
        private readonly HashingPort $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'oauth:client:rotate';
        $this->description = 'Rotate a confidential OAuth2 client secret';

        $this->addTenantOptions();
        $this->addOption('client', 'c', 'Client id', acceptsValue: true);
    }

    protected function handle(): int
    {
        $id = trim((string) $this->option('client'));
        if ($id === '') {
            $this->error('Provide --client <id>.');
            return self::FAILURE;
        }

        // One shared new secret applied to each target that has the client.
        $secret = bin2hex(random_bytes(32));
        $hash   = $this->hasher->make($secret);

        $targets  = $this->tenantTargets($this->connections);
        $labelled = $this->tenantLabelled($targets);
        $rotated  = 0;
        foreach ($targets as [$label, $db]) {
            $ok = (new ClientRepository($db))->updateSecret($id, $hash);
            $rotated += $ok ? 1 : 0;
            if ($labelled) {
                $this->info(($ok ? '✓ rotated in ' : '· no confidential client in ') . $label);
            }
        }

        if ($rotated === 0) {
            $this->error("No confidential client found for id: {$id}");
            return self::FAILURE;
        }

        $this->success('Secret rotated. The previous secret is now invalid.');
        $this->info('client_id     : ' . $id);
        $this->info('client_secret : ' . $secret . '   (shown once — store it now)');

        return self::SUCCESS;
    }
}
