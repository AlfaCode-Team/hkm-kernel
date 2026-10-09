# CliPipeline — Command Execution Runtime

The `CliPipeline` is the kernel's CLI engine. It wraps the `php-io-cli` `CLIApplication` and orchestrates command registration, dependency injection, and error handling for console commands.

## Overview

When you run `hkm cli <command>` (or `php app/cli/run.php <command>`) in a project, the CliPipeline:

1. Initializes the `CLIApplication` (the underlying CLI dispatcher)
2. Loads every command registered by modules via their `Provider::boot()`
3. Parses argv and executes the matching command
4. Catches exceptions and routes them through the `ErrorPipeline` for centralized logging

Commands extend `AbstractCommand` and implement two methods: `configure()` (register arguments/options) and `handle()` (do the work).

## Architecture

```
Kernel.cli()
  ↓
CliPipeline.run(argv)
  ├─ Materialize: build all registered commands
  ├─ CLIApplication.run(argv)
  │   ├─ Parse arguments/options
  │   ├─ Resolve command by name
  │   └─ Call command.execute()
  ├─ Catch Throwable → ErrorPipeline
  └─ Return exit code
```

## Registration

Modules register commands during their bootstrap phase:

```php
class Provider implements ModuleContract
{
    public function boot(
        HttpPipeline $http,
        CliPipeline $cli,
        WorkerPipeline $worker,
        EventBus $events,
    ): void {
        // Register a command (as a class-string)
        $cli->command(GenerateInvoiceCommand::class);
        
        // Or register a pre-constructed instance
        $cli->command(new ListTasksCommand());
        
        // Or defer registration (for expensive dependencies)
        $cli->defer(function ($cli) {
            $cli->command(DatabaseHealthCheckCommand::class);
        });
    }
}
```

### Three registration modes

| Mode | Use when | Example |
|---|---|---|
| **Class-string** | Command has injectable dependencies | `$cli->command(GenerateCommand::class)` |
| **Instance** | Command is pre-built with module-scoped services | `$cli->command(new ListCommand($moduleService))` |
| **Deferred closure** | Building the command is expensive (DB-backed, file I/O) | `$cli->defer(fn($cli) => $cli->command(...))` |

Deferred registration is lazy: the command is not built unless the CLI is actually invoked. This prevents DB queries and file I/O during HTTP or worker bootstraps, even though every module's `boot()` runs on all entry points.

### Declaring commands in module.json

A module can declare its commands instead of registering them in code:

```json
{
  "commands": [
    { "name": "invoice:export", "handler": "Plugins\\Invoice\\Cli\\ExportInvoicesCommand" }
  ]
}
```

They compile to `command-manifest.php`. `CliPipeline` reads that manifest only when it needs to: for `list` and `help`, or when the command you run is not one `boot()` registered. Running an ordinary registered command never reads it. When it does read it, after every `boot()` registration (and every `defer()` callback) has run, it adds each declared handler that is a loadable `AbstractCommand` class, has a constructor with no parameters (not even optional ones), and is not already registered under the same class or command name. Registration in `boot()` therefore always wins.

A declared command **with** constructor parameters is skipped, optional ones included, even when the `CoreContainer` could autowire it. Autowiring would build it from the core bindings, and a command that should run against a module's own connection would quietly run against the project's `DatabasePort` instead. Register those in `boot()` with `$cli->command(MyCommand::class)` (port dependencies) or `$cli->defer(...)` (scoped services).

## Dependency injection

Commands are instantiated through the `CoreContainer`, so they can receive constructor dependencies:

```php
class GenerateInvoiceCommand extends AbstractCommand
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly MailPort $mail,
        private readonly TransactionManager $txn,
    ) {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this->name = 'invoice:generate';
        // ...
    }
    
    protected function handle(): int
    {
        // DatabasePort, MailPort, TransactionManager are injected
        // ...
        return self::SUCCESS;
    }
}
```

Ports and app-lifetime singletons are available. Module-scoped services are NOT (they are per-request; a CLI command is not a request).

### Optional dependencies

A command can declare optional dependencies with default values:

```php
public function __construct(
    private readonly DatabasePort $db,
    private readonly ?CachePort $cache = null,  // Optional
) {}
```

