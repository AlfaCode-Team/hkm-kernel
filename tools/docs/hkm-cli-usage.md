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
hkm install [path|name]      register a project, restore var/userdata/plugins after a git clone
hkm run [path|name]          run a project locally (PHP dev server / Swoole)
hkm cli [command]            run a project's console interactively
hkm worker [args]            run a project's queue worker
hkm service [verb]           run that worker as a systemd/launchd service
hkm list                     list registered projects        (alias: ls)
hkm update <path|name>       refresh a project's registry entry
hkm plugins [subcommand]     analyse / manage plugins         (alias: modules)
hkm ui [subcommand]          federate enabled plugins' UIs into the frontend
hkm ppkg [subcommand]         native package tooling — autoloader, test envs
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

## hkm install — bring a cloned/pulled project up to a runnable state

`plugins/`, `var/*` and `userdata/storage/*` are gitignored on purpose: plugin
source is fetched from its own git remote, and `var/`/`userdata/` are runtime
state, not source. That means a project pushed to git and pulled somewhere
else — a teammate's machine, a fresh server, CI — is missing all three and
will not boot. Run `hkm install` once, from inside the project, to fix that.

```
hkm install [path|name] [options]

--no-register             skip kernel registry registration
--no-key                   skip creating .env / generating APP_KEY
--no-install               skip composer install
--no-plugins               skip fetching the bootstrap's plugins
--no-chmod                 skip fixing var/ and userdata/ mode bits
--verify-plugins           run each plugin's own test suite while installing (slow)
--production, --prod       harden the WHOLE tree: code 0750/0640, var+userdata 2770/0660
--owner=<user>[:<group>]   chown the whole project to this user[:group] (needs root/sudo)
```

What it does, in order: registers the project in the kernel registry; recreates
`var/logs`, `var/cache/manifests`, `var/tmp`, `var/locks`, `var/sessions`,
`var/queue` and `userdata/storage` if missing; chmods `var/` and `userdata/`
(and anything already inside them) writable; creates `.env` from `.env.example`
and generates `APP_KEY` if either is missing or empty (never overwrites a real
key); runs `composer install`; then fetches every plugin the project's own
`app/bootstrap/app.php` wires, the same fetch-and-lock step `hkm new` runs
right after scaffolding.

### `--production` / `--owner` — correct permissions AND ownership on a server

Dev mode chmods `var/`/`userdata/` to `0775`/`0664` and stops there — good
enough when the files are already owned by whoever is running `hkm`. On a real
server that is never the case: the web server / PHP-FPM pool runs as its own
account (`www-data`, `nginx`, `app`, …), and it needs to READ every PHP file in
the project and EXECUTE (traverse) every directory holding one — not just write
to `var/`. Chmod alone cannot arrange that; only `chown` can.

Passing `--production` or `--owner=` runs a **hardening pass over the whole
project**, as the LAST step of the install — after `composer install` and the
plugin fetch, because both create files (`vendor/`, `plugins/`) owned by
whoever ran the command.

The model is **split ownership**: the deploy account keeps the code, the pool
reaches it through the GROUP.

| Path | `--production` | default (`--owner` alone) |
|---|---|---|
| directories holding code | `2750` | `2755` |
| files holding code | `0640` | `0644` |
| an already-executable file (`bin/psp`, `vendor/bin/*`) | `0750` | `0755` |
| `var/`, `userdata/` directories | `2770` | `2775` |
| `var/`, `userdata/` files | `0660` | `0664` |
| `.env` | `0640` | `0640` |
| `.git` | untouched | untouched |

**There is no chmod-only version of this.** Opening the tree with
`chmod -R o+rX` looks like it would avoid the chown, and it cannot: `.env`
carries `APP_KEY` and the database password, so world-readable is the one thing
it must never be — and `var/cache/manifests` needs the pool to WRITE (the boot
compiles manifests into it on every request unless `BOOT_CACHE=1`), which no
amount of "execute for others" grants. Reaching the tree through a shared GROUP
is what covers both.

Five details are load-bearing:

- **`.env` is `0640`, never `0600`.** PHP-FPM has to read `APP_KEY`, and a
  `0600` `.env` owned by the deploy user is the single most common reason a tree
  that runs fine from the shell fails to boot under a pool running as another
  account. Group-readable, never group-writable, never world-anything.
- **Code is never group-writable**, in either profile. An FPM pool that can
  rewrite the PHP it is executing turns any file-write bug into remote code
  execution. Only `var/` and `userdata/` — which the application genuinely
  writes — are group-writable.
- **Every directory carries setgid** (the `2` prefix), code included. The group
  is the only thing granting the pool access, and a file created later — a log
  the pool writes at 3am, a file a `git pull` lands — otherwise takes the
  creating account's primary group and drops out of the share. Without it every
  deploy silently un-shares whatever it touched, and the site 500s on a file
  that was readable an hour ago.
- **An already-executable file keeps its exec bit**, re-granted only where the
  profile grants read (so `0640` → `0750`, never `0751`). A flat `chmod 0640`
  over the tree strips `bin/psp` and `vendor/bin/*`, and the install looks like
  it worked right up until the first invocation.

`.git` is skipped by both the chown and the chmod: it holds the whole history —
every secret ever committed and later removed included — nothing in a request
path reads it, and leaving it alone means the deploy user can still `git pull`
after a `sudo hkm install`.

After the pass the command **re-stats what it changed** and reports any mode
that did not actually take (a filesystem refusing setgid, an ACL, not being
root), rather than reporting success for a chmod the kernel rejected.

It also checks whether the pool can REACH the project at all: opening
`/home/deploy/shop/app/public_html/index.php` needs execute on every directory
down that path, and a home directory is `0700` on a stock Debian install.
Nothing inside the project can fix that, so the offending parents are named —
and reported only, never changed: widening a directory outside the project is
the operator's call.

- `--owner=<user>[:<group>]` is passed straight through to the system `chown`,
  so `www-data`, `deploy:www-data` and `:www-data` (group only) all work.
  Requires root/sudo unless the process already owns the files; a failed chown
  is reported, never swallowed silently.
- **`--owner=:www-data` (group only) is the form to reach for when the tree is
  deployed by a CI account** — a Bitbucket/Jenkins user, a `git pull` from a
  developer's login. It changes the GROUP and leaves the OWNER alone, so the
  deploy account keeps writing to its own checkout while the pool gets in
  through the group. Re-run it after a deploy that adds files; setgid keeps the
  ones created inside existing directories correct on its own.
- Set `HKM_PROD_OWNER` once in your deploy environment to avoid repeating
  `--owner=` on every run; an explicit `--owner=` flag always wins.
- `--production` with no `--owner` (and no `HKM_PROD_OWNER`) still applies the
  mode bits, but warns that ownership was left unchanged instead of guessing —
  correctness matters more than convenience, and guessing wrong on a shared box
  is worse than asking.

