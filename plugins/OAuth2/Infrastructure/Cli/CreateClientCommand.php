<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\OAuth2\Infrastructure\Cli\Concerns\TargetsTenant;
use Plugins\OAuth2\Infrastructure\Persistence\ClientRepository;

/**
 * oauth:client:create — register an OAuth2 client.
 *
 *   hkm oauth:client:create --tenant=acme-inc --name="My SPA" --public \
 *       --redirect="https://app.example.com/callback" --grant=authorization_code --scope="profile email"
 *
 *   hkm oauth:client:create --name="Service" --grant=client_credentials --scope="reports:read"
 *
 * A confidential client (default) is issued a secret ONCE — store it now.
 * OAuth2 tables are tenant-scoped, so pass --tenant to write into a tenant DB;
 * omit it to use the central/default connection.
 */
final class CreateClientCommand extends AbstractCommand
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
        $this->name        = 'oauth:client:create';
        $this->description = 'Register an OAuth2 client (confidential by default; --public for SPA/mobile)';

        $this->addTenantOptions();
        $this->addOption('name', '', 'Display name', acceptsValue: true);
        $this->addOption('public', '', 'Public client (no secret; must use PKCE)');
        $this->addOption('redirect', '', 'Allowed redirect URI (repeat comma-separated)', acceptsValue: true, default: '');
        $this->addOption('grant', '', 'Grant types, comma-separated', acceptsValue: true, default: 'authorization_code,refresh_token');
        $this->addOption('scope', '', 'Allowed scopes, space- or comma-separated', acceptsValue: true, default: '');
    }

    protected function handle(): int
    {
        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $this->error('A --name is required.');
            return self::FAILURE;
        }

        $public       = $this->hasOption('public');
        $redirects    = $this->splitList((string) $this->option('redirect'));
        $grantTypes   = $this->splitList((string) $this->option('grant'));
        $scopes       = $this->splitList(str_replace(' ', ',', (string) $this->option('scope')));

        if (in_array('authorization_code', $grantTypes, true) && $redirects === []) {
            $this->error('authorization_code requires at least one --redirect URI.');
            return self::FAILURE;
        }

        $id         = bin2hex(random_bytes(16));
        $secret     = null;
        $secretHash = null;
        if (!$public) {
            $secret     = bin2hex(random_bytes(32));
            $secretHash = $this->hasher->make($secret);
        }

        // One shared id/secret provisioned into each target (a single tenant, or
        // the whole fleet with --all).
        $targets  = $this->tenantTargets($this->connections);
        $labelled = $this->tenantLabelled($targets);
        foreach ($targets as [$label, $db]) {
            (new ClientRepository($db))->create($id, $name, $secretHash, $redirects, $grantTypes, $scopes, !$public);
            if ($labelled) {
                $this->info("· provisioned in {$label}");
            }
        }

        $this->success('OAuth2 client created.');
        $this->info('client_id     : ' . $id);
        if ($secret !== null) {
            $this->info('client_secret : ' . $secret . '   (shown once — store it now)');
        } else {
            $this->info('type          : public (PKCE required)');
        }
        $this->info('grant_types   : ' . implode(', ', $grantTypes));
        $this->info('redirect_uris : ' . (implode(', ', $redirects) ?: '(none)'));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function splitList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s) => $s !== ''));
    }
}
