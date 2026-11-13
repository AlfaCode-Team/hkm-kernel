# WAF — Application firewall

`WafLayer` is an opt-in security layer that inspects a request in the gateway, before any module loads. A refused request costs a few regex passes and nothing else: no dependency graph, no container, no controller.

It is one of two layers the kernel ships (the other is [`CsrfTokenLayer`](/security/csrf)). IP filtering and rate limiting are deliberately **not** kernel layers — they are opt-in route filters from the SecurityFilters plugin, so a route pays for them only when it declares them.

## Enabling it

List it **first** in `withSecurity()`. It is the cheapest layer, and a scanner should be refused before anything more expensive runs.

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\WafLayer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\WafMode;

Kernel::configure()
    ->withSecurity([
        new WafLayer(
            mode:            WafMode::Monitor,                    // log only, let through
            exemptPaths:     ['/api/webhooks'],                   // never inspected
            ruleExemptPaths: ['injection' => ['/admin/posts']],   // one rule off for an editor
            trustedIps:      ['10.0.0.0/8'],                      // exact or CIDR, v4/v6
            banAfter:        0,                                   // strikes before an IP ban; 0 = never
        ),
        new CsrfTokenLayer(/* … */),
    ]);
```

::: warning Start in Monitor
The constructor's default is `WafMode::Block`. On a live site, pass `WafMode::Monitor` first, read the log for a few days, and only then switch. A new rule set meeting real traffic for the first time will refuse something you did not expect.

::: danger Monitor mode logs nothing unless `LoggerPort` is bound in the CORE container
On a project scaffolded by `hkm new`, the WAF writes **no log line at all** — in `Monitor` *or* `Block` mode. `WafLayer::report()` returns early when it cannot resolve a `LoggerPort`, and the scaffold binds none in `withPorts()`: the Logger plugin binds `LoggerPort` into the **module** container, while a security layer resolves ports from the **core** container at materialize.

So "run Monitor and read the log" silently produces an empty log, and `Block` mode refuses requests without recording which rule fired. Bind it in the bootstrap:

```php
->withPorts([
    LoggerPort::class => static fn() => \Plugins\Logger\Provider::fromConfig(),
])
```

```
warning: waf.blocked {"rule":"probe","signature":"wordpress","location":"path", …}
```

The same silence affects `EventBus` listener failures and `ServerContext::info()`, for the same reason.
:::
:::

## The default rules

Ordered cheapest first, so the common case exits early.

| Rule | What it matches |
|---|---|
| `malformed` | NUL and control bytes, invalid UTF-8, `Content-Length` together with `Transfer-Encoding`, too many inputs |
| `traversal` | `..` segments, climbs to system directories, `php://` and similar wrappers (the wrapper signature is matched in the **query string only**, not the path) |
| `probe` | dotfiles, WordPress paths, phpMyAdmin, `vendor/phpunit`, `.sql` and `.bak` files |
| `injection` | SQLi, XSS, Log4Shell and PHP-code signatures |

`injection` inspects the path, query string, `User-Agent` and `Referer`. It inspects the **body** only when the rule itself is constructed with `inspectBody: true`.

::: warning `inspectBody` is a RULE parameter, not a layer parameter
`new WafLayer(inspectBody: true)` is a fatal error — `Unknown named parameter $inspectBody`. The flag belongs to `InjectionSignatureRule`, so turning it on means replacing that one rule in the set:

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Rules\InjectionSignatureRule;

new WafLayer(rules: [
    ...WafLayer::defaultRules(),               // keeps malformed / traversal / probe
    new InjectionSignatureRule(inspectBody: true),
]);
```
:::

Pass your own set with `rules:` — any `WafRuleContract` works, and `WafLayer::defaultRules()` returns the built-ins so you can extend rather than replace:

```php
new WafLayer(rules: [...WafLayer::defaultRules(), new MyRule()]);
```

## Four behaviours worth knowing

### It fails open

A rule that throws, or a cache that cannot be reached, logs `waf.error` and lets the request through. A firewall whose cache outage takes the whole site down has become the attack it was installed to prevent.

### The 403 never names the rule

The response says only that the request was refused. The log entry (`waf.blocked`, at warning level) carries the rule, the matched field and the client address. Naming the rule in the response tells a scanner exactly which probe to vary.

### Bans are off by default, on purpose

`banAfter: 0` means never. Carrier-grade NAT puts thousands of mobile users behind a single address, so an IP ban on a consumer-facing site can remove a whole mobile network. Enable it only where clients have their own addresses. IPv6 clients are banned by their `/64`; behind a CDN, bans need the real client address restored first.

This is **not** rate limiting. It counts only requests that already matched an attack signature. For rate limiting, declare the `throttle` route filter.

### Every rule must stay linear

A query string of 8 KB of `../` once cost 0.6 s per request through a nested-quantifier regex — which turns the firewall itself into the denial of service. Each rule is gated on a byte it requires, prefers `str_contains` on the rarest literal, and avoids leading character classes and `(?:x+)*`.

`WafRulesTest::test_rules_stay_linear_on_hostile_input` pins this. Extend its units when you add a signature.

## Ports arrive through the container

Security layers are constructed in the bootstrap, before the core container exists. A layer that wants a `CachePort` or `LoggerPort` implements `PortAwareLayerContract`:

```php
interface PortAwareLayerContract
{
    public function usePorts(\Psr\Container\ContainerInterface $ports): void;
}
```

The parameter is a PSR-11 container, **not** `CoreContainer`. Implementing the narrower type is a fatal:

```
Declaration of MyLayer::usePorts(CoreContainer $core): void must be compatible with
PortAwareLayerContract::usePorts(ContainerInterface $ports): void
```

The kernel calls it at materialize, so the layer resolves the **same** singletons every module uses. Without it, a layer would construct a second cache adapter and a ban recorded by one worker would be invisible to the others. Explicit `cache:` and `logger:` constructor arguments still win.

## What it is not

- **Not the SQLi or XSS defence.** Bound parameters and output escaping are. This is depth behind them.
- **Not a body scanner by default.** Turning on `inspectBody` application-wide when any route accepts rich text will refuse legitimate content; exempt that rule per path instead.
- **Not stateful.** Rules are app-lifetime and shared across requests. Never store request state on a `WafRuleContract` — under OpenSwoole it would leak between requests.