```bash
cd my-shop && hkm install       # after a fresh git clone
hkm install ./my-shop
hkm install shop                # by registered name
hkm install --no-install        # vendor/ already cached — skip composer
sudo hkm install --production --owner=deploy:www-data     # on a server
HKM_PROD_OWNER=deploy:www-data sudo hkm install --production
```

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
hkm worker --queue=emails --max-iterations=100 --memory=256
```

The worker entry point parses its own flags — `-q/--queue`, `-n/--max-iterations`,
`--memory`, `-h/--help` — and each one overrides the matching environment
variable (`WORKER_QUEUE`, `WORKER_MAX_ITERATIONS`, `WORKER_MEMORY_LIMIT_MB`). An
argument it does not recognise is an error, not something it ignores.

---

## hkm service — supervise the worker

`hkm worker` is a foreground process: it dies with the terminal, it does not come
back after a crash or a reboot, and nothing collects its output. `hkm service`
generates the unit that fixes all three, for whichever supervisor the host runs.

```
hkm service [path|name]      show the unit that would be generated (writes nothing)
hkm service write            write it to <project>/var/service/
hkm service install          place it in the system and reload the manager
hkm service remove           stop, disable and delete the installed unit

-q, --queue=NAME             queue to drain (default: WORKER_QUEUE from .env, else 'default')
    --name=UNIT              unit name (default: hkm-worker-<project>[-<queue>])
    --run-as=USER[:GROUP]    systemd User=/Group= (system scope; default: the invoking user)
    --max=N                  pass --max-iterations=N to the worker
    --memory=MB              pass --memory=MB to the worker
    --system | --user        install scope (default: system on Linux, user on macOS)
    --platform=systemd|launchd   override host detection
    --hkm-bin=PATH           launcher the unit executes (default: hkm on PATH)
    --php-bin=PATH           php the unit pins (default: php on PATH)
    --exec=hkm|php           ExecStart runs the launcher (default) or php directly
    --out=DIR                write: put the file here instead of var/service/
    --start                  install: enable and start it immediately
    --force                  overwrite an existing unit file
-y, --yes                    do not ask before writing to a system location
-n, --dry-run                report every write and command, perform none of them
```

```bash
hkm service --queue=mails                      # preview, change nothing
hkm service install --queue=mails --start -n   # what install would do, done to nothing
hkm service install --queue=mails --start      # install and run it now
hkm service install shop --queue=mails --run-as=deploy:www-data
hkm service write --platform=systemd --out=./deploy   # a Linux unit, from a Mac
hkm service remove --queue=mails
```

`--dry-run` (`-n`) applies to `write`, `install` and `remove`: each reports every
file it would write and every command it would run, and does none of it.

```
$ hkm service install --platform=systemd --queue=mails -n
would write  /srv/shop/var/service/hkm-worker-shop-mails.service  (1104 bytes)
would run    sudo mkdir -p /etc/systemd/system
would run    sudo cp -f /srv/shop/var/service/… /etc/systemd/system/…
would run    sudo chmod 644 /etc/systemd/system/hkm-worker-shop-mails.service
would run    sudo systemctl daemon-reload
```

It still refuses over an existing unit without `--force`, because that is what
the real run would do. The one filesystem touch it makes is a create-and-delete
write probe in the destination directory — that is how it knows whether to tell
you `sudo`, and on a directory needing root the probe writes nothing at all.

| | systemd | launchd |
|---|---|---|
| `--system` | `/etc/systemd/system/<name>.service` | `/Library/LaunchDaemons/<label>.plist` |
| `--user` | `~/.config/systemd/user/<name>.service` | `~/Library/LaunchAgents/<label>.plist` |
| default scope | system | user |
| logs | journal (`journalctl -u <name> -f`) | `<project>/var/log/<name>.{out,err}.log` |

Three things the generated unit gets right that a hand-written one usually does
not:

- **`ExecStart` runs the launcher**, `hkm worker -p <root>`, not `php` plus an
  absolute `vendor/autoload.php`. The launcher self-locates the kernel, so a
  kernel upgrade that moves a version-stamped install directory cannot silently
  break the queue.
- **`TimeoutStopSec` / `ExitTimeOut` is 90s.** The worker traps SIGTERM and
  finishes the job in flight before exiting — that is what makes a redeploy
  safe — and a shorter timeout SIGKILLs it mid-transaction instead.
- **`PATH` and `HKM_PHP_BIN` are pinned.** A service does not inherit a login
  shell's PATH, and `/opt/homebrew/bin` is on neither systemd's nor launchd's
  default. Without the pin the worker dies with `error: FileNotFound` and
  nothing in the log names php.

Nothing outside the project is touched unless the verb is `install` or `remove`;
`install` always leaves a copy of the unit in `<project>/var/service/` so what
was installed stays reviewable. A systemd `--user` unit stops when you log out —
`loginctl enable-linger $USER` keeps it running.

Run one unit per queue: repeat the command with a different `--queue`, or pass
`--name` to run two units on the same one.

### `--exec=php` — when the server has no launcher

`--exec=hkm` (the default) is right whenever `hkm` is installed on the machine
that runs the worker. When it is not — a deploy artefact, a container, a server
where the kernel arrives only through composer — `--exec=php` puts php and the
entry point in `ExecStart` instead:

```bash
hkm service write --platform=systemd --exec=php \
  --name=hkmstd-worker --queue=mail \
  --run-as=www-data:www-data --php-bin=/usr/bin/php
```

```ini
ExecStart=/usr/bin/php /srv/hkmstd/app/worker/run.php --queue=mail
Environment=HKM_GLOBAL_AUTOLOAD=/opt/hkm-kernel/vendor/autoload.php
```

The trade is stated in the unit itself: nothing in that command line can locate
the kernel, so the autoload is pinned, and an upgrade that moves the kernel
directory breaks the unit until `hkm service` is re-run. That is the whole reason
the launcher is the default. Two smaller points the generated form also fixes:
the script path is **absolute** (`app/worker/run.php` only resolves because
`WorkingDirectory` happens to be set, and the two lines then depend on each other
silently), and the queue is a command-line flag rather than
`Environment=WORKER_QUEUE=…`, so `ps` shows which queue a given process is on.

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
hkm plugins update  [plugin] [proj]        publish new assets, refresh unedited ones, keep edited ones + migrate
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
--overwrite          update/upgrade: replace published files you edited (see below)
--help, -h           show command help
```

### Published files you edited are kept

`enable`, `update` and `upgrade` copy a plugin's `config/`, `database/` and
`resources/` files into the project, and `var/plugin-assets.json` records a
SHA-256 of every file as it was published. On the next `update`, each file is
compared with the plugin's current copy:

