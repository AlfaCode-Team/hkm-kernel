# Quick Start

Build and run your first HKM project in minutes. This guide walks you through creating a project, writing a route and controller, and understanding the module structure.

::: info Prerequisites
- [HKM installed](/guide/installation) and `hkm doctor` passing
- PHP 8.4.1+ with required extensions
- Basic PHP knowledge
:::

## Create a project

```bash
hkm new ~/apps/shop --project=shop
```

This scaffolds a complete project tree with entry points, config, and sample code, then registers it with the kernel.

```text
shop/
├── app/
│   ├── bootstrap/
│   │   ├── app.php              # builds the kernel: ports, security, modules
│   │   └── kernel-autoload.php  # finds the globally installed kernel
│   ├── public/                  # web root (PHP-FPM / php -S)
│   │   ├── index.php
│   │   └── .htaccess
│   ├── swoole/index.php         # OpenSwoole server
│   ├── cli/run.php              # console entry point (hkm cli ...)
│   └── worker/run.php           # queue worker entry point (hkm worker ...)
├── config/
│   ├── storage.php
│   ├── let-migrate.php
│   └── environments/            # migration connection config per environment
├── src/                         # your code, namespace Shop\
│   ├── Domain/Greeting.php
│   ├── Application/GreetingService.php
│   └── Infrastructure/Http/HomeController.php
├── resources/welcome.php        # view for the welcome page
├── var/                         # cache, logs, queue (generated at runtime)
├── userdata/
├── proj.json                    # routes, domains, essentials, route policy
├── composer.json                # autoload: Shop\ → src/, Plugins\ → plugins/
├── .env
└── .env.example
```

The project namespace is the StudlyCase of the project name: `--project=shop` gives `Shop\`.

## Run the dev server

```bash
hkm run shop
```

This starts PHP's built-in server on `http://127.0.0.1:8000` (`hkm run shop --swoole` runs the OpenSwoole server instead). Open it to see the welcome page served by `HomeController@index`.

## Write your first route

Open `proj.json` and add a route:

```json
{
  "name": "shop",
  "routes": [
    { "method": "GET", "path": "/",         "handler": "Shop\\Infrastructure\\Http\\HomeController@index" },
    { "method": "GET", "path": "/ping",     "handler": "Shop\\Infrastructure\\Http\\HomeController@ping" },
    { "method": "GET", "path": "/products", "handler": "Shop\\Infrastructure\\Http\\ProductController@index" }
  ]
}
```

The `handler` is the **fully qualified** class name, then `@`, then the method. Nothing is resolved relative to a namespace: a short name like `ProductController@index` fails when the route is requested (set `ROUTE_VERIFY_HANDLERS=1` in development to catch it at boot).

## Write a controller

Create `src/Infrastructure/Http/ProductController.php`:

```php
<?php declare(strict_types=1);

namespace Shop\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

final class ProductController
{
    public function index(Request $request): Response
    {
        $products = [
            ['id' => 1, 'name' => 'Widget', 'price' => 19.99],
            ['id' => 2, 'name' => 'Gadget', 'price' => 29.99],
        ];

        return Response::json($products);
    }
}
```

Refresh the browser and visit http://127.0.0.1:8000/products. You should see the JSON response.

### Routes resolve to what you declare

Routes are **declared in `proj.json`** or a plugin's `module.json`, never auto-discovered from controller namespaces or annotations. Under `hkm run` (PHP's built-in server) the kernel is rebuilt on every request, so a `proj.json` edit takes effect on the next request. Under OpenSwoole the kernel is built once per worker, so restart the server.

## Write a plugin (module)

A **plugin** is a self-contained unit solving one business domain. Your project's `composer.json` maps the `Plugins\\` namespace to the project's own `plugins/` directory, so a local plugin is just a folder there. Published plugins are installed with `hkm plugins`; see [Plugins](/modules/plugins).

Let's build a small task-tracking plugin:

```bash
# From the project root
mkdir -p plugins/Task/API/Contracts
mkdir -p plugins/Task/{Domain,Application/Services,Infrastructure/Http,Infrastructure/Persistence}
```

### Define the module

Create `plugins/Task/module.json`:

```json
{
  "name": "task",
  "version": "1.0.0",
  "solves": "task.management",
  "type": "module",
  "requires": [],
  "exposes": ["Plugins\\Task\\API\\Contracts\\TaskServiceContract"],
  "routePrefix": "/api/tasks",
  "routes": [
    { "method": "GET",  "path": "",           "handler": "Plugins\\Task\\Infrastructure\\Http\\TaskController@index" },
    { "method": "POST", "path": "",           "handler": "Plugins\\Task\\Infrastructure\\Http\\TaskController@create" },
    { "method": "GET",  "path": "/{id:num}",  "handler": "Plugins\\Task\\Infrastructure\\Http\\TaskController@show" }
  ]
}
```

Three things to notice:

- **Handlers are fully qualified.** A short `TaskController@index` cannot be resolved.
- **`requires` lists module domains, not ports.** This service touches no other module; once its repository needs the database, add `"database.management"` (the domain the default Database plugin solves). An unknown domain fails the boot.
- **No `auth` filter yet.** A default project ships the SecurityFilters plugin, so `"filters": ["auth"]` would work, but it answers 401 until a user is logged in, which would break the `curl` test below. Add it once authentication is wired up.

`POST /api/tasks` from `curl` is not blocked by CSRF, because the default bootstrap exempts `/api` from the `CsrfTokenLayer`.

### Define the service contract

Create `plugins/Task/API/Contracts/TaskServiceContract.php`:

```php
<?php declare(strict_types=1);

namespace Plugins\Task\API\Contracts;

interface TaskServiceContract
{
    public function index(): array;
    public function find(string $id): array;
    public function create(string $title, string $description): array;
}
```

### Implement the service

Create `plugins/Task/Application/Services/TaskService.php`:

```php
<?php declare(strict_types=1);

namespace Plugins\Task\Application\Services;

use Plugins\Task\API\Contracts\TaskServiceContract;

final class TaskService implements TaskServiceContract
{
    public function index(): array
    {
        // For now, return static data. A real implementation
        // would query the database via TaskRepository.
        return [
            ['id' => '1', 'title' => 'Buy milk'],
            ['id' => '2', 'title' => 'Fix the bug'],
        ];
    }

    public function find(string $id): array
    {
        return ['id' => $id, 'title' => 'Buy milk'];
    }

    public function create(string $title, string $description): array
    {
        return ['id' => '3', 'title' => $title, 'description' => $description];
    }
}
```

### Wire it together (Provider)

Create `plugins/Task/Provider.php`:

```php
<?php declare(strict_types=1);

namespace Plugins\Task;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use Plugins\Task\API\Contracts\TaskServiceContract;
use Plugins\Task\Application\Services\TaskService;

class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'task.management';
    }

    public function requires(): array
    {
        return [];
    }

    public function exposes(): array
    {
        return [TaskServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bind(TaskServiceContract::class, fn() =>
            new TaskService()
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Register pipeline hooks or event listeners here
    }
}
```

### Write a controller

Create `plugins/Task/Infrastructure/Http/TaskController.php`:

```php
<?php declare(strict_types=1);

namespace Plugins\Task\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Task\API\Contracts\TaskServiceContract;

final class TaskController
{
    public function __construct(private readonly TaskServiceContract $service) {}

    public function index(): Response
    {
        return Response::json($this->service->index());
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json($this->service->find($id));
    }

    public function create(Request $request): Response
    {
        $data = $this->service->create(
            $request->input('title', ''),
            $request->input('description', '')
        );
        return Response::json($data, 201);
    }
}
```

### Enable the plugin in the project

Edit `app/bootstrap/app.php` and add the provider to the existing `withModules([...])` list:

```php
->withModules([
    // ... other modules ...
    Plugins\Task\Provider::class,
])
```

## Run and test

With `hkm run`, the next request rebuilds the kernel and picks the plugin up, so no restart is needed. If `Plugins\\Task\\Provider` is reported as not found, regenerate the autoloader (`hkm ppkg dump-autoload`, or `composer dump-autoload`).

Now test your new routes:

```bash
curl http://127.0.0.1:8000/api/tasks

# Output:
# [
#   {"id":"1","title":"Buy milk"},
#   {"id":"2","title":"Fix the bug"}
# ]
```

## The layer structure

Every HKM module follows a strict vertical layering:

```
plugins/Task/
├── API/Contracts/          Published interfaces (other modules import only these)
├── Domain/                 Pure business logic (zero external imports)
├── Application/Services/   Transaction + event orchestration
├── Infrastructure/         
│   ├── Persistence/        Database access (DatabasePort only)
│   ├── Gateways/          Vendor SDKs (external APIs only)
│   └── Http/              Controllers (≤3 lines: DTO → service → Response)
└── Provider.php           DI wiring
```

Each layer has one job and talks only to layers below it:

- **Domain** has no dependencies except other Domain classes
- **Application/Services** orchestrates domain logic and talks to Infrastructure
- **Infrastructure** talks to ports (DatabasePort, MailPort, StoragePort, …)
- **Controllers** call services and translate to responses

This isolation means you can test services without mocking the entire framework, and replace a database driver by rebinding the port — never touching business code.

## Next steps

- **[Directory structure](/guide/directory-structure)** — Understand the full project layout
- **[Request lifecycle](/guide/request-lifecycle)** — See how a request flows through the kernel
- **[Modules](/modules/module-contract)** — Deep dive into the module contract and configuration
- **[Routing](/routing/basics)** — Learn route declaration, parameters, and groups
- **[Services](/layers/service)** — Write transaction-safe services with domain events

## Source

- [README.md — Your first project](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/README.md#your-first-project)
- [src/System/GlobalKernelProjectScaffolder.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/System/GlobalKernelProjectScaffolder.php) — What `hkm new` generates
- [templates/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/templates) — Scaffold templates
