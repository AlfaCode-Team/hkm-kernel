<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\CommandModule;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

/**
 * Declared in module.json, never registered in boot(), and its only
 * constructor parameter is OPTIONAL. The CoreContainer would still inject a
 * WiredDependency rather than leave null, so it must not be auto-registered.
 */
final class OptionalWiredCommand extends AbstractCommand
{
    public function __construct(private readonly ?WiredDependency $dependency = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'fixture:optional';
        $this->description = 'Fixture command with an optional constructor dependency';
    }

    protected function handle(): int
    {
        return $this->dependency === null ? self::SUCCESS : self::FAILURE;
    }
}