| Project file | What happens |
|---|---|
| missing | published |
| identical to the plugin's copy | nothing (its hash is recorded) |
| unchanged since it was published | refreshed with the plugin's new version |
| edited, or never recorded | **kept**; the plugin's version is written beside it as `<file>.plugin-new` |

A kept file is listed in the output, with the other plugin named when two
plugins publish the same path. Merge the `.plugin-new` by hand and delete it; it
is removed automatically once the file matches the plugin again. No compiler
stage reads it — config, migration and view globs all match `.php` only.

`--overwrite` restores the old behaviour for one run: every differing file is
replaced with the plugin's copy. A manifest written before hashes existed
records none, so on its first update every file that differs is kept, never
overwritten.

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

## hkm ppkg — native package tooling

Composer work, done natively — a drop-in replacement for the commands a project
actually uses. Everything that operates on what is already on disk — `install`
from a warm cache, `autoload`, `test-env`, `show`, `why`, `licenses`,
`validate`, `status`, `config` — is offline by construction. `resolve`,
`update`, `require`, `outdated`, `audit` and `search` ask packagist.org, and say
so.

The engine behind these subcommands is a **separate repository**,
`modules/hkm-ppkg`, wired in by `tools/build.zig` as the `ppkg` module. It knows
nothing about hkm: argument parsing, the kernel-specific paths and the output
style are on this side, and the package manager is on the other. Its own README
carries the coverage claims and the list of what Composer does that it does not
— read that before relying on a behaviour this page does not name.

A checkout without it is incomplete; `tools/` will not build:

```sh
git submodule update --init modules/hkm-ppkg
```

```
── the tree ──────────────────────────────────────────────────────────────────
hkm ppkg install [path]       build vendor/ from composer.lock — no resolution
hkm ppkg update [path|pkg…]   resolve composer.json and WRITE composer.lock
hkm ppkg autoload [path]      regenerate vendor/composer/autoload_*.php
hkm ppkg test-env <dir>       build a plugin's test vendor/ from what is on disk
hkm ppkg resolve [path]       resolve composer.json against packagist
hkm ppkg reinstall <pkg>      delete a package so the next install replaces it
hkm ppkg exec [bin] [args]    run a binary from the project's bin dir
hkm ppkg clear-cache          empty the download and metadata cache

── the manifest ──────────────────────────────────────────────────────────────
hkm ppkg require <pkg>[:<c>]  add a dependency, re-lock and install
hkm ppkg remove <pkg>         drop a dependency, re-lock and install
hkm ppkg init --name v/p      write a new composer.json
hkm ppkg config <key> [val]   read or write one key (--list, --unset)
hkm ppkg repository <action>  list/add/remove/set-url/get-url/enable/disable
hkm ppkg policy add-source    record a dependency-policy source (NOT enforced)
hkm ppkg bump                 raise each constraint to the version the lock pins
hkm ppkg run-script <name>    run one entry from the project's scripts block

── reports ───────────────────────────────────────────────────────────────────
hkm ppkg audit                security advisories against the locked versions
hkm ppkg search <terms>       look a package up on packagist
hkm ppkg outdated [path]      compare installed versions against packagist
hkm ppkg show [name]          list installed packages
hkm ppkg why <vendor/pkg>     which installed packages require it
hkm ppkg prohibits <pkg> <v>  what stands in the way of that version
hkm ppkg status               which installed packages differ from the lock
hkm ppkg suggests             optional companions that are not installed
hkm ppkg fund                 how to support the installed packages
hkm ppkg home <pkg>           print a package's homepage url
hkm ppkg licenses             licence summary of what is installed
hkm ppkg validate             check composer.json for later-breaking mistakes
hkm ppkg check-platform-reqs  does this machine satisfy every php/ext-* requirement
hkm ppkg lock --check         is composer.lock in canonical form
hkm ppkg content-hash         composer.lock's content-hash, and whether it matches
hkm ppkg compat [path]        can this tool handle this project, or is composer needed

── the machine, and new projects ─────────────────────────────────────────────
hkm ppkg diagnose [path]      can this machine and this project do the work
hkm ppkg archive [path]       write the project out as a tar or a zip
hkm ppkg create-project v/p [dir] [version]   start a project from a package
hkm ppkg global <cmd> …       run any command against $COMPOSER_HOME
hkm ppkg browse <pkg>         a package's url (--homepage refuses to substitute)
hkm ppkg self-update          is a newer release out, and how to get it
hkm ppkg completion <shell>   a completion script for bash, zsh or fish
hkm ppkg list                 the command index

── on every command ──────────────────────────────────────────────────────────
-q  -n  -d/--working-dir DIR  --no-cache  --ansi/--no-ansi  --no-plugins
```

Three of those do less than Composer's command of the same name, deliberately.
`policy` records a source in `config.policy` byte-identically and enforces
nothing — no policy document is fetched, no package is refused on account of
one — and says so every time it runs, rather than leaving a project to believe
a policy is in force. `archive` archives the PROJECT, not a named dependency — archiving a dependency
means resolving and downloading it, which is a different command wearing the
same name, and it is refused rather than quietly archiving the wrong thing.
`self-update` reports the newest release and the upgrade command for however
this binary was installed; it does not overwrite the file, because Homebrew (or
an installer, or a build tree) owns it and a package manager overwriting a file
Homebrew believes it manages leaves the machine in a state neither tool can
reason about.

### `hkm ppkg compat` — ask before you switch

Reads composer.json and reports anything the native tooling cannot honour,
without touching a file. `install` and `update` run the same audit and refuse
rather than produce a wrong answer that looks right.

```
$ hkm ppkg compat
▲ phpstan/extension-installer — generates
  vendor/phpstan/extension-installer/src/GeneratedConfig.php. Without it phpstan
  will not auto-register extensions — list them in phpstan.neon instead.
└  usable, with the caveats above
```

A finding **blocks** when proceeding would give a wrong answer that looks right
— a different package, or a tree in a directory nothing looks in. It **warns**
when the result is merely incomplete, like a plugin that did not generate its
config file. `--ignore-unsupported` on `install` / `update` downgrades a block,
for an operator who knows their `vcs` entry mirrors the packagist package.

Run it against an INSTALLED tree and it says more: the manifest alone cannot
know which composer plugins are actually there, and "plugins are not loaded" is
not something a reader can act on, while the message above is.

**On this workspace's 48 composer.json files: 46 fully compatible, 2 usable with
caveats, 0 blocked.** (It was 33 blocked before `vcs` repositories were
implemented.) Both remaining caveats are `config.allow-plugins` on a tree with
no `vendor/` yet — see "composer plugins" below for what does and does not run.

### `vcs` repositories, without the rate limit

A plugin here declares **34** of them. Composer's driver reads each through
`api.github.com`: one call for the ref list, one per ref for that ref's
composer.json — against 60 calls an hour unauthenticated. It runs out partway
and falls back to cloning every repository. Measured: **6m15s**.

