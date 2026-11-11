<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * NullPubSub — the default when no bus adapter is bound.
 *
 * Exists for the same reason as {@see NullMetrics}: so a call site can publish
 * unconditionally instead of guarding, because a guard at every call site is how
 * events come to be emitted on some paths and not others.
 *
 * {@see self::subscribe()} THROWS, WHERE {@see self::publish()} DOES NOTHING.
 * The asymmetry is the useful part of this class.
 *
 * Publishing into a bus nobody configured is a no-op by design — an application
 * without realtime should not fail for emitting an event that nothing is
 * listening to. But a SERVER whose whole job is to subscribe, started against no
 * bus, has nothing to do and must say so: it would otherwise sit there looking
 * healthy, accept connections, and deliver nothing to any of them. That is the
 * failure this class is most likely to be asked to produce, so it refuses
 * loudly instead.
 */
final class NullPubSub implements PubSubPort
{
    private static ?self $instance = null;

    /** The shared no-op. Holds no state, so sharing it leaks nothing. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function publish(string $channel, array $payload): int
    {
        // -1, not 0. Zero is a real answer meaning "nobody was listening"; this
        // is "nothing was asked", and a status command that cannot tell them
        // apart will report a working bus with no subscribers.
        return -1;
    }

    public function subscribe(array $channels, callable $onMessage): void
    {
        throw new \RuntimeException(
            'No PubSubPort adapter is bound, so there is no bus to subscribe to. A realtime or broker '
            . 'server cannot run without one: it would hold client connections open and deliver nothing. '
            . 'Bind a PubSubPort in withPorts() — Redis, NATS or MQTT — and start the server again.',
        );
    }

    public function stop(): void {}

    public function describe(): string
    {
        return 'none (no PubSubPort adapter bound)';
    }
}
