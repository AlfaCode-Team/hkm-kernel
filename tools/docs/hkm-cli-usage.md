# hkm — CLI Usage Reference

The native launcher for the **PhpServicePlatform** framework. Scaffold projects,
run them locally, forward console/worker commands, and manage plugins (kernel or
project) — including asset publishing and database migrations.

> A typeset PDF of this document is built with `zig build docs`
> (output: `tools/docs/hkm-cli-usage.pdf`).

---

## Synopsis

```
hkm <command> [arguments] [options]
hkm <command> --help        # detailed help for a command
```

## Commands at a glance

```
hkm new <path> [opts]        scaffold a new PhpServicePlatform project
hkm run [path|name]          run a project locally (PHP dev server / Swoole)
hkm cli [command]            run a project's console interactively
hkm worker [args]            run a project's queue worker
hkm list                     list registered projects        (alias: ls)
hkm update <path|name>       refresh a project's registry entry
hkm plugins [subcommand]     analyse / manage plugins         (alias: modules)
hkm ui [subcommand]          federate enabled plugins' UIs into the frontend
hkm doctor                   diagnose the local environment
hkm help                     show top-level help
```

> **Project resolution.** Most commands accept a project as either a *path* (a
> directory containing `proj.json`) or a *name* registered in the kernel
> registry. With no argument, the current working directory is used.

---

## hkm new — scaffold a project

Creates a complete project tree (bootstrap, entry points, config, database
folders, sample `src/`) and registers it with the kernel.

```
hkm new <path> [options]

--project=<name>        project name (default: derived from <path>)
--domains=a.com,b.com   comma-separated domains to register
--no-register           skip kernel registry registration
```

```bash
hkm new ./my-shop
hkm new ./my-shop --project=shop --domains=shop.localhost,shop.test
hkm new ./scratch --no-register
```

> On creation, hkm also **publishes the assets** (config, migrations, seeders,
> factories, views) of every plugin the new project enables — copy only, no
> migrations are run.

---

## hkm run — serve a project

Resolves the project root and the kernel autoload, then starts a local server in
front of the project's front controller. Long-running servers get an interactive
supervisor: press `r` to restart, `q`/Ctrl+C to quit.

```
hkm run [path|name] [options]

--pick, -i            choose the project from the registry interactively
--host=127.0.0.1      interface to bind          (default: 127.0.0.1)
--port=8000           port to listen on          (default: 8000)
--swoole              run app/swoole/index.php    (OpenSwoole server)
--cli [args...]       run app/cli/run.php instead of serving
--worker              run app/worker/run.php instead of serving
```

```bash
hkm run .
hkm run --pick                 # pick from the list, then serve
hkm run -i --swoole            # pick, then run with OpenSwoole
hkm run ./my-shop --port=9000
hkm run shop --host=0.0.0.0
hkm run shop --swoole --port=9502
hkm run --cli migrate --seed
```

---

## hkm cli / hkm worker — console & queue

Forward arguments verbatim to a project's PHP entry point. A bare `hkm cli`
drops into the project's interactive command picker.

```
hkm cli [command] [args...]     run app/cli/run.php in ./ (terminal attached)
hkm worker [args...]            run app/worker/run.php in ./
  -p <name|path>                target a registered project or path
```

```bash
hkm cli                         # interactive command picker
hkm cli list                    # forward `list` to the project console
hkm cli make:migration          # interactive prompt
hkm cli route:list              # dump the compiled route manifest
hkm cli route:list --method=GET --path=/api   # filter by verb + path prefix
hkm cli route:list --json       # machine-readable output
hkm cli -p shop migrate:run     # target a registered project by name
hkm worker
hkm worker -p shop --queue=emails
```

---

## hkm list / hkm update — the registry

```
hkm list                  list registered projects (name, path, domains)
hkm ls                    alias for list
hkm update <path|name>    refresh a project's kernel registry entry
```

---

## hkm plugins — manage plugins

Analyse which plugins a project uses, toggle them in the bootstrap, scaffold or
delete plugins, and add migrations/seeders/factories to a plugin.
Alias: `hkm modules`.

### Plugin sources

- **project** — `<project>/plugins` — a project's own local plugins.
- **kernel** — `<kernel>/plugins` — shared, first-party, *contributor-protected*.

When a plugin of the same name exists in both, you are prompted to choose.

### Subcommands

