<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\User\Application\Services\OutboxRelayService;

/**
 * user:outbox:relay — dispatch pending user integration events to the EventBus.
 *
 * Run on a short interval (cron / supervised loop) so downstream modules receive
 * user.registered / user.updated / user.deleted reliably, decoupled from the
 * request that produced them.
 */
final class RelayUserOutboxCommand extends AbstractCommand
{
    public function __construct(
        private readonly OutboxRelayService $relay,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'user:outbox:relay';
        $this->description = 'Relay pending user integration events from the outbox to the EventBus';
        $this->addOption('limit', 'l', 'Max events to relay this run', acceptsValue: true, default: 100);
    }

    protected function handle(): int
    {
        $limit = (int) $this->option('limit', 100);
        $count = $this->relay->relayBatch($limit);

        $this->success("Relayed {$count} event(s).");

        return self::SUCCESS;
    }
}
