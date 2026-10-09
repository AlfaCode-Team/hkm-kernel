# Built-in Commands

The kernel ships a comprehensive set of CLI commands for database migrations, seeding, job scheduling, and module management. They are registered via `Migrate\CliCommandFactory`, `Seed\SeedCommandFactory`, and module providers.

## Invoking commands

These are **project** commands: they run inside your project's kernel, through its console entry point `app/cli/run.php` (which calls `$kernel->cli()->run($argv)`). Run them from the project directory with the native launcher:

```bash
hkm cli <command> [arguments] [options]          # terminal attached; interactive prompts work
hkm cli -p shop <command> ...                    # target a registered project by name or path
php app/cli/run.php <command> [arguments]        # the same, without the launcher
hkm cli                                          # no command: the interactive command picker
```

`hkm migrate:run` (without `cli`) does **not** work: the launcher forwards unknown commands to the kernel's global CLI, which only knows `new`, `ground` and `doctor`, and exits with "Unknown command".

## Migration commands

All migration commands are built on **LetMigrate** — the framework's enterprise-grade migration engine. See [/packages/let-migrate](/packages/let-migrate) for the full schema builder API.

::: tip `--help` is safe
`<command> --help`, `<command> -h` and `help <command>` print the command's help and run nothing. (Before this fix, php-io-cli executed the command first, so `migrate:fresh --help` dropped every table. If your kernel predates the fix, read this page instead of asking a destructive command for help.)
:::

### migrate:run

Apply all pending migrations.

```bash
hkm cli migrate:run                 # Run all pending
hkm cli migrate:run --pretend       # Preview SQL without executing
hkm cli migrate:run --force         # Skip destructive-operation linter
hkm cli migrate:run --lock          # Acquire deploy lock (mutex)
hkm cli migrate:run --connection=X  # Specify connection name
hkm cli migrate:run --config=path   # Path to let-migrate config
```

| Option | Short | Purpose | Default |
|---|---|---|---|
| `--pretend` | `-p` | Output SQL instead of executing | — |
| `--force` | `-f` | Skip destructive operation lint guard | — |
| `--lock` | `-l` | Acquire an advisory deploy lock | — |
| `--lock-timeout` | `-lt` | Seconds to wait for lock | 10 |
| `--connection` | `-c` | Connection name in config | — |
| `--config` | — | Path to let-migrate config | — |
| `--json` | — | Machine-readable output | — |

Exit codes: 0 = success, 1 = failure, 2 = invalid args.

### migrate:pending

List migrations that have not been applied yet.

```bash
hkm cli migrate:pending
hkm cli migrate:pending --json
```

| Option | Purpose |
|---|---|
| `--connection` | Connection name in config |
| `--config` | Path to let-migrate config |
| `--json` | Machine-readable output |

### migrate:status

Show the status of every known migration (applied/pending/unknown).

```bash
hkm cli migrate:status
hkm cli migrate:status --json
```

Displays a table with migration name, status, and timestamp. JSON output includes the full status record.

### migrate:list

An audit view of every migration: status, batch, when it was applied, and the age of the file (from its filename timestamp). Use `migrate:status` for the compact "what runs next" view.

```bash
hkm cli migrate:list
hkm cli migrate:list --pending      # only unapplied
hkm cli migrate:list --applied      # only applied
hkm cli migrate:list --batch=3      # only batch 3
```

| Option | Short | Purpose |
|---|---|---|
| `--pending` | `-p` | Show only unapplied migrations |
| `--applied` | `-a` | Show only applied migrations |
| `--batch` | `-b` | Filter by batch number (takes a value) |

Read-only: it queries the tracking table.

### migrate:rollback

Roll back the last N migration batches.

```bash
hkm cli migrate:rollback              # Rollback the last batch
hkm cli migrate:rollback --steps=2    # Rollback the last 2 batches
```

| Option | Purpose |
|---|---|
| `--steps` | Number of batches to rollback (default: 1) |

### migrate:reset

Roll back ALL migrations.

```bash
hkm cli migrate:reset
```

Reverts the database to its initial state. Use with caution in production.

### migrate:refresh

Roll back all migrations and run them again.

```bash
hkm cli migrate:refresh              # Reset + run all
hkm cli migrate:refresh --seed       # Also seed the database
```

| Option | Purpose |
|---|---|
| `--seed` | Run seeders after migrations |
| `--force` | Skip destructive lint guard |

### migrate:fresh

