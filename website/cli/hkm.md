# hkm — The Native Launcher

The `hkm` command is a native launcher (written in Zig) that scaffolds projects, runs them locally, forwards console commands, and manages plugins. It is your primary interface to the framework — `hkm` is what you type most.

## Installation and setup

`hkm` is installed as part of the framework. Once installed, use it to scaffold projects and manage the local environment:

```bash
hkm doctor              # Diagnose the local environment
hkm new ./my-project   # Scaffold a new project
hkm install            # Bring a cloned project up to speed
hkm run                # Run a project locally
```

## Command overview

```bash
hkm new <path>          Scaffold a new PhpServicePlatform project
hkm install [path|name] Bring a cloned project up to a runnable state
hkm run [path|name]     Serve a project locally (PHP dev server or Swoole)
hkm cli [command]       Run a project's console
hkm worker [args]       Run a project's background worker
hkm list                List registered projects
hkm update [name]       Refresh a project's registry entry
hkm plugins [cmd]       Analyse and manage plugins
hkm ui [cmd]            Build the frontend from enabled plugins
hkm ppkg [cmd]          Package management (Composer-compatible)
hkm doctor              Diagnose the local environment
hkm help                Show help
```

## Project resolution

Most `hkm` commands accept a project as either:
- **A path**: a directory containing `proj.json`
- **A name**: a project registered in the `hkm` kernel registry
- **Nothing**: the current working directory is used

```bash
hkm run .                        # Run by path (current dir)
hkm run ./my-shop
hkm run my-shop                  # Run by registered name
hkm install my-shop
```

## hkm new — scaffold a project

Create a complete project tree with bootstrap, entry points, config, database folders, and sample code.

```bash
hkm new <path> [options]
```

| Option | Purpose |
|---|---|
| `--project=<name>` | Project name (default: derived from path) |
| `--domains=a.com,b.com` | Comma-separated domains to register |
| `--no-register` | Skip kernel registry registration |

```bash
hkm new ./my-shop
hkm new ./my-shop --project=shop --domains=shop.localhost,shop.test
hkm new ./scratch --no-register
```

