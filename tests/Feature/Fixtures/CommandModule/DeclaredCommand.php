<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\CommandModule;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

final class DeclaredCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'fixture:declared';
        $this->description = 'Fixture command';
    }

    protected function handle(): int
    {
        return self::SUCCESS;
    }
}
