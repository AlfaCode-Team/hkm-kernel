# Changelog

All notable changes to the AlfacodeTeam PhpServicePlatform (Sentinel) kernel are
documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.4.3] - 2026-08-28

### Fixed
- **`install.sh` / `install-macos.sh` crashing with `unbound variable`.** Both
  installers' PATH hint used `${SHELL##*/}`, which throws under `set -u`
  whenever `$SHELL` isn't exported in the invoking environment — unlike
  `$HOME`, POSIX doesn't guarantee it, and it's commonly absent under
  `curl | sh`, cron, and some IDE task runners. Guarded with `${SHELL:-}` and
  matched by suffix instead, so a missing value now just falls through to the
  generic PATH-hint case instead of aborting the whole install.

## [1.4.2] - 2026-08-28

### Added
- **`tools/install-macos.sh`** — a macOS-specific installer. Previously
  `tools/install.sh` (Linux-only auto-download) simply refused to run on
  macOS with no working alternative documented anywhere, leaving `HKM.app`
  users to hand-discover that it needs the Gatekeeper quarantine flag cleared
  and a `PATH` entry before `hkm` runs at all. The new installer downloads
  (or accepts a local) `hkm-kernel-<version>-macos-universal.tar.gz`, swaps
  `HKM.app` into `/Applications`, clears `com.apple.quarantine`, and wires
  `hkm`/`hkm-config` onto `PATH` via a tiny `exec` wrapper script rather than
  a symlink — `_NSGetExecutablePath` is not guaranteed to resolve through a
  symlink the way Linux's `/proc/self/exe` does, and the launcher self-locates
  its kernel relative to its own executable path, so a symlink risked it
  silently finding the wrong kernel (or none). Shipped as its own release
  asset (`install-macos.sh`) and bundled inside the macOS tarball itself,
  mirroring how `install.sh` ships with the Linux one.

## [1.4.1] - 2026-08-28

### Fixed
- **`hkm upgrade --user` / `--system` crashing on a self-upgrade** — the
  tarball's `tools/install.sh` replaced `bin/hkm` and `bin/hkm-config` with a
  plain `cp`, which truncates and writes INTO the existing file. Since the
  process running the upgrade IS that exact binary, the kernel it's running
  under refuses with `cp: cannot create regular file '.../hkm': Text file
  busy` — the same failure `hkm upgrade --local` was fixed for previously, just
  never carried over to this installer. Now stages each binary beside its
  target and `mv`s it over, so the running process keeps its old (unlinked)
  inode and the next invocation picks up the new build.

## [1.4.0] - 2026-08-28