If `CachePort` is not bound, the constructor receives `null` instead of throwing an exception.

### Unresolvable commands are skipped

If a command cannot be constructed (missing dependencies), it is silently skipped so other commands still run. This happens when:
- A plugin-provided command's owning module is disabled
- A dependency is unbound
- The constructor has required parameters that cannot be resolved

The command is never registered, and `list` or `help` do not show it.

## Error handling

Exceptions are caught and routed through the `ErrorPipeline`:

```php
try {
    return $cli->run($argv);
} catch (Throwable $e) {
    $errorPipeline->consume(
        ErrorContext::fromThrowable($e, requestPath: 'cli', requestMethod: 'CLI')
    );
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return AbstractCommand::FAILURE;  // Exit code 1
}
```

This centralizes logging and observability so CLI failures are tracked the same way as HTTP 500s.

## Materialization

Commands are built lazily, only when the CLI is invoked:

```php
$kernel->cli()->run($argv);  // Triggers materialization
```

Until that call, no command is instantiated — even though every module's `boot()` ran during kernel bootstrap. This means:

- HTTP requests never pay for command construction
- Worker jobs never pay for command construction
- CLI-only entry points construct commands once (idempotent, repeatable under Swoole)

```php
public function run(array $argv): int
{
    // All deferred callbacks run here
    $this->materialize();
    
    // Now all commands are built and added to CLIApplication
    $this->app->run(array_slice($argv, 1));
}
```

## Core API

### Public methods

```php
// Register a command (all three overloads)
$cli->command(CommandClass::class);        // class-string
$cli->command($commandInstance);           // AbstractCommand instance
$cli->command(fn($cli) => ...);           // Deferred closure

// Defer registration until CLI is materialized
$cli->defer(function ($cli) {
    $cli->command(ExpensiveCommand::class);
});

// Check if a command is registered or queued
$cli->hasQueued('invoice:generate');       // bool

// Get the underlying CLIApplication
$app = $cli->application();

// Get the app-lifetime CoreContainer
$container = $cli->container();

// Parse argv and execute, return exit code
$exitCode = $cli->run($argv);
```

### Hook points

Like all pipelines, CliPipeline has no built-in hook system; it simply runs commands. Hooks for CLI behavior live in plugins via SecurityFilters or command decorators.

## Writing a command

All commands extend `AbstractCommand`:

```php
<?php
declare(strict_types=1);
namespace App\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

final class PublishPostCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'post:publish';
        $this->description = 'Publish a draft post';
        $this->help        = 'Mark a post as published and send notifications.';
        
        // Arguments (positional)
        $this->addArgument(
            name: 'post_id',
            description: 'The post ID to publish',
            required: true
        );
        
        // Options (flags)
        $this->addOption(
            long: 'notify-followers',
            short: 'n',
            description: 'Send email to followers',
            acceptsValue: false
        );
        
        $this->addOption(
            long: 'schedule',
            short: 's',
            description: 'Schedule publish time (ISO 8601)',
            acceptsValue: true,
            default: null
        );
    }

    protected function handle(): int
    {
        $postId = $this->argument('post_id');
        $notify = $this->hasOption('notify-followers');
        $schedule = $this->option('schedule');
        
        $this->info("Publishing post {$postId}...");
        
        // Do work here
        
        if ($notify) {
            $this->info('Sending notifications...');
        }
        
        $this->success('Post published.');
        return self::SUCCESS;
    }
}
```

## Handling user input

Commands can interact with users via built-in components:

```php
protected function handle(): int
{
    // Free-text input
    $email = $this->ask('Email address?', default: 'user@example.com');
    
    // Single selection
    $role = $this->select('Role?', ['admin', 'user', 'guest']);
    
    // Yes/No
    if (!$this->confirm('Proceed?', default: true)) {
        $this->warning('Cancelled.');
        return self::SUCCESS;
    }
    
    // Progress bar
    $bar = $this->progressBar('Processing', total: 100);
    $bar->start();
    for ($i = 0; $i < 100; $i++) {
        $bar->advance(1);
    }
    $bar->finish();
    
    // Spinner for indeterminate work
    $spinner = $this->spinner('Loading...');
    $spinner->start();
    // Do work
    $spinner->stop();
    
    return self::SUCCESS;
}
```

