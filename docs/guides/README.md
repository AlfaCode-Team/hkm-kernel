# HKM Kernel — Guides

Layer-by-layer guides to the **Gated Demand Architecture (GDA)** kernel and its first-party
plugins. Start with the overview, then dive into the layer you're working in.

> New here? Read the [project README](../../README.md) first for the big picture, install
> steps, and a full end-to-end feature walkthrough.

## Architecture & lifecycle

| Guide | What it covers |
|---|---|
| [00 · Overview](00_SENTINEL_OVERVIEW.md) | Full architecture + the request lifecycle |
| [01 · Kernel](01_KERNEL.md) | Boot pipeline, materialization, the fluent builder |
| [02 · Module](02_MODULE.md) | Module contract, `module.json`, on-demand loading |
| [11 · Project](11_PROJECT.md) | Project layer — wiring, domain resolution, bootstrap |

## The layers

| Guide | Layer |
|---|---|
| [03 · Domain](03_DOMAIN.md) | Entities, value objects, domain events (zero external imports) |
| [04 · Service](04_SERVICE.md) | Transaction + event orchestration (the mandatory shape) |
| [05 · Repository](05_REPOSITORY.md) | `DatabasePort` only; translate every `\PDOException` |
| [06 · Gateway](06_GATEWAY.md) | Vendor SDKs only; translate vendor exceptions |
| [07 · Controller](07_CONTROLLER.md) | ≤3-line actions, DTO validation, base controllers |
| [08 · Events](08_EVENTS.md) | Domain vs. integration events, the EventBus |

## Cross-cutting

| Guide | Topic |
|---|---|
| [09 · Security](09_SECURITY.md) | SecurityGateway, Identity, layers |
| [21 · CSRF](21_CSRF.md) | `CsrfTokenLayer` — HMAC-token CSRF |
| [10 · Testing](10_TESTING.md) | Port fakes, service tests |
| [12 · Worker](12_WORKER.md) | Worker pipeline, jobs, retry strategies |
| [13 · Anti-patterns](13_ANTIPATTERNS.md) | Wrong/correct code pairs |
| [15 · Error handling](15_ERROR_HANDLING.md) | ErrorGuard + ErrorPipeline, notifiers |

## CLI & data

| Guide | Topic |
|---|---|
| [14 · CLI](14_CLI.md) | CLI pipeline, `AbstractCommand` |
| [17 · php-io-cli](17_PHP_IO_CLI.md) | The interactive terminal component library |
| [18 · Migrations](18_MIGRATIONS.md) | LetMigrate engine, migrations, seeders |
| [19 · Database](19_DATABASE.md) | Multi-driver `DatabasePort`, connections |
| [22 · Data access blueprint](22_DATA_ACCESS_ORM_BLUEPRINT.md) | Repository/hydrator/entity mapping, portable SQL |
| [27 · Entity support](27_ENTITY_SUPPORT.md) | Casting engine, hydrator, the Entity base |

## Plugins

| Guide | Plugin |
|---|---|
| [16 · Plugins](16_PLUGINS.md) | The `plugins/` convention + local-module checklist |
| [20 · First-party plugins](20_FIRST_PARTY_PLUGINS.md) | The bundled plugin catalogue |
| [23 · Tenancy](23_TENANCY.md) | Multi-tenant routing, membership, invitations |
| [24 · User](24_USER.md) | Central identity store, outbox, audit log |
| [25 · Auth](25_AUTH.md) | JWT / PAT / session issuance + verification |
| [26 · OAuth2](26_OAUTH2.md) | OAuth 2.1 + OIDC authorization server |

Each first-party plugin also ships its own README under `plugins/<Name>/`.