A project's `plugins/`, `var/*` and `userdata/storage/*` are gitignored on
purpose — plugin source is fetched from its own git remote, and `var/`/
`userdata/` are runtime state, not source. That leaves a project pulled onto a
new machine (a teammate's clone, a fresh server, CI) missing all three, unable
to boot until someone reconstructs them by hand.

### Added
- **`hkm install [path|name]`** — brings a cloned/pulled project up to a
  runnable state in one command: registers it in the kernel registry;
  recreates `var/logs`, `var/cache/manifests`, `var/tmp`, `var/locks`,
  `var/sessions`, `var/queue` and `userdata/storage`; creates `.env` from
  `.env.example` and generates `APP_KEY` if either is missing or empty (never
  touches a key that is already set); runs `composer install`; then fetches
  every plugin the project's own `app/bootstrap/app.php` wires — the same
  fetch-and-lock step `hkm new` runs right after scaffolding, now shared via
  `lib/plugin_provision.zig` instead of duplicated. Every step past directory
  creation has a `--no-*` flag.
- **`hkm install --production` / `--owner=<user>[:<group>]`** — correct
  permissions AND ownership on a server, not just "writable". `--production`
  tightens `var/`/`userdata/` to `0750`/`0640` (no "other" access) instead of
  the dev defaults `0775`/`0664`; it deliberately does not guess an owner.
  `--owner` recursively `chown`s both directories to the account your web
  server / PHP-FPM pool actually runs as (`user`, `user:group` and `:group`
  all work, passed straight through to the system `chown`) and reports a
  failed chown per-directory rather than swallowing it — unlike chmod, a
  production ownership fix that silently didn't happen is worse than one that
  says so. `HKM_PROD_OWNER` sets a default so a deploy environment does not
  have to repeat `--owner=` on every run.

## [1.3.3] - 2026-08-18

Follows 1.3.2 within a day, and the theme is narrower: 1.3.2 fixed *which*
kernel a command acts on, this one fixes *how* commands talk to the shell around
them, plus a full uninstall. An audit of the Zig toolchain drove it — every item
below was reproduced against the shipped `--release=small` binary.

### Added
- **`hkm uninstall`** — removes every hkm install on the machine (both kernels,
  both pairs of launchers, the pre-1.4 user kernel, `~/.config/hkm` and the
  shared plugin store) and deregisters the `.deb` from dpkg, while keeping **your
  projects and the project registry**. Those two are protected by construction,
  not by a filter: every path it can delete is computed from the install layout,
  so a project directory cannot enter the plan at all; and `projects.json` +
  `platform.json` are rescued out of a kernel tree into the userdata directory
  *before* anything is deleted, so the registry survives even when its only copy
  was inside the tree being removed. `--dry-run` prints the plan and exits;
  system paths are reported rather than silently skipped when not root.

### Changed
- `hkm doctor` and `hkm version` share one PATH lookup with the launcher
  passthrough (`util.findOnPath`). Three private copies of "which binary would
  actually run" is three chances to disagree.
- **CI actions moved off the deprecated Node 20 runtime.** `upload-artifact`
  v5→v7, `download-artifact` v5→v8, `codeql-action/upload-sarif` v3→v4 and
  `action-gh-release` v2→v3 all declared `node20`, which the runners were
  already forcing onto Node 24. A `.github/dependabot.yml` now watches the
  `github-actions` ecosystem weekly and groups the bumps into one PR, so the
  next runtime deprecation arrives as a reviewable change rather than a notice
  in the log of a workflow that still passes.

### Fixed
- **Command output went to stderr, so nothing could be piped.** The whole
  `prompt` renderer used `std.debug.print`, which writes to stderr — so
  `hkm list > projects.txt` produced an empty file and a command's results were
  indistinguishable from its errors. Results (`intro`/`section`/`item`/`ok`/
  `muted`/`note`/`table`/`outro`) now go to **stdout**; `err`/`warn` and every
  interactive prompt stay on **stderr**. This was found once before and fixed a
  single function wide (`banner.printShort`); the cause was in the shared
  renderer all along.
- **ANSI escapes were emitted unconditionally and `NO_COLOR` was ignored**, so
  colour codes landed in redirected output, log files and CI transcripts.
  Colour is now decided per stream from the rule `tools/install.sh` already
  applied: off when `NO_COLOR` is set, when `TERM=dumb`, or when that stream is
  not a terminal.
- **Tables truncated to 80 columns when redirected.** `termCols()` falls back to
  80 whenever the `ioctl` fails — exactly the non-TTY case — so piping cut the
  end off every long path, with the `…` as the only clue. Truncation now applies
  only when stdout really is a terminal.
- **A mistyped command printed a raw Zig error.** Anything not handled natively
  is forwarded to the PHP CLI, and a spawn failure propagated out of `main` as
  `error: FileNotFound` — no filename, no mention of PHP, no pointer to
  `hkm doctor`. The three causes (no PHP, no kernel CLI, an unknown command) are
  now told apart and each names its own fix.
- **Unknown flags were silently ignored, which inverted a destructive command.**
  Every command parsed the flags it knew and dropped the rest. The token most
  likely to be misspelled is the one that makes a command safe, so
  `hkm uninstall --dryrun --yes` parsed as "no dry run, and don't ask" and
  deleted the install without a prompt. `uninstall` and `upgrade` now reject
  anything they do not recognise before acting on anything they do.
- **`projects.json` and `plugins.lock.json` were written non-atomically.**
  `writeFile` truncates before writing, so a process killed part way through — or
  a full disk — left a truncated registry rather than the previous one. Both now
  write a sibling temp file and `rename()` over the target, the same pattern
  `install.sh` and the launcher install already used.
- **Every unknown long option crashed the CLI parser.** `php-io-cli`'s
  long-option branch recorded a different array shape than its two siblings, and
  `rejectUnknownOptions()` reads the key it omitted — so the feature meant to
  suggest a correction raised `TypeError: suggestOption(): Argument #1 ($name)
  must be of type string, null given` on every unknown `--flag`. Fixed upstream
  (php-io-cli `b1dd657`) rather than pinned back, so the handling stays in.
- **`hkm uninstall` could destroy the registry it promises to keep** (found in
  review). Two holes: `HKM_USERDATA_DIR` may point INSIDE a deletion target —
  `/opt/hkm-kernel/projects` is the obvious case — so the plan listed it under
  "Will KEEP" and deleted its parent moments later; and `rescueRegistry`
  swallowed every write failure, so a failed rescue was followed by the delete
  anyway while the command reported success. It now refuses the first layout
  outright and aborts before removing anything if the rescue fails. Rescued
  files are written atomically.
- **Output fixes that had gaps of their own** (found in review): `hkm-config
  print` still wrote the config to stderr; a line longer than 8 KiB fell back to
  `std.debug.print` and silently changed stream; remediation text printed after
  an error went to stdout, splitting one message across two streams;
  `writeFileAtomic` used a fixed temp name two processes could collide on;
  `findOnPath` skipped empty `PATH` entries, which POSIX defines as the current
  directory; the passthrough blamed a missing PHP for a missing kernel CLI; and
  `--` ended flag VALIDATION but not flag PARSING, so `hkm upgrade -- --system`
  still selected the system scope.
- **A failed `.deb` install could still report success.** The fallback path
  treated `apt-get -f install` exiting 0 as evidence the package had landed, but
  it exits 0 whenever it finds nothing to repair — so a `dpkg -i` that failed for
  any non-dependency reason (a truncated download, a corrupt `.deb`) was reported
  as "updated" with the previous kernel still installed. The dependency repair is
  now followed by a second `dpkg -i`, and that result alone is the verdict.
- **`prompt.item` padded by byte count**, so a key containing any multi-byte
  glyph shifted its description column left — a single `→` misaligned the row by
  two. It now measures display width using the helper already written for
  `table()`.

## [1.3.2] - 2026-08-17

Fixes a class of failure that made installing or upgrading on a machine with an
existing install appear to do nothing. A machine can hold BOTH a system install
(`.deb` → `/opt/hkm-kernel` + `/usr/bin`) and a user install (tarball →
`~/.local`); the CLI did not model that, and every symptom below followed from
the same gap.

**If you are upgrading from 1.3.1 or earlier, the old launcher cannot install
the user scope.** Install it from the release instead — the fixed `hkm upgrade`
takes over from there:

```sh
curl -fsSL https://github.com/AlfaCode-Team/hkm-kernel/releases/latest/download/install.sh | sh
hkm version          # shows every install and which one your PATH runs
```

### Added
- **`hkm version` reports every install on the machine**, not just the launcher's
  own compile-time stamp: the kernel version in each scope (read from that
  kernel's `composer.json`), the launcher serving it and the version IT was built
  as, and an arrow on the one this invocation resolves. It also names the states
  that make a later "my upgrade did nothing" report inevitable — another `hkm`
  earlier on `PATH`, a kernel with no `vendor/`, a stale config pin. `hkm
  --version` is unchanged and still prints one line for scripts.
- **`hkm upgrade --user` / `--system`** to force a scope. Without either, the
  target is chosen from privilege — root → system, otherwise → user — so
  `sudo hkm upgrade` and `hkm upgrade` are two predictable commands rather than
  one command whose target depends on machine state.
- **`hkm-config unset <KEY>`**, for clearing a stale `HKM_KERNEL_HOME`.
- `hkm doctor` gained an **Installs** table: both scopes, their versions and
  whether each has resolved dependencies.

### Changed
- **Kernel resolution ranks sources by how specific they are to the invocation**
  (`tools/src/lib/kernel.zig`): an exported `HKM_CLI_PATH` / `HKM_KERNEL_HOME`,
  then self-location relative to the launcher's own binary, then a
  `config.env` pin, then `/opt/hkm-kernel`. The pin was previously checked
  first. It still applies wherever self-location genuinely fails — a custom
  prefix — but no longer overrides an install sitting next to the binary.
  A launcher in a system bin directory (`/usr/bin`) claims `/opt/hkm-kernel` at
  the self-location step, since no relative probe can reach it from there.
- **`hkm upgrade --user` installs to `~/.local/lib/hkm-kernel`**, matching
  `install.sh`, instead of `~/.local/share/hkm/kernel`. The old path sits outside
  every self-location probe, so it could only ever be reached through a
  machine-wide pin — which is what created the cross-scope hijack below. An
  install left at the old location is detected and reported, not silently used.
- **`install.sh` removes a redundant or superseded `HKM_KERNEL_HOME` pin**
  rather than repointing it. A repointed pin is still read by every launcher on
  the machine; no pin lets each one find its own kernel. A pin aimed at a genuine
  custom layout is reported and left alone. It also lists the installs already
  present with their versions, and prints `Version: old -> new` when it finishes.
- **`hkm-config check` no longer pins `HKM_KERNEL_HOME` for a self-locating
  layout** — writing one on behalf of whichever install ran it last is how the
  shared pin came to exist. It removes one that has become redundant.
- `hkm upgrade --local` obeys the same scope rule (non-root installs to the user
  scope, creating it if absent) and installs the launcher into that scope's `bin`
  directory rather than always `/usr/bin`.
- The scaffolded `kernel-autoload.php` tries `~/.local/lib/hkm-kernel` before
  `/opt/hkm-kernel`, so a project run under PHP-FPM or systemd resolves the
  kernel its owner actually manages. The pre-1.4 user path is still tried.

### Fixed
- **One install silently ran the other's kernel.** `~/.config/hkm/config.env` is
  read by every `hkm` on the machine, and `HKM_KERNEL_HOME` was checked before
  self-location — so whichever installer wrote that pin last redirected the other
  install too. A `.deb` launcher would report its own version while running a
  kernel out of the user's home, and upgrading either scope could not move the
  number on screen.
- **`hkm upgrade` could not update a user install on Linux.** It only ever
  fetched the `.deb` and shelled out to `sudo apt-get`, despite the user-local
  tarball being the documented default since 1.3.1. Because `PATH` usually
  resolves `~/.local/bin` before `/usr/bin`, the command reported success and the
  very next invocation ran the old launcher unchanged. The user scope now
  installs from the tarball via its own `install.sh`, with no `sudo` anywhere in
  that path.
- **Upgrade decisions used the wrong version.** `hkm upgrade` compared the
  LAUNCHER's compile-time stamp against the latest release tag, then went on to
  replace a KERNEL somewhere else — two numbers that differ exactly when the
  launcher on `PATH` belongs to the other scope. Versions are now read from the
  kernel being replaced, and the command names the other scope when it is also
  behind instead of reporting an unqualified "you are on the latest version".
- **A `--local` install could never report what it was.** It copied the
  checkout's `composer.json`, which carries no `version` field by design, so
  `hkm version` read "unstamped" forever and the next upgrade had nothing to
  compare. The `git describe` version is now recorded as semver build metadata
  (`1.3.1-2-g34abb2c` → `1.3.1+2.g34abb2c`), which Composer accepts and which
  semver excludes from precedence — a change of spelling, not of meaning. A
  release build still stamps the exact tag or nothing.
- A `--system` upgrade run without root now says so once, up front, with the
  command that works, instead of failing one permission error at a time. The
  system path no longer prefixes `sudo` unconditionally, which broke on the
  containers and CI images where a system install is most useful and `sudo` is
  frequently absent.

## [1.3.1] - 2026-08-12

Supersedes 1.3.0, which was tagged from a commit that never reached `master`
(the branch had advanced remotely between the build and the push). Tags are
immutable in this repository, so 1.3.0 was left in place rather than moved —
it builds, but it predates the `php-io-cli` pin below. **Use 1.3.1.**

### Added
- **Domain lists.** `domain` / `subdomain` now take either a string or a LIST,
  at all three levels — module-wide (`routeDomain` / `routeSubdomain`), group,
  and route. A project serving several hosts can pin a group to "these three and
  not that one" instead of duplicating the group per host. The domain is still
  part of the route KEY, and a route grouped under a host the project does not
  serve is still rejected at boot.
- **Plugin env seeding.** Enabling a plugin writes the environment it declares
  in `module.json` `config[]` straight into `.env`, in three shapes: a documented
  default is written ACTIVE, a required key with no default is written active but
  EMPTY (so the boot failure points at a line you can see), and an optional key
  with no default is written COMMENTED. Previously that list was discoverable
  only from a boot stack trace, one variable per attempt.
- **A user-local install that needs no root.** Linux releases now ship a portable
  tarball alongside the `.deb`; `tools/install.sh` unpacks kernel and launcher
  entirely inside `$HOME` and writes nothing outside it. Published with the
  release assets, so `curl … | sh` works without a checkout. The `.deb` remains
  for multi-user machines and CI images.
- **Scaffold support for `@pageflow/admin`** (Pageflow v1.1.0): a three-state
  theme provider (`{ theme, resolvedTheme, setTheme, toggle }` with a "system"
  default that keeps following the OS), the sidebar CSS variables the shell
  consumes, and a globbed `ui/admin/nav.ts` navigation registry. Both scaffold
  surfaces now wrap their tree in `AppErrorBoundary`.

### Fixed
- **`modules/php-io-cli` pinned back to its last loadable commit.** The newer
  pointer merged two parallel implementations of unknown-option handling and kept
  both, declaring `AbstractCommand::$unknownOptions` twice — a fatal at class
  load, so every command built on `AbstractCommand` died, not just the test that
  surfaced it. Only the pointer is reverted; which implementation is canonical is
  php-io-cli's call.

### Changed
- `hkm doctor` reports which install is actually in use, and whether a stale
  `HKM_KERNEL_HOME` pin in `~/.config/hkm/config.env` is overriding it — the
  failure that otherwise presents as "my changes do nothing".

## [1.2.0] - 2026-08-12

### Added
- **Route groups.** `groups[]` in `module.json` / `proj.json` states a `prefix`,
  `filters`, `requires`, `name` prefix and `domain` once for every route inside;
  groups nest (max depth 16). Module-wide `routePrefix` / `routeFilters` /
  `routeRequires` / `routeName` / `routeDomain` / `routeSubdomain` do the same for
  a whole file. Expanded at BOOT into ordinary flat routes — zero request-time cost.
- **Domain grouping.** A route may declare the host it answers on
  (`"domain": "africavoting.local"`, `"domain": "*.example.com"`, or a bare
  `"subdomain": "api"`). The domain is part of the route KEY, so one project can
  answer `GET /` differently per host. Ungrouped routes stay global; a bare
  subdomain answers on that label of every domain. A declared host is validated
  against `proj.json` `"domains"`.
- **Parameter types `path` and `enum(a|b)`, and optional `{id?}`.** `path` is a
  traversal-safe catch-all (`any` is unchanged and still has no guard);
  `enum` members are `preg_quote`d, so no regex can be injected from JSON.
- `HEAD` requests are served by the `GET` route (`ROUTE_HEAD_FALLBACK`), with the
  body stripped. Opt-in `405 Method Not Allowed` + `Allow`
  (`ROUTE_METHOD_NOT_ALLOWED`) and trailing-slash policy (`ROUTE_TRAILING_SLASH`).
- **`BOOT_CACHE`** — `Kernel::build()` skips recompiling manifests that are already
  current. Under PHP-FPM the boot pipeline previously ran on *every request*
  (~2 ms, ~150 KB of writes for ~130 routes); with the cache that becomes ~0.02 ms.
  Off by default; clear `var/cache/manifests/` on deploy.
- `route()`, `signed_route()` and `url()` global helpers; `UrlGenerator` bound in
  the `CoreContainer`. Absolute URLs follow the route's own domain group.
- `signed` route filter (SecurityFilters) — enforces a `signed_route()` link
  declaratively, the URL counterpart to `hmac`.
- Two derived manifests beside `route-manifest.php`: `route-index.php` (the
  matcher-ready index) and `route-names.php` (the name index `UrlGenerator` reads).
  Both optional at runtime — every consumer falls back to the flat manifest.

### Fixed
- **Captured route parameters are percent-decoded and re-validated against their
  type.** `/files/..%2F..%2Fetc%2Fpasswd` no longer satisfies `{name}`, and
  `/users/Jos%C3%A9` now reaches the controller as `José` rather than `Jos%C3%A9`.
- Route patterns are anchored with the `D` modifier — a trailing newline in the
  request path no longer satisfies `$`.
- Literal path text is `preg_quote`d, so `/feed.xml/{id}` no longer matches
  `/feedXxml/1`.
- Signed-URL verification compares the query byte-for-byte instead of round-tripping
  it through `parse_str()`, which rewrote `.`, ` ` and `[` in parameter names and
  made some legitimately signed URLs impossible to verify.
- `UrlGenerator` supports a repeated placeholder (`/a/{id}/b/{id}`), which
  previously reported the second occurrence as a missing parameter.
- `resolveEssentialModules()` no longer re-reads every `module.json` a second time
  during `build()`.

### Changed
- Route filter stages are resolved once per worker instead of being reconstructed
  on every request; filter specs, the handler split and the dependency-graph key
  are precompiled into the manifest.
- Dynamic routes are bucketed by their first literal path segment, so a request
  tests only the patterns that could match its prefix.
- These now FAIL THE BOOT instead of compiling into a route that silently never
  matched: a path not starting with `/`, a duplicated or PCRE-invalid capture name,
  a handler without exactly one `@`, a filter alias no `Provider::boot()`
  registered, and a route domain absent from `proj.json` `"domains"`.
- `RouteCatalog::publicPaths()` takes an optional `$domain` — the default is
  unchanged (shared routes only).

### Docs
- `docs/Sentinel-Routing-Guide.pdf` — a practical, example-driven routing manual.

## [1.1.0-beta.1] - 2026-08-07

First **installable** pre-release of the 1.1.0 line. `1.1.0-dev.2` and
`1.1.0-dev.3` are withdrawn — see below.

### Fixed
- **`composer install` aborted on every machine that took `1.1.0-dev.2` or
  `-dev.3`.** The build stamps its version into `composer.json`, and
  `1.1.0-dev.N` is not a valid Composer version: Composer's `dev` suffix takes
  no counter. `composer install` refuses to run at all on an unparseable
  version, so the package unpacked and then failed to resolve its dependencies.
  The stamper now validates and skips rather than writing something Composer
  rejects, and this release is named `-beta.1`, which Composer accepts — so the
  version marker the native distribution needs is actually present again.
- The stamper trimmed `v` from both ends of the version, so any version ending
  in `v` lost it — `1.1.0-dev` became `1.1.0-de`, the one pre-release form
  Composer does accept.

### Note on upgrading from 1.0.21
A 1.0.21 client has no pre-release filter: it strips the suffix, sees
`1.1.0 > 1.0.21` and offers this automatically. That filter ships **in** this
release, so the behaviour self-corrects after one upgrade. If you took
`1.1.0-dev.2` or `-dev.3` and the install reported a composer schema error,
upgrading to this release repairs it.

## [1.1.0-dev.3] - 2026-08-07

Re-cut of `1.1.0-dev.2` from `main` rather than `master`, so the artefacts
include the PHPStan work that landed with #107. Contents are otherwise
identical — see `[1.1.0-dev.2]` below for the full list.

### Fixed
- **`ProcessLocalLock` could not write its own lock table.** The registry was
  typed as an anonymous `object{locks: ...}` shape, whose properties PHPStan
  treats as read-only, so every write was an error against a type that
  described the shape but never named the one class satisfying it.
- PHPStan is green again: the project scaffolding that binds to plugin
  contracts is scoped out of analysis here, since those plugins are
  deliberately not dependencies of the kernel. It is analysed in a project that
  has installed them.

## [1.1.0-dev.2] - 2026-08-07

Development pre-release. Published so the new tooling can be exercised against
real projects before a stable 1.1.0; `hkm upgrade` will NOT offer it unless you
ask for it with `--pre`.

### Added
- **Plugins install from git.** `hkm plugins install|uninstall|versions|outdated|lock`
  fetch a plugin from its own repository, and `hkm plugins enable` now installs a
  missing plugin instead of wiring it into the bootstrap by name and failing at
  boot with a class-not-found. Installs resolve to a TAG, never a branch.
- **`plugins.lock.json`** records the remote, tag, commit and kernel version for
  every installed plugin, so an install is reproducible and reviewable.
  `hkm plugins lock` restores a project to exactly what it records.
- **Kernel compatibility gate.** A plugin declares `"kernel": "^1.0"` in its
  module.json and an incompatible pairing is refused at install time rather than
  surfacing at request time as a missing method on a contract.
- **Translation catalogue cascade.** `CompileLangManifestStage` compiles every
  plugin's `lang` declaration into `lang-manifest.php` using the same
  project-first priority model as views, so plugins can finally ship messages.
  Groups MERGE across the cascade, so overriding one key does not require
  copying the rest.
- **English + French catalogues** for every plugin with user-facing text
  (validation, auth emails, OAuth consent/device/admin, tenancy admin, user
  screens and the verification email).
- **`hkm upgrade --local`** installs the local checkout over the installed
  kernel, for testing a kernel change against real projects without cutting a
  release.
- **Memory inspector** wired into the CLI: `--mem` prints per-command
  allocation stats and leak backtraces, `HKM_MEM_STRICT` turns a leak into a
  non-zero exit for CI.
- `zig build test` step (there was none) and `zig build stamp` to write the
  build version into composer.json for the native distribution.

### Fixed
- **Releases published with no binaries attached.** This repository has
  immutable releases enabled, which forbid attaching assets after publishing;
  the workflow published first and uploaded second, so every artifact it built
  had nowhere to go. Assets now attach while the release is a draft, which is
  published afterwards. (v1.1.0-dev.1 was withdrawn for this reason — it exists
  as a burned version number and was never installable.)
- **`**` array-repeat was removed in Zig 0.17**, so the memory inspector's
  border drawing failed to compile under the pinned toolchain that every release
  is built with, while compiling fine on 0.16.
- **PHPStan had been unable to run since the plugin decoupling** — it still
  analysed a `plugins` path the kernel no longer has, and died before reading a
  single file.
- **Deferred cleanup never ran.** Every command exited via `std.process.exit`,
  which skips defers, so `threaded.deinit()` and the arena teardown never
  executed and no end-of-run reporting was possible.
- **`--help` was broken in five commands.** `hkm discover --help` ignored the
  flag and ran a full registering scan; `cli`/`worker` printed help and exited
  2; `run` could not distinguish `--help` from bad arguments; `new`/`update` had
  no handling at all.
- **`hkm --version` wrote to stderr**, so `VERSION=$(hkm --version)` returned an
  empty string despite the function documenting itself as being for scripted use.
- **A `git describe` build sorted below its own tag.** "1.0.21-138-gbdbbf34" was
  read as a pre-release of 1.0.21, so a build made after v1.0.21 was treated as
  older than it and refused plugins it could run.
- **A pre-release tag was treated as the latest release**, which would have
  pushed a dev build to every stable user on `hkm upgrade`.
- `hkm upgrade` queried the pre-rename repository name and only worked via
  GitHub's redirect.
- Release-mode builds inherited the debug allocator's `never_unmap` and
  `retain_metadata`, neither of which belongs in a shipped binary.

## [1.0.21] - 2026-07-22

### Changed
- **Rebranded to HKM Kernel.** The CLI banner (`hkm version`) now renders the HKM
  block-letter art and reads "HKM Kernel · Gated Demand Architecture"; the debug/error
  page and CLI exception header are branded **HKM** (was "Sentinel"); the global-kernel
  autoload error prefix is now `[HKM]`.
- **README rewritten as a guided document** — leads with Purpose, project goals, and an
  honest "done vs. cooking" status map, followed by install and usage. Adds the HKM hero
  banner and points at the new public guides.

### Added
- **Public architecture guides under `docs/guides/`** — a curated, reader-facing set of
  layer-by-layer guides (kernel, modules, plugins, security, data access, and more), with
  an index. The internal AI-context source stays private.

### Fixed
- **Security-layer docs corrected to match the code.** The guides no longer describe a
  kernel `FirewallLayer` / `RateLimiterLayer` (which do not exist) — the kernel ships only
  `CsrfTokenLayer`; authentication comes from the Auth plugin (`JwtAuthLayer` /
  `PersonalAccessTokenLayer`), and rate-limiting / IP-filtering are SecurityFilters route
  filters (`throttle` / `shield`).

### Merged
- Integrates edge features, CLI commands, and security updates from #36.

## [1.0.20] - 2026-07-22

### Added
- **`hkm module` command** for managing first-party kernel packages (the
  `modules/` submodules: bind-it, php-io-cli, let-migrate, http) — inspect,
  and update the pinned package set from one CLI entry point.
- **`alfacode-team/http` as a first-party package dependency** (`^1.0`;
  dev-master inside the monorepo, `v1.0.0` for stable releases). The http
  submodule is pinned at its latest master.

### Changed
- **Pageflow stages refactored and consolidated** — the SPA-bridge pipeline
  stages are simplified into fewer, clearer units.
- **Open-source readiness** — license, composer package metadata, and a
  `.env.example` added; issue/PR templates, CODEOWNERS, and required-reviewer
  configuration for `main`.

### Fixed
- **`MigrateListCommand`** parent wiring repaired and the **`OutboxWriter`**
  port contract corrected.
- **CI analysis gates** — PHPStan level-5 config + baseline made a blocking
  gate (optional Swoole/OpenSwoole coroutine calls ignored); CodeQL, Semgrep,
  and `composer audit` wired in.

## [1.0.19] - 2026-07-21

### Added
- **Edge reuses & updates an existing nginx SNI stream splitter.** When both nginx
  and Apache run and the host already declares a `map $ssl_preread_server_name`
  splitter (located via `nginx -T`), Edge no longer writes a second, conflicting
  `stream {}` block. It emits only the internal backend vhosts AND **merges the
  platform's public domains into the existing `map` in place** — inside a marked,
  idempotent sub-block, leaving hand-written entries untouched and never
  duplicating a domain. New `StreamConfigWriter`; `EDGE_REUSE_STREAM` (default on),
  `EDGE_STREAM_BACKEND` (default `nginx_backend`).
- **Force a single-server strategy with no fallback.** `edge:apply --nginx-only` /
  `--apache-only` (and `edge:status` preview) bypass host auto-detection;
  `EDGE_FORCE_STRATEGY` sets a deploy default.
- **Behind-SNI-router awareness.** The nginx-only vhost now listens on the internal
  backend port (e.g. 444) instead of `:443` when the host runs an SNI stream router
  that already owns `:443` — auto-detected, or forced via `EDGE_BEHIND_SNI_ROUTER`
  / pinned with `EDGE_NGINX_SSL_PORT`. Prevents nginx failing to start with
  "Address already in use". The `:80→HTTPS` redirect still targets the public port.
- **Configurable CORS, TLS pinning, method guard and deny lists** for generated
  vhosts: `EDGE_CORS` (off/allowlist/wildcard — wildcard opt-in, allowlist echoed
  via a `$http_origin` map), `EDGE_SSL_PROTOCOLS`/`EDGE_SSL_CIPHERS`/
  `EDGE_SSL_STAPLING`, `EDGE_ALLOWED_METHODS`, `EDGE_DENY_DIRS`.
- **`plugins/Edge/USAGE.md`** — full command + environment reference.

### Fixed
- **CLI parser rejects unknown/misspelled options** instead of silently ignoring
  them (e.g. a typo'd `--tsl=both` no longer produces the wrong config with a zero
  exit). In a script/CI it exits non-zero with a Damerau-Levenshtein "did you
  mean?" suggestion; on an interactive terminal it auto-applies the obvious
  correction with a visible notice. Launcher-injected globals stay tolerated.
- **`--tls=both` emits the port-80 redirect block** (with ACME/Let's-Encrypt
  HTTP-01 passthrough before the redirect) alongside the `:443` block.
- **Security/CORS headers are no longer dropped inside location blocks.** Header
  emission is centralised so every location that declares an `add_header` re-emits
  the full set — headers now land on real app/API responses, not just static
  paths.
- **DEVELOPMENT profile emits short-lived HSTS** (`max-age=300`, no
  `includeSubDomains`, never `preload`); production keeps long-form HSTS with
  `preload` opt-in.
- **`/nginx-status` is dev-only** — removed from production, where the SNI stream
  proxy makes `allow 127.0.0.1` world-open.
- **Production denies source maps.** `.map` is added to the deny list (not merely
  dropped from the static-asset rule, which `location /` would still serve via
  `try_files`); development keeps serving maps for debugging.
- **Deny rules are ordered before the static-asset regex** and directories use
  `^~` prefix locations, so a denied path (e.g. `vendor/composer/installed.json`)
  can no longer be served through a whitelisted extension.
- **Per-site access/error logs are emitted in production** (previously dev-only,
  silently falling back to the global log).
- **IPv4/IPv6 listeners are consistent** — `listen [::]:443 ssl` now mirrors the
  `:80` block.
- **Production static-asset regex drops `map`/`json`**, and explicit TLS
  protocol/cipher pinning + session settings are emitted for every TLS listener;
  `error_log … debug` is opt-in (`EDGE_NGINX_DEBUG_LOG`), default `warn`.

## [1.0.18] - 2026-07-20

### Added
- **`hkm plugins recover [proj]` — rebuild a lost/drifted `var/plugin-assets.json`
  (aliases `rebuild` / `reindex`).** Reconstructs the plugin-assets manifest from
  ground truth: for every plugin ENABLED in the project bootstrap it records the
  published assets that actually exist on disk, healing a manifest that was
  deleted, truncated, or fell out of sync. It copies nothing (use
  `hkm plugins update` to re-publish physically-missing assets) and preserves any
  migration `batch` already recorded, since batch numbers cannot be derived from
  the filesystem. `--dry-run` (`-n`) previews the rebuild; unresolvable enabled
  plugins are reported and skipped. Implemented natively in Zig.

### Changed
- **`hkm discover` now restores each project's gitignored runtime folders.**
  After locating a project it ensures `var/logs`, `var/cache/manifests`,
  `var/tmp`, `var/locks`, `var/sessions`, `var/queue` and `userdata/storage`
  exist — a freshly cloned or moved project is usually missing them, which would
  otherwise fail at boot. `--dry-run` reports how many are missing without
  creating them; a real run creates them (idempotent — an already-complete
  project reports nothing).

## [1.0.17] - 2026-07-20

### Added
- **`hkm discover [root]` — find projects on disk and register them (alias
  `hkm scan`).** Walks a directory tree, finds every folder holding a
  `proj.json`, and upserts each into the kernel registry (`projects.json`) with
  its name, version, ABSOLUTE path, and domains read straight from that project's
  own `proj.json`. The bulk counterpart to `hkm update <path>` (one project):
  use it to adopt projects scaffolded with `--no-register`, cloned from git, or
  moved on disk. Reports each match as `new` / `moved` / `up-to-date` against the
  current registry; `--dry-run` (`-n`) previews without writing; `--depth=N`
  (default 4) caps descent. Skips `vendor`, `node_modules`, `var`, `.git`,
  `dist`, `zig-out`, `.zig-cache` and dotfolders, and stops descending once a
  folder is identified as a project root. Implemented natively in Zig (no PHP
  required), reusing the same registry resolver as `new`/`update`/`list`.
- **`TENANCY_CONTROL_PLANE` — serve a super-admin host with Tenancy enabled.**
  `Tenancy::boot()` previously registered `TenantContextStage` unconditionally,
  which made a central control-plane deployment unservable: every request either
  500'd (route did not load Tenancy, so `TenantIdentifier` was unbound and the
  stage threw) or 404'd (loaded, but no tenant resolves on an admin host). Set
  `TENANCY_CONTROL_PLANE=true` and the `after.load` hook is skipped, so
  `DatabasePort` stays on central. Everything else the plugin publishes — the
  registry, connection resolver, admin/membership/invitation services and the
  `tenant:*` provisioning commands — is unaffected. Defaults to **false**, so a
  tenant-serving deployment cannot lose tenant isolation by omission.

### Changed
- **`tenant:migrate` is scoped to the calling project by default.** It now
  migrates only the tenants recorded in that project's `var/tenants.json`,
  instead of every active tenant in the registry. Several projects may share one
  central registry, and a sibling's tenant is encrypted with that project's
  `APP_KEY` — so it surfaced on every run as a spurious "Could not decrypt
  payload (invalid key or tampered data)" failure. Pass `--all` for the previous
  fleet-wide behaviour; a project with no `var/tenants.json` still migrates every
  active tenant, so single-project deployments are unchanged. Skipped tenants are
  reported rather than silently dropped.

## [1.0.16] - 2026-07-18

### Added
- **TLS modes for `edge:apply`.** `--tls=ssl|none|both` (plus `--no-ssl` as an
  alias for `none`) picks how each vhost terminates TLS: HTTPS only, plain HTTP
  on `:80`, or `:80` that 301-redirects to `:443`. `--ssl-cert` / `--ssl-key`
  override the certificate paths per run. Default comes from `EDGE_TLS_MODE`
  (`ssl`), so existing behaviour is unchanged.
- **Cache profiles derived from `APP_ENV`.** `local` / `development` →
  DEVELOPMENT (nothing is browser-cached: HTML, the front controller and every
  asset are `no-store`, so a rebuild is picked up without clearing the browser
  cache); `production` → PRODUCTION (dynamic responses stay uncached,
  fingerprinted assets get `expires 1y` + `public, immutable`). Anything
  unrecognised falls back to DEVELOPMENT — never production. The profile is
  written into the file as a `# HKM Edge cache profile: …` banner.
- **Environment flags on `edge:apply` / `edge:service`.** `--local` (alias
  `--dev`), `--development` / `-d`, and `--production` set `APP_ENV` for the
  run. They are command-scoped, not launcher-global.
- **OpenSwoole runtime.** A project can now set `"edge": { "runtime":
  "openswoole" }` in its `proj.json` and Edge renders nginx as a reverse proxy
  instead of a PHP-FPM vhost: a dedicated `upstream` (least_conn, `max_fails` /
  `fail_timeout`, keepalive pool, multiple workers via `"ports": [9501, 9502]`),
  a `$connection_upgrade` map, a separate `/ws` WebSocket location with long
  timeouts, an optional `/health` endpoint, and Cloudflare's `CF-Connecting-IP`
  forwarded upstream. Static assets are still served straight off disk.
- **`edge:service` command.** Generates the systemd unit (or supervisor program
  block with `--supervisor`) that keeps a project's OpenSwoole server alive.
  `--write[=dir]` writes it out; PHP-FPM projects are skipped since php-fpm
  already supervises those workers. PHP binary, entry script, port and worker
  count are configurable.
- **Response compression.** `EDGE_COMPRESSION=auto` (default) prefers Brotli
  when the server actually supports it and falls back to gzip — resolved per
  server from nginx's `ngx_brotli` build and Apache's loaded `mod_brotli`, so an
  Apache-only host no longer inherits nginx's answer. Brotli mode also emits a
  gzip block for clients without `br`.
- **HSTS.** Emitted only for the TLS modes (`ssl` / `both`), never for plain
  HTTP, with configurable `max-age`, `includeSubDomains` and `preload`.
- **Optional http-context prelude** (`EDGE_HTTP_PRELUDE=1`, off by default):
  the `log_format`, `limit_req_zone` / `limit_conn_zone` and Cloudflare
  `set_real_ip_from` ranges that the vhost directives depend on. Off by default
  because re-declaring a zone that already exists in `nginx.conf` is a
  duplicate-definition error.

### Changed
- **Cache mode is no longer inferred from the kernel mode.** Nothing in vhost
  generation reads `HKM_DEV` any more — the cache profile comes from `APP_ENV`
  alone, so choosing which kernel to run against (`hkm … --dev`) and choosing
  how assets are cached are independent. `dev_vhost` defaults to "follow the
  cache profile"; set `EDGE_DEV_VHOST` to force it either way.
- **All generated paths derive from the project root.** The vhost records its
  provenance (`# HKM Edge project root: …` / `public root: …` / `swoole root:
  …`) and the OpenSwoole entry script now defaults to `app/swoole/index.php`
  relative to the project root — matching what `hkm run <project> --swoole`
  actually executes (it previously defaulted to a `bin/server.php` that no HKM
  project has).
- **Security headers are repeated inside `location` blocks that set their own
  `add_header`.** nginx drops every inherited `add_header` as soon as a location
  adds one, which silently stripped `nosniff` / `X-Frame-Options` /
  `Referrer-Policy` / HSTS from static assets and the front controller.
- Static assets resolve only under the public root; a miss is a hard `404` and
  is never forwarded to the application.

### Fixed
- **Generated nginx failed `nginx -t`.** The PHP-FPM vhost nested `location =
  /index.php` inside `location ~ \.php$`, which nginx rejects ("location … is
  outside location …"), so `edge:apply` could never pass its own config test.
  Replaced with the flat front-controller pair (`location = /index.php` for
  FastCGI, `location ~ \.php$ { return 404; }` for everything else).
- **`.well-known` was denied, breaking ACME/Let's Encrypt.** A blanket
  `location ~ /\.` shadowed the later negative-lookahead rule, so HTTP-01
  challenges 404'd and certificates could not be issued or renewed.
- **Apache vhosts failed `apachectl configtest`.** `ServerTokens` is a
  server-level directive and is rejected inside `<VirtualHost>`; it is no longer
  emitted (`ServerSignature` / `LimitRequestBody` are valid there and remain).
- **Apache no longer emits directives for modules that are not loaded.** The
  loaded module set is probed from `apachectl -M`, and HSTS (`mod_headers`) and
  compression (`mod_filter` + `mod_brotli` / `mod_deflate`) degrade to whatever
  the host supports instead of failing the config test.

## [1.0.15] - 2026-07-17

### Fixed
- **Edge now serves local (`.local`/`.test`) domains in dev.** The
  `EDGE_LOCAL_IN_SERVER` flag was defined but never read, so a project whose
  domains are all local rendered an empty vhost (header comment only). Dev mode
  (`hkm … --dev`, which exports `HKM_DEV=1`) now folds local domains into the
  generated nginx/Apache vhost automatically — `hkm cli -p <project> --dev
  edge:apply` produces a working local site with no extra flag. A production
  (non `--dev`) run still keeps local domains out of the server config (they
  resolve through DNS); `EDGE_LOCAL_IN_SERVER=true` forces local-in-server
  outside dev. Local domains continue to sync to `/etc/hosts` in both cases.

## [1.0.13] - 2026-07-17

### Added
- **Edge plugin (`Plugins\Edge`, solves `edge.routing`).** Generates this host's
  web-server front config from the platform's registered domains and adapts to
  what is actually running: nginx **SNI stream splitter** (raw-TLS `ssl_preread`
  routing to nginx `:444` / Apache `:8443`) when both run, else a plain
  **nginx-only** or **Apache-only** vhost. Project-aware: one vhost per project
  (docroot `<project>/app/public`), served via **PHP-FPM** or **OpenSwoole**
  (configurable per project in `proj.json` `"edge"`). The **run-env** the
  launcher exports (`APP_ENV`, `HKM_KERNEL_HOME`, `HKM_DEV_HOME`,
  `HKM_USERDATA_DIR`, `PSP_GLOBAL_AUTOLOAD`, `PSP_PROJECTS_DIR`) is passed through
  into each vhost so FPM workers boot the correct kernel. The PHP-FPM socket is
  auto-resolved to the **CLI PHP version** (multi-PHP hosts). Local (`.local`/
  `.test`) domains are excluded from the server config and synced to `/etc/hosts`
  (dev only, `--dev` required; never duplicates an existing entry). CLI:
  `edge:status`, `edge:apply`, `edge:hosts` (default-scoped to the current
  project, `--all` for the whole registry). See `plugins/Edge/README.md`.
- **`PSP_PROJECTS_DIR` is now exported by `hkm run` / `hkm cli`** — resolved to
  the same project registry the launcher uses (`HKM_USERDATA_DIR` → `PSP_PROJECTS_DIR`
  → `HKM_KERNEL_HOME/projects`), so the kernel and plugins read one registry
  without re-deriving it. `--dev` also exports `HKM_DEV=1` as an explicit marker.

### Fixed
- **Frontend build output path.** Vite wrote hashed assets + manifests to
  `<project>/public_html/build/`, but the docroot and `ViteManifest` both use
  `<project>/app/public` — so built assets landed outside the web root and were
  never found. The frontend template now builds to `app/public/build/`.
- **`hkm … --dev` under `sudo`.** The launcher read its config from root's home
  (`/root/.config/hkm/config.env`) and lost `HKM_DEV_HOME`; it now honours
  `SUDO_USER` and reads the invoking user's `config.env`, so `sudo hkm … --dev`
  resolves the dev kernel.

## [1.0.12] - 2026-07-16

### Changed
- **Bundle dependencies pinned to the PHP 8.4 series (not `>= 8.4`).** The
  Debian `.deb` `Depends`/`Recommends` now use the versioned `php8.4-*`
  packages instead of the unversioned `php-cli (>= 8.4)` meta-package — which
  would also let PHP 8.5+ satisfy the dependency. The docstring and Windows
  `INSTALL.txt` wording changed from "PHP >= 8.4" to "PHP 8.4". The runtime is
  now locked to the 8.4 line, not "8.4 or newer".

## [1.0.11] - 2026-07-16

### Added
- **proj.json `"essentials"` — project-declared global modules.**
  `Kernel::withEssentialModules()` now accepts module DOMAINS as well as
  provider class-strings; domains resolve to providers at `build()` and an
  unknown domain fails the boot. `EntryHelpers::projectEssentials()` reads the
  new key and the scaffold bootstrap appends it — which plugins are global is
  now a per-project deployment decision, not a code edit. Session-cookie apps
  declare `auth.identity` + `user.management` here so `SessionAuthStage`
  resolves logins on every page.
- **Boot-time `requires[]` validation.** `CompileServiceManifestStage` now
  FAILS the boot on any module.json `requires` entry that matches no registered
  module's `solves` (previously dropped silently — a typo or a plugin missing
  from `withModules` surfaced only as an unbound-contract error at request
  time). Port/contract class names no longer belong in `requires[]`.
- **let-migrate tenant resolver support classes** (ported to scaffolded
  projects): `DatabaseTenantResolver` + `CentralTenantRegistry` + `Dsn` read
  the tenant fleet from the central `tenants` table — `tenant:status` /
  `tenant:migrate` and request routing share ONE registry.
- **Display identity in the `pageflow_auth` prop.** `PageflowAuth::resolve()`
  now shares the non-sensitive display fields off the `Identity` — `username`,
  `fullName`, `email`, `avatarUrl` — so the browser `useAuth()` renders the
  real name/email/avatar instead of the raw user id. The `PageflowAuth` TS
  type + guest default gain the fields.

### Changed
- **STRICT tenant routing — no unscoped passthrough (BREAKING).**
  `TenantContextStage` now 404s any request that resolves no tenant (cookie
  hint first — principal-bound, guests included — then the `TENANCY_MODE`
  identifier). Every served host must be assigned to a tenant
  (`tenant:host:add`); control-plane code pins the central connection
  explicitly. The remembered-tenant cookie's user binding is now actually
  enforced (no cross-user replay).
- **Essential modules load their transitive `requires[]`.** Essential domains
  are seeded into every request's dependency graph (previously an essential
  registered alone and its dependencies were silently missing). Each module
  still registers exactly once per request.
