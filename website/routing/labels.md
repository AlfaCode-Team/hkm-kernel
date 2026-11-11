# Route labels — audit, metric, emits

A route can declare what should be recorded when it succeeds, and the kernel does the recording. The handler carries none of it.

```jsonc
{ "method": "POST", "path": "/orders", "handler": "…\\OrderController@create",
  "audit":  "order.created",
  "metric": ["orders.created"],
  "emits":  ["order.created"] }
```

| Label | Delivered to | Installed by |
|---|---|---|
| `audit` | whatever is bound to `AuditLabelSink` | an audit plugin |
| `metric` | the request-scoped accumulator on the `usage` request attribute | a metering plugin |
| `emits` | `EventBus`, as a `RouteEmittedEvent` | the kernel |

Labels work in `module.json` and `proj.json`, and inherit through [route groups](/routing/groups) like any other key.

## When a label is applied

`RouteLabelStage` sits at the innermost `after.execute` position — directly in front of `ExecuteStage`, behind every registered hook. That placement lets it see both the matched route entry and the controller's final `Response`.

A label is applied only when the handler **ran** and returned a status **below 400**.

It is not applied when:

- the route never matched;
- a security layer denied the request;
- an `auth` or `throttle` filter refused it;
- the handler returned an error status, or threw;
- the request was a `HEAD`.

::: tip That is a decision, not an accident of position
A label names the thing the route *does* — `order.created`. A 401, a 429 or a 422 means it did not happen, so counting or auditing or announcing it would make the label lie.

Refusals are not lost. `ObservabilityStage` wraps the whole pipeline, sees the matched route template for any request that got that far, and counts every request with its status as `http.server.requests{route,status}`. Denials are measured **there**, once, rather than duplicated here under a name that means something else.

`HEAD` is skipped because it is served by the `GET` route as a courtesy and is not the action the label describes.
:::

## Absent sinks are inert

The kernel cannot require a plugin. A label whose sink is not installed does nothing — never an error, never a changed response. It is logged at **debug** level, so *"why is nothing being recorded?"* has somewhere to start.

A sink that **throws** is caught and logged at warning level. The work being observed has already succeeded, and observing it must not turn that into a 500.

## audit

The sink is resolved from the request container:

```php
interface AuditLabelSink
{
    public function record(string $action, array $meta): void;
}
```

The kernel builds `$meta` from route identifiers only:

| Key | Value |
|---|---|
| `method` | the request method |
| `route` | the matched route template |
| `status` | the response status |
| `correlation_id` | the request's correlation id |
| `param.<name>` | each route parameter |

Every value is clipped to **256 characters**, because a path parameter is client-controlled. No credential reaches this path.

An implementation should stay thin: everything that makes an audit row correct — filling the actor, redacting the metadata — belongs in the audit service, not in the sink. `hkm-plugin-audit` ships `RouteLabelAuditSink`, which forwards only `$action` and `$meta` for exactly that reason.

## metric

The sink is the request-scoped accumulator a metering plugin puts on the request as the `usage` attribute. The contract is one method:

```php
public function count(string $metric, int $by = 1): void;
```

It is checked **structurally** rather than by class, because the kernel names no plugin class. A `\Countable` is excluded: its `count()` takes no name and is not this.

## emits

Dispatched on the `EventBus` as a `RouteEmittedEvent` carrying the event name, route template, method, status, route parameters, user and tenant ids, correlation id, and an RFC3339 timestamp.

`EventBus` isolates and logs listener failures itself, but only when it was built with a logger. The stage says so too: a listener that never ran is otherwise indistinguishable from an event nobody subscribed to.

## Cost when unused

A route with no labels costs one request-attribute lookup and nothing else. The `!is_array($labels)` guard is a **performance** guard, not a behavioural one — revert-checked, removing it changes no test result, because the loops iterate nothing when there are no labels. What it saves is a container lookup for `LoggerPort` on every unlabelled request.

The status and `HEAD` guards **are** behavioural, and are pinned by tests.
