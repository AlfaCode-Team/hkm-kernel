# Introduction to HKM Kernel

HKM Kernel is a modular PHP 8.4+ service framework built on the Gated Demand Architecture—a design pattern that puts security first and only loads the modules a request actually needs. This guide covers what HKM is, how it differs from other frameworks, and what the architecture looks like.

## What HKM Kernel is

HKM (pronounced "hiking") is a **micro-kernel framework** for building resilient, secure PHP services. It ships as both a PHP library and a **native cross-platform CLI** (`hkm`) built with Zig. You install it like a binary—no Composer bootstrapping—and use it to scaffold projects, run migrations, and manage plugins.

The kernel itself is small: it knows about request pipelines, dependency injection, security gates, and event buses. Everything else—authentication, validation, templating, mail—ships as first-party plugins that you opt into. Each plugin owns exactly one business domain, and the kernel enforces isolation between them at runtime.

## The problem HKM solves

Most PHP frameworks boot the **entire application** before deciding what a request needs. That means:

- An unauthenticated request that should cost nothing still pays the cost of constructing half your app.
- Domain boundaries live only in code reviews, not in the runtime.
- Adding a new module touches the global application state, which couples everything.

HKM inverts this problem with the **Gated Demand Architecture (GDA)**:

```
Security is the first gate.           An unauthenticated request costs zero wiring.
Load only what a route uses.          Every module is resolved on-demand per request.
Isolation is enforced at runtime.     Cross-module access throws, not lints.
```

The result is a framework where the fast, secure, well-bounded way to build something is also the easy way.

## Core philosophy

| Principle | What it means |
|---|---|
| **Security before everything** | A `SecurityGateway` runs before any module loads. A denied request costs *zero* module construction. |
| **Load only what is needed** | Only modules required for this specific request are wired in, resolved from a per-request dependency graph. |
| **One module, one domain** | Every module owns exactly one bounded business domain. No exceptions. |
| **Isolation by default** | Modules cannot access each other's internals. Request-scoped containers enforce this at runtime. |
| **Infrastructure independence** | The kernel defines *port* interfaces; the project supplies implementations (MySQL, Redis, S3, SMTP, …). |
| **Explicit over implicit** | Everything is declared in `module.json`. Nothing is auto-discovered at runtime. |

## The three worlds

HKM organizes code into three layers:

```
┌─────────────────────────────────────────────┐
│  PROJECT LAYER  (wiring only)               │
│  ┌─────────────────────────────────────────┐ │
│  │  MODULE LAYER  (business domains)       │ │
│  │  ┌───────────────────────────────────┐  │ │
│  │  │  KERNEL                           │  │ │
│  │  │  (boot · security · loading · DI  │  │ │
│  │  │   · pipelines · events · ports)   │  │ │
│  │  └───────────────────────────────────┘  │ │
│  └─────────────────────────────────────────┘ │
└─────────────────────────────────────────────┘
```

**Kernel** — knows nothing about your domains and changes rarely. It defines how modules are loaded, secured, and wired together.

**Module** — a self-contained unit that solves one business problem and declares what it needs and what it offers. The kernel loads modules on-demand per request.

**Project** — pure wiring. It declares which modules are active, what infrastructure ports they use, and what routes it exposes. It contains no business logic.

Each layer only talks to the layers below it. A module never imports another module's internals—only published contracts. The project wires everything together but adds no logic of its own.

## What is not included (and why)

This is **not Laravel, Symfony, or Slim**. HKM borrows none of their conventions:

- **No globals or facades.** Everything is explicitly injected.
- **No auto-discovery.** Routes, config, commands, and event listeners are all declared in `module.json`.
- **No implicit defaults.** A missing configuration key fails the boot, not at request time.
- **No vendor coupling in the kernel.** The kernel stays dependency-free; plugin adapters supply the infrastructure.

If you expect Magic::method() to work, or routes auto-discovered from controller namespaces, or a config file that auto-loads because it sits in a particular folder, HKM will disappoint you. The tradeoff is predictability: you always know what runs and why.

## Project status

HKM is under **active development**. The architecture and core plugins are in daily use, and releases are cut regularly.

### Stable & complete

- The GDA kernel (boot, security, DI, HTTP/CLI/Worker pipelines, events, ports)
- The five access rules, enforced at runtime
- Native distribution (`hkm` launcher, Zig-built, published as `.deb`, macOS `.app`, Windows `.zip`)
- Forty-plus first-party plugins covering auth, users, tenancy, OAuth2, mail, storage, session, validation, and more
- Multi-project, multi-tenant hosting with domain-based routing
- Multi-driver database engine (MySQL, PostgreSQL, SQLite, SQL Server) with LetMigrate migrations
- Frontend federation with per-project surfaces and the Pageflow SPA bridge

### Still cooking

- **Dependency-free HTTP core.** The kernel's `Request`/`Response` currently build on Symfony's `http-foundation` as a deliberate, temporary choice. They are being reimplemented as pure value objects so the kernel carries no vendor coupling. Build against the kernel's own API (`$request->input()`, `Response::json()`, …) and the switch will be non-breaking.
- **API surface hardening.** Some plugin contracts and config keys are still settling. Pin your version and read the [CHANGELOG](/reference/changelog) before upgrading.
- **Docs.** This website is being expanded from the layer guides.
- **Test coverage.** PHPStan (level 5) and PHPUnit are CI gates; depth is still growing.

## Next steps

- **[Installation](/guide/installation)** — Get HKM running on your machine and verify the environment.
- **[Quick start](/guide/quick-start)** — Create your first project and write a simple feature.
- **[Request lifecycle](/guide/request-lifecycle)** — Walk through how a request flows through the kernel.
- **[Architecture overview](/architecture/gda)** — Deep dive into the Gated Demand Architecture.

## Source

- [README.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/README.md) — Project overview
- [Kernel.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Kernel.php) — The fluent builder entry point