- **Tenancy module `requires` trimmed to `["database.management"]`** — the
  always-on stage path. Its selection/admin/invitation/host routes now carry
  `auth.identity` / `user.management` / `audit.trail` as route-level
  `requires[]`, cutting the every-request graph from 13 modules (~135µs) to 2
  (~15µs) in a Tenancy-essential project.
- **One `Provider::requires()` convention.** All plugin providers now mirror
  module.json domains (the single source of truth the kernel reads); the
  `ModuleContract` docblock documents the convention.
- **Per-worker loading caches**: `LoadStage` memoizes resolved dependency
  graphs; `OnDemandLoader` caches provider instances (providers are stateless
  by contract).
- **Auth-required browser navigations redirect to login.** `RequireAuthStage`
  now sends a full page load OR a Pageflow SPA navigation (detected via the
  `X-Pageflow` header) to `/login?redirectTo=…` instead of a raw JSON 401;
  genuine API/fetch callers (JSON expected, no `X-Pageflow`) still get the
  machine-readable 401. The original path rides along as `redirectTo`.
- **MySQL sessions pinned to UTC.** `MySQLConfiguration` sets
  `time_zone = '+00:00'` (via `MYSQL_ATTR_INIT_COMMAND` + `initStatements`, so
  it survives auto-reconnect) — `NOW()` / `CURRENT_TIMESTAMP` and `TIMESTAMP`
  read-back are now unambiguously UTC, matching the PHP-side UTC clock.