None of that is needed. Refs come from `git ls-remote` (the git protocol, no
quota), a ref's composer.json from `raw.githubusercontent.com` (static, no
quota), and the archive from the zipball URL that redirects to codeload. Every
manifest is keyed by an immutable commit sha, so it caches forever; ref listings
get a 5-minute TTL, and `--refresh` skips it.

Same project, same answer — 71 packages, identical versions and commit
references — in **1.67s**.

**Every other GIT host works too**, over a bare mirror kept in the cache: refs from
`git ls-remote` against the mirror, a ref's composer.json from
`git cat-file blob <sha>:composer.json`, and the code from `git archive` — so no
`.git` lands in `vendor/`. One fetch per repository per 5-minute TTL, then every
question is a local read. GitLab, Bitbucket, Gitea, self-hosted and `ssh://` all
go this way; it is not slower than Composer, whose generic driver clones too,
but it is slower than the GitHub path, which skips the clone entirely.

Verified against a self-hosted remote: byte-identical `composer.lock` and
byte-identical `vendor/`.

### Mercurial and Subversion

Both read, with `"type": "hg"` / `"type": "mercurial"` and `"type": "svn"` /
`"type": "subversion"` — and behind a bare `"type": "vcs"` too, where the tool
is probed once (git, then hg, then svn) and then used for every question about
that repository.

Each works the way its own tool does. Mercurial gets a `--noupdate` clone in the
cache, like the git mirror, and both `hg branches` and `hg bookmarks` are read:
a repository that uses bookmarks as its branch model has nothing but `default`
in the first, and reading one list would make every version of such a package
vanish without an error.

Subversion gets no cache at all — it is a server protocol, so `svn ls`,
`svn cat` and `svn export` talk to the remote directly and there is nothing
local that could disagree with it. Its references are a path AND a revision
(`/tags/1.2.0/@42`), because a Subversion tag is an ordinary directory anyone
can commit to; `trunk-path`, `branches-path`, `tags-path` and `package-path` are
honoured, including `false` to disable a section.

Both were checked against real repositories: byte-identical `composer.lock`, and
a `vendor/` identical to Composer's except that its source install leaves a
`.hg` / `.svn` directory behind and this one does not. `fossil` and `perforce`
are not read.

`hkm ppkg diagnose` checks for each tool, but only when the project declares a
repository of that kind — and a missing one is a FAILURE there, because without
it the repository is unreadable and its package falls through to whatever
packagist has under the same name.

### `hkm ppkg require` / `remove` — change what a project depends on

```sh
hkm ppkg require psr/log                # work out the best version and pin it
hkm ppkg require psr/log:^3.0           # or say which
hkm ppkg require --dev phpunit/phpunit  # into require-dev
hkm ppkg remove psr/log
hkm ppkg remove 'symfony/*'             # a glob removes everything matching
```

Three steps, in order: edit `composer.json`, re-resolve and write
`composer.lock`, install. If the resolution fails the manifest is **restored** —
a `require` that leaves a package in composer.json but not the lock has put the
project in a state neither `install` nor `update` can explain.

`--no-update` stops after the manifest; `--no-install` stops after the lock;
`--dry-run` writes nothing.

The manifest edit is a splice, not a re-encode: key order, indentation, the
blank line between sections, and `1.0` not becoming `1` all survive, because the
alternative turns a one-line change into a diff nobody reviews. The resulting
`composer.json` **and** `composer.lock` are byte-identical to Composer's, under
`config.sort-packages` too.

`remove` looks only in the section you named. A package found in the other one
is reported with the flag that would remove it, rather than removed — which is
what Composer does non-interactively, and it is the behaviour you want from a
command that deletes.

### `hkm ppkg audit` — advisories against what is locked

```
$ hkm ppkg audit
│  Security advisories
│  guzzlehttp/guzzle 7.9.2       [high] Guzzle: Noncanonical host can bypass host-based checks
│        CVE-2026-69246  ·  affects >=8.0.0,<8.0.1|<7.15.2
│        https://github.com/advisories/GHSA-v5mv-p594-2x33
│  ▲ 9 advisory(ies) match a locked version.
```

**Exits non-zero when anything is found**, so a CI step fails rather than
logging. `--advisory-only` reports and exits 0.

Only the package NAMES are sent to packagist; the version matching happens
locally, against the same constraint algebra the resolver uses. Sending the
versions too would save a little work and hand a third party a complete
inventory of what the project runs.

### `hkm ppkg config` / `init` — the manifest, without an editor

```sh
hkm ppkg config vendor-dir            # read (bare value, pipe-able)
hkm ppkg config vendor-dir lib/vendor # write
hkm ppkg config sort-packages true    # a bare `true` is a bool, not a string
hkm ppkg config --unset vendor-dir
hkm ppkg config --list
```

A bare key means `config.<key>`, the way Composer reads it; `name`,
`autoload`, `extra.*` and the other top-level keys mean themselves. A
set-then-unset round-trip leaves the file byte-identical.

`init` writes a new manifest and refuses to overwrite one without `--force`:

```sh
hkm ppkg init --name acme/my-app --type project --license MIT \
  --author "Jane Doe <jane@example.com>" \
  --require psr/log:^3.0 --require-dev phpunit/phpunit:^11.0 --autoload src/
```

The output is byte-identical to `composer init -n` given the same options —
including Composer's key order, which is neither documented nor alphabetical.

### `scripts` — they run

The install-lifecycle events fire: `pre`/`post-install-cmd`,
`pre`/`post-update-cmd`, `pre`/`post-autoload-dump`. Every entry form Composer
supports works — a shell command, `@another-script`, `@php`, `@composer`,
`@putenv`, and a `Vendor\Class::method` callable run against the project's own
autoloader. The bin directory goes on `PATH`, so `"test": "phpunit"` runs the
project's copy.

**Only the ROOT package's scripts are ever run.** A dependency's are read for
reporting and never executed. That is Composer's rule too, and it is what makes
running them by default defensible: the commands executed are the ones in the
manifest in front of you, not something that arrived in a tarball.

`--no-scripts` on `install` / `update` / `require` / `remove`, or
`HKM_PPKG_NO_SCRIPTS=1`, disables them. `hkm ppkg run-script <name>` invokes one
by name; `--list` shows what a project declares.

Per-PACKAGE events (`post-package-install` and friends) exist for composer
plugins to hook, and nothing here loads a plugin — so `compat` names them if a
project declares one.

### `config.vendor-dir` and `config.bin-dir`

Both are honoured, including `{$vendor-dir}` interpolation and the
`COMPOSER_VENDOR_DIR` / `COMPOSER_BIN_DIR` environment overrides. With
`vendor-dir: lib/vendor` all five generated autoload files are byte-identical to
Composer's — `$baseDir` becomes `dirname(dirname($vendorDir))`, `installed.php`
records `__DIR__ . '/../../../'`, and a `bin/` launcher outside the vendor tree
includes through the right number of `..` hops.

