<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * PubSubPort — fan-out messaging: every subscriber gets every message.
 *
 * WHY THIS IS NOT QueuePort, AND WHY THE DIFFERENCE IS THE WHOLE POINT
 * -------------------------------------------------------------------
 * {@see QueuePort} delivers each message to EXACTLY ONE consumer. That is
 * correct for work — two workers must not both charge the same card — and it is
 * precisely wrong for notification.
 *
 * The failure is worth spelling out because the two ports look
 * interchangeable and a queue is usually already configured. A realtime server
 * running four worker processes holds a quarter of the connected clients in
 * each. An event raised while handling an HTTP request has to reach all four,
 * because the server does not know which worker holds the client that cares.
 * Publish it to a QUEUE and exactly one worker receives it: a quarter of the
 * clients see the update and three quarters never do, at random, with no error
 * anywhere. The bug reproduces only under more than one worker, which is to say
 * never in development.
 *
 * So: a queue is point-to-point and durable, a bus is one-to-many and ephemeral.
 * An application generally needs both, and they are different ports.
 *
 * WHAT AN ADAPTER MAY AND MAY NOT PROMISE
 * ---------------------------------------
 * AT-MOST-ONCE, and deliberately. A subscriber that is not connected when a
 * message is published does not receive it, and nothing replays it later. Every
 * bus this port is written for behaves that way by default — Redis pub/sub, NATS
 * core, MQTT at QoS 0 — and a port that promised more would be a port no adapter
 * could honour. Anything that must survive a disconnect belongs in a queue or a
 * table, with the bus used only to say "something changed, come and look".
 *
 * NO ORDERING ACROSS CHANNELS. Within one channel an adapter should preserve
 * publish order; across channels nothing is guaranteed, because the backends
 * shard by channel and a cross-channel guarantee would mean serialising them.
 *
 * {@see self::publish()} MUST NOT THROW. It is called from inside a request, to
 * report something that has ALREADY happened and been committed — so a bus
 * outage must cost the notification, never the response. {@see self::subscribe()}
 * is the opposite: it IS the server's main loop, so a broken connection there is
 * the one thing the operator needs to hear about, and it throws.
 */
interface PubSubPort
{
    /**
     * Send $payload to everyone currently subscribed to $channel.
     *
     * @param  non-empty-string     $channel a dotted or slashed name, e.g.
     *                                       'documents.42' — the adapter owns
     *                                       its own separator conventions
     * @param  array<string, mixed> $payload JSON-encodable
     * @return int                  `>= 0` — the bus ACCEPTED it, carrying the
     *                              subscriber count when the backend reports
     *                              one. `-1` — it was NOT published: no adapter
     *                              is bound, the channel was refused, or the
     *                              publish failed.
     *
     * The `>= 0` / `-1` split is the only distinction a caller may rely on, and
     * it is a deliberate narrowing: an earlier version of this contract used
     * `-1` for "accepted, count unknown", which looks harmless until the first
     * adapter that cannot count. NATS core has no subscriber count at all, and
     * MQTT at QoS 0 has none either — so under that reading EVERY publish
     * through either of them reported failure to a caller doing the obvious
     * `>= 0` check, for publishes that worked perfectly.
     *
     * The cost of the narrowing is that `0` now means "accepted, and either
     * nobody was listening or this backend cannot say" — two things Redis can
     * tell apart and NATS cannot. Conflating them loses a diagnostic that is
     * unavailable on most backends anyway; the alternative loses the one answer
     * every caller needs. An adapter that does know puts the detail in
     * {@see self::describe()}.
     */
    public function publish(string $channel, array $payload): int;

    /**
     * Subscribe to $channels and BLOCK, invoking $onMessage per message.
     *
     * Blocking is the contract, not an implementation detail: this is the
     * server process's main loop, so it returns only when {@see self::stop()}
     * is called, the connection dies, or $onMessage throws. Calling it from
     * inside an HTTP request hangs that request until the client gives up.
     *
     * @param list<non-empty-string>                              $channels
     * @param callable(string $channel, array<string, mixed> $payload): void $onMessage
     * @throws \RuntimeException when the subscription cannot be established or
     *         is lost — an adapter must not silently return, because a
     *         subscriber that quietly stopped listening looks exactly like a
     *         system where nothing is happening
     */
    public function subscribe(array $channels, callable $onMessage): void;

    /**
     * Ask a running {@see self::subscribe()} to return.
     *
     * Safe to call from a signal handler or another coroutine. The loop is not
     * required to stop instantly — it stops at its next message or poll — so a
     * caller that needs a hard deadline must still kill the process.
     */
    public function stop(): void;

    /** Where this adapter publishes, for a status command. Never a credential. */
    public function describe(): string;
}