### Fixed
- **Settings plugin required the non-existent `database.query` domain** (now
  `database.management`) — the Database module was silently absent from its
  graph; caught by the new boot-time validation.
- Scaffold template comments taught wrong `solves` values
  (`database.query`, `crypto`, `i18n`, `commands`).

### Security
- **SiteSEO JSON-LD stored XSS.** `Schema.php` now encodes structured data with
  `JSON_HEX_TAG` so a `</script>` in user-controlled content can't break out of
  the `<script type="application/ld+json">` block.


## [1.0.10] - 2026-07-14

### Added
- **Display identity on the kernel `Identity`** — new best-effort fields
  `username`, `email`, `fullName`, `avatarUrl`. `AuthService` fills
  username/email from the central user store at issuance when the caller
  doesn't supply them; they ride as OIDC claims (`preferred_username`,
  `email`, `name`) on JWTs — rebuilt statelessly by `JwtAuthLayer` — and as
  session keys (`auth.username/email/name/avatar`) for session logins,
  remember-me resurrection and `GET /auth/me`. `fullName` comes from the
  TENANT `user_profiles` table, so only tenant-scoped credentials carry it.
- **Post-login "previous page" redirect.** The Session plugin's
  `StartSessionStage` now records the last eligible page view (GET + 2xx,
  HTML navigation or Pageflow page object; auth/OAuth/API/asset paths exempt —
  extend with the new `SESSION_PREVIOUS_EXEMPT` env) under
  `StartSessionStage::PREVIOUS_URL`. On successful `POST /auth/login` the
  redirect target is: an explicit `redirectTo` on the request (query/body) →
  the recorded page (pulled one-time) → `/`. Browser POSTs get a 302; AJAX
  callers get `redirectTo` in the JSON payload. Every candidate passes an
  open-redirect guard (relative paths only). SocialAuth's web callback honours
  the same recorded page before `SOCIAL_AUTH_SUCCESS_REDIRECT`.