Complete component documentation: [/packages/php-io-cli](/packages/php-io-cli).

## Deprecated API

The old `CommandContract`, `Arguments`, and `Output` classes are deprecated:

```php
// ✗ Old (deprecated)
class OldCommand extends CommandContract
{
    public static function name(): string { return 'old'; }
    public function execute(Arguments $args, Output $out): void { }
}

// ✓ New
class NewCommand extends AbstractCommand
{
    protected function configure(): void { }
    protected function handle(): int { }
}
```

**Migration path:**

1. Extend `AbstractCommand` instead of `CommandContract`
2. Move your name to `configure()` and set `$this->name`
3. Rename `execute()` to `handle()` and return an exit code
4. Replace `$args` with `$this->argument()` / `$this->option()` / `$this->hasOption()`
5. Replace `$out` with `$this->info()` / `$this->error()` / etc.

The old classes will be removed in a future version. Migrate all commands to `AbstractCommand`.

## hkm cli entry point

There are two PHP entry points, and they are easy to confuse:

| Entry point | What it runs | How you reach it |
|---|---|---|
| `app/cli/run.php` (in each project) | the **project's** kernel: `$kernel->cli()->run($argv)`, so every module and kernel command (`migrate:*`, `schedule:*`, your own) | `hkm cli <command>`, or `php app/cli/run.php <command>` |
| `bin/hkm-cli` (in the kernel) | the **global** kernel CLI: `new`, `ground`, `doctor`, `help` | anything the native `hkm` launcher does not handle itself is forwarded here |

`app/worker/run.php` is the matching worker entry point (`hkm worker [args]`).

Inside an installed bundle, `bin/hkm-cli` is installed as `<kernel>/bin/hkm`, because that is the path the launcher's passthrough resolves; renaming or moving it breaks every installed launcher.

## Best practices

### Keep commands focused

A command should do one thing well. If you need to call another command's logic, extract it into a service and have both commands use that service.

```php
// ✗ Wrong
class PublishCommand { /* 200 lines */ }
class UnpublishCommand { /* 150 lines, duplicates PublishCommand logic */ }

// ✓ Right
class PublishCommand { /* calls PostService::publish() */ }
class UnpublishCommand { /* calls PostService::unpublish() */ }
class PostService { /* shared logic */ }
```

### Use modules to register commands

Commands belong in modules, not in the project. A module owns a domain and publishes its commands as part of that domain.

```
invoice-module/
├── Provider.php
│   └─ boots → $cli->command(InvoiceGenerateCommand::class)
├── Commands/
│   └─ InvoiceGenerateCommand.php
└─ API/
   └─ Contracts/
      └─ InvoiceServiceContract.php
```

### Avoid long-running processes in single commands

If a command takes > 30 seconds, consider breaking it into smaller tasks that a worker can process in parallel. Use the `QueuePort` and a `Job`.

### Fail explicitly

Always return a proper exit code:

```php
if ($error) {
    $this->error('Something went wrong.');
    return self::FAILURE;  // Exit code 1
}

$this->success('Done.');
return self::SUCCESS;  // Exit code 0
```

## Testing commands

Test commands by calling them directly:

```php
class InvoiceGenerateCommandTest extends TestCase
{
    public function test_generates_invoice(): void
    {
        $db = new FakeDatabasePort();
        $db->onQuery('insert', ['id' => 'inv-1']);
        
        $command = new InvoiceGenerateCommand();
        // Set up dependencies...
        
        $code = $command->execute(['inv-1'], $io);
        
        $this->assertSame(0, $code);
    }
}
```

Or use the `ground` harness to boot the command in isolation:

```php
$ground = PluginGround::for(InvoiceProvider::class)->boot();
$code = $ground->cli('invoice:generate', ['--customer=123']);
$this->assertSame(0, $code);
```

## Source

- [src/Kernel/Pipelines/Cli/CliPipeline.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Cli/CliPipeline.php)
- [modules/php-io-cli/src/AbstractCommand.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/php-io-cli/src/AbstractCommand.php)
- [src/Commands/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/src/Commands)
