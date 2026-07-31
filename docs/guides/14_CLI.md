# HKM Kernel — CLI Command Pipeline

> CLI commands share the same module system, port abstractions, and business logic as
> HTTP requests. A command is a module — it declares dependencies in `module.json`
> and loads only what it needs.
>
> **Engine:** `CliPipeline` wraps `AlfacodeTeam\PhpIoCli\CLIApplication` from the
> **php-io-cli** package (`modules/php-io-cli/`). Module commands extend `AbstractCommand`
> — a **standalone** class with zero Symfony dependency.
>
> For the complete php-io-cli reference (components, I/O layer, Shell, Colors) see
> `docs/guides/17_PHP_IO_CLI.md`.

---

## CLI Pipeline Engine

```php
// CliPipeline wraps CLIApplication from php-io-cli.
// Module commands extend AbstractCommand — NOT CommandContract (deprecated).
// Registration: class-string — CliPipeline instantiates via CoreContainer (DI) or directly.

// In Provider::boot():
public function boot(HttpPipeline $http, CliPipeline $cli, ...): void
{
    $cli->command(GenerateMonthlyInvoicesCommand::class);
    $cli->command(InvoiceDatabaseSeeder::class);
}

// Run the CLI (in bin/cli.php entry point):
exit($kernel->cli()->run($argv));
```

---

## CLI Pipeline Stages

```text
$ php cli.php invoice:generate-monthly --dry-run
    │
    ▼
CorrelationIdStage      ← generate CommandId for log tracing
    │
    ▼
AuthenticateCommandStage (optional)
    │   Validates operator credentials for protected commands
    ▼
ResolveCommandStage
    │   Matches argv[1] to a registered command name
    ▼
OnDemandLoaderStage
    │   Dep graph → loads only needed modules
    ▼
ValidateArgsStage
    │   Validates arguments against declared addArgument() defs
    ▼
ExecuteCommandStage
    │   Calls command->execute(tokens, $io) via AbstractCommand
    │   Returns exit code (0 = success, 1 = failure, 2 = invalid)
    ▼
ErrorStage (wraps all) ← routes uncaught errors to ErrorPipeline
```

---

## AbstractCommand — Base Class for All Commands

`AbstractCommand` is **standalone** — it does NOT extend or wrap any Symfony class.
Its `handle()` method receives no parameters; input is read via `$this->argument()` /
`$this->option()`, and output is written via `$this->info()` / `$this->success()` etc.

```php
use AlfacodeTeam\PhpIoCli\AbstractCommand;
use InvoiceModule\API\Contracts\InvoiceServiceContract;
use InvoiceModule\Application\DTO\GenerateMonthlyInvoicesDTO;

final class GenerateMonthlyInvoicesCommand extends AbstractCommand
{
    public function __construct(
        private readonly InvoiceServiceContract $invoices,
    ) {}

    protected function configure(): void
    {
        $this->name        = 'invoice:generate-monthly';
        $this->description = 'Generate monthly invoices for all active clients';

        $this->addArgument('month', 'Target month (Y-m)', required: false);
        $this->addOption('dry-run', 'd', 'Simulate — no invoices created');
        $this->addOption('tenant',  't', 'Restrict to a single tenant', acceptsValue: true);
    }

    protected function handle(): int
    {
        $month  = $this->argument('month', date('Y-m'));
        $dryRun = $this->hasOption('dry-run');
        $tenant = $this->option('tenant');

        $this->section('Invoice Generation');
        $this->info("Month: {$month}" . ($dryRun ? ' [dry-run]' : ''));

        if (!$this->confirm('Proceed?')) {
            $this->muted('Aborted.');
            return self::SUCCESS;
        }

        try {
            $result = $this->invoices->generateMonthly(
                new GenerateMonthlyInvoicesDTO(month: $month, dryRun: $dryRun, tenantId: $tenant)
            );
        } catch (\Throwable $e) {
            $this->alertError('Generation failed', [$e->getMessage()]);
            return self::FAILURE;
        }

        $this->alertSuccess(
            "Generated {$result->created} invoices",
            ["Skipped: {$result->skipped}"],
        );
        return self::SUCCESS;
    }
}
```

---

## Argument and Option Declaration

Declared in `configure()` — **not** in `module.json`. The command name IS declared in
`module.json` under `"type": "command"` for the BootPipeline manifest, but signatures
live in PHP.

```php
// Positional argument
$this->addArgument(
    name:        'environment',
    description: 'Target environment (prod, staging, dev)',
    required:    true,
    default:     null,
);

// Boolean flag: --force  /  -f
$this->addOption('force', 'f', 'Skip confirmation prompts');

// Value-accepting option: --tag=v1.0  or  --tag v1.0
$this->addOption('tag', 't', 'Git tag to deploy', acceptsValue: true, default: 'latest');
```

Reading inside `handle()`:

```php
$env    = $this->argument('environment');        // string|null
$force  = $this->hasOption('force');             // bool
$tag    = $this->option('tag', 'latest');        // mixed with fallback default
```

---

## Output Methods

All available inside `handle()`. These are NOT Symfony Console formatting tags.

```php
$this->info('Connecting to database…');           // cyan text
$this->success('Migration complete.');            // ✔ green
$this->warning('Disk usage above 80%.');          // ! yellow (stderr)
$this->error('Connection refused.');              // ✘ red   (stderr)
$this->muted('Skipped — already exists.');        // dim gray

$this->section('Build Pipeline');                // bold cyan heading + underline rule
$this->newLine(2);                               // blank lines

// Alert boxes
$this->alertSuccess('Deployed!', ['Version: 2.4.1', 'Region: eu-west-1']);
$this->alertError('Build failed', ['See /var/log/build.log']);
$this->alertWarning('Rate limit at 80%');
$this->alertInfo('New version available: 3.0.0');
```

---

## Interactive Component Factory Shortcuts

```php
$name    = $this->ask('Project name');
$env     = $this->select('Target', ['prod', 'staging', 'dev']);
$ok      = $this->confirm('Continue?');
$bar     = $this->progressBar('Installing', total: 10);  // total=0 → indeterminate
$spin    = $this->spinner('Compiling');
$table   = $this->table();
```

---

## Exit Codes

| Constant | Value | Meaning |
|---|---|---|
| `self::SUCCESS` | `0` | Completed normally |
| `self::FAILURE` | `1` | Command failed |
| `self::INVALID` | `2` | Bad input / missing required argument |

Always `return` an exit code from `handle()`. Never call `exit()`.

---

## Command module.json

```json
{
  "name":    "command-generate-invoices",
  "version": "1.0.0",
  "solves":  "command.invoice.generate-monthly",
  "type":    "command",
  "requires": ["database.query", "invoice.generation"],
  "config":  ["INVOICE_CURRENCY"]
}
```

The `"type": "command"` tells `CompileCommandManifestStage` to include this in the CLI manifest.
The command's human-readable name (`invoice:generate-monthly`) comes from `$this->name` in `configure()`.

---

## Seeder Command Pattern

Seeders are CLI commands — they inject a service contract and call it in a loop.

```php
final class InvoiceDatabaseSeeder extends AbstractCommand
{
    public function __construct(
        private readonly InvoiceServiceContract $invoices,
    ) {}

    protected function configure(): void
    {
        $this->name        = 'db:seed:invoices';
        $this->description = 'Seed fake invoices into the database';
        $this->addOption('count', 'c', 'Number of invoices to create', acceptsValue: true, default: '50');
    }

    protected function handle(): int
    {
        $count = (int) $this->option('count', 50);
        $this->info("Seeding {$count} invoices…");

        $bar = $this->progressBar('Seeding', $count);
        $bar->start();

        for ($i = 1; $i <= $count; $i++) {
            $this->invoices->create(CreateInvoiceDTO::fake());
            $bar->advance();
        }

        $bar->finish('Done');
        $this->success("Seeded {$count} invoices.");
        return self::SUCCESS;
    }
}
```

---

## Protected Commands (Require Authentication)

```php
// For commands requiring operator credentials:
// AuthenticateCommandStage checks against CLI_OPERATOR_TOKEN env var.
// List protected command names in config:

return [
    'protected_commands' => [
        'db:seed:invoices',
        'migrate:reset',
        'tenant:delete',
    ],
    'operator_token_env' => 'CLI_OPERATOR_TOKEN',
];

// Usage:
// CLI_OPERATOR_TOKEN=xxx php cli.php db:seed:invoices
```

---

## Rules for CLI Command Code

- **DO** extend `AbstractCommand` (from php-io-cli)
- **DO** implement `configure()` to set `$this->name`, `$this->description`, arguments, options
- **DO** implement `handle()` with no parameters — read input via `$this->argument()` / `$this->option()` / `$this->hasOption()`
- **DO** write output via `$this->info()`, `$this->success()`, `$this->warning()`, `$this->error()`, `$this->muted()`
- **DO** return one of the three exit code constants from `handle()`
- **DO** inject services via published contracts — same rules as HTTP controllers
- **DO** register with `$cli->command(ClassName::class)` in `Provider::boot()`
- **DO** use `$this->progressBar()` or `$this->spinner()` for long operations
- **DON'T** use `InputInterface` / `OutputInterface` — those are Symfony Console types, not used here
- **DON'T** use `$output->writeln('<info>...</info>')` — use `$this->info()` etc.
- **DON'T** call `exit()` — return an integer
- **DON'T** use `CommandContract`, `Arguments`, or `Output` from `Cli/` — all @deprecated
- **DON'T** put business logic in the command — delegate to a Service
- **DON'T** declare the command signature in module.json — it belongs in `configure()`
- **DON'T** access `$_SERVER` or `$argv` directly