### Private repositories — `auth.json` and friends

A private package without a credential is a **404**, and a 404 during resolution
reads as "no such package" — which sends the reader looking for a typo in a name
that is spelled correctly. So credentials are read from all four sources
Composer reads, in Composer's precedence order, so that CI overrides a
checked-in value:

```
$COMPOSER_HOME/auth.json  →  composer.json config  →  ./auth.json  →  $COMPOSER_AUTH
```

Every scheme Composer has is honoured — `github-oauth`, `gitlab-token`,
`gitlab-oauth`, `bitbucket-oauth`, `http-basic`, `bearer`, `custom-headers` and
`client-certificate` — each in the header spelling it actually uses: a GitHub
token is `Authorization: token …`, a GitLab personal token is
`PRIVATE-TOKEN: …`, and getting that wrong is a 401 rather than a fallback.
`GITHUB_TOKEN` / `GH_TOKEN` are read too, at the lowest precedence; that is an
addition rather than a compatibility claim.

`custom-headers` are sent verbatim. A `client-certificate` is not a header at
all, and Zig's TLS client cannot present one — so a host configured with a
certificate is fetched through `curl`, with the key passphrase on stdin rather
than in an argument `ps` would show to everyone on the machine.

`hkm ppkg diagnose` reports which declared hosts have no credential, and
`resolve` prints the hosts it has one for — **the host and the scheme, never the
secret**.

Redirects are followed by hand, with the credential looked up **fresh for each
hop's URL**. Every dist URL in a lock is a redirect to somewhere else, and a
credential must not travel to a host nobody configured. This is stricter than
the parent-domain rule `std.http.Client` documents — that one would send a
`github.com` token to any `*.github.com`.

### `package` and `artifact` repositories

Both are read, and both produce a byte-identical lock and `vendor/`.

An **`artifact`** repository is a directory of archives — `.zip`, `.tar`,
`.tar.gz`, `.tgz`, `.tar.xz`. The version comes from the composer.json INSIDE
the archive, never from the filename: `mylib-1.0.0.zip` whose manifest says
`2.0.0` is `2.0.0`, and guessing from the name is how a build pins a version
that does not exist. Each manifest is extracted once and cached against the
file's size and mtime.

A **`package`** repository carries the package definition inline. Nothing
validates it, so two mistakes are refused here rather than carried into a lock:
a definition with no `version` (it would match no constraint and silently never
be chosen) and one with neither `dist` nor `source` (it resolves, it locks, and
then it fails to install — on whichever machine runs `install` next, not on the
one that wrote the lock).

### composer plugins — what runs, and what does not

**Composer plugins do not run**, and that is not going to change by adding code.
A plugin is a PHP class handed Composer's own object graph — `Composer\Composer`,
`InstallationManager`, `RepositoryManager`, `IOInterface` and the package model
under all of them — and it may call anything on it. Running one means either
depending on `composer/composer` (which makes a Composer replacement require
Composer) or shipping a shim of that graph, which works until a plugin reaches a
method the shim lacks and then fails PART WAY THROUGH, having already written
some of its output. A plugin that half-ran is worse than one that did not run:
the tree looks finished and is not.

What you get instead is a report you can act on. `hkm ppkg compat` against an
installed tree names each plugin, says whether the project allowed it (one it
did not allow would not have run under Composer either, so it is not a gap), and
says what that specific plugin would have done.

**The one exception is `composer/installers`,** because a wrong LOCATION is a
broken tree rather than a tree with something missing. Its root-package
mechanism is pure data and is implemented natively:

```jsonc
"extra": { "installer-paths": {
    "web/app/mu-plugins/{$name}/": ["acme/must-use"],
    "web/app/plugins/{$name}/":    ["type:wordpress-plugin"],
    "web/app/themes/{$name}/":     ["vendor:acme"]
}}
```

Criteria are `type:x`, `vendor:x` or a literal `vendor/package`; the first PATH
whose criteria match wins, so a specific rule goes above a general one.
Placeholders are `{$name}`, `{$vendor}` and `{$type}`. Verified against Composer
running the real plugin: same tree, same `install-path`, and the same
`$baseDir`-anchored rules in all five autoload files.

Its **built-in** per-framework table — a hundred PHP classes that place a
`drupal-module` under `web/modules/contrib/` with no declaration at all — is not
implemented, and a project relying on it is BLOCKED rather than silently
misplaced.

### `hkm ppkg diagnose` — the failures that present as something else

```
$ hkm ppkg diagnose
│  git             git version 2.50.1
│  php             8.5.10
│  composer.json   acme/shop
│  cache           ~/Library/Caches/hkm/pkg
│  composer.lock   107 packages, current with composer.json
│  vendor          vendor
│  platform        every declared php/ext requirement is met
│  credentials     none configured, and none of the declared repositories needs one
│  packagist       reachable
└  healthy
```

Each check exists because its failure looks like a different problem: no `git`
on PATH makes every `vcs` repository "unreadable", which reads as a network
fault; an unwritable cache makes every install re-download everything, silently,
forever; a lock out of date with composer.json makes `install` faithfully build
requirements nobody declared any more.

A **failed** check exits non-zero; a warning does not. This is a thing CI runs,
and a check that turns a passing build red for a warning gets switched off
within a week. `--offline` skips the packagist reachability check.

### `hkm ppkg archive` — write the project out

```sh
hkm ppkg archive                       # ./<name>-<version>.tar
hkm ppkg archive -f zip --dir dist/    # dist/<name>-<version>.zip
hkm ppkg archive -f tar.gz --file release --ignore-filters
```

The working tree, `vendor/` included, minus version-control directories,
anything `.gitignore` excludes and anything `archive.exclude` excludes — which
is Composer's rule, and the two share one pattern language. `--ignore-filters`
drops the last two; VCS directories go regardless.

The bytes are not identical to `composer archive`'s and cannot be — a zip
records a timestamp per entry — so what is checked is that both archives extract
to **identical trees**, in zip and in tar.gz.

### `hkm ppkg create-project` — start from a package

```sh
hkm ppkg create-project laravel/laravel myapp
hkm ppkg create-project acme/skeleton . "^2.0" --no-dev
```

Downloads the package, unpacks it as a project rather than as a dependency, and
installs its dependencies. If the package shipped a `composer.lock`, it is
**honoured** — that lock is a statement about the versions its author tested, and
re-resolving hands the user a different tree than the one the README describes.
`--ignore-lock` re-resolves instead. `post-create-project-cmd` runs LAST, after
the dependencies are in place, because that hook exists to generate a key or
seed an `.env` and both need a working vendor tree.

