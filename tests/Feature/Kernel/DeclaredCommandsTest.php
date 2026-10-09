<?php

declare(strict_types=1);

namespace Tests\Feature\Kernel;

use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Fixtures\CommandModule\Provider as CommandProvider;
use Tests\Feature\Support\KernelTestCase;

/**
 * A command declared in module.json `commands[]` must exist on the CLI.
 *
 * The boot pipeline compiled `commands[]` into command-manifest.php, but no
 * code read that manifest, so a declared-but-not-boot()-registered command
 * silently did not exist.
 */
#[Group('feature')]
final class DeclaredCommandsTest extends KernelTestCase
{
    public function testACommandDeclaredOnlyInModuleJsonIsRegistered(): void
    {
        $app = $this->boot(modules: [CommandProvider::class])->cli()->application();

        self::assertTrue($app->has('fixture:declared'));
    }

    public function testACommandAlsoRegisteredInBootIsStillAvailable(): void
    {
        $app = $this->boot(modules: [CommandProvider::class])->cli()->application();

        self::assertTrue($app->has('fixture:both'));
        self::assertCount(
            1,
            array_filter($app->all(), static fn ($c): bool => $c->getName() === 'fixture:both'),
        );
    }

    /**
     * A command with constructor dependencies is NOT auto-registered, even
     * when the CoreContainer could build it: boot() alone knows which container
     * its dependencies must come from.
     */
    public function testADeclaredCommandWithDependenciesIsLeftToBoot(): void
    {
        $app = $this->boot(modules: [CommandProvider::class])->cli()->application();

        self::assertFalse($app->has('fixture:wired'));
    }

    /**
     * Not even an OPTIONAL dependency: the CoreContainer fills an optional
     * typed parameter from its own bindings instead of leaving the default,
     * so the command would still be built against core services.
     */
    public function testADeclaredCommandWithAnOptionalDependencyIsLeftToBoot(): void
    {
        $app = $this->boot(modules: [CommandProvider::class])->cli()->application();

        self::assertFalse($app->has('fixture:optional'));
    }

    /** run() finds a declared-only command even though it loads them lazily. */
    public function testRunningADeclaredOnlyCommandWorks(): void
    {
        $cli = $this->boot(modules: [CommandProvider::class])->cli();

        self::assertSame(0, $cli->run(['bin', 'fixture:declared']));
    }

    /**
     * Running a command boot() registered must not read command-manifest.php:
     * the declared commands are loaded only when the requested name is missing,
     * so the common path costs exactly what it did before they existed.
     */
    public function testRunningARegisteredCommandDoesNotLoadDeclaredOnes(): void
    {
        $cli = $this->boot(modules: [CommandProvider::class])->cli();

        self::assertSame(0, $cli->run(['bin', 'fixture:both']));

        $app = (new \ReflectionProperty($cli, 'app'))->getValue($cli);
        self::assertFalse($app->has('fixture:declared'), 'the manifest was read on the fast path');
    }

    public function testABareNameInventoryEntryIsIgnored(): void
    {
        $app = $this->boot(modules: [CommandProvider::class])->cli()->application();

        self::assertFalse($app->has('InventoryOnlyCommand'));
    }
}