Drop all tables and run all migrations from scratch.

```bash
hkm cli migrate:fresh                # Fresh start
hkm cli migrate:fresh --seed         # Also seed
```

Use when you want a guaranteed clean slate. This is more aggressive than `refresh` — it does not rely on `down()` methods.

| Option | Purpose |
|---|---|
| `--seed` | Run seeders after migrations |
| `--force` | Skip destructive lint guard |

### migrate:install

Create the migrations tracking table if it does not exist.

```bash
hkm cli migrate:install
```

Usually not needed — `migrate:run` creates the table automatically. Use if the table was dropped and needs to be recreated.

### migrate:generate

Introspect the database schema and generate a migration stub from it.

```bash
hkm cli migrate:generate
```

Compares the current database schema against the migration history and generates a migration to bridge the gap — useful when schema changes were made directly (not via migrations).

### migrate:diff

Compare the schema defined in code against the live database.

```bash
hkm cli migrate:diff [--connection=X]
```

Reports any discrepancies so you can add a migration to correct them.

### migrate:check

Verify the database matches what migrations define.

```bash
hkm cli migrate:check
```

Exit code 0 = schema matches, 1 = mismatch.

### migrate:lint

Check migrations for dangerous operations (DROP, TRUNCATE, etc.).

```bash
hkm cli migrate:lint [--force]
```

Used by `migrate:run --force` to decide whether to block execution.

### migrate:squash

Compress old migrations into a single file.

```bash
hkm cli migrate:squash [--squash-before=YYYY_MM_DD]
```

Consolidates the migration history when it becomes too long. Useful for projects with hundreds of migrations.

### migrate:redo

Roll back N migrations and re-apply them.

```bash
hkm cli migrate:redo               # Redo the last migration
hkm cli migrate:redo --steps=3     # Redo the last 3 migrations
```

| Option | Short | Purpose | Default |
|---|---|---|---|
| `--steps` | `-s` | Number of migrations to redo | 1 |

Useful for testing migration changes during development without rolling back manually.

### migrate:to

Run pending migrations up to (and including) a target migration.

```bash
hkm cli migrate:to 2024_01_15_000005_create_invoices_table
```

Runs all pending migrations in sequence until the specified migration is reached. Useful for incremental migration testing or recovering to a specific point.

### migrate:breakpoint

Set, clear, or list rollback breakpoints (production safety rail).

```bash
hkm cli migrate:breakpoint                 # Set breakpoint at latest migration
hkm cli migrate:breakpoint --unset         # Clear the breakpoint
hkm cli migrate:breakpoint 2024_01_15_000003_create_users_table --set
```

| Option | Short | Purpose |
|---|---|---|
| `--set` | `-s` | Set the breakpoint (default) |
| `--unset` | `-u` | Clear the breakpoint |

Breakpoints prevent accidental rollback past a specific migration in production. When a breakpoint is set, `rollback` will stop before that migration.

## Make commands (scaffolders)

### make:migration

Generate a blank migration file.

```bash
hkm cli make:migration create_users_table
hkm cli make:migration add_email_to_users --table=users
```

| Option | Purpose |
|---|---|
| `--table` | Pre-populate with a table name (for `alter` operations) |

The generated file has `up()` and `down()` stubs ready to be filled.

### make:seeder

Generate a blank seeder class.

```bash
hkm cli make:seeder UserSeeder
```

The generated class implements the seeder interface and is ready for data insertion.

### make:factory

Generate a blank factory class.

```bash
hkm cli make:factory UserFactory
```

Factories are stubs; implement your own fixture generation logic inside.

## Database seeders

Seeders populate the database with test or reference data.

### seed:run

Execute all pending seeders in dependency order.

```bash
hkm cli seed:run                     # Run pending seeders
hkm cli seed:run --force             # Re-run already-seeded seeders
hkm cli seed:run --no-progress       # Suppress spinner output
```

| Option | Short | Purpose |
|---|---|---|
| `--force` | `-f` | Re-run all seeders even if already executed |
| `--no-progress` | `-q` | Suppress spinner output |

### seed:status

Show the status of every registered seeder.

```bash
hkm cli seed:status
```

Displays a table of seeders and their run status.

### seed:fresh

Clear all seeders' status and run them from scratch.

```bash
hkm cli seed:fresh [--force]
```

Useful for resetting test data without rolling back migrations.

## Database command

### db:seed

Run seeders (legacy alias).

```bash
hkm cli db:seed
```

