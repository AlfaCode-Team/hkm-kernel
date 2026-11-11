<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * An EventBus listener that records instead of reacting.
 *
 * EventBus is final, so the ground cannot observe dispatches by wrapping it.
 * It subscribes this to `EventBus::ALL` instead, which records every dispatch
 * whatever its name.
 *
 * It used to subscribe one name at a time from the plugin's module.json
 * `emits[]`, because that was the only list available — and that made the
 * recorder exactly as complete as a hand-maintained declaration. An event
 * fired under an undeclared name was simply never seen, so a test asserting it
 * failed with "recorded: nothing", which reads like the dispatch never ran.
 *
 * The instance is bound into the CoreContainer before it freezes, so EventBus
 * resolves this exact object rather than constructing a fresh one per dispatch.
 */
final class EventRecorder implements EventListenerContract
{
    /** @var list<IntegrationEventContract> */
    private array $events = [];

    public function handle(IntegrationEventContract $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<IntegrationEventContract> */
    public function all(): array
    {
        return $this->events;
    }

    /** @return list<string> every event name recorded, in dispatch order */
    public function names(): array
    {
        return array_map(static fn(IntegrationEventContract $e): string => $e->name(), $this->events);
    }

    /** Was an event dispatched? Accepts the event NAME or its class-string. */
    public function dispatched(string $nameOrClass): bool
    {
        return $this->countOf($nameOrClass) > 0;
    }

    public function countOf(string $nameOrClass): int
    {
        $matches = 0;
        foreach ($this->events as $event) {
            if ($event->name() === $nameOrClass || $event instanceof $nameOrClass) {
                $matches++;
            }
        }

        return $matches;
    }

    /** The first matching event, for asserting on its payload. */
    public function first(string $nameOrClass): ?IntegrationEventContract
    {
        foreach ($this->events as $event) {
            if ($event->name() === $nameOrClass || $event instanceof $nameOrClass) {
                return $event;
            }
        }

        return null;
    }

    /** The payload of the first matching event, or [] when none was dispatched. */
    public function payloadOf(string $nameOrClass): array
    {
        return $this->first($nameOrClass)?->payload() ?? [];
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