- **User: published `TenantProfileReaderContract`** — tenant `user_profiles`
  display reads (`fullName(userId, tenantId)`), implemented by
  `TenantProfileProvisioner` in pinned-repository or per-call resolver mode;
  best-effort, never throws. `UserDTO` gains `fullName`, `avatarUrl` and
  `permissions`; `UserProfile::fullName()` composes first + last.
- Base controllers (`ApiController`, `ViewController`) now compose
  `InteractsWithSession`, as documented — `sessionGet/put/pull`, `flash`,
  `csrfToken` and friends are available on every controller.

### Changed
- **Tenant selection decomposed (tenancy ≠ authentication).**
  `MembershipService` is control plane only: `selectTenant()` re-verifies the
  seat, audits, and returns the verified `TenantSummary` — it no longer mints
  tokens and lost its Auth dependency. `TenantController` is the composition
  point: it mints the `tnt` token via `AuthServiceContract` (with `roles` and
  the `name` claim via `TenantProfileReaderContract`) and builds the
  `TenantSelection` response. Response shape is unchanged.
- **Tenant-scoped auth data now rides the per-request `DatabasePort`.** Auth's
  personal-access-token + device-session repositories, Audit's `audit_log`,
  OAuth2's server tables and SocialAuth's `social_identities` resolve the
  request connection (tenant-rebound by `TenantContextStage`) instead of
  pinning the central connection; their migrations moved to each plugin's
  `database/tenant-template/`. User, Tenancy control plane and Auth refresh
  tokens stay pinned to central.
