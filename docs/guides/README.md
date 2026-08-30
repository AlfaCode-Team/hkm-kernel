# HKM Kernel — Guides

Layer-by-layer guides to the **Gated Demand Architecture (GDA)** kernel. Start
with the overview, then dive into the layer you're working in.

> **Scope.** These guides cover the kernel (`src/`) and the first-party packages
> it runs on (`modules/`) — and nothing else. Everything outside that documents
> itself; see [Not documented here](#not-documented-here) at the bottom.

> New here? Read the [project README](../../README.md) first for the big picture,
> install steps, and a full end-to-end feature walkthrough.

## Architecture & lifecycle

| Guide | What it covers |
|---|---|
| [00 · Overview](00_SENTINEL_OVERVIEW.md) | Full architecture + the request lifecycle |
| [01 · Kernel](01_KERNEL.md) | Boot pipeline, materialization, the fluent builder |
| [02 · Module](02_MODULE.md) | Module contract, `module.json`, on-demand loading |
| [16 · Plugins](16_PLUGINS.md) | How the kernel loads a module from `plugins/` |

## The layers

| Guide | Layer |
|---|---|
| [03 · Domain](03_DOMAIN.md) | Entities, value objects, domain events (zero external imports) |
| [04 · Service](04_SERVICE.md) | Transaction + event orchestration (the mandatory shape) |
| [05 · Repository](05_REPOSITORY.md) | `DatabasePort` only; translate every `\PDOException` |
| [06 · Gateway](06_GATEWAY.md) | Vendor SDKs only; translate vendor exceptions |
| [07 · Controller](07_CONTROLLER.md) | ≤3-line actions, DTO validation, `RequestAware` |
| [08 · Events](08_EVENTS.md) | Domain vs. integration events, the EventBus |

## Cross-cutting

| Guide | Topic |
|---|---|
| [09 · Security](09_SECURITY.md) | SecurityGateway, `SecurityVerdict`, `Identity` |
| [21 · CSRF](21_CSRF.md) | `CsrfTokenLayer` — HMAC-token CSRF |
| [10 · Testing](10_TESTING.md) | Port fakes, service tests |
| [12 · Worker](12_WORKER.md) | Worker pipeline, jobs, retry strategies |
| [13 · Anti-patterns](13_ANTIPATTERNS.md) | Wrong/correct code pairs |
| [15 · Error handling](15_ERROR_HANDLING.md) | ErrorPipeline, classifier, notifiers |
| [30 · Routing cookbook](30_ROUTING_COOKBOOK.md) | 13 worked recipes, each compiled with its real output |

## CLI & data

| Guide | Topic |
|---|---|
| [14 · CLI](14_CLI.md) | CLI pipeline, `AbstractCommand` |
| [22 · Data access blueprint](22_DATA_ACCESS_ORM_BLUEPRINT.md) | Repository/hydrator mapping, portable SQL, no vendor ORM |

## `modules/` — the packages the kernel runs on

| Guide | Package |
|---|---|
| [17 · php-io-cli](17_PHP_IO_CLI.md) | `alfacode-team/php-io-cli` — the interactive terminal component library |
| [18 · Migrations](18_MIGRATIONS.md) | `alfacode-team/let-migrate` — schema engine, migrations, seeders |

The other three (`phpshots/bind-it`, `phpshots/common-type-alias`,
`alfacode-team/http`) document themselves in their own submodules.

## Operations

| Guide | Topic |
|---|---|
| [Safe deployments](SAFE_DEPLOYMENTS_GUIDE.md) | Release and rollback runbooks |

## Not documented here

| Subject | Where |
|---|---|
| The `Project\` layer (`projects/`) | [hkm-project-layer](https://github.com/AlfaCode-Team/hkm-project-layer) |
| Any plugin — behaviour, API, config | that plugin's own repository: `README.md`, `CLAUDE.md`, and `module.json` as the authority |
| Which plugin claims a `solves` domain | `hkm plugins domains` — live, and never stale |
| The `hkm` CLI, bundling, installers | [`tools/README.md`](../../tools/README.md), [`tools/docs/hkm-cli-usage.md`](../../tools/docs/hkm-cli-usage.md) |
| Per-project frontend, `hkm ui`, surfaces | [`templates/frontend/docs/HOW_IT_WORKS.md`](../../templates/frontend/docs/HOW_IT_WORKS.md) |

This is deliberate. A copy of someone else's documentation living in the kernel
is the copy that goes stale, and it did: the catalogue this repo used to carry
recorded a plugin's `solves` domain wrongly, claimed another had no dependencies,
and understated four plugins' `requires[]`. The manifest is the authority.
