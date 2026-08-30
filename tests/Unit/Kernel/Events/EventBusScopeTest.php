<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An INTERFACE dependency — the shape a real plugin listener has, and the
 * reason this cannot be papered over by autowiring. A container that has no
 * binding for it cannot invent one.
 */
interface RecorderContract
{
    public function record(string $name): void;
}

final class SpyRecorder implements RecorderContract
{
    /** @var list<string> */
    public array $seen = [];

    public function record(string $name): void
    {
        $this->seen[] = $name;
    }
}

/** A listener with a constructor dependency a plugin binds in register(). */
final class DependentListener
{
    public function __construct(private readonly RecorderContract $recorder) {}

    public function handle(IntegrationEventContract $event): void
    {
        $this->recorder->record($event->name());
    }
}

/** A dependency-free listener — the only kind the old `new` fallback could build. */
final class SimpleListener
{
    public static int $calls = 0;

    public function handle(IntegrationEventContract $event): void
    {
        self::$calls++;
    }
}

final class ScopeTestEvent implements IntegrationEventContract
{
    public function name(): string { return 'scope.test'; }
    public function version(): string { return '1.0'; }
    public function payload(): array { return []; }
}

/**
 * The EventBus is constructed once, at materialize, with the CoreContainer —
 * while listener dependencies are bound per request, in the ModuleContainer.
 * That mismatch dropped events silently, and every project papered over it by
 * hand-assembling listeners into withPorts().
 */
#[CoversClass(EventBus::class)]
final class EventBusScopeTest extends TestCase
{
    protected function setUp(): void
    {
        SimpleListener::$calls = 0;
    }

    public function test_a_listener_with_an_unbound_dependency_is_still_dropped_quietly(): void
    {
        // Characterises the failure mode itself: nothing is bound anywhere, so
        // the listener genuinely cannot be built. dispatch() must isolate that
        // — the caller of an integration event must never see it throw.
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe('scope.test', DependentListener::class);

        $bus->dispatch(new ScopeTestEvent());

        $this->addToAssertionCount(1); // no throw escaped
    }

    public function test_forContainer_resolves_a_listener_from_the_request_container(): void
    {
        // The fix. RecorderContract is bound exactly where a plugin binds it:
        // in the request-scoped container, from Provider::register().
        $core     = new CoreContainer();
        $recorder = new SpyRecorder();

        $module = new ModuleContainer($core);
        $module->setScope('');
        $module->singleton(RecorderContract::class, static fn (): RecorderContract => $recorder);

        $bus = new EventBus($core);
        $bus->subscribe('scope.test', DependentListener::class);

        $bus->forContainer($module)->dispatch(new ScopeTestEvent());

        self::assertSame(['scope.test'], $recorder->seen);
    }

    public function test_a_core_bound_listener_still_resolves_through_the_view(): void
    {
        // Resolution must be a SUPERSET: the hand-wired withPorts() listeners
        // every existing project relies on cannot stop working.
        $recorder = new SpyRecorder();

        $core = new CoreContainer();
        $core->singleton(
            DependentListener::class,
            static fn (): DependentListener => new DependentListener($recorder),
        );

        $bus = new EventBus($core);
        $bus->subscribe('scope.test', DependentListener::class);

        $module = new ModuleContainer($core);
        $module->setScope('');

        $bus->forContainer($module)->dispatch(new ScopeTestEvent());

        self::assertSame(['scope.test'], $recorder->seen);
    }

    public function test_a_dependency_free_listener_still_runs_with_nothing_bound(): void
    {
        // The case the old `new $listenerClass()` fallback existed for. It has
        // to keep working with no binding and no autowiring help at all.
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe('scope.test', SimpleListener::class);

        $bus->dispatch(new ScopeTestEvent());

        self::assertSame(1, SimpleListener::$calls);
    }

    public function test_making_a_view_does_not_disturb_the_bus_it_came_from(): void
    {
        $core = new CoreContainer();
        $bus  = new EventBus($core);
        $bus->subscribe('scope.test', SimpleListener::class);

        $module = new ModuleContainer($core);
        $module->setScope('');

        $bus->forContainer($module)->dispatch(new ScopeTestEvent());
        self::assertSame(1, SimpleListener::$calls, 'the view carries the subscriptions');

        $bus->dispatch(new ScopeTestEvent());
        self::assertSame(2, SimpleListener::$calls, 'the original bus still dispatches');
    }
}
