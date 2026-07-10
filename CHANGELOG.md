# Changelog

All notable changes to the AlfacodeTeam PhpServicePlatform (Sentinel) kernel are
documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