A target that exists and is not empty is refused. Unpacking a project over
someone's working directory is not recoverable.

### `hkm ppkg global` — the same commands, in $COMPOSER_HOME

`global` is a prefix, not a command: `hkm ppkg global require acme/tool` is
`require`, run in `$COMPOSER_HOME`. Every subcommand works after it, including
ones added later, because it rewrites the working directory rather than being
told about each one. The directory is the same one Composer uses, so a
`hkm ppkg global require` and a `composer global require` land in one tree.

### `hkm ppkg update` — resolve, and write the lock

The one subcommand that changes a file the rest of a team depends on. It
resolves `composer.json` against packagist and writes `composer.lock`; run
`hkm ppkg install` afterwards to bring `vendor/` in line.

```
$ hkm ppkg update
│  resolved                      37 packages in 2710ms
│  packages                      10
│  packages-dev                  27
│  content-hash                  96e75027321b692cfb511a586c710d53
│  ✓ composer.lock written.
```

`--dry-run` reports whether the lock would change and writes nothing.
`--no-dev` records `"packages-dev": null`, which is what Composer writes for the
same flag — a different file from the `[]` a project with no dev dependencies
gets. Unlike Composer's, this command also runs the install afterwards; pass
`--no-install` for the lock alone.

**Naming packages makes it a PARTIAL update**, which is how the command is
mostly used:

```
$ hkm ppkg update psr/log
│  held at the lock              36 packages
```

