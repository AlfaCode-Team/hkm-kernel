<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Sample;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * A command the ground can run, so the CLI half of the harness has something to
 * be tested against.
 *
 * It takes a port in its constructor on purpose: `CliPipeline` resolves a
 * registered command through the CoreContainer, and "does a command get its
 * injected dependencies" is exactly the thing a plugin author cannot easily
 * check without running the whole binary against a real database.
 */
final class SampleCommand extends AbstractCommand
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'sample:report';
        $this->description = 'Report what the sample repository can see';

        $this->addOption('fail', 'f', 'Exit non-zero, to exercise the failure assertions');
    }

    protected function handle(): int
    {
        if ($this->hasOption('fail')) {
            $this->error('sample:report was asked to fail.');

            return self::FAILURE;
        }

        $rows = $this->db->query('select * from samples');

        $this->info('samples visible: ' . \count($rows));

        return self::SUCCESS;
    }
}
