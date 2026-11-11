# PubSubPort — Fan-out messaging

`PubSubPort` delivers a message to **every** subscriber. [`QueuePort`](/background/workers-and-jobs) delivers it to **exactly one**.

```php
interface PubSubPort
{
    public function publish(string $channel, array $payload): int;   // subscribers reached
    public function subscribe(array $channels, callable $onMessage): void;
    public function stop(): void;
    public function describe(): string;
}
```

## Why this is not QueuePort

The two look interchangeable, and a queue is usually already configured — which is exactly what makes this worth spelling out.

A queue delivering each message to one consumer is **correct for work**: two workers must not both charge the same card. It is **precisely wrong for notification**.

Consider a realtime server running four worker processes. Each holds a quarter of the connected clients. An event raised while handling an HTTP request has to reach all four, because the server does not know which worker holds the client that cares.

Publish it to a queue and exactly one worker receives it. A quarter of the clients see the update; three quarters never do, at random, with no error anywhere.

::: danger It reproduces only under more than one worker
Which is to say: never in development. This is the failure mode this port exists to prevent.
:::

So a queue is point-to-point and durable; a bus is one-to-many and ephemeral. An application generally needs both, and they are different ports.

## What an adapter may promise

### At-most-once, deliberately

A subscriber that is not connected when a message is published does not receive it, and nothing replays it later.

Every bus this port is written for behaves that way by default — Redis pub/sub, NATS core, MQTT at QoS 0 — so a port promising more would be a port no adapter could honour. Anything that must survive a disconnect belongs in a queue or a table, with the bus used only to say *"something changed, come and look"*.

### No ordering across channels

Within one channel an adapter should preserve publish order. Across channels nothing is guaranteed: the backends shard by channel, and a cross-channel guarantee would mean serialising them.

### publish() must not throw

It is called from inside a request, to report something that has **already** happened and been committed. A bus outage must cost the notification, never the response.

`subscribe()` is the opposite — it *is* a server's main loop, so a broken connection there is the one thing the operator needs to hear about, and it throws.

## NullPubSub

The default when no adapter is bound, so a call site can publish unconditionally instead of guarding. A guard at every call site is how events come to be emitted on some paths and not others.

```php
NullPubSub::instance();   // shared; holds no state
```

The asymmetry is the useful part:

| Method | Behaviour | Why |
|---|---|---|
| `publish()` | no-op | An application without realtime should not fail for emitting an event nothing is listening to |
| `subscribe()` | **throws** | A server whose whole job is to subscribe, started against no bus, has nothing to do and must say so |

Without that throw, such a server would sit there looking healthy, accept connections, and deliver nothing to any of them.

## Adapters

The kernel ships none. Two first-party plugins implement this port:

| Plugin | `solves` | Transport |
|---|---|---|
| `hkm-plugin-mqtt` | `pubsub.mqtt` | Pure-PHP MQTT client; QoS 0/1/2, retained messages, last will |
| `hkm-plugin-nats` | `pubsub.nats` | NATS core, with subject wildcards and queue groups |

`hkm-plugin-nats` also declares the broker it needs through [`daemon[]`](/background/daemons), so `daemon:install` provisions `nats-server` against a checksum pinned in the plugin's own manifest.