Equivalent to `seed:run`. Provided for compatibility.

## Scheduler

The scheduler runs tasks on a fixed minute interval. One crontab line runs everything:

```text
* * * * * cd /path/to/app && hkm cli schedule:run >> /dev/null 2>&1
```

Tasks are declared in module.json and evaluated at runtime — no server restarts needed.

### schedule:run

Dispatch every scheduled task that is due right now.

```bash
hkm cli schedule:run                 # Run all due tasks
hkm cli schedule:run --task=NAME     # Run one task by name (for testing)
hkm cli schedule:run --at="2026-01-01 02:00"  # Evaluate against a specific time
hkm cli schedule:run --pretend       # List what WOULD run, without running it
```

| Option | Purpose |
|---|---|
| `--task` | Run ONE task by name, whether due or not |
| `--at` | Evaluate tasks against this moment instead of now (any strtotime format) |
| `--pretend` | List due tasks and dispatch nothing |

**Silent by design**: when nothing is due, this command exits with code 0 and prints nothing (it runs every minute). Only failed dispatches produce output.

### schedule:list

List all scheduled tasks and their next run times.

```bash
hkm cli schedule:list [--json]
```

| Option | Purpose |
|---|---|
| `--json` | Machine-readable output |

## Module management

Module commands add and remove Composer path packages (Git submodules) from the project.

### module:add

Add a Git submodule and register it as a Composer path package.

```bash
hkm cli module:add payments git@github.com:acme/payments.git acme
hkm cli module:add payments git@github.com:acme/payments.git acme --offline
```

| Argument | Purpose |
|---|---|
| `name` | Module name in kebab-case (e.g. `user-auth`) |
| `git-url` | Git repository URL (SSH or HTTPS) |
| `org` | Composer vendor / GitHub org (e.g. `acme`) |

| Option | Short | Purpose |
|---|---|---|
| `--offline` | `-o` | Install without network (`COMPOSER_DISABLE_NETWORK=1`) |

Steps performed:
1. Validates the module does not already exist
2. Clones the repo as a Git submodule
3. Scaffolds `src/` and `composer.json` if missing
4. Patches root `composer.json` to add the path repository
5. Runs `composer update` (or offline mode)

### module:remove

Remove a Git submodule and unregister it from Composer.

```bash
hkm cli module:remove payments
hkm cli module:remove payments --offline
```

| Argument | Purpose |
|---|---|
| `name` | Module name to remove |

| Option | Purpose |
|---|---|
| `--offline` | Install without network |

Steps performed:
1. Validates the module exists
2. Removes the submodule entry from `.gitmodules`
3. Removes the submodule folder from `modules/`
4. Patches root `composer.json` to remove the path repository
5. Runs `composer update`

## Common patterns

### Seeding after a fresh install

```bash
hkm cli migrate:fresh --seed
```

Creates a clean schema and populates it with reference data in one command.

### Testing a migration before applying it

```bash
hkm cli migrate:run --pretend
```

Outputs all SQL that would be executed. Review it, then run without `--pretend`.

### Checking scheduler health

```bash
hkm cli schedule:list
hkm cli schedule:run --pretend --at="2026-02-15 03:00"
```

The first shows all tasks and their next run times. The second simulates running at a specific moment without actually dispatching.

### Manual module integration

If `module:add` fails (network, repo access), complete the steps manually:

```bash
# 1. Add the submodule
git submodule add <url> modules/<name>
git submodule update --init --recursive

# 2. Register in root composer.json
composer config repositories.<name> path ./modules/<name>
composer require <vendor>/<name>:@dev

# 3. Verify
hkm cli list
```

## Error handling

All commands follow standard exit codes:

| Exit code | Meaning |
|---|---|
| 0 | Success |
| 1 | Failure (exception, validation error) |
| 2 | Invalid arguments / wrong usage |

Check the exit code to integrate with deployment pipelines:

```bash
hkm cli migrate:run
if [ $? -eq 0 ]; then
    echo "Migrations OK"
else
    echo "Migrations failed"
    exit 1
fi
```

## Source

- [src/Commands/Migrate/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/src/Commands/Migrate)
- [src/Commands/Seed/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/src/Commands/Seed)
- [src/Commands/Schedule/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/src/Commands/Schedule)
- [src/Commands/ModuleAddCommand.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Commands/ModuleAddCommand.php)
- [src/Commands/ModuleRemoveCommand.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Commands/ModuleRemoveCommand.php)