```
hkm plugins [path|name]                    analyse a project's enabled plugins
hkm plugins enable  <plugin> [proj]        wire a plugin into the bootstrap
hkm plugins disable <plugin> [proj]        remove a plugin from the bootstrap
hkm plugins update  [plugin] [proj]        publish NEW assets of enabled plugin(s) + migrate them
hkm plugins upgrade [proj]                 full upgrade after plugins changed (deps + assets + SPLIT reconcile)
hkm plugins create  <name>   [proj]        scaffold a new plugin
hkm plugins delete  <name>   [proj]        delete a plugin folder from disk
hkm plugins make:migration <plugin> <name> add a migration INTO a plugin
hkm plugins make:seeder    <plugin> <name> add a seeder into a plugin
hkm plugins make:factory   <plugin> <name> add a factory into a plugin
```

### Options

```
--all, -a            also list available-but-disabled plugins (analyse)
--essential, -e      enable into withEssentialModules() (default: on-demand)
--kernel, -k         create/delete a KERNEL plugin (kernel monorepo only)
--dry-run, -n        preview the change without writing
--help, -h           show command help
```

### Command aliases

```
enable          = add | on
disable         = remove | off
update          = sync
upgrade         = reconcile | migrate
create          = new | scaffold
delete          = del | destroy | rm
make:migration  = make-migration | migration
make:seeder     = make-seeder    | seeder
make:factory    = make-factory   | factory
list            = ls
```

### Examples

```bash
hkm plugins                          # table of enabled plugins
hkm plugins --all                    # also show available, disabled plugins
hkm plugins enable billing           # wire + publish + migrate
hkm plugins enable redis-cache -e    # into withEssentialModules()
hkm plugins disable billing          # un-wire (then asks to unpublish)
hkm plugins upgrade                   # after upgrading plugins: heal deps, publish/migrate, reconcile splits
hkm plugins upgrade shop --dry-run    # preview the whole upgrade for a project
hkm plugins create loyalty           # scaffold a project plugin
hkm plugins create http2 --kernel    # scaffold a kernel plugin (contributors)
hkm plugins delete loyalty           # delete a plugin folder (confirms first)
hkm plugins make:migration loyalty points
hkm plugins enable billing --dry-run # preview only
```

---

## hkm ui — federate plugin UIs into the frontend

Some plugins ship a client-side UI alongside their PHP (e.g. **Pageflow**, whose
React/TS SPA bridge lives in `plugins/Pageflow/ui/`). A plugin *owns* its UI
there — developed and tested in place (its own `vitest.config.ts`,
`package.json`, …). A project *activates* that UI only while the plugin is
enabled in its `app/bootstrap/app.php`.

```
hkm ui init [path|name]          scaffold frontend/ from the template + federate UIs
hkm ui [sync] [path|name]        mirror every enabled plugin's ui/ + regenerate glue
hkm ui list [path|name]          list enabled plugins that ship a UI
hkm ui link <plugin> [path]      symlink a plugin ui/ for live co-development
hkm ui unlink <plugin> [path]    drop the symlink, restore a copied mirror
hkm ui clean [path|name]         remove all generated mirrors + glue
```

Flags: `--force`/`-f` overwrites even a linked mirror on `sync`.

**What `sync` writes** (all generated — do not hand-edit):

| Path | Role |
|---|---|
| `frontend/plugins/<slug>/` | read-only mirror of the plugin's `ui/` (dev-only subtrees — `node_modules`, `tests`, `dist` — and `.pdf`/`.map` files are skipped) |
| `frontend/plugins/index.ts` | registry barrel: `plugins`, `PluginName`, `pluginNames` |
| `frontend/plugins/manifest.json` | machine-readable inventory (name, alias, entry, framework, version, linked) |
| `frontend/tsconfig.plugins.json` | path aliases (`@pageflow/*` → `plugins/pageflow/*`) — extend your app `tsconfig`/vite `resolve.alias` from it |

**Per-plugin convention.** An optional `plugins/<Name>/ui/ui.json` overrides the
defaults:

```json
{ "alias": "@pageflow", "entry": "index.ts", "framework": "react" }
```

Without it the alias defaults to `@<lowercased-name>` and the entry to
`index.ts`. Federation is deterministic and project-over-plugin: only enabled
plugins are mirrored, and the command never touches the project's own frontend
source — just the generated `frontend/plugins/` tree and `tsconfig.plugins.json`.

