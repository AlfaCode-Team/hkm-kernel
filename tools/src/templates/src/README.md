# `src/` — project code (hexagonal / ports & adapters)

This directory holds the code that is specific to **this** project (namespace
`{{STUDLY}}\`). It is organised as a lightweight **hexagonal architecture** so the
business logic stays independent of the framework, the database, and the web.

```
src/
├── Domain/            ← the core. Pure business model & rules.
│                        NO framework, NO I/O, NO external imports. The centre
│                        of the hexagon — everything points inward to it.
│
├── Application/       ← use cases. Orchestrates the domain to do one thing.
│   └── Ports/           Depends only on Domain + Ports (interfaces it needs).
│                        Ports are the "holes" in the hexagon — the app declares
│                        WHAT it needs (e.g. a GreetingRepository); it does not
│                        care HOW it is provided.
│
└── Infrastructure/    ← adapters. The outside world plugged into the ports.
    ├── Http/            DRIVING adapters (controllers) call INTO the application.
    └── Persistence/     DRIVEN adapters (repositories, gateways) implement the
                         Application's Ports using the framework's ports
                         (DatabasePort, HttpClientPort, ...).
```

## The dependency rule

Dependencies only point **inward**: Infrastructure → Application → Domain.
The Domain never imports Application or Infrastructure; the Application never
imports Infrastructure (it depends on its own Port **interfaces**, which the
Infrastructure implements). This is what keeps the core testable in isolation.

## The example slice in this scaffold

```
GET /  →  Infrastructure\Http\HomeController   (driving adapter)
              └─ Application\GreetingService    (use case)
                     └─ Domain\Greeting         (pure domain value object)
```

The controller is autowired by the kernel from the request container, and it
type-hints `GreetingService` — so dependency injection wires the slice for you.

## Adding persistence (when you need a database)

1. Define a **port** the use case needs — an interface in `Application/Ports/`,
   e.g. `interface GreetingRepository { public function save(Greeting $g): void; }`
2. Implement a **driven adapter** in `Infrastructure/Persistence/` that fulfils
   it using the framework's `DatabasePort`.
3. Bind the interface to the adapter in `app/bootstrap/app.php` (or, for heavier
   domains, promote the code into a proper plugin under `plugins/`).

Keep controllers thin (DTO → service → Response); keep real logic in the
Application/Domain layers; let Infrastructure be the only place that knows about
frameworks and vendors.
