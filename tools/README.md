# hkm — PhpServicePlatform CLI (Zig)

Native launcher + project tooling for the AlfacodeTeam PhpServicePlatform.
Builds two binaries: `hkm` (the launcher/CLI) and `hkm-config`.

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
