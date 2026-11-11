<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Where every listener in this file records what it saw, in order. */
final class WildcardLog
{
    /** @var list<string> */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$seen = [];
    }
}

final class ObserverListener
{
    public function handle(IntegrationEventContract $event): void
    {
        WildcardLog::$seen[] = 'observer:' . $event->name();
    }
}

final class SecondObserverListener
{
    public function handle(IntegrationEventContract $event): void
    {
        WildcardLog::$seen[] = 'second:' . $event->name();
    }
}

final class NamedListener
{
    public function handle(IntegrationEventContract $event): void
    {
        WildcardLog::$seen[] = 'named:' . $event->name();
    }
}

final class ThrowingObserverListener
{
    public function handle(IntegrationEventContract $event): void
    {
        WildcardLog::$seen[] = 'throwing:' . $event->name();

        throw new \RuntimeException('observer exploded');
    }
}

final class NamedEvent implements IntegrationEventContract
{
    public function __construct(private readonly string $name) {}

    public function name(): string { return $this->name; }
    public function version(): string { return '1.0'; }
    public function payload(): array { return []; }
}

/**
 * Subscribing to EVERY event.
 *
 * A webhook or audit consumer cannot enumerate the names it wants: they belong
 * to other plugins, and the consumer is installed precisely because nobody
 * knows in advance what the application emits. Before this, the only list
 * available was module.json `emits[]` — so every such consumer was exactly as
 * complete as a hand-maintained declaration, and a name missing from it was
 * silently unobserved.
 */
#[CoversClass(EventBus::class)]
final class EventBusWildcardTest extends TestCase
{
    protected function setUp(): void
    {
        WildcardLog::reset();
    }

    public function test_a_wildcard_subscriber_receives_an_event_nothing_subscribed_to_by_name(): void
    {
        // THE central property. Nobody named 'thing.happened' anywhere.
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe(EventBus::ALL, ObserverListener::class);

        $bus->dispatch(new NamedEvent('thing.happened'));

        self::assertSame(['observer:thing.happened'], WildcardLog::$seen);
    }

    public function test_a_wildcard_subscriber_receives_every_name_in_dispatch_order(): void
    {
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe(EventBus::ALL, ObserverListener::class);

        $bus->dispatch(new NamedEvent('user.registered'));
        $bus->dispatch(new NamedEvent('invoice.paid'));

        self::assertSame(
            ['observer:user.registered', 'observer:invoice.paid'],
            WildcardLog::$seen,
        );
    }

    public function test_exact_subscribers_still_run_and_run_before_the_wildcard(): void
    {
        // Ordering here is backward compatibility, not semantics: a wildcard
        // observer added later must not interpose itself ahead of a handler
        // that was already there.
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe(EventBus::ALL, ObserverListener::class);
        $bus->subscribe('user.registered', NamedListener::class);

        $bus->dispatch(new NamedEvent('user.registered'));

        self::assertSame(
            ['named:user.registered', 'observer:user.registered'],
            WildcardLog::$seen,
        );
    }

    public function test_every_wildcard_subscriber_runs(): void
    {
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe(EventBus::ALL, ObserverListener::class);
        $bus->subscribe(EventBus::ALL, SecondObserverListener::class);

        $bus->dispatch(new NamedEvent('thing.happened'));

        self::assertSame(
            ['observer:thing.happened', 'second:thing.happened'],
            WildcardLog::$seen,
        );
    }

    public function test_a_class_subscribed_to_both_the_name_and_all_is_invoked_once(): void
    {
        // The wildcard's first consumers deliver OUTWARD: a webhook sent twice
        // is a duplicate POST to someone else's endpoint, charged to them. And
        // "I subscribed to everything, and also to the one I care about" is an
        // entirely natural way to arrive there.
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe(EventBus::ALL, ObserverListener::class);
        $bus->subscribe('user.registered', ObserverListener::class);

        $bus->dispatch(new NamedEvent('user.registered'));

        self::assertSame(['observer:user.registered'], WildcardLog::$seen);
    }

    public function test_the_same_class_subscribed_twice_to_one_name_still_runs_twice(): void
    {
        // De-duplication is against the wildcard ONLY. Exact subscriptions are
        // not de-duplicated against each other, because that behaviour predates
        // the wildcard and nothing here is entitled to change it.
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe('user.registered', NamedListener::class);
        $bus->subscribe('user.registered', NamedListener::class);

        $bus->dispatch(new NamedEvent('user.registered'));

        self::assertSame(
            ['named:user.registered', 'named:user.registered'],
            WildcardLog::$seen,
        );
    }

    public function test_with_no_wildcard_subscription_dispatch_is_unchanged(): void
    {
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe('user.registered', NamedListener::class);

        $bus->dispatch(new NamedEvent('user.registered'));
        $bus->dispatch(new NamedEvent('something.else'));

        // The second event reaches nothing, exactly as before.
        self::assertSame(['named:user.registered'], WildcardLog::$seen);
    }

    public function test_a_throwing_wildcard_listener_is_isolated_and_reported(): void
    {
        // An observer subscribed to everything sees every event, so one that
        // throws would otherwise break every dispatch in the application.
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe(EventBus::ALL, ThrowingObserverListener::class);
        $bus->subscribe(EventBus::ALL, ObserverListener::class);

        $failures = $bus->dispatch(new NamedEvent('thing.happened'));

        self::assertArrayHasKey(ThrowingObserverListener::class, $failures);
        self::assertSame('observer exploded', $failures[ThrowingObserverListener::class]->getMessage());
        // And the listener after it still ran.
        self::assertContains('observer:thing.happened', WildcardLog::$seen);
    }

    public function test_an_event_named_like_the_wildcard_is_delivered_once(): void
    {
        // Its exact bucket and the wildcard bucket are the same array. The
        // de-duplication collapses them rather than double-delivering.
        $bus = new EventBus(new CoreContainer());
        $bus->subscribe(EventBus::ALL, ObserverListener::class);

        $bus->dispatch(new NamedEvent(EventBus::ALL));

        self::assertSame(['observer:*'], WildcardLog::$seen);
    }

    public function test_a_view_for_a_request_container_carries_wildcard_subscriptions(): void
    {
        // Subscriptions are app-lifetime and copied by value into the view, so
        // the wildcard has to travel with them like any other.
        $core = new CoreContainer();
        $bus  = new EventBus($core);
        $bus->subscribe(EventBus::ALL, ObserverListener::class);

        $module = new ModuleContainer($core);
        $module->setScope('');

        $bus->forContainer($module)->dispatch(new NamedEvent('thing.happened'));

        self::assertSame(['observer:thing.happened'], WildcardLog::$seen);
    }
}