- `UserServiceContract::find()` gains `bool $isAuth = false` — issuance-time
  lookups by Auth skip the self-or-permission check (the request Identity is
  still guest during login).

### Fixed
- **Login hang (30s `max_execution_time`)** — a container resolution cycle
  `AuthService → UserService → MembershipService → AuthService` recursed
  forever. Fixed twice over: the Auth provider resolves the user store through
  a lazy closure, and the selection refactor removes the cycle's closing edge.
- Remember-me resurrection fataled when the user had no tenant (nullable
  `tenantId` passed to `startSession()`).
- `UserDTO` declared a readonly property with a default value (PHP fatal on
  every load); `permissions` is now a promoted constructor parameter.

## [1.0.9] - 2026-07-13

### Added
- **Audit plugin (`audit.trail`)** — the single owner of the shared central
  `audit_log` table. User, Feedback and Tenancy no longer write the table
  themselves; they require `audit.trail` and record through the published
  `AuditServiceContract` (actor/tenant auto-filled, JSON log line + best-effort
  persistence — an audit write never breaks the action it records).
  `AuditReaderContract` adds keyset-paginated queries + retention purge.
- **Auth: device sessions, mobile auth and OTP password reset.** New routes:
  `GET/DELETE /auth/sessions[/{id}]` + `POST /auth/logout-other-devices`
  (device-session listing/revocation backed by the new tenant `auth_sessions`
  table), `POST /auth/mobile/{login,register,logout}` (token-first mobile flow),
  and `POST /auth/password/{forgot,verify-otp,reset}` (OTP reset via the
  CachePort-backed broker, mail optional). New config keys:
  `AUTH_SESSION_TTL/REFRESH`, `AUTH_FINGERPRINT_HEADER`,
  `AUTH_MOBILE_ACCESS_TTL/AUTOVERIFY`, `AUTH_OTP_TTL`; namespaced `auth::` views.