What it does:
1. Copies the project templates (from the kernel's `templates/`): `app/` entry points (`bootstrap/`, `public/`, `swoole/`, `cli/`, `worker/`), `config/`, `src/` (namespace = StudlyCase of the project name), `resources/`, `proj.json`, `composer.json`, `.env` / `.env.example`
2. Generates `APP_KEY` in `.env` (skip with `--no-key`)
3. Installs the default set of pinned plugins and runs `composer install` (skip with `--no-install`; `--verify-plugins` also runs each plugin's tests)
4. Registers the project in the kernel's project registry (skip with `--no-register`)

It works with no PHP or Composer on the machine for the scaffolding step itself: the scaffolder is native.

**Note**: no migrations are run automatically. You run them manually with `hkm cli migrate:run`.

## hkm install — restore a cloned project

When a project is cloned or pulled from git, it is missing `plugins/`, `var/`, and `userdata/` (all gitignored). `hkm install` restores them:

```bash
hkm install [path|name] [options]
```

| Option | Purpose |
|---|---|
| `--no-register` | Skip registry registration |
| `--no-key` | Skip `.env` / `APP_KEY` generation |
| `--no-install` | Skip `composer install` |
| `--no-plugins` | Skip fetching plugins |
| `--no-chmod` | Skip fixing `var/` and `userdata/` permissions |
| `--verify-plugins` | Run each plugin's test suite (slow) |
| `--production, --prod` | Harden the whole tree: code 0750/0640, var/userdata 2770/0660 |
| `--owner=<user>[:<group>]` | `chown` the whole tree (needs root/sudo) |
| `--check` | Report only — check if the web server can use the tree |
| `--as=<user>` | With `--check`: the PHP-FPM account to verify (default: www-data) |

### What it does

1. Registers the project in the `hkm` kernel registry
2. Recreates missing `var/` subdirectories (logs, cache, tmp, locks, sessions, queue)
3. Recreates `userdata/storage` if missing
4. Fixes permissions on `var/` and `userdata/` to be writable
5. Creates `.env` from `.env.example` if missing
6. Generates `APP_KEY` if `.env` exists and `APP_KEY` is empty
7. Runs `composer install`
8. Fetches every plugin the project's bootstrap wires

### --production / --owner — on a server

```bash
# After git-ftp or CI deploy:
sudo hkm install --production --owner=deploy:www-data

# Or set the owner in the environment once:
export HKM_PROD_OWNER=deploy:www-data
sudo hkm install --production
```

Hardening makes the project readable/executable by the web server (PHP-FPM):

| Path | --production | default |
|---|---|---|
| Directories holding code | 2750 | 2755 |
| Files holding code | 0640 | 0644 |
| Already-executable files (`vendor/bin/*`, shell scripts) | 0750 | 0755 |
| `var/`, `userdata/` directories | 2770 | 2775 |
| `var/`, `userdata/` files | 0660 | 0664 |
| `.env` | 0640 | 0640 |

**Five details are load-bearing:**

1. **.env is 0640** — PHP-FPM must read `APP_KEY`. A 0600 `.env` owned by deploy is the #1 reason trees fail under a pool running as another account.
2. **Code is never group-writable** — an FPM pool that can rewrite PHP it is executing turns any file-write bug into RCE. Only `var/` and `userdata/` are group-writable.
3. **Every directory has setgid** — ensures files created later (git pull, cron jobs) stay in the group.
4. **Executable files keep their exec bit** — a flat chmod 0640 would strip the exec bit from `vendor/bin/*` launchers and shell scripts; files that were executable stay executable.
5. **.git is skipped** — the deploy account can still `git pull` afterward.

### --check — verify access without changing anything

```bash
sudo hkm install --check --as=www-data
```

Inspects these paths and **changes nothing**:

- Every parent directory (can the pool pass through them?)
- `.env`, `.env.<domain>`, `.env.local` (can the pool read them?)
- `var/logs`, `var/cache/manifests`, etc. (exist and writable?)
- Everything under `var/` and `userdata/` (pool can read+write?)

Exit codes: 0 = correct, 1 = problems found, 2 = could not check.

## hkm run — serve a project locally

Start a development server (PHP dev server or OpenSwoole) in front of the project:

```bash
hkm run [path|name] [options]
```

| Option | Purpose |
|---|---|
| `--pick, -i` | Choose the project from the registry interactively |
| `--host=<ip>` | Interface to bind (default: 127.0.0.1) |
| `--port=<num>` | Port to listen on (default: 8000) |
| `--swoole` | Use OpenSwoole instead of PHP's dev server |
| `--cli [args...]` | Run the CLI instead of serving |
| `--worker` | Run the background worker instead of serving |

```bash
hkm run .
hkm run --pick                    # Pick from registry, then serve
hkm run -i --swoole               # Pick, then run with Swoole
hkm run ./my-shop --port=9000
hkm run shop --host=0.0.0.0 --swoole
hkm run --cli migrate --seed      # Run a CLI command
hkm run --worker --queue=emails   # Run the worker
```

Long-running servers show an interactive supervisor:
- Press `r` to restart
- Press `q` or Ctrl+C to quit

## hkm cli — run console commands

Forward arguments to a project's CLI (`app/cli/run.php`):

```bash
hkm cli [command] [args...]
hkm cli -p <project> [command] [args...]
```

| Option | Purpose |
|---|---|
| `-p <name\|path>` | Target a specific project |

```bash
hkm cli                          # Interactive command picker
hkm cli list                     # Forward `list` to the project
hkm cli make:migration           # Interactive prompt
hkm cli migrate:run              # Run migrations
hkm cli migrate:run --pretend    # Preview without executing
hkm cli -p shop migrate:run      # Target a specific project
```

## hkm worker — run the queue worker

Forward to a project's worker (`app/worker/run.php`):

```bash
hkm worker [args...]
hkm worker -p <name|path> [args...]
```

```bash
hkm worker                                   # Default queue
hkm worker -p shop --queue=emails           # Specific queue
hkm worker --max-iterations=100             # Run N jobs then exit
hkm worker --memory=256                     # Stop before hitting 256MB RAM
```

Worker runs until manually stopped (Ctrl+C). Use `--max-iterations` or `--memory` to auto-stop.

## hkm service — run the worker as a system service

Integrate with `systemd` (Linux) or `launchd` (macOS):

```bash
hkm service <verb> [--project=X]
```

| Verb | Purpose |
|---|---|
| `install` | Create and enable the service |
| `start` | Start the worker service |
| `stop` | Stop the worker service |
| `restart` | Restart the worker service |
| `status` | Show the service status |
| `logs` | Follow the service logs |
| `uninstall` | Disable and remove the service |

```bash
sudo hkm service install --project=my-shop
sudo systemctl start hkm-worker-my-shop     # (on Linux)
hkm service logs --project=my-shop
sudo hkm service uninstall --project=my-shop
```

## hkm list — list registered projects

Show all projects registered in the `hkm` kernel registry:

```bash
hkm list [--json]
```

Displays: name, path, whether it is the default project.

```bash
hkm list --json      # Machine-readable output
```

## hkm update — refresh a project's registry entry

Update a project's path or metadata in the registry:

```bash
hkm update <path|name>
```

Use after moving a project to a new location.

## hkm plugins — plugin management

Analyse and manage plugins:

```bash
hkm plugins [subcommand]
```

| Subcommand | Purpose |
|---|---|
| `list` | List all registered plugins and their status |
| `enable <name>` | Enable a plugin for the current project |
| `disable <name>` | Disable a plugin |
| `domains` | Show which plugin claims each domain |
| `audit` | Check plugin integrity |
| `check` | Static conformance checks |

```bash
hkm plugins list                         # All plugins
hkm plugins enable hkm-plugin-auth       # Enable Auth plugin
hkm plugins disable hkm-plugin-cache     # Disable Cache plugin
hkm plugins domains                      # Domain → plugin map
hkm plugins audit                        # Check for issues
```

See [/modules/plugins](/modules/plugins) for the full plugin API.

## hkm ui — build the frontend

Compile and bundle the frontend from enabled plugins:

```bash
hkm ui [options]
```

| Option | Purpose |
|---|---|
| `--watch` | Rebuild on file changes |
| `--production` | Optimize for production |

Runs Vite (or the configured bundler) on `templates/frontend/`, pulling in UI components from enabled plugins. Its layout is documented in [`templates/frontend/docs/HOW_IT_WORKS.md`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/templates/frontend/docs/HOW_IT_WORKS.md).

## hkm ppkg — package management

The native Zig-based Composer-compatible package manager:

```bash
hkm ppkg <command> [args]
```

Same commands as Composer: `install`, `update`, `require`, `remove`, `audit`, etc.

See [/packages/hkm-ppkg](/packages/hkm-ppkg) for full details.

```bash
hkm ppkg install                  # Install dependencies
hkm ppkg update                   # Update composer.lock
hkm ppkg require vendor/pkg:^2.0
hkm ppkg audit                    # Check for CVEs
hkm ppkg compat                   # Check Composer plugin compatibility
```

## hkm doctor — diagnose the environment

Verify that your machine is ready to run the framework:

```bash
hkm doctor
```

Checks:
- PHP version (8.4+)
- Required PHP extensions (PDO, JSON, etc.)
- `composer` availability
- Git availability
- Writable `/tmp`
- Database connectivity (if configured)

**hkm doctor is the single authority on environment health.** Report environment problems there and nowhere else.

## hkm help — show help

```bash
hkm help                    # Top-level help
hkm new --help              # Help for a native launcher command
```

`--help` works on the launcher's own commands (`new`, `install`, `run`, `plugins`, `ppkg`, `ui`, `env`, `doctor`, and the rest listed on this page) and, through `hkm cli`, on project commands: `hkm cli migrate:fresh --help` prints help and runs nothing.

## Global flags

The launcher itself parses only a few flags; everything else belongs to the command you run.

| Flag | Purpose |
|---|---|
| `--help`, `-h` (or `hkm help`) | Show help |
| `--version`, `-v` (or `hkm version`) | Show the launcher and kernel version |
| `--dev` | Run against the development kernel checkout (`HKM_DEV_HOME`) instead of the installed one. A subcommand that owns its own `--dev` keeps it: `hkm ppkg require --dev phpunit/phpunit` adds a dev dependency, and `hkm --dev ppkg …` selects the dev kernel. |
| `--mem` | Print a memory dashboard when the command finishes |

## Configuration

### hkm-config

`hkm-config` inspects and repairs the launcher's persistent configuration, `~/.config/hkm/config.env`, which every `hkm` reads at startup:

```bash
hkm-config                       # check: resolve the kernel and fill in anything missing
hkm-config check                 # same as no arguments
hkm-config print                 # show the config file path and its contents
hkm-config set-kernel-home <dir> # pin HKM_KERNEL_HOME
hkm-config set-autoload <file>   # pin HKM_GLOBAL_AUTOLOAD (a vendor/autoload.php)
hkm-config set-dev-home <dir>    # pin HKM_DEV_HOME (the checkout --dev uses)
hkm-config unset <KEY>           # remove a key, e.g. a stale HKM_KERNEL_HOME
```

`check` pins `HKM_KERNEL_HOME` only when the launcher cannot find its kernel by itself. The standard layouts (`bin/` beside `lib/hkm-kernel/`) self-locate, and a machine can hold two installs that must not redirect each other.

### Environment variables

| Variable | Purpose |
|---|---|
| `HKM_KERNEL_HOME` | Kernel install directory, when it cannot be found next to the binary |
| `HKM_GLOBAL_AUTOLOAD` | Explicit path to the kernel's `vendor/autoload.php` |
| `HKM_DEV_HOME` | Development kernel checkout used by `--dev` |
| `HKM_USERDATA_DIR` | Where the machine-wide project registry lives |
| `HKM_PROJECT` | Project booted when no host resolves one (CLI, workers) |
| `HKM_POOL_USER` | Account `hkm install --check` checks permissions for (default `www-data`); same as `--as` |
| `HKM_PROD_OWNER` | Default `--owner` for `hkm install --production` |

## Common workflows

### First-time setup

```bash
hkm new ./my-app
cd my-app
hkm run
# App is live at http://127.0.0.1:8000
```

### Bringing in a colleague's clone

```bash
git clone git@github.com:acme/app.git
cd app
hkm install
hkm run
```

### Running migrations

```bash
hkm cli migrate:run
hkm cli migrate:run --pretend    # Preview first
hkm cli migrate:rollback         # Undo the last batch
```

### Running the queue worker

```bash
hkm worker &                     # Background
# or
hkm worker --queue=emails --max-iterations=100
```

### Deploying to production

```bash
# On the server:
sudo hkm install --production --owner=deploy:www-data
sudo systemctl start hkm-worker-myapp
```

## Source

- [tools/docs/hkm-cli-usage.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/tools/docs/hkm-cli-usage.md)
- [tools/src/commands/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/tools/src/commands)
