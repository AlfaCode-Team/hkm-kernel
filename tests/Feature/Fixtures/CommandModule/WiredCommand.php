<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\CommandModule;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

/**
 * Declared in module.json but never registered in boot(), and it has a
 * constructor dependency the CoreContainer COULD autowire. It must still not be
 * auto-registered: only boot() knows which container its dependencies belong to.
 */
final class WiredCommand extends AbstractCommand
{
    public function __construct(private readonly WiredDependency $dependency)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'fixture:wired';
        $this->description = 'Fixture command with a constructor dependency';
    }

    protected function handle(): int
    {
        return $this->dependency instanceof WiredDependency ? self::SUCCESS : self::FAILURE;
    }
}