- **SocialAuth: end-to-end social sign-in.** `GET /auth/social/{driver}` →
  provider redirect, `/callback` maps the profile onto a central user (linked
  identity → email match → create) opening a platform session or returning a
  JWT+refresh pair (`?mode=token`); `POST /auth/social/{driver}/token` verifies
  native-SDK tokens (Google access/id token, Apple identity token vs JWKS) for
  mobile. Links persist in central `social_identities`.
- **Authorization: policy seeding + enforcement surfaces.** `SeedPolicyCommand`
  (CSV policy seed via `config/policy.seed.csv`), HTTP pipeline stages, and
  globally autoloaded `Engine/functions.php` helpers. Auth now requires
  `authorization.policy` and resolves roles through the new `RoleResolver`.
- **Tenancy: `var/tenants.json` default tenant for the CLI.** `tenant:create`
  records the provisioned tenant (last created = default) so `tenant:delete` /
  `tenant:host:add` work without `--tenant`/`--slug`; new `tenant:remember`
  backfills pre-existing tenants (`--slug`, `--tenant`, `--all`, or interactive).
  Hints are re-validated against the registry and stale entries self-drop.
- **`hkm plugins update` — full analyse + sync.** Update now compares every
  publishable surface (config, database migrations/tenant-template/seeders/
  factories, resources, ui) byte-for-byte against the project: publishes NEW
  files, refreshes content-drifted files (plugin wins), re-syncs a drifted
  plugin ui mirror (+ glue regen), and runs migrations when a central OR tenant
  migration changed. Dry-run previews the full analysis.
- **Kernel: request-scoped `client.ip` binding.** `OnDemandLoader::load()`
  exposes the client IP in the request container so request-scoped services
  (e.g. the audit trail) can attribute an action's origin without threading it
  through controllers.

### Changed
- **Tenant-membership is now part of the user fetch.** `UserServiceContract`
  id-based operations take a `checkMembership` flag; `ModelUserProvider` fetches
  with membership enforced, so on a tenant-scoped request a user without an
  active seat is indistinguishable from a non-existent user.
- **Tenant-scoped tables moved to `database/tenant-template/`.** Auth
  (personal access tokens, refresh tokens, auth_sessions), Authorization
  (casbin_rule) and OAuth2 (oauth_* tables) migrations are now provisioned per
  tenant database instead of the central DB.
- **User outbox refactored into GDA layers** (`OutboxRelayService` +
  `OutboxRepository` replace the Infrastructure outbox writer/relay pair) and
  the email-verification flow gained a full page path (`VerifyEmailResult`,
  `account/verify` view, `VerifyEmail.tsx` site page).
- **`hkm` tenant migrate passes are now independent.** A plugin shipping only
  tenant-template migrations still gets its tenant pass, and `tenant:migrate`
  is triggered by shipped tenant-template files (registry-driven) instead of a
  `tenants` key in `config/let-migrate.php`.
- `modules/let-migrate` bumped: safe transaction handling around implicit DDL
  commits; unsigned integers, CHECK constraints and table options in the schema
  builder.

### Fixed
- **`AuthUserProxy::withSecurity()`/`withAccessToken()` dropped `joinedAt`**,
  shifting constructor arguments and throwing a `TypeError` on every session /
  JWT guard resolution.

## [1.0.8] - 2026-07-11

