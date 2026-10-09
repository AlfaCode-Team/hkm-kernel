---
layout: home

hero:
  name: "HKM Kernel"
  text: "A modular PHP 8.4+ framework built on the Gated Demand Architecture"
  tagline: "Security before modules. Load only what you need. Enforce isolation at runtime."
  actions:
    - theme: brand
      text: "Get Started"
      link: /guide/introduction
    - theme: alt
      text: "Architecture"
      link: /architecture/gda
    - theme: alt
      text: "GitHub"
      link: "https://github.com/AlfaCode-Team/hkm-kernel"

features:
  - title: "Zero-Cost Denial"
    details: "Security gates requests before any module loads. Denied requests cost zero initialization and zero database queries — the cheapest possible rejection."
    link: /security/gateway
  - title: "Per-Request Loading"
    details: "Only the modules a route actually needs are wired in. Unused modules never pay to initialize, keeping latency predictable."
    link: /architecture/module-loading
  - title: "Runtime-Enforced Isolation"
    details: "One module, one domain. The container throws if modules try to access each other's internals — violations surface in tests, not production."
    link: /architecture/gda
  - title: "Ports & Adapters"
    details: "The kernel defines contracts (DatabasePort, CachePort, etc.); your project provides implementations. Swap MySQL for PostgreSQL without touching domain code."
    link: /infrastructure/ports
  - title: "FPM & OpenSwoole"
    details: "Runs on PHP-FPM with BOOT_CACHE optimization (86× faster than uncached), and under OpenSwoole with per-worker builds and graceful request isolation."
    link: /deployment/production
  - title: "Native CLI"
    details: "The hkm launcher is a cross-platform native binary built with Zig. Install like a Go binary — no Composer required to get started."
    link: /cli/cli-pipeline
---

## Quick Taste

Declare a route once — it lives in data, not PHP code:

```json
{
  "routes": [
    { "method": "POST", "path": "/api/invoices", "handler": "Shop\\Http\\InvoiceController@create" }
  ]
}
```

Write a controller in three lines — just orchestration:

```php
<?php
declare(strict_types=1);
namespace App\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use App\Invoices\API\Contracts\InvoiceServiceContract;

final class InvoiceController
{
    public function __construct(
        private InvoiceServiceContract $invoices,
    ) {}

    public function create(Request $request): Response
    {
        $dto    = CreateInvoiceDTO::fromRequest($request);
        $result = $this->invoices->create($dto);
        return Response::json($result->toArray(), 201);
    }
}
```

The service handles transactions, domain events, and rollback. The repository speaks only to the database. The domain layer stays pure and dependency-free. The framework enforces all of this at runtime.

## Where to Go Next

- **[Introduction](/guide/introduction)** — What HKM is and when you need it.
- **[Installation](/guide/installation)** — Get `hkm` installed and create your first project.
- **[Gated Demand Architecture](/architecture/gda)** — The six core principles that drive every decision.
- **[Request Lifecycle](/guide/request-lifecycle)** — How a request flows through the security gate, pipelines, and modules.
- **[Modules](/modules/module-contract)** — Understand `module.json`, the `ModuleContract`, and how modules work.
- **[HTTP Layer](/http/request)** — Working with immutable requests and typed responses.
- **[Testing](/testing/)** — Test patterns for domain logic, services, and full integration.
- **[Deployment](/deployment/production)** — Running on PHP-FPM or OpenSwoole in production.
