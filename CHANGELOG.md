# Changelog

All notable changes to the AlfacodeTeam PhpServicePlatform (Sentinel) kernel are
documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
