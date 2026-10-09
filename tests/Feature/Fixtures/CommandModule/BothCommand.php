<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\CommandModule;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

final class BothCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'fixture:both';
        $this->description = 'Fixture command';
    }

    protected function handle(): int
    {
        return self::SUCCESS;
    }
}