Everything not named stays at the version the lock already holds, so the diff
is one package rather than all of them. `-w` also moves what those packages
require (except the project's own direct requirements), `-W` moves those too.
`--root-reqs` restricts the update to direct requirements; `--lock` moves
nothing at all and simply rewrites the file, which is what a manifest edit that
changes no resolution needs.

`--prefer-lowest` takes the floor of every constraint instead of the ceiling —
the CI run that finds out whether the `^1.2` a library published is a version
it has ever been tested against. `--prefer-stable` prefers a stable release
over a newer unstable one, whatever the manifest says.

**The output is byte-identical to `composer update`.** That was verified on five
differently-shaped projects, and Composer installs from a lock this writes
without reporting it out of date. It matters more than it sounds: a lock that is
merely *equivalent* but reordered turns one dependency bump into a
four-thousand-line diff nobody reads.

The resolver behind it is still a backtracking search, not Composer's CDCL SAT
solver — see `hkm ppkg resolve` above for what that means when a graph is hard.

### Platform requirements, and refusing to enforce them

`--ignore-platform-reqs` installs a tree on a machine that does not satisfy it —
a CI image assembling an artefact for somewhere else. Three forms:

```
--ignore-platform-reqs             every requirement, both bounds
--ignore-platform-req=ext-gd       one, by name (repeatable; `ext-*` works)
--ignore-platform-req=php+         only the CEILING, keeping the floor
```

The last is the one worth knowing. A project whose dependencies declare
`^8.1 <8.3` can be tested on PHP 8.5 with `php+` without also claiming it runs
on PHP 5 — which is what the unsuffixed form says, and it is a much larger
claim.

A waived requirement is reported as `ignored`, never as satisfied: the machine
was not checked, and a report saying it passed would be describing an
inspection that did not happen. Ignoring platform requirements also suppresses
`vendor/composer/platform_check.php`, because that file is code the application
runs on every request — writing a check the operator has just waived produces a
tree that installs and then refuses to boot.

### `hkm ppkg repository` — where code comes from

```
$ hkm ppkg repository list
│  [private]                     composer  https://repo.example.com
│  [packagist.org]               composer  https://repo.packagist.org

$ hkm ppkg repository add private composer https://repo.example.com
$ hkm ppkg repository add zips '{"type":"artifact","url":"/srv/zips"}'
$ hkm ppkg repository disable packagist.org
$ hkm ppkg repository set-url private https://repo2.example.com
```

`add` puts the entry FIRST, because resolution is in declaration order and the
reason to add a repository is almost always to have it outrank Packagist;
`--append`, `--before <name>` and `--after <name>` place it otherwise. The file
it writes is byte-identical to `composer repository`'s, `},{` bracket style
included.

Disabling writes `{"packagist.org": false}` — the only way to switch off a
repository the project never declared, and the one that matters.

### `hkm ppkg lock --check` — is the lock in canonical form

Re-renders `composer.lock` from its own contents and compares bytes. A
difference means either the file was hand-edited, or it was written by a
different Composer generation. `--diff` shows both sides of the first differing
line.

### `lib-*` — the linked library versions

`lib-openssl`, `lib-icu`, `lib-curl-openssl`, `lib-pcre-unicode` and the rest
are determined, not skipped. The detection is a port of Composer's whole
`PlatformRepository` switch, kept as PHP in `src/probe.php` so that `php -l`
checks it and its output can be diffed against `composer show --platform`
directly — which it matches, library for library and version for version.

Provided aliases are honoured without being claimed: `lib-libxml` provides
`lib-dom-libxml`, so a constraint on the latter is satisfied, and a listing of
installed libraries does not pretend it is one.

The one thing still reported as unchecked is a machine with **no interpreter to
ask**. That is not the same as a missing extension, and reporting it as one
would be a claim about a machine nobody managed to inspect.

### `hkm ppkg check-platform-reqs` — can this machine run the tree

Checks the root's platform requirements AND every installed package's, against
ONE probe of the interpreter — `php`, ext-* and lib-* in a single process,
because probing per requirement would pay one interpreter start each.
`config.platform` in composer.json overrides the probe, which is how a developer
on 8.5 checks a tree that has to run on the 8.1 in production.

```
$ hkm ppkg check-platform-reqs
│  php                           8.5.10
│  extensions                    70 loaded
│
│  ext-mbstring * — provided by symfony/polyfill-mbstring
│  ext-ctype * — provided by symfony/polyfill-ctype
│
│  satisfied                     16
│  provided by a package         2
```

`--php <bin>` (or `HKM_PHP`) picks the interpreter. `lib-*` requirements are
checked like any other — see the section above. The only thing reported as **not
checked** is a machine with no interpreter to ask, because a requirement nobody
could check must not read as one that was met.

### `hkm ppkg content-hash` — is the lock current

`composer.lock` records an md5 over the parts of composer.json that affect
resolution. When Composer says *"the lock file is not up to date with the latest
changes"* it is comparing this field and telling you nothing else.

```
$ hkm ppkg content-hash
│  computed                      73f3771ab6032584840fafbda3e3f8cc
│  in composer.lock              73f3771ab6032584840fafbda3e3f8cc
│  ✓ The lock is current with composer.json.
```

Exit code 1 when they differ. Verified against `Locker::getContentHash` over 201
real composer.json files.

### `hkm ppkg install` — vendor/ from the lock, without a solver

A `composer.lock` has already chosen every version and records the URL and
immutable reference for each, so installing from one is a DOWNLOAD, not a
resolution. That is also why it is untouched by the rate limit that makes
Composer slow here: the 60-requests-an-hour cap is on GitHub's **metadata** API,
which resolution hammers and a locked install never calls at all. Dist URLs look
like `api.github.com/...zipball/...` but 302 to `codeload`, which costs no quota
— measured, not assumed.

| | cold cache | warm cache |
|---|---|---|
| `composer install` | — | 6.56s |
| `hkm ppkg install` | 24.1s | **2.18s** |

Downloads run concurrently (`HKM_PKG_JOBS`, default 8, capped at 16); only the
network is parallel, while every filesystem change stays on one thread. Serially
the same cold install took 217s at 2% CPU — it was latency, never bandwidth.

What it produces is a complete tree, not just package directories:

- packages unpacked from their zipball, with GitHub's generated root directory
  stripped, staged and moved into place so an interrupted run is safe to re-run;
- **path repositories symlinked**, as Composer does, so an edit in `modules/` is
  live without a reinstall;
- `vendor/composer/installed.json` and `installed.php`, including
  `version_normalized` and `extra.branch-alias`, so `InstalledVersions::satisfies()`
  answers correctly at runtime;
- `vendor/bin/*` launchers — proxies, not symlinks, because the target script
  needs `$GLOBALS['_composer_autoload_path']` set relative to the LAUNCHER.
  Composer emits four different shapes here (a shell proxy for a target that is
  not PHP, a PHP proxy, the same plus a PHP<8 stream wrapper, and PHPUnit's two
  extra workarounds) and so does this, byte for byte;
- the autoloader, by the same code path as `hkm ppkg autoload`.

Composer's own runtime is produced without Composer. `autoload.php` and
`autoload_real.php` are GENERATED from the same templates `AutoloadGenerator`
interpolates; `ClassLoader.php` and `InstalledVersions.php` are its MIT-licensed
source, embedded with its licence text and written out beside them. They used to
be copied from the kernel's vendor tree, which meant an install on a machine
that had never run Composer placed every package and then reported that it could
not produce an entry point.

Nothing else is written into `vendor/`. The record of which reference each
directory holds lives in the cache, so `diff -r` against a Composer-built tree
reports nothing at all.

| Option | Effect |
|---|---|
| `--no-dev` | skip `packages-dev` |
| `-o`, `--optimize` | scan psr-4 roots into the classmap |
| `-a`, `--classmap-authoritative` | never fall back to the filesystem (implies `-o`) |
| `--apcu-autoloader` | memoise class lookups in APCu |
| `--prefer-source` | a git working copy at the locked commit, `.git` and all |
| `--prefer-dist` | the published archive, even where config says source |
| `--ignore-platform-reqs` | do not enforce php/ext-* (see above for the narrower forms) |
| `--no-autoloader` | place packages, generate nothing |
| `--download-only` | fill the cache and stop |
| `--no-progress` | drop the per-package lines, keep the summary |
| `--dry-run` | report what would happen, write nothing |
| `--force`, `-f` | reinstall packages already at the locked reference |

`--prefer-source` is the one with a visible consequence: the package arrives as
a real repository with an `origin` pointing at its own URL, which is what makes
it possible to edit a dependency, see the diff, and produce a patch. A `vcs`
package that publishes no archive at all still arrives as an export — nothing
was promised about `.git` there, and the smaller tree is the better default.

### Integrity — what was checked, and what was not

A dist is verified against the `shasum` in the lock whenever there is one. When
there is not, it is still installed — GitHub publishes no digest for a generated
zipball, so refusing would refuse every `vcs` package, and Composer does not
refuse either — but the install now SAYS so:

```
▲ 3 archive(s) had no checksum in the lock and were installed unverified.
  Pass --require-checksums to refuse instead.
```

`--require-checksums` turns that into a refusal, for a build that may not ship a
byte nobody vouched for. The three failure modes are reported apart, because
each has a different fix: no checksum recorded, a checksum that did not match,
and a download that did not happen.

### `hkm ppkg resolve` — the dependency resolver

Reads `composer.json`, fetches candidate versions from the Packagist v2 metadata
API, and chooses a version for every package. `--check` then diffs that answer
against `composer.lock` package by package.

```
$ hkm ppkg resolve . --check
│  path repositories    5 pinned from disk
│  root requirements    24
│  stability floor      dev  (prefer-stable)
│  metadata             107 packages fetched concurrently
│  resolved             107 packages in 240ms
│  identical            66
│  different version    41
```

Metadata is fetched concurrently and cached for an hour (it is mutable, unlike an
archive keyed by an immutable sha). Candidate lists are memoised per package —
without that the solver re-parses and re-expands every version document each time
it reconsiders a package, which on this tree was the difference between 0.3s and
70s of CPU.

**Rules it implements, each verified against Composer:**

- the full constraint algebra — `^ ~ || comparators wildcards - ranges != @stability`
- versions normalised to four components, with `version_compare` stability order
- `minimum-stability` and `prefer-stable` as a POOL filter, not a constraint:
  `^1.0.0` genuinely accepts `1.0.0-beta1`, and what excludes a beta from an
  ordinary install is the stability floor
- branch aliases — `dev-master` satisfies `^1.0` only when the package declares
  `extra.branch-alias`, which is what makes this kernel's path modules resolve
- `X.Y.x-dev` is a branch alias (an EXACT match), not a range; a bare `1.0` is an
  exact pin on `1.0.0.0`, not the 1.0 series
- path repositories read from disk, including the `gitdir:` indirection that
  every submodule and worktree uses, and the branch-at-this-commit lookup
  Composer's VersionGuesser does for a detached HEAD

**What it is not.** Composer runs a CDCL SAT solver; this is a backtracking
search with a most-constrained-first heuristic and a step budget. It reports
`exhausted` — a distinct outcome from `unsatisfiable` — when it gives up, because
"I could not find one" and "there is not one" are different claims.

**How the agreement is measured.** `testdata/semver_corpus.json` holds 5881
`[version, constraint, answer]` rows — every constraint in this kernel's tree,
crossed with a spread of versions, evaluated by `Composer\Semver\Semver` itself.
The unit test replays all of them and requires zero disagreements. Regenerate it
by extracting the pairs from `composer.lock` + `composer.json` and running them
through `Semver::satisfies`.

### `hkm ppkg outdated` — what has moved since the install

Compares each installed package against Packagist, and separates three cases that
are usually collapsed into one:

```
│  upgradable now             10  (declared in composer.json)
│  needs a wider constraint    3
│  transitive                 24  (moved only by their dependents)
│  not on packagist            3
```

The distinction is the point: an update takes the first group, the second needs
`composer.json` edited first, and the third is not the root project's decision at
all. `--constrained` reports only the first.

### `hkm ppkg status` / `suggests` / `fund` / `prohibits` / `home`

Offline reports over `vendor/composer/installed.json` and the lock.

`status` compares every installed package against the reference it was placed
at, and names the ones that differ or are missing. Path repositories are
symlinks into your own source tree — permanently "modified" by design — so they
are counted and not listed.

`prohibits <pkg> <version>` is the inverse of `why`: it names each installed
package whose `require` or `conflict` would be violated by that version. It is
the answer to "I asked for 3.0 and got 2.4".

`suggests` lists only the suggestions that are NOT already installed, and `fund`
only the packages that declare funding. `home <pkg>` prints a URL rather than
opening a browser — which works over ssh, where opening one does not.

### `hkm ppkg show` / `why` / `licenses` / `validate`

Read-only reports, answered from `vendor/composer/installed.json` — what is
actually on disk, rather than what a lock says should be.

```
$ hkm ppkg why psr/log
│  psr/log                       installed at 3.0.2
│  Required by
│      alfacode-team/let-migrate  requires  ^3.0
│      composer/composer          requires  ^1.0 || ^2.0 || ^3.0   (dev)
│      symfony/cache              requires  ^1.1|^2|^3
└  5 dependent(s)
```

`show` takes an optional substring filter; `licenses` calls out packages with no
declared licence separately, since those are the ones a redistribution review has
to look at. `validate` checks the mistakes that survive an install and surface
much later — a psr-4 prefix without its trailing backslash, an autoload path that
does not exist, an `autoload.files` entry that will fail at boot, a missing lock.

### `hkm ppkg autoload` — a native `composer dump-autoload`

Reads the root `composer.json` and `vendor/composer/installed.json` and rewrites
the five data files Composer's `ClassLoader` consumes:

```
autoload_psr4.php  autoload_namespaces.php  autoload_classmap.php
autoload_files.php  autoload_static.php
```

It does **not** rewrite `autoload.php`, `autoload_real.php` or `ClassLoader.php`.
Those are Composer's runtime, they do not change when a package is added, and the
generator has no business maintaining a copy of someone else's loader. It also
**reuses the existing autoloader suffix**, read out of `vendor/autoload.php` — 
`autoload_real.php` names `ComposerStaticInit<suffix>` literally, so emitting a
different one would leave that call pointing at a class that does not exist.

| Option | Effect |
|---|---|
| `--no-dev` | skip `autoload-dev` rules |
| `-o`, `--optimize` | scan psr-4/psr-0 roots into the classmap |
| `-a`, `--classmap-authoritative` | never fall back to the filesystem (implies `-o`) |
| `--apcu`, `--apcu-autoloader` | memoise class lookups in APCu |
| `--strict-psr` | fail when a class is not where its psr-4 rule says |
| `--check` | compare against what is on disk instead of writing |

`--strict-psr` reports every class a psr-4 rule claims but could never load, in
Composer's own wording and with Composer's exit code:

```
Class Wrong\Misplaced located in src/Misplaced.php does not comply with psr-4
autoloading standard (rule: Acme\ => src).
```

That is not a style complaint. The autoloader turns a class name into a path
arithmetically, so a class in the wrong file is unfindable — and with `-o` the
classmap hides it, which is worse than the plain failure: it works in
production, where the optimised autoloader is generated, and fails in
development, where it is not.

`--check` is how the parity claim is kept honest: run Composer, then run this,
and a clean `--check` is the evidence rather than the assertion. On the kernel —
107 packages, 83 psr-4 rules, 1464 classmap entries, 25 bootstrap files — all
five files come out byte-identical.

```
$ hkm ppkg autoload . --check
│  = autoload_psr4.php
│  = autoload_namespaces.php
│  = autoload_classmap.php
│  = autoload_files.php
│  = autoload_static.php
│  ✓ Generated output matches what is on disk.
```

Note that `vendor/` must already exist: this maintains an autoloader, it does
not fetch packages. Run `hkm ppkg install` (or `composer install`) once, then
this keeps it current. It regenerates `autoload.php` and `autoload_real.php`
along with the data files, because all of them name the same class suffix and
one rewritten without the others leaves `autoload.php` calling a class that no
longer exists.

### `hkm ppkg test-env` — a plugin test suite without a resolve

Writes `<plugin>/vendor/autoload.php`, a bootstrap that delegates to the
**kernel's** autoloader — which already holds phpunit, the kernel itself and
every Packagist package — and layers the plugin's own psr-4 rules on top. A
longer psr-4 prefix always wins in Composer's `ClassLoader`, so `Plugins\Foo\`
registered here takes precedence over the kernel's generic `Plugins\` rule.

Dependencies are found by SEARCHING checkouts already on disk (the plugin's own
parent directory, the kernel plugins dir, the project plugins dir) for one whose
`composer.json` declares that package name — never by fetching.

This is what `hkm plugins install` now uses to verify a freshly fetched plugin.
The old route was a `composer install` inside each plugin, which is a dependency
RESOLUTION: a plugin declares ~34 `vcs` repositories with `dev-master` /
`dev-main` constraints and ships no `composer.lock`, so Composer had to read a
`composer.json` off every branch and tag of all 34 GitHub repositories, per
plugin, with `vendor/` deleted in between so nothing was reused. Without a GitHub
token the API returns 403 after 60 requests an hour and Composer falls back to
full `git clone`s.

The fast route is **skipped, never forced**, whenever it cannot be trusted — no
kernel with a built `vendor/`, no phpunit in it, or a test dependency with no
checkout on disk. Each of those would produce a suite that fails for want of a
class, which reads as the plugin being broken. `hkm plugins install` then falls
back to the Composer route unchanged.

Set `HKM_PLUGIN_TESTS_COMPOSER=1` to force the Composer route always.

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
HKM_GLOBAL_AUTOLOAD    explicit kernel autoload (exported to child PHP)
HKM_PROJECTS_DIR       dir holding the kernel projects.json registry
HKM_TEMPLATES_DIR      override the scaffolding templates directory
HKM_PLUGIN_TESTS_COMPOSER  force `composer install` for plugin test runs
                           instead of the native offline test environment
HKM_PKG_CACHE          where `hkm ppkg install` caches downloaded archives
HKM_PKG_JOBS           concurrent downloads (default 8, max 16)
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
- **Kernel autoload:** `HKM_GLOBAL_AUTOLOAD` → `HKM_GLOBAL_AUTOLOAD` →
  `HKM_KERNEL_HOME/vendor` → registry-inferred kernel root.
- **Templates:** `HKM_TEMPLATES_DIR` → `HKM_KERNEL_HOME/templates` →
  `<exe_dir>/templates` → FHS `<exe_dir>/../share/hkm/templates` →
  registry-inferred `<kernel_root>/templates`.

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
