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

    /**
     * Dispatch an integration event to all subscribers, each in isolation.
     *
     * RETURNS THE FAILURES. Isolation is right — one broken listener must not
     * stop the others — but isolation was being read as success, and a caller
     * that cannot tell the difference will record one. That is precisely how a
     * mis-scoped listener cost a real tenant membership: the listener threw, the
     * bus swallowed it exactly as designed, and the transactional outbox then
     * marked the row dispatched because dispatch() had returned normally. The
     * row was consumed, never retried, and the seat was lost permanently — while
     * `status=1, attempts=1, last_error=NULL` said the delivery went fine.
     *
     * A void return left the caller no way to know, so the fix belongs here.
     * Adding the value is backward compatible: every existing
     * `$bus->dispatch($e);` keeps working untouched and simply ignores it. An
     * outbox, a relay, or anything else that records delivery SHOULD check it
     * and re-queue rather than consume — see OutboxRelayService.
     *
     * Failures are still logged here, so a caller that ignores the return is no
     * worse off than before. Note the logger is optional: with none bound there
     * is no log line at all, which is the second reason the return value has to
     * exist.
     *
     * @return array<class-string, \Throwable> listener class => its failure.
     *         Empty when every subscriber handled the event.
     */
    public function dispatch(IntegrationEventContract $event): array
    {
        $failures = [];

        foreach ($this->subscribers[$event->name()] ?? [] as $listenerClass) {
            try {
                $listener = $this->resolveListener($listenerClass);
                $listener->handle($event);
            } catch (\Throwable $e) {
                // Isolate subscriber failures — never mask the original dispatch.
                $failures[$listenerClass] = $e;

                $this->logger?->error('EventBus listener failed', [
                    'listener' => $listenerClass,
                    'event'    => $event->name(),
                    'version'  => $event->version(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $failures;
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
     * AND THE FALLBACK IS NOW CONDITIONAL, because unconditionally it destroyed
     * the evidence. A listener bound with bindInternal() is unresolvable from
     * outside its module scope, so the container threw ScopeViolationException —
     * naming the real fault exactly. That was caught here, discarded, and
     * replaced with `new`, which threw ArgumentCountError; dispatch() logged
     * "Too few arguments to function …::__construct()". Every reader then went
     * looking at the listener's constructor, which was fine, instead of at the
     * binding, which was not. The third time this shape cost a production seat
     * was enough.
     *
     * So `new` is attempted ONLY when it can actually work — a constructor with
     * no required parameters. Otherwise the container's own exception is
     * rethrown untouched, and the log finally names the cause.
     *
     * @param class-string $listenerClass
     *
     * @throws \Throwable the container's failure, when `new` cannot substitute
     */
    private function resolveListener(string $listenerClass): object
    {
        try {
            $listener = $this->container->get($listenerClass);

            if (is_object($listener)) {
                return $listener;
            }

            // Bound to a non-object. `new` below is still worth a try, but there
            // is no container exception to rethrow, so synthesise the cause.
            $containerFailure = new \RuntimeException(sprintf(
                'Container resolved [%s] to a %s, not a listener object.',
                $listenerClass,
                get_debug_type($listener),
            ));
        } catch (\Throwable $e) {
            $containerFailure = $e;
        }

        if ($this->needsConstructorArguments($listenerClass)) {
            throw $containerFailure;
        }

        return new $listenerClass();
    }

    /**
     * Would `new $listenerClass()` fail for want of arguments?
     *
     * A missing or unreadable class is reported as "no arguments needed" so the
     * `new` below still runs and throws the honest Error about the class itself
     * — reflection's complaint would only add a layer.
     *
     * @param class-string $listenerClass
     */
    private function needsConstructorArguments(string $listenerClass): bool
    {
        try {
            $constructor = (new \ReflectionClass($listenerClass))->getConstructor();
        } catch (\ReflectionException) {
            return false;
        }

        return $constructor !== null && $constructor->getNumberOfRequiredParameters() > 0;
    }
}
