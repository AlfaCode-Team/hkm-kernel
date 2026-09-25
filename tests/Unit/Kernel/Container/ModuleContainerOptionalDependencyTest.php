<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Container;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\EntryNotFoundException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use PHPUnit\Framework\TestCase;

interface OptionalCollaborator
{
}

final class TakesAnOptionalCollaborator
{
    public function __construct(public readonly ?OptionalCollaborator $collaborator = null)
    {
    }
}

final class NeedsACollaborator
{
    public function __construct(public readonly OptionalCollaborator $collaborator)
    {
    }
}

/**
 * An autowired dependency that DECLARES a default gets the default when this
 * request's graph binds nothing for it.
 *
 * bind-it falls back to a parameter's default only on its own
 * BindingResolutionException; ModuleContainer signals "unbound" with
 * EntryNotFoundException, which bind-it did not catch — so a constructor
 * written `?Contract $x = null`, the idiomatic way to say "optional", threw
 * instead of receiving null. A plugin whose module is not in the request's
 * graph is exactly the case that idiom exists for.
 */
final class ModuleContainerOptionalDependencyTest extends TestCase
{
    public function test_an_unbound_optional_dependency_receives_its_default(): void
    {
        $made = (new ModuleContainer(new CoreContainer()))->make(TakesAnOptionalCollaborator::class);

        self::assertNull($made->collaborator);
    }

    public function test_a_bound_optional_dependency_is_still_injected(): void
    {
        $container = new ModuleContainer(new CoreContainer());
        $container->bind(OptionalCollaborator::class, static fn (): OptionalCollaborator => new class implements OptionalCollaborator {});

        self::assertInstanceOf(OptionalCollaborator::class, $container->make(TakesAnOptionalCollaborator::class)->collaborator);
    }

    public function test_a_required_dependency_still_fails_loudly(): void
    {
        $this->expectException(EntryNotFoundException::class);

        (new ModuleContainer(new CoreContainer()))->make(NeedsACollaborator::class);
    }
}