**Live co-development.** `hkm ui link <plugin>` swaps the copied mirror for a
symlink to the plugin's real `ui/`, so edits flow both ways while you work; `hkm
ui unlink` restores the copy. `sync` leaves a linked mirror alone unless
`--force` is given.

---

## Plugin asset lifecycle

A plugin ships its own `config/`, `database/{migrations,seeders,factories}/`
and `resources/`. These map 1:1 onto a project's layout.

**On enable**

1. Insert `Plugins\Name\Provider::class` into the bootstrap, with a
   documentation comment from `module.json`.
2. **Publish** the plugin's assets into the project (overwriting).
3. Record them in `var/plugin-assets.json`.
4. Run `migrate:install` + `migrate:run --force` so the tables exist.

**On disable**

1. Remove the provider entry (and its doc comment / import) from the bootstrap.
2. Offer to **unpublish**. Declining leaves files and the DB untouched.
3. Accepting rolls back *only that plugin's* migrations (`migrate:reset` scoped
   to its files), then deletes the published files and clears the manifest.

**make:\* (author-time)**

`make:migration` / `make:seeder` / `make:factory` write into the *plugin's*
`database/` directory and are **not** published. They ship with the plugin and
publish on the next enable. Migrations get a UTC timestamp prefix
(`YYYY_MM_DD_HHMMSS`) for correct ordering.

> **Project plugins must be autoloadable** for the auto-migrate step to boot the
> kernel. The project template maps `"Plugins\\": "plugins/"` in
> `composer.json`; run `composer dump-autoload` after creating a new project
> plugin.

---

## Environment variables

```
HKM_PHP_BIN            override the php binary           (default: php)
HKM_CLI_PATH           override the target php CLI script
HKM_KERNEL_HOME        kernel root (registry at <root>/projects/projects.json)
HKM_DEV_HOME           development kernel checkout used by --dev (hkm-config set-dev-home)
HKM_GLOBAL_AUTOLOAD    override the kernel vendor/autoload.php
PSP_GLOBAL_AUTOLOAD    explicit kernel autoload (exported to child PHP)
PSP_PROJECTS_DIR       dir holding the kernel projects.json registry
HKM_TEMPLATES_DIR      override the scaffolding templates directory
```

## --dev — target the development kernel

Every command accepts `--dev` (anywhere in the args; stripped before command
parsing). It pins that ONE invocation to the DEVELOPMENT kernel instead of the
installed stable copy — for contributors keeping both side by side:

```bash
hkm-config set-dev-home ~/code/php-service-platform   # one-time (validated)
hkm run my-shop            # stable kernel (/opt/hkm-kernel)
hkm run my-shop --dev      # SAME project on the dev checkout
hkm doctor --dev           # confirm what --dev resolves to
```

Resolution order: `HKM_DEV_HOME` (works from the installed binary, anywhere) →
walk UP from a repo-built launcher to the nearest `composer.json`. It exports
`HKM_KERNEL_HOME` + `HKM_CLI_PATH` for the child process only — nothing
persistent changes. When no dev kernel is found, `--dev` fails loudly; it never
silently falls back to the stable kernel.

**Resolution order**

- **Kernel plugins dir:** `HKM_KERNEL_HOME/plugins` → registry-root `/plugins` →
  project `/plugins`.
- **Kernel autoload:** `PSP_GLOBAL_AUTOLOAD` → `HKM_GLOBAL_AUTOLOAD` →
  `HKM_KERNEL_HOME/vendor` → registry-inferred kernel root.
- **Templates:** `HKM_TEMPLATES_DIR` → `HKM_KERNEL_HOME/tools/src/templates` →
  next to the binary → FHS `share/hkm/templates` → registry-inferred kernel root.

---

## Typical workflows

**Start a new project**

```bash
hkm new ./my-shop --project=shop
cd ./my-shop
composer install
hkm run                      # http://127.0.0.1:8000
```

**Add and wire a plugin**

```bash
hkm plugins create billing            # scaffold under plugins/Billing
hkm plugins make:migration billing invoices
composer dump-autoload                # make the plugin autoloadable
hkm plugins enable billing            # publish assets + migrate
hkm plugins                           # confirm it is enabled
```

**Remove a plugin cleanly**

```bash
hkm plugins disable billing           # answer "y" to unpublish + rollback
hkm plugins delete billing            # remove the folder (confirms first)
```
