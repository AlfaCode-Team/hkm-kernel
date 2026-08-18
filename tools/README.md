# hkm — PhpServicePlatform CLI (Zig)

Native launcher + project tooling for the AlfacodeTeam PhpServicePlatform.
Builds two binaries: `hkm` (the launcher/CLI) and `hkm-config`.

## Install (no root)

```sh
# latest release, into ~/.local — nothing is written outside your home
curl -fsSL https://github.com/AlfaCode-Team/hkm-kernel/releases/latest/download/install.sh | sh

# or, from a downloaded tarball / this checkout
./tools/install.sh hkm-kernel-1.2.3-linux-x86_64.tar.gz
./tools/install.sh --version v1.2.3
HKM_PREFIX=/srv/hkm ./tools/install.sh
./tools/install.sh --uninstall
```

| Path | Holds |
|---|---|
| `~/.local/bin/hkm`, `hkm-config` | the launcher |
| `~/.local/lib/hkm-kernel/` | kernel source + `vendor/` |
| `~/.config/hkm/config.env` | launcher config — outside the install tree |
| `~/.local/share/hkm/` | project registry — outside the install tree |

Upgrades replace the kernel tree but carry `projects/projects.json` and
`projects/platform.json` across; `--uninstall` leaves your config and registry
alone.

**The `bin/` + `lib/hkm-kernel/` pairing is load-bearing.** `resolveHome()` in
`src/lib/kernel.zig` probes `<parent-of-exe-dir>/lib/hkm-kernel`, which is why
the launcher finds its kernel with no env var and no config file — both from an
extracted tarball run in place and from `~/.local`. Change one side and you must
change the other.

**The install has no preconditions.** It does not require PHP, composer, git or
node to be present — it copies files into your home, runs `hkm doctor`, and
finishes successfully either way. Gating it on a runtime an administrator has
not installed yet would leave you without the binary that tells you what to ask
for.

## `hkm doctor` — what the kernel needs

The single authority on whether this machine can run the kernel. It enumerates
every requirement, not just PHP:

| Section | Checks |
|---|---|
| Launcher | this binary, whether its dir is on `PATH`, whether another `hkm` shadows it |
| Kernel | kernel root, PHP CLI, `composer.json`, `src/`, the four first-party `modules/`, `vendor/autoload.php`, writability |
| Configuration | `config.env`, a stale `HKM_KERNEL_HOME` pin, userdata dir + writability, the registry |
| Tooling | `php`, `composer`, `git`, `node`, `npm` — each labelled with what needs it |
| PHP runtime | version >= 8.4.1, the nine required extensions, a PDO driver, `memory_limit`, plus optional redis/swoole/gd/intl/zip/sodium/opcache |

Output ends in two lists, each line carrying the command that fixes it:

- **Must fix** — blocks the kernel. Exit code 1, so `hkm doctor` gates CI.
- **Worth fixing** — warns only. Exit code 0.

The extension and version checks are asked of PHP itself through a `php -r`
preflight, so they describe the exact runtime a project will use rather than a
guess. With no `php` on PATH that section is skipped and reported, not fatal to
the run.

Installing **PHP and its extensions** is the one part that needs an
administrator. Everything else `doctor` reports, you can fix yourself.

### The `.deb` (system-wide, needs root)

`hkm-kernel_<version>_amd64.deb` installs `/opt/hkm-kernel` + `/usr/bin/hkm` for
**all** users, with apt managing the PHP dependency chain. Use it for multi-user
machines, servers and CI images. It is the exception; the tarball is the default.

Root is required there for packaging reasons only — `dpkg` must run as root,
`/opt` and `/usr/bin` are root-owned, `Depends:` drives apt, and `postinst` runs
composer into a root-owned tree. The launcher itself has never needed root.

Note `/usr/bin` normally precedes `~/.local/bin` on `PATH`, so a leftover `.deb`
install silently shadows a user install. `install.sh` warns when it sees one;
remove it with `sudo apt remove hkm-kernel`.

## Two installs on one machine

A system install and a user install **coexist by design** and are updated
separately. Everything below follows from that, and `hkm version` is the command
that shows the whole picture at once:

```
$ hkm version
  scope   kernel                        kernel version   launcher
  system  /opt/hkm-kernel               1.3.1            /usr/bin/hkm (1.3.1)
→ user    ~/.local/lib/hkm-kernel       1.4.0            ~/.local/bin/hkm (1.4.0)
```

