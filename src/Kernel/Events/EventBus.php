<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\{EventListenerContract, IntegrationEventContract};
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Psr\Container\ContainerInterface;

// ─── EventBus ────────────────────────────────────────────────────────────────

/**
 * Dispatches integration events to subscribed module listeners.
 *
 * Subscriptions are registered ONCE during module boot() (app-lifetime) so the
 * routing table is stable and shareable across requests under OpenSwoole.
 *
 * Listener instances are resolved from the supplied PSR-11 container. Listeners
 * must be stateless integration handlers (they receive primitive-only events).
 *
 * Subscriber failures are isolated and logged through the kernel's LoggerPort —
 * one failing listener never prevents the others from receiving the event.
 *
 * The logger is OPTIONAL (null = no logging). It is deliberately not defaulted to
 * a null-object: a swallowed listener exception that is also silently unlogged is
 * indistinguishable from an event that was never dispatched, and that is exactly
 * the failure this codebase already had when the only LoggerInterface binding
 * pointed at a NullLogger.
 *
 * IMPORTANT: dispatch ONLY after a successful transaction commit.
 */
final class EventBus
{
    /** @var array<string, list<class-string<EventListenerContract>>> event name => listener classes */
    private array $subscribers = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?LoggerPort $logger = null,
    ) {}

    /**
     * A view of this bus that resolves listeners from a REQUEST-SCOPED container.
     *
     * Subscriptions are app-lifetime — registered once, from Module::boot(),
     * onto the instance in the CoreContainer. Listener DEPENDENCIES are not: a
     * plugin binds them in Provider::register(), which runs per request against
     * the ModuleContainer. A bus that only ever saw the CoreContainer therefore
     * could not construct any listener with constructor arguments — has()
     * returned false, dispatch fell through to `new $listenerClass()`, that
     * threw ArgumentCountError, and the catch below logged it as a failed
     * listener. The event was silently dropped, and projects worked around it
     * by hand-assembling listeners (and their plugins' internals) into
     * withPorts() so they would be in the core container after all.
     *
     * Resolution here is strictly a SUPERSET of before: ModuleContainer::make()
     * consults its own bindings and then delegates to the CoreContainer, so a
     * listener already bound in core resolves exactly as it did.
     *
     * Subscribers are copied by value, which is correct precisely because
     * subscribe() only runs during materialize, before any request builds a
     * view — a later subscription could not be seen, and there is no supported
     * way to make one.
     */
    public function forContainer(ContainerInterface $container): self
    {
        $bus = new self($container, $this->logger);
        $bus->subscribers = $this->subscribers;

        return $bus;
    }

    /**
     * Subscribe a listener to an event. Called from Module::boot().
     *
     * @param class-string<EventListenerContract> $listenerClass
     */
    public function subscribe(string $eventName, string $listenerClass): void
    {
        $this->subscribers[$eventName][] = $listenerClass;
    }

    /** Dispatch an integration event to all subscribers, each in isolation. */
    public function dispatch(IntegrationEventContract $event): void
    {
        foreach ($this->subscribers[$event->name()] ?? [] as $listenerClass) {
            try {
                $listener = $this->resolveListener($listenerClass);
                $listener->handle($event);
            } catch (\Throwable $e) {
                // Isolate subscriber failures — never mask the original dispatch.
                $this->logger?->error('EventBus listener failed', [
                    'listener' => $listenerClass,
                    'event'    => $event->name(),
                    'version'  => $event->version(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Build a listener, preferring the container.
     *
     * This used to be gated on `has()`, and that gate was the bug. `has()`
     * reports only what is EXPLICITLY BOUND — so an ordinary listener class,
     * which no one binds by name because the container can autowire it, was
     * reported absent and constructed with `new $listenerClass()`. That works
     * for a listener with no constructor arguments and throws
     * ArgumentCountError for every other one, which the caller catches and logs
     * as "listener failed": a dropped event whose cause reads like a bug in the
     * listener.
     *
     * Asking the container first lets it autowire the dependencies it knows
     * about. `new` stays as the fallback for the case it was always right for —
     * a dependency-free listener and a container that cannot resolve it —
     * so nothing that worked before stops working.
     *
     * @param class-string $listenerClass
     */
    private function resolveListener(string $listenerClass): object
    {
        try {
            $listener = $this->container->get($listenerClass);

            if (is_object($listener)) {
                return $listener;
            }
        } catch (\Throwable) {
            // Not bound and not autowirable here — fall through.
        }

        return new $listenerClass();
    }
}