### Fixed
- **`tenant:migrate` command collision.** The kernel's generic LetMigrate
  `tenant:*` commands (registered via the `Commands` plugin's migration factory)
  were overwriting the Tenancy plugin's registry-based `tenant:migrate` under the
  CLI's last-wins registration, so the wrong command ran and demanded a
  `tenants` resolver config the project does not use. The generic factory now
  yields to any command a plugin already claimed via the new
  `CliPipeline::hasQueued()` — so the Tenancy command wins when Tenancy is
  enabled, and the kernel commands still register normally when it is not.

### Changed
- **Tenancy `tenant:migrate` template path is now project-relative.** The default
  template migrations path resolves under the active project root
  (`projects/<name>/database/tenant-template`) via `Paths::project()`. The
  `TENANCY_TEMPLATE_PATH` override is honoured as-is when absolute, or resolved
  under the project root when relative. The previous plugin-directory fallback
  was removed.

> Tenant migrations against MySQL / SQL Server also required a companion fix in
> the `let-migrate` module (DDL implicitly commits, closing the open
> transaction) — released separately in that package.

## [1.0.7] - 2026-07-11

### Added
- **`hkm plugins upgrade [project]` — split-safe project upgrade.** A new
  command (aliases: `reconcile`, `migrate`) that upgrades a project after the
  plugins it depends on have changed, in three idempotent phases: (1) dependency
  healing — auto-enable the provider of any domain a plugin newly `requires`;
  (2) assets + migrations — publish each enabled plugin's NEW assets and run its
  pending migrations (delegates to `update`; already-applied migrations skip by
  name); (3) **split reconciliation** — when a plugin SPLITS and a migration file
  moves to a new plugin (e.g. Feedback extracted from User), the migration's
  ownership in `var/plugin-assets.json` transfers to the new owner WITHOUT
  touching the database. The shared `let_migrations` row is keyed by filename and
  stays applied, so the table AND its data are preserved — and a later `disable`
  of the OLD plugin can no longer roll back (drop) a table the NEW plugin owns.

### Fixed
- Plugin asset publishing now includes `database/tenant-template` migrations
  (previously never copied into projects), so tenant-scoped tables ship on
  enable/update and tenant-template splits reconcile correctly.

## [1.0.6] - 2026-07-09

### Added
- **Project route policy — disable plugin routes without forking.** A plugin
  still owns and declares its routes, but the deploying project is now the
  final authority: `proj.json` gains `"routePolicy": { "disable": [...] }`
  (wired via the new `Kernel::withRoutePolicy()` +
  `EntryHelpers::projectRoutePolicy()`). Each spec is either `"METHOD /path"`
  (one plugin route) or a module domain like `"oauth.server"` (every route that
  module solves). Applied at boot to plugin routes BEFORE project routes
  compile, so a project can veto a plugin route and re-declare its own on the
  freed key. A spec matching nothing fails the build with a descriptive error —
  typos never pass silently.
- **`hkm <command> --dev`** — pin a single invocation to the DEVELOPMENT kernel
  instead of the installed stable copy. Resolves via the new `HKM_DEV_HOME`
  config key (set once with `hkm-config set-dev-home <checkout>`, validated),
  or by walking up from a repo-built launcher to the nearest `composer.json`.
  Exports `HKM_KERNEL_HOME` + `HKM_CLI_PATH` for the child process only —
  nothing persistent changes, and the flag is stripped before downstream arg
  parsing. Fails loudly when no dev kernel is found (never silently falls back
  to stable).
- `hkm-config set-dev-home <path>` subcommand + `HKM_DEV_HOME` in `hkm help`;
  contributor "Dev environment" guide in `tools/README.md`.
- New-project templates updated: scaffolded `proj.json` ships a
  `routePolicy.disable` stub, the bootstrap wires `withRoutePolicy(...)`, and
  the project README documents the three route verbs (add / override / disable).

## [1.0.5] - 2026-07-08

### Added
- New projects also ship a full Apache virtual-host sample
  (`app/apache.conf.example`) alongside the nginx one — DocumentRoot pinned to
  `app/public`, dotfiles denied, only `index.php` executable, security headers.

## [1.0.4] - 2026-07-08

### Security
- Scaffolded `.env` (which holds the generated `APP_KEY`) is now written
  `chmod 600` (owner-only); `~/.config/hkm/config.env` too.
- Debug output is force-disabled when `APP_ENV=production`, even if `APP_DEBUG`
  was left `true` in a mis-set `.env` — production never leaks internals.
- New projects ship an `app/public/.htaccess` (Apache) and an
  `app/nginx.conf.example` (nginx): deny dotfiles (`.env`, `.git`), disable
  directory listing, drop `X-Powered-By`, add baseline security headers, and
  route through the single front controller with docroot pinned to `app/public`.

### Added
- `HKM_USERDATA_DIR` — relocate the persistent registry (`projects.json` +
  `platform.json`) outside the kernel install so a kernel update never
  overwrites it. Honoured by the `hkm` CLI (registry) and the PHP
  `DomainResolver`; falls back to `<kernel>/projects` when unset.
- The `.deb` marks `projects.json` + `platform.json` as dpkg conffiles, so an
  in-place upgrade preserves a user's registrations even without relocating.

### Changed
- `hkm-config` now sets up the FULL required environment in one run: it
  resolves + pins `HKM_KERNEL_HOME`, and creates a persistent userdata dir
  (`XDG_DATA_HOME/hkm` or `~/.local/share/hkm`), migrates any existing
  registry into it, and pins `HKM_USERDATA_DIR`.

## [1.0.3] - 2026-07-08

### Changed
- Scaffolding templates moved from `tools/src/templates/` to a top-level
  `templates/` directory so they ship inside the kernel payload. `tools/` is not
  bundled, which previously broke `hkm new` / `hkm ui init` on packaged installs.

### Fixed
- `hkm run` / `hkm run --pick` / the registry now **self-locate the installed
  kernel** (`/opt/hkm-kernel`, or the dir relative to the launcher) instead of
  only using env vars or a dev tree found by walking up from the CWD. Fixes
  "Kernel registry not found" on packaged installs and stops an installed
  launcher from silently using a development kernel.
- `hkm-config` is now a real config checker: it resolves the kernel, verifies
  `vendor/autoload.php` + the projects registry, and writes/repairs
  `HKM_KERNEL_HOME` in `~/.config/hkm/config.env`.
- The launcher now **loads `~/.config/hkm/config.env`** at startup (real
  environment variables still win), so `hkm-config` settings actually apply.

## [1.0.2] - 2026-07-07

### Changed
- `hkm upgrade` now performs the update automatically for packaged installs:
  it detects the OS, downloads the matching release artifact (`.deb` / `.tar.gz`
  / `.zip`), and installs it (Linux: `apt`; macOS: extract + `install.sh`;
  Windows: downloads and points at `install.bat`). Previously it only printed
  manual instructions.

## [1.0.1] - 2026-07-07

### Changed
- The Sentinel ASCII banner + version now shows as the header of `hkm` and
  `hkm help` (previously only on `hkm version`).

## [1.0.0] - 2026-07-07

First native release — the framework ships as OS-native bundles for Linux,
macOS, and Windows, built and published automatically from a `v*` tag.

### Added
- **Native `hkm` launcher** (Zig) for Linux, macOS (universal arm64 + x86_64),
  and Windows, cross-compiled from a single Linux host.
- **`hkm doctor`** — verifies PHP ≥ 8.4.1, required extensions
  (json, mbstring, ctype, tokenizer, filter, pdo, openssl, curl, fileinfo), a
  PDO driver, and reports the resolved kernel path.
- **`hkm version` / `--version` / `-v`** with a Sentinel ASCII banner.
- **`hkm upgrade [--check]`** — checks the repo's `v*` tags for a newer release
  and updates a git-checkout install (packaged installs get reinstall guidance).
- **Self-locating kernel** — the launcher finds the kernel relative to its own
  executable, so `.app`/zip/portable installs need no environment variable.
- **`tools/bundle.sh`** — assembles the `.deb`, macOS `.app` tarball, and Windows
  `.zip`; ships source (`src`, `plugins`, `projects`, `modules`) and resolves
  Composer dependencies on the target at install time (`vendor/` not bundled).
  `MODULES=git` mode fetches path-repo modules from pinned commits instead.
- **CI** (`.github/workflows/ci.yml`) — PHPUnit + Zig cross-builds on push/PR to `main`.
- **Release** (`.github/workflows/release.yml`) — test-gated; builds all three
  OS bundles on Linux and publishes the GitHub release automatically.
- **Self-hosted Zig toolchain** fetch (`tools/ci/setup-zig.sh`) so CI is immune
  to upstream purging the pinned dev build.
- **Kernel unit test suite** (`tests/Unit/Kernel/`) — Identity, SecurityVerdict,
  FrameworkException/ValidationException, DependencyGraphCalculator, Request,
  Response (427 tests total, all green).

### Changed
- **Minimum PHP is now 8.4.1** (aligned with the actual Symfony 8 dependency);
  previously advertised as 8.2+.
- The kernel is packaged under `/opt/hkm-kernel` with `hkm` on `PATH`.

### Fixed
- PSR-4 autoloading violations: split `SeedCommands.php` into one class per file
  and excluded path-loaded `database/{seeders,migrations,factories}` from the
  classmap.
- Registered the Cookie and Pageflow support helpers in Composer's `files`
  autoload so their global functions resolve.
- Added a tracked `phpunit.xml.dist` so CI finds the test configuration
  (`phpunit.xml` is gitignored).
- Windows cross-compilation: guarded POSIX-only raw-mode TTY code.

[Unreleased]: https://github.com/AlfaCode-Team/php-service-platform/compare/v1.0.7...HEAD
[1.0.7]: https://github.com/AlfaCode-Team/php-service-platform/compare/v1.0.6...v1.0.7
[1.0.6]: https://github.com/AlfaCode-Team/php-service-platform/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/AlfaCode-Team/php-service-platform/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/AlfaCode-Team/php-service-platform/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/AlfaCode-Team/php-service-platform/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/AlfaCode-Team/php-service-platform/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/AlfaCode-Team/php-service-platform/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/AlfaCode-Team/php-service-platform/releases/tag/v1.0.0