Three versions are in play and they can all differ: the **launcher** binary's
compile-time stamp, the **kernel** on disk (from its `composer.json`), and one
of each per scope. `hkm --version` still prints just this launcher's, for
scripts.

### Which install does `hkm upgrade` touch?

Privilege decides, so the two forms are two predictable commands rather than one
command with a machine-dependent target:

| Command | Target | Artifact |
|---|---|---|
| `hkm upgrade` | `~/.local` (this user) | the linux `.tar.gz` + its `install.sh` |
| `sudo hkm upgrade` | `/opt` + `/usr/bin` | the `.deb`, via apt |
| `hkm upgrade --user` / `--system` | forces either | as above |

`hkm upgrade --check` reports the scope you asked about and names the *other*
one when it is also behind — because "you are on the latest version" is
misleading when the launcher your `PATH` resolves belongs to the scope that was
not checked.

Versions come from the **kernel being replaced**, never from `banner.version()`.
Comparing the launcher's compile-time stamp to the latest tag answered "is this
binary current" while the command went on to replace a kernel somewhere else.

### Kernel resolution, and why a config pin no longer wins

`~/.config/hkm/config.env` is read by **every** `hkm` on the machine. When
`HKM_KERNEL_HOME` was checked first, whichever installer wrote it last silently
redirected the other install:

```
$ /usr/bin/hkm --version   → 1.3.1                       # the .deb's launcher
$ /usr/bin/hkm doctor
  kernel root  ~/.local/share/hkm/kernel                 # …the USER's kernel
  resolved via HKM_KERNEL_HOME override
```

Upgrading either scope then looked like a no-op. Resolution now ranks sources by
how specific they are to *this* invocation (`src/lib/kernel.zig`):

1. `HKM_CLI_PATH` / `HKM_KERNEL_HOME` **exported in the real environment**
2. **self-location** relative to the launcher's own executable — per-install by
   construction, so the other scope cannot affect it. A launcher in a system bin
   dir (`/usr/bin`) claims `/opt/hkm-kernel` here, since no relative probe can
   reach it from there
3. `HKM_KERNEL_HOME` from `config.env` — now a **fallback**, for custom layouts
   self-location genuinely cannot find
4. `/opt/hkm-kernel`

So a pin still works wherever it was actually needed; it no longer overrides an
install sitting next to the binary. `hkm-config check` writes one only when
self-location failed, and `hkm-config unset HKM_KERNEL_HOME` clears a stale one.

### `hkm uninstall` — remove everything, keep the projects

```sh
hkm uninstall --dry-run    # print the plan, delete nothing
hkm uninstall              # everything this user can remove
sudo hkm uninstall         # …including the .deb under /opt + /usr/bin
```

| Removed | Kept |
|---|---|
| `/opt/hkm-kernel`, `~/.local/lib/hkm-kernel`, the pre-1.4 user kernel | **your projects**, wherever they live |
| `/usr/bin/{hkm,hkm-config}`, `~/.local/bin/{hkm,hkm-config}` | **`projects.json`** + **`platform.json`** |
| `~/.config/hkm` (config.env), the shared plugin store | |
| the `hkm-kernel` dpkg registration (`remove`, never `purge`) | |

The two kept items are protected **by construction, not by a filter**:

- Every path the command can delete is *computed* from the install layout. None
  is read from the registry, the working directory, or an argument — so a
  project directory cannot appear in the plan at all.
- `projects.json` and `platform.json` are **rescued into the userdata directory
  before anything is deleted**, so the registry survives even when its only copy
  was inside the kernel tree being removed. Reinstall later and `hkm list` still
  shows every project.

Two rules in the rescue exist because testing found them the hard way: it only
copies from a tree it is *actually removing*, and it prefers the user's kernel
over the system one. The `.deb` ships `projects/projects.json` as `{}` (a
packaged conffile), and without both rules that empty default won the race to the
destination and shadowed the user's real project list.

`tools/install.sh --uninstall` remains deliberately narrow — it removes only what
that script installed, at that prefix. `hkm uninstall` is the full removal.

### `hkm upgrade --local`

Installs the current checkout over an installed kernel, obeying the same scope
rule (non-root → your user install, which it creates if absent). It stamps the
`git describe` version into the destination `composer.json`, so the result can
report what it is — without that, a locally installed kernel read `unstamped`
forever and had nothing to compare on the next upgrade.

## Layout

```
tools/
├── build.zig              # build graph (defines the hkm + hkm-config binaries)
├── .zig-version           # pinned Zig toolchain
└── src/
    ├── main.zig           # hkm entry: parses argv, dispatches to a command
    ├── config.zig         # hkm-config entry (separate binary)
    ├── commands/          # one file per subcommand — each exposes `run(...)`
    │   ├── new.zig        #   hkm new     — scaffold a project
    │   ├── run.zig        #   hkm run     — serve / swoole / cli / worker (+ --pick)
    │   ├── list.zig       #   hkm list    — list registered projects
    │   └── update.zig     #   hkm update  — refresh a registry entry
    ├── lib/               # shared modules used by the commands
    │   ├── prompt.zig     #   terminal UI: intro/note/select/text/confirm…
    │   ├── registry.zig   #   projects.json read / list / upsert
    │   └── util.zig       #   path / string / filesystem helpers
    └── templates/         # scaffolding templates (read at runtime, NOT embedded)
```

### Design

- **`main.zig` only routes.** It maps a command word to `commands/<cmd>.run(...)`
  and renders the root help. No business logic lives here.
- **Every command exposes the same entry signature** so dispatch stays uniform:
  ```zig
  pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap,
             args: []const []const u8) !u8   // returns the process exit code
  ```
- **`lib/` holds anything shared.** Commands import it via `../lib/<name>.zig`.
  Keep cross-command logic here, not duplicated across commands.
- **Templates are read from disk** (see `commands/new.zig` resolution order), so
  they can be edited without recompiling.

## Build

```sh
zig build                  # Debug (~13MB, full safety + debug info) — for dev
zig build --release=safe   # ~4MB, keeps runtime safety checks — recommended ship
zig build --release=small  # ~230KB, smallest (no safety checks)
```

Binaries land in `zig-out/bin/{hkm,hkm-config}`.

## Dev environment — stable install + dev checkout side by side (`--dev`)

A contributor typically has TWO kernels on the machine:

| Kernel | Where | Used when |
| --- | --- | --- |
| **Stable** (installed) | `/opt/hkm-kernel` (from the `.deb` / release bundle) | everyday `hkm …` — real projects keep working |
| **Dev** (checkout) | the cloned monorepo, e.g. `~/Documents/HKMCODE` | `hkm <command> --dev` — testing framework changes |

`hkm` always targets the stable install (via `HKM_KERNEL_HOME` in
`~/.config/hkm/config.env`). Appending `--dev` to ANY command pins that ONE
invocation to the dev checkout instead — it exports `HKM_KERNEL_HOME` +
`HKM_CLI_PATH` for the child process only, so nothing persistent changes and
the flag never leaks into downstream arg parsing.

### One-time contributor setup

```sh
git clone <repo> ~/Documents/HKMCODE
cd ~/Documents/HKMCODE && composer install        # dev kernel needs its vendor/
hkm-config set-dev-home ~/Documents/HKMCODE      # register the checkout (validated)
```

### Daily use

```sh
hkm run my-shop            # stable kernel — production behaviour
hkm run my-shop --dev      # SAME project, but on your patched dev kernel
hkm doctor --dev           # confirm which kernel --dev resolves to
```

`--dev` resolves the dev kernel in this order:

1. **`HKM_DEV_HOME`** (set once via `hkm-config set-dev-home`) — works from
   anywhere, including the installed `/usr/bin/hkm`.
2. **Self-location** — when you run a repo-built launcher
   (`tools/zig-out/bin/hkm`), it walks UP from its own executable to the nearest
   ancestor holding `composer.json`. No config needed inside the checkout.

If neither resolves, `--dev` fails loudly (it never silently falls back to the
stable kernel — a "dev" run must never accidentally test production code).

## Adding a command

1. Create `src/commands/<name>.zig` with the standard `run(...)` signature.
   Import shared helpers with `@import("../lib/prompt.zig")` /
   `@import("../lib/registry.zig")`.
2. In `src/main.zig`: add `const <name>_cmd = @import("commands/<name>.zig");`,
   a dispatch branch (`if (std.mem.eql(u8, cmd, "<name>")) ...`), and a
   `prompt.item(...)` line in `printHelp()`.
3. Give the command its own `printHelp()` shown on `--help` / bad args.
