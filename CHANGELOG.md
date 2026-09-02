# Changelog

All notable changes to the AlfacodeTeam PhpServicePlatform (Sentinel) kernel are
documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.13.0] - 2026-09-03

### Fixed
- **`hkm install --owner=` left every plugin file owned by the deploying user.**
  A project's plugins are not in the project: `hkm plugins install` keeps one
  copy per (plugin, version, origin) in the global store and links the project
  at it, so `plugins/Logger` is a symlink out of the tree. Both halves of the
  hardening pass stopped at that boundary by design — `hardenTree` skips
  symlinks because a chmod would follow one and rewrite a target outside the
  project, and the chown only walked the project root. The result was a project
  that verified clean and could not serve: every file the pool has to read
  first, every Provider and every controller a route resolves to, still belonged
  to whoever ran the command, under a report that said `Project owned by
  deploy:www-data`. `--owner` now also chowns the store versions the project
  links to, plus the directories between them and the store root so the trees it
  just chowned can be reached. Only the versions THIS project links to: the store
  is shared by every project on the machine, and claiming all of it for one
  project's web account is not that command's call.
- **`--production` reported a reachable project while the plugins were
  unreachable.** The traversal check walked the parents of the project root only.
  Since the store moved out of the project it defaults to `$HOME/.cache`, which a
  deploy under sudo resolves to `/root/.cache` — 0700 on every mainstream distro
  — so the chown succeeded on every entry and the site still could not read one
  of them. The check now covers the store's own parents, with its own remedy:
  relocate the store (`hkm plugins store --set=`, or `HKM_PLUGIN_STORE`) rather
  than widen a home directory to reach a cache.
- **A plugin that gained an env var never got it.** `hkm plugins enable` returns
  early when the plugin and its dependencies are already wired, so a plugin
  declaring a new `config[]` entry in a later version left an `.env` block that
  was now incomplete — and the boot failed on the missing key with nothing
  pointing at the cause. Enabling an already-enabled plugin now tops up its
  block. Safe by construction: the seeder only ever ADDS keys the file does not
  already mention, in any form, so a real secret is never rewritten.
- **`.env.example` documented the Tenancy control-plane switch as a hostname.**
  `TENANCY_CONTROL_PLANE=admin.example.com` reads as "the control plane lives
  here"; the plugin declares the key as `type: bool`, where any non-empty string
  is truthy — so the example value silently turned tenant routing OFF for anyone
  who uncommented it. Corrected to a bool, with `TENANCY_CENTRAL_DOMAINS` (a
  real declared key that was missing) added beside it and the mode values named.
  The plugin's own `module.json` stays the authority; this is the example
  catching up to it.
- **Re-seeding wrote a second block for the same plugin.** The append was
  unconditional, so a plugin seeded twice got two `# ─── Auth ───` headings, and
  three after that. Every key was still present exactly once, so nothing broke —
  the grouping the block exists to provide just quietly stopped being true. New
  keys are now merged into the block the plugin already owns, keeping the blank
  line that separates it from the next one.

### Added
- **`hkm env` — audit and tidy a project's `.env`.** A dotenv file accumulates:
  a plugin seeds its block on enable, someone appends a key at the bottom to try
  something, a second plugin declares a variable the first one already did. None
  of that is an error anywhere. The loader resolves a repeated key silently, the
  boot succeeds, and the value in effect is whichever line happens to be last —
  a file that works and does not say what it is doing.
  - `hkm env` reports duplicates with every occurrence's line number and marks
    which one is live. That marker is the point: `LoadEnvironment::setVar`
    overwrites on each call and the cascade reads a file top to bottom, so the
    LAST active assignment wins — the opposite of what most people assume when
    they append a key to the bottom of a .env.
  - `hkm env dedupe` asks per key rather than choosing. The right survivor is
    not derivable: `DB_HOST=localhost` on line 12 and `DB_HOST=10.0.0.4` on line
    88 are both plausible, and the one in effect is as likely to be the accident
    as the intent. `--keep=effective` is the scriptable form that cannot change
    behaviour; `--keep=first` / `--keep=last` are positional.
  - `hkm env group` reorders the file into blocks: a key a plugin declares in its
    `module.json` `config[]` goes under that plugin, otherwise under the feature
    its prefix names, otherwise `Ungrouped`. Comments attached to a key move with
    it, comments attached to nothing are rescued into a `Notes` block rather than
    dropped, and the pass refuses to write unless every key AND every
    informational comment that went in comes out again.
  - Every write leaves the previous file beside it as `.env.bak`, at 0600.
- **A project is found from anywhere inside it.** `resolveRoot` checked the exact
  working directory, so `hkm env` in `<project>/app` answered "'.' is neither a
  project folder (with proj.json) nor a registered name" about a project one
  directory up. It now walks up to the filesystem root, the way git, composer and
  npm all find theirs — for every command that takes a `[path|name]`, not just
  `env`. An EXPLICIT path stays exact: the same resolver backs
  `hkm install --owner`, and a command that chowns a tree must never quietly
  retarget itself above where it was pointed.

## [1.12.1] - 2026-09-02

### Fixed
- **A plugin fetch asked for a GitHub account, for a repo that is public.**
  `FileManager` was the one hyphenated plugin missing from the slug override
  table, so it resolved to `hkm-plugin-filemanager` — a repository that does not
  exist. GitHub answers **404 for "does not exist" and "not yours" alike**; it
  will not confirm a private repo to an anonymous request. Git cannot tell those
  apart, assumed the second, and stopped to ask for a username and password that
  no account could have satisfied. Added the override, plus tests pinning every
  multi-word folder to its real hyphenated slug (and round-tripping back to the
  PSR-4 folder name) so the next repo added with a hyphen cannot drift the same
  way.
- **Git could block a deploy on a credential prompt.** The plugin fetch inherited
  the terminal, so an unreachable remote hung `hkm install` on a password box
  until somebody killed it — on a deploy box or in CI, indefinitely. Every git
  invocation now runs with `GIT_TERMINAL_PROMPT=0` and SSH `BatchMode=yes`: a bad
  remote fails immediately and the call site names the plugin and URL, which is
  the information actually needed. `HKM_GIT_INTERACTIVE=1` restores the prompt
  for a genuinely private remote you intend to authenticate against by hand.

## [1.12.0] - 2026-09-02

### Fixed
- **A project installed with `--production --owner=` still could not be served
  by PHP-FPM.** The pass only ever touched `var/` and `userdata/`, so every
  directory a request actually reads — `app/public_html`, `src/`, `vendor/`,
  `plugins/` — kept the deploying user's ownership and whatever mode the clone
  arrived with. The pool could write logs it was never going to reach the code
  to produce. Three separate reasons a boot failed, each fixed:
  - the pass now covers the WHOLE project tree, and runs LAST — after
    `composer install` and the plugin fetch, both of which create `vendor/` and
    `plugins/` as whoever ran the command. Running at step 3, as it did, meant
    the two largest directories in the project were created *after* the
    permissions were "fixed".
  - `.env` was chmod'd `0600`. PHP-FPM running as another account cannot read
    `APP_KEY` through that, and the boot fails on a file whose mode bits look
    deliberate. It is now `0640` — group-readable, never group-writable, never
    world-anything.
  - nothing reported that the pool could not TRAVERSE to the project. Reaching
    `app/public_html/index.php` needs execute on every parent directory, and a
    home directory is `0700` on a stock Debian install — unfixable from inside
    the project, so the offending parents are now named (reported only, never
    changed).

- **The installed kernel was left at whatever the installing account's umask
  produced** (`tools/install.sh`). `/opt/hkm-kernel` is shared infrastructure —
  every PHP-FPM pool on the box loads its PHP out of that one tree, and none of
  those pools runs as the account that installed it. With `umask 027` or `077`
  the whole tree landed 0750/0700 and every site died with "Permission denied"
  on a kernel file, while the install reported success because the installer
  could obviously read what it had just written. The installer now normalises
  the tree it lays down: directories traversable, files readable, and anything
  that WAS executable still executable.

### Changed
- `hkm install --production` / `--owner=` now apply a split-ownership model:
  code owned by the deploy user and only READABLE through the web server's
  group (`2750`/`0640`), `var/` and `userdata/` group-writable (`2770`/`0660`).
  EVERY directory carries setgid, code included: the group is the only thing
  granting the pool access, so a file created later — a log written at 3am, a
  file a `git pull` lands — would otherwise take the creating account's primary
  group and drop out of the share, and each deploy would silently un-share
  whatever it touched.
  Code is never group-writable in either profile — an FPM pool that can rewrite
  the PHP it executes turns any file-write bug into code execution. An
  already-executable file keeps its exec bit (re-granted only where the profile
  grants read, so `bin/psp` and `vendor/bin/*` survive at `0750`, not `0751`),
  and `.git` is skipped by both the chown and the chmod.
- The pass re-stats what it changed and reports any mode the filesystem
  refused, instead of reporting success for a chmod the kernel rejected.


## [1.11.0] - 2026-09-02

### Fixed
- **The migration engine only worked on MySQL** (`modules/let-migrate`). A
  commit titled *"refactor: remove deprecated methods"* had restored `src/`,
  `tests/` and the README byte-for-byte to their state before a day of merged
  PR work — undoing four driver fixes and a Laravel-parity alias, and deleting
  the eight tests that covered them. Nothing was failing that the deletion
  fixed. Restored and carried forward:
  - `ALTER TABLE` compiled MySQL syntax for every driver — additions batched
    into one comma-separated statement, indexes added with `ADD KEY`, and drops
    running columns BEFORE the indexes over them. A rollback written in the
    correct order was reordered by the compiler into one that could not run
    anywhere but MySQL, in the one direction nobody exercises until they
    uninstall a plugin.
  - PostgreSQL rejected `BOOLEAN DEFAULT 1`, and `modifyColumn()` emitted three
    `;`-joined statements into a clause the extended query protocol refuses.
  - SQLite could not add a foreign key to an existing table, and `modifyColumn()`
    compiled `CREATE TABLE "__tmp_users" ()` — it was broken outright, because
    the rebuild SQLite requires was driven from a blueprint holding only the
    delta. It now reconstructs the full table from the `SchemaInspector`,
    carrying existing indexes across and unwrapping defaults so a literal is
    not re-quoted on every rebuild.
  - Seeding a second database in one run died with *Cannot redeclare class* —
    reachable the moment one run seeds once per driver, which is what
    `hkm ground migrate` does.
- **PostgreSQL: every schema lookup silently matched nothing.** The driver and
  inspector used libpq's `$1` placeholders, which PDO neither understands nor
  rejects — so `tableExists()` answered `false` for a table with seven columns,
  and the inspector reported no columns, indexes or foreign keys. Anything
  guarded by `hasTable()`, and everything built on schema diffing or dumping,
  was quietly wrong on that driver. Found only by executing against a live
  server.
- **SQL Server emitted invalid T-SQL for every column addition and every
  foreign key** — `ALTER TABLE … ADD COLUMN` (T-SQL has no `COLUMN` keyword
  there) and `ON DELETE RESTRICT` (unimplemented; its actions are `NO ACTION`,
  `CASCADE`, `SET NULL`, `SET DEFAULT`). Both are argued from the T-SQL
  specification and are **not** verified against a live server — none was
  reachable — but each replaces SQL the server rejects outright.
- **`MigrationConfig`: singular `path` overrode plural `paths`** instead of
  acting as its fallback, so a config carrying both silently ran one directory
  and ignored the array — failing by doing less work rather than by erroring.
- **`Blueprint::dropColumn()` accepted one column**, so `dropColumn('a', 'b')`
  silently dropped only `a`. Now variadic, and the `drop*` methods chain.

### Added
- **`useCurrent()` / `useCurrentOnUpdate()` / `bigIncrements()`** — Laravel
  parity, so a ported migration compiles unchanged. Without them the failure is
  a fatal *Call to undefined method* raised the moment the migration runs,
  during a deploy.
- **`StatusRenderer`** — `migrate:status` as normalised data, aligned table
  lines and JSON from one source, so the human and `--json` views cannot
  disagree.
- **`tests/Live` — the migration compiler executed against every reachable
  engine.** Configured with `LETMIGRATE_DB_MYSQL` / `_PGSQL` / `_SQLSRV`
  (`GROUND_DB_*` honoured); each run uses its own scratch database and drops it.
  A driver that is unconfigured or not answering SKIPS with the reason, never
  counted as a pass. This release was verified on SQLite, MariaDB 12.3 and
  PostgreSQL 18; SQL Server skipped, and says so.

### Changed
- `docs/guides/18_MIGRATIONS.md` — `->useCurrent()` and `->useCurrentOnUpdate()`
  now exist, so the anti-pattern entry saying they do not is corrected. The
  `->index()` half stands: an index is declared on the Blueprint, not the
  column.


## [1.10.1] - 2026-09-01

### Fixed
- **`hkm ground init` generated a CI workflow that called a binary nothing
  installs.** The generated `.github/workflows/ground.yml` ran
  `vendor/bin/hkm-ground check --here` and `vendor/bin/hkm-ground migrate
  --strict`, but this package declares `"bin": ["bin/hkm-cli",
  "modules/ground/bin/ground"]` — so what a plugin actually gets in its
  `vendor/bin` is `ground`. No package has ever shipped a `hkm-ground`
  executable, and `alfacode-team/ground` is not published separately at all: it
  is a path repo inside this repository, reachable only because the kernel
  autoloads it.

  So every plugin that ran `ground init` got a workflow whose first step could
  only ever exit 127, `No such file or directory` — and it failed at the step
  AFTER `composer install` succeeded, which reads like the plugin is broken
  rather than like the workflow named the wrong file. Five plugin repositories
  had already been scaffolded with it.

  The generator now emits `vendor/bin/ground`. Existing checkouts need the two
  lines changed by hand or `ground init` re-run; nothing else in the workflow
  moves.

## [1.10.0] - 2026-09-01

### Added
- **`ground dev` renders a plugin's pages on a BENCH rather than bare.** The
  generated entry handed Pageflow's `<App>` no `children`, so a page that
  declares no `.layout` — three of the eight plugin admin pages on disk — opened
  as unstyled markup on a white document, which reads like the page is broken
  rather than like a one-line assignment has not been written yet.

  The entry now frames every page in `GroundFrame` (`@ground/dev`, new, in this
  package's own `ui/`). It is a FRAME, not a replacement layout: the production
  tree renders untouched inside it, because `ground serve` running the real
  pipeline is the only reason to trust what it shows. The layout therefore
  becomes a control rather than a decision — `page` (the page's own `.layout`,
  else the admin shell for an `admin/Pages` page and nothing for a `site/Pages`
  one), `bare`, `admin`, `auth`.

  The bench also carries what only ground knows: every page from the glob with
  the route that renders it (a page no route reaches was previously unreachable
  in a browser at all), the plugin's routes with prefixes and filters resolved,
  the Pageflow page object, and an `adminShell` seeder.

- **`PluginManifest::expandedRoutes()`** — every route with its module and group
  prefixes, names, filters and `requires` resolved. `allRoutes()` deliberately
  leaves entries as written, which is right for checking declarations and wrong
  for anything that navigates: a route declared `/{id}` inside `{"prefix":
  "/admin"}` under `"routePrefix": "/api"` is served at `/api/admin/{id}`.

- **The `--sidebar-*` design tokens**, in the shared frontend theme. The admin
  shell in `@pageflow/admin` names eleven of them (`bg-sidebar-bg`,
  `text-sidebar-fg-muted`, `w-[var(--sidebar-width)]`, …) and not one was defined
  anywhere — the shell was ported out of HKM 0.3 and its stylesheet was left
  behind, so the sidebar rendered transparent with no width in every project. An
  undefined custom property is not an error, so nothing reported it.

### Fixed
- **`ground dev` marked only ONE surface hot, so pages on any other surface got
  no scripts at all.** PHP looks for `{surface}-hot` for whichever surface the
  CONTROLLER named; `--mode` wrote one file. A plugin whose pages render on
  `site` therefore served a bare shell with an empty `#app` while `yarn dev` sat
  there reporting itself ready on `admin` — no error in the PHP log, nothing in
  the console. One server serves every entry under the same root, so every
  declared surface is now marked hot.

- **A dev server that failed to start deleted a running one's hot file.** The
  cleanup was armed in `configureServer`, which runs before the port is bound,
  so with `strictPort` a second `yarn dev` exited during startup and removed the
  hot file belonging to the server that was working. Ownership is now claimed
  inside the `listening` handler, and the teardown hooks are armed there too.

- **`ground dev` loaded no CSS at all.** The generated config had no Tailwind
  plugin and the entry imported no stylesheet, while the pages, the shared `@ui`
  kit and the admin shell are all Tailwind-only. Every page rendered unstyled.
  The workspace now generates a stylesheet — the kernel theme inlined, so
  `@import "tailwindcss"` resolves from a location that has it — with explicit
  `@source` directives, since Tailwind's automatic scan stops at gitignored
  directories and every file that matters is outside it.

- **The scaffolded vitest tests never applied a page's `.layout`.** They rendered
  a bare `<Page />`, so the shell around it — the thing most likely to break —
  was never exercised. They now apply `Page.layout ?? AdminLayout`, which
  immediately surfaced that jsdom implements neither `matchMedia` nor
  `ResizeObserver`, both reached by `AdminLayout` on its first render; the
  generated `setup.ts` now supplies both. `@testing-library/dom` was also missing
  from the generated `package.json` — a peer of `@testing-library/react` since
  v16, without which the whole suite dies on import.

- **The shared `ThemeProvider` did not implement the API its own consumers
  call.** It exposed `{ theme, toggle }` with `theme: "light" | "dark"`, while
  `@pageflow/admin`'s `ThemeToggle` destructures `setTheme` and calls it with
  `"light" | "dark" | "system"` — so every click on the theme switch called
  `undefined` — and the shared `sonner.tsx` does `const { theme = 'system' }`.
  Neither failure is visible to a type-check or a build. The provider now
  exposes `setTheme` and `resolvedTheme`, treats `system` as a real third state
  that keeps tracking the OS, and guards `localStorage` in both directions (the
  unguarded read in the state initializer took the whole app down before first
  paint in a private window).

### Changed
- The default theme is now `system` rather than `light`. Anyone who has never
  chosen one follows their OS — which is what `sonner.tsx` already assumed.

## [1.9.0] - 2026-08-31

### Added
- **`ground migrate` runs the whole dependency CHAIN, seeds it, and separates
  the CENTRAL and TENANT databases.** Three related gaps, all of which let a
  green tick stand for a schema that could not deploy:

  - *The chain.* Only the target plugin's migrations ran. A plugin's schema does
    not stop at its own tables — tenancy's `user_tenants` has a foreign key onto
    `users`, which the User plugin owns — so running one plugin alone rehearsed
    something no project ever does. The chain is the same transitive `requires[]`
    walk that decides what a REQUEST loads, so it cannot disagree with what
    `ground serve` boots. `--with` names an undeclared dependency, `--alone`
    restores the old behaviour.
  - *Seeding.* Seeders now run against the freshly built schema, before the
    rollback. A seeder is the first thing to notice a column a migration renamed,
    and it exercises the schema the way the application will — real INSERTs, real
    constraints, real defaults.
  - *Central vs tenant.* `database/migrations` builds the central database and
    `database/tenant-template` builds ONE tenant's. Running both into a single
    scratch database made ground the only place those tables coexist: a
    tenant-template migration declaring `foreign('user_id')->on('users')` applied
    happily, while in production `users` lives in a different database where no
    engine can point a key. Each layer now gets its own database, created and
    dropped independently, with its own seeders (`database/seeders`,
    `database/tenant-seeders`).

  Because SQLite records a foreign key to a missing table without complaint —
  and `PRAGMA foreign_key_check` returns nothing on empty tables — the harness
  reads the declared keys and fails a layer whose parent table it did not build.
  That caught `social_identities.user_id → users` in a tenant schema.

- **Ground reads the plugin's own `.env`, and `ground init` writes it.**
  Ground already synthesised an environment — `APP_KEY`, `APP_ENV=testing`, and
  each `config[]` var's default or a type-correct placeholder — which is what
  lets a plugin boot with no configuration at all. What it could not know was a
  REAL value: a sandbox API key, a local MailHog host, `APP_DEBUG=false` for an
  afternoon. Those are properties of one machine.

  `init` now writes a `.env` seeded from the `config[]` declarations of the
  plugin AND every plugin it depends on, grouped by which one declared each var,
  and merges on re-run so a key someone pasted in survives. Precedence is
  `config[] default/placeholder` → `.env` → `PluginGround::env()`: a test naming
  a value still wins, because that value is a precondition of the test and must
  not depend on a file the test never mentions.

  Vars WITHOUT a default are written commented out — deliberately unlike
  `hkm plugins enable`, which writes them as an active empty `KEY=` so a project
  boot fails until the secret is supplied. That is right for a project and
  exactly wrong here: an empty string is a value, it would override ground's
  placeholder, and the bench would stop booting. The `.env` is gitignored.

- **Seeding a second database in one run died with "Cannot redeclare class".**
  LetMigrate's `SeederRunner` `require`d every seeder file unconditionally, and
  `require` EXECUTES it — so a seeder declaring a named class could be loaded
  only once per process. Nothing hit that until `ground migrate` began seeding
  once per driver in a single run; with SQLite alone there was one target and
  one load. It now skips the require when the class is already in memory and
  instantiates it directly, while `return new class {...}` files — which declare
  no named class — are still required every time, as they must be.

- **`ground init --ignore` — refresh a plugin's .gitignore and nothing else.**
  The ignore list grows as ground learns to generate more (a dev workspace, a
  lockfile from a different package manager), and an existing plugin then needs
  only that one step. Running the whole of `init` to get it would also scaffold
  a CI workflow and reinstall dependencies the plugin never asked for.

  The list itself now covers everything ground or its toolchain writes:
  `/vendor/`, `/node_modules/`, `/ui/node_modules/`, the generated
  `ui/package.json` and all three lockfiles, `ui/vitest.config.ts`,
  `ui/tsconfig.plugins.json`, `ui/.ground/`, `ui/dist/`, `ui/.vite/`,
  `composer.local.*`, `ground.databases.json`, `docker-compose.ground.yml` and
  the phpunit caches. Tests, fixtures, `phpunit.xml` and migrations stay source
  and are deliberately NOT ignored.

- **`ground drop` — remove scratch databases a killed run left behind.**
  `migrate` drops its own in a `finally`, so normally there is nothing to do;
  `GROUND_KEEP_DATABASE=1`, a hard kill, or a crash inside the drop itself each
  leave a whole database sitting on a server under an unrecognisable name. It
  only ever touches the `ground_` prefix, refuses anything else by name, and
  `--list` shows what it would do.

- **`hkm ground dev` — `yarn dev` inside a plugin, with HMR, against the real
  kernel.** A plugin's pages import `@pageflow/react`, `@ui/button`,
  `@providers/theme` — aliases that only existed after `hkm ui sync` had
  mirrored the plugin into a PROJECT. So "let me see this page in a browser"
  answered "first build a project", which is the wrong answer while the plugin
  is the thing being written.

  The command generates a gitignored Vite workspace at `ui/.ground/` (config,
  one entry per surface declared in `ui.json`, a `dev` script in
  `ui/package.json`), reusing the alias map `UiWorkspace` already derives for
  vitest. `ground serve` then sets `VITE_PUBLIC_PATH` so ViteManifest finds the
  dev server's hot file, and `PAGEFLOW_ROOT_VIEW` so the responder renders
  Pageflow's real layout instead of its minimal fallback shell.

  There is **no proxy**: PHP renders the page and points the browser straight at
  Vite for the modules, which is what the hot file has always been for. Run
  `hkm ground serve .` and `yarn dev` side by side, browse the PHP port, and a
  saved `.tsx` hot-updates.

  Two details are load-bearing. The entries are generated at exactly
  `src/surfaces/{surface}/index.tsx` — the path the Pageflow layout requests by
  default — so nothing has to inject a `viteEntry` prop. And EVERY surface's
  pages are registered in EVERY entry, because the server may render a component
  authored under `site/Pages` onto the admin surface, and the component key
  carries no surface in it.

### Fixed
- **`ground serve` alone 500'd every Pageflow page once the layout was wired.**
  The real layout calls `vite()`, which THROWS when there is neither a hot file
  nor a production manifest — so choosing that layout at startup turned "no
  `yarn dev` running" from a bare-but-valid shell into
  `ViteManifestNotFoundException`. The layout is now chosen PER REQUEST, from
  whether a hot file exists at that moment. Starting or stopping `yarn dev`
  therefore needs no restart of the PHP server either: the next reload just
  takes the other path.
- **The generated vitest setup left `localStorage` undefined on Node 24+.** Node
  ships its own, which shadows the one jsdom provides and is `undefined` unless
  the process was started with `--localstorage-file`. Any component reading a
  stored preference then died on `getItem` of undefined — the shared
  `ThemeProvider` does exactly that, so a page wrapped in it failed to render
  for a reason having nothing to do with the page. The setup now installs a
  working in-memory stand-in, because in a browser localStorage always exists.
- **`ground serve` rendered Pageflow pages with no assets at all.** Pageflow's
  Provider resolves a relative `PAGEFLOW_ROOT_VIEW` against the active project
  root, which under ground is a throwaway workspace containing no layout — so
  the responder fell back to its minimal built-in shell: correct page object,
  correct root element, and not one script tag. The page rendered, the React
  never booted, and nothing reported an error, because an empty shell is a
  legitimate thing to render.

## [1.8.1] - 2026-08-31

### Fixed
- **`ground serve` handed its router an autoloader path that exists in no
  layout**, so every request died before reaching the kernel:
  `Failed opening required '.../modules/ground/src/vendor/autoload.php'`. The
  router `php -S` executes runs in a fresh process per request and so has to
  `require` a composer autoloader itself; the path was built by counting
  directories up from the command's own source file, which named a `vendor/`
  inside `src/`. The command still started and printed `Listening` — the fatal
  was in a different process, on the first request, which is why nothing caught
  it. It now asks where the autoloader ALREADY IN EFFECT lives (two levels above
  the loaded `Composer\Autoload\ClassLoader`), correct by construction in a
  kernel checkout, an installed bundle and a plugin's own `vendor/` alike.
  `ServeTest` now reads the emitted router and asserts the paths it names are
  real — a generated file is executed by something other than the test runner,
  so nothing about it is checked unless it is checked deliberately.

## [1.8.0] - 2026-08-31

### Added
- **`hkm ground` — the plugin developer's bench, as a kernel module.** Developing
  a plugin previously required a project to test it from, which is backwards:
  the plugin is the thing being written and the project does not exist yet.
  `modules/ground` boots ONE plugin on the real kernel — real BootPipeline, real
  route compiler, real dependency graph, real scope isolation — with every port
  bound to a fake and the compiled manifests written to a throwaway workspace.
  Six verbs, no arguments: the plugin is the one you are standing in (`.` says
  so explicitly; a name reaches a different one), resolved by walking up from
  the working directory so it works from `ui/` and `tests/` too.

  - `ground init` — everything a plugin needs to be testable, once: `.gitignore`
    entries first, `phpunit.xml`, a CI workflow, dependencies, a scaffolded
    test, the UI setup, and the database harness. Idempotent, and it reports
    whether each file was WRITTEN or KEPT rather than overwriting an author's.
  - `ground check` — static conformance: manifest drift, undeclared `env()`,
    unbound contracts, access-rule violations. Exits 1 on any error.
  - `ground probe` — boot it and report what compiled.
  - `ground serve` — the real `HttpPipeline` behind `php -S`, against fakes.
  - `ground test` — phpunit, then vitest when the plugin ships page tests.
  - `ground migrate` — see below.

  It is a MODULE, not a plugin: it owns no business domain and extends no
  project. Because every plugin already requires the kernel, every plugin now
  gets `PluginGroundTestCase` with no dev dependency at all, and
  `vendor/bin/ground` without `hkm` on PATH.

- **`ground migrate` — migrations against every database, for real.** A
  migration is the one thing in a plugin that cannot be tested against a fake:
  a fake records SQL without parsing it, and `--pretend` compiles without
  executing, so a statement MySQL accepts and PostgreSQL rejects passes both.
  This connects to actual servers, CREATES its own scratch database per run,
  applies every migration, inspects the schema, then `reset()`s to exercise
  every `down()` — the half that `hkm plugins disable` depends on — and drops
  the scratch database afterwards. It never touches a database anyone
  configured. Drivers that are unconfigured or unreachable are reported as
  SKIPPED with the reason and never counted as passes; `--strict` fails the run
  when any supported database went untested, which is what CI should use.

  Connections come from `ground.databases.json` — written by `--init` straight
  into `.gitignore`, because it holds credentials for servers that exist on one
  machine — or from `GROUND_DB_MYSQL` / `GROUND_DB_PGSQL` / `GROUND_DB_SQLSRV`,
  which override the file so CI needs no file at all.

### Changed
- **`psp` is gone from every name a user sees.** `bin/psp` is now `bin/hkm-cli`;
  the bundler and the upgrade path still install it as `bin/hkm`, so installed
  layouts are unchanged. `[psp]` output prefixes are `[hkm]`, and the global CLI
  calls itself "HKM Kernel CLI".
- **`PSP_GLOBAL_AUTOLOAD` / `PSP_PROJECTS_DIR` are now `HKM_*`.** The old names
  are still READ as a fallback everywhere, and the launcher EXPORTS both — a
  project generated before this release reads `PSP_`, one generated after reads
  `HKM_`, and nothing can tell which it is about to run.
- **Generated project glue is `hkm_*`.** `psp_require_kernel_autoload()`,
  `psp_kernel_home()` and `psp_register_project_autoload()` become `hkm_*` in
  new projects; the managed marker is `[hkm-support:<Folder>]`. The tooling
  reads BOTH spellings, and `hkm plugins` emits whichever name the target
  project actually defines — writing the new name into a project generated
  before this release would produce a config that fatals on an undefined
  function.

### Fixed
- **`hkm --dev` handed PHP the launcher binary.** The CLI path was hardcoded to
  `<root>/bin/hkm`, which in a BUNDLE is the PHP CLI but in the dev monorepo is
  this launcher's own compiled executable — so every `--dev` passthrough died
  with a parse error thousands of lines into a Mach-O file. Resolution now takes
  `bin/hkm` when it IS a PHP script and falls back to `bin/hkm-cli`, reading the
  first bytes rather than trusting the name.
- **`ALTER TABLE` compiled MySQL syntax for every driver** (`modules/let-migrate`).
  Additions were batched into one statement and indexes added with `ADD KEY`,
  neither of which SQLite, PostgreSQL or SQL Server accept; and drops ran
  columns BEFORE the indexes over them, which only MySQL tolerates. A rollback
  written in the correct order was reordered by the compiler into one that could
  not work anywhere but MySQL. Found by `ground migrate` on its first run.

## [1.7.0] - 2026-08-30

### Security
- **A `Host:` header could make one project load another project's `.env`.**
  `DomainResolver` matches the request host against the MACHINE-GLOBAL registry
  (`HKM_USERDATA_DIR/projects.json`), which lists every project on the box by
  absolute path — so a host owned by a *different* project resolved to that
  project's directory, and tier 3 of the cascade read its `.env` with no check
  that it was the application being served. The result was a foreign `APP_KEY`,
  `DB_*` and `SESSION_*` spliced over ours, selected by a header the caller
  controls; with `ENV_CACHE=1` the merged result — our secrets included — was
  then written under *that* project's `var/cache`. Tier 3 is now confined to the
  application root, and a refusal is announced via `error_log` rather than
  silently skipped. The test is CONTAINMENT, not equality, because both layouts
  are legitimate: flat, where the project path *is* the root, and nested, where
  it is `<root>/projects/<name>`. Comparison is done after `realpath()` and with
  an explicit separator on the prefix test, so a sibling sharing a name prefix
  (`/srv/app-backup` against `/srv/app`) is not treated as inside. **When the
  environment loads is unchanged** — still before `Kernel::build()`; only which
  directory tier 3 will read has changed.

### Added
- **`routePolicy.only` — an allowlist for the routes your plugins publish.**
  A plugin owns and declares its routes, and one `hkm plugins install` can add
  thirty of them at once; the only existing control, `routePolicy.disable`,
  SUBTRACTS, so it helps only once you already know a route exists. You cannot
  veto what you were never shown. `Kernel::withRouteAllowPolicy()` (and
  `proj.json` `"routePolicy": {"only": [...]}`) inverts it: when the list is
  non-empty, a plugin route must match a spec or it is never exposed. Two
  asymmetries are deliberate, both so the safer posture is not the harder one to
  adopt — an EMPTY list means "no allowlist" rather than "allow nothing" (which
  would empty an application on upgrade), and an allow spec matching nothing
  does NOT fail the boot (naming routes from a plugin this deployment has not
  enabled is normal in shared configuration), unlike a disable spec. Allow is
  applied before disable, so the two compose: allow a module's whole domain,
  then subtract the handful of its routes you do not want.
- **Route-policy PREFIX specs — `"GET /mail/demo/*"`.** The exact form fails
  OPEN on upgrade: veto five demo routes by exact key and the plugin's next
  release adds a sixth, the five still match, the anti-typo guard is satisfied,
  and the surface grows with nothing to announce it. A prefix keeps covering
  what arrives later, which is the only form that survives a dependency bump.
  Prefixes are method-specific (`GET /admin/*` does not silently also drop the
  POST that mutates) and segment-bounded (`/mail/demo/*` never swallows
  `/mail/demos`). Available to both `disable` and `only`; the exact and
  module-domain forms are unchanged, and a prefix matching nothing still fails
  the boot.
- **`module.json` `"files"` — plain PHP files a module needs loaded.** A plugin
  is loaded by the KERNEL, not by Composer: plugins are symlinked into
  `plugins/` and reached through the PSR-4 `Plugins\` map, so no plugin's own
  `composer.json` is ever read. That is fine for classes and fatal for
  FUNCTIONS — a plugin declaring `"autoload": {"files": [...]}` has declared it
  in the one place nothing looks, and nothing complains until something calls
  one and dies with "Call to undefined function". Projects were hand-patching
  this with a `require_once` in `bootstrap/app.php`, which works exactly once,
  in the one project that noticed. Declared files are compiled into
  `files-manifest.php` and required at boot; a declared file that does not exist
  now FAILS THE BOOT instead of becoming a runtime fatal inside a plugin. When
  `module.json` declares none, the module's own `composer.json`
  `autoload.files` is honoured as a fallback — so existing plugins work with no
  plugin change at all.

### Fixed
- **Plugin event listeners with dependencies were silently dropped.** The
  `EventBus` is constructed once, at materialize, with the `CoreContainer`,
  while listener DEPENDENCIES are bound per request by `Provider::register()`
  into the `ModuleContainer`. Dispatch also gated resolution on `has()`, which
  reports only what is EXPLICITLY BOUND — so an ordinary listener class was
  reported absent and built with `new $listenerClass()`, which throws
  `ArgumentCountError` for anything with constructor arguments, which the catch
  logged as a failed listener. The event was dropped, the cause read like a bug
  in the listener, and projects worked around it by hand-assembling listeners
  (and four levels of a plugin's internals) into `withPorts()` so they would be
  in the core container after all. `EventBus::forContainer()` now gives each
  request and job a view that resolves against its own container, and dispatch
  asks the container before falling back to `new`. Resolution is a strict
  SUPERSET: a listener already bound in core resolves exactly as it did.
- **`BOOT_CACHE` could silently skip a manifest a newer kernel added.** The
  cached-boot check gated on one sentinel manifest, so a cache written by an
  OLDER kernel — whose stamp still matches, because the builder inputs did not
  change — was accepted while a manifest that version never compiled was simply
  absent, and the stage reading it did nothing. The sentinel is now a list, so
  an older cache invalidates and recompiles instead of leaving a new feature
  inert until someone clears `var/cache` by hand. The route allowlist is also
  part of the stamp key: without it, TIGHTENING the allowlist would leave the
  previous, wider route manifest cached — the worst way for a security control
  to fail.
- **`route:list` gained `--unfiltered` and `--plugin`** (in the Commands
  plugin): the inverse of `--filter`, and the one question an audit actually
  asks — what did enabling these plugins expose with no filter in front of it?
  An unfiltered route is not automatically unsafe (a login form, `robots.txt`,
  or a page shell whose data sits behind a filtered endpoint are all
  legitimately unfiltered); it is the set that has to be justified one by one.
- **`hkm plugins enable` now reports the HTTP surface it activates** — how many
  routes the plugin publishes and how many of those run no filter — instead of
  reporting none of them.

### Templates
- Removed a duplicate `HashingPort` binding (the second silently overwrote the
  first) and a dead `PdoDatabase` import.
- The connection pool is no longer built and `warmup()`-ed at bootstrap. Under
  PHP-FPM the bootstrap re-runs on EVERY request, so a pool there opened its
  connections, served one request and was thrown away — strictly more expensive
  than not pooling. It is now a lazy factory, gated on the CLI SAPI (which is
  what OpenSwoole and the queue worker run under).
- `app/worker/run.php` read `WORKER_QUEUE` and `WORKER_MAX_ITERATIONS` with
  `getenv()`, which cannot see a `.env` value because the environment loader
  deliberately skips `putenv()` — so both were silently ignored and every worker
  drained `default`. Now `env()`.
- `app/public/index.php` resolves the domain EXPLICITLY instead of reading the
  `$domain` the bootstrap happened to leave in scope. `require` shares scope, so
  the old form worked until the bootstrap returned early or renamed the
  variable — at which point `ResolveStage` falls back to the RAW `Host` header
  for `route_host`, the value the client controls. This matches what the
  OpenSwoole entry point already had to do per request.
- The process timezone is pinned explicitly (`APP_TIMEZONE`, default `UTC`).

## [1.6.1] - 2026-08-30

### Fixed
- **Essential modules never reached a queued job.** `HttpPipeline` passed its
  essentials into `OnDemandLoader`; `WorkerLoop` built its loader with none, and
  `Kernel::materialize()` had no way to hand them over. So a module the project
  declared app-wide in `proj.json` `"essentials"` was app-wide for requests and
  **absent from every job** — and for an essential that rebinds a port per scope
  (tenancy rebinding `DatabasePort`) the failure is silent rather than loud: the
  binding still resolves, just to the wrong connection. The worker now registers
  essentials into every job container and seeds their domains into the job's
  graph, so their transitive `requires[]` come with them — the same two steps
  `LoadStage` performs for a request. A job whose class the manifest does not
  know now also gets a container rather than the bare `CoreContainer`, since
  "essential" means every unit of work; an application that declares no
  essentials keeps its exact previous behaviour, including that fallback.
  The class→domain mapping both surfaces need moved to
  `DependencyGraphCalculator::domainsFor()`; a private copy in each pipeline is
  how they drifted apart in the first place.
- **`APP_DEBUG` meant two different things in one file.** `ErrorStage::isDebug()`
  parsed the value with `FILTER_VALIDATE_BOOL` while `publicError()` compared it
  `=== 'true'`. With `APP_DEBUG=1` the HTML debug page — stack trace and source
  excerpt — was served to anything sending `Accept: text/html`, while every JSON
  response still masked its message as "An internal error occurred.". One flag,
  two behaviours, and the more revealing of the two was the one that engaged.
  There is now one `isDebug()`, used by both; `FILTER_VALIDATE_BOOL` is the
  surviving parse because it is what every other kernel flag uses
  (`HttpPipeline::flag()`), so `1`, `on`, `yes` and `true` mean the same thing
  throughout. It also now reads through `env()` rather than `$_ENV`/`getenv()`:
  the environment loader deliberately skips `putenv()`, so `getenv()` is not the
  source of truth for a `.env` value. **Note the direction of the change** — with
  `APP_DEBUG=1` the JSON path now reveals exception messages, which is what the
  flag was asked for; `APP_DEBUG` unset or falsy masks them exactly as before.

## [1.6.0] - 2026-08-29

### Added
- **`Kernel::withWorkerSecret()` — the queue can finally be an authenticated
  channel.** `WorkerLoop` has always carried a signature check, but the kernel
  had no way to give it a key: `$signingSecret` defaulted to `''`, was never
  passed at construction, and there was no builder method. In every deployment
  that has ever run, the check was dead code and the worker executed whatever it
  was handed. A queue is an input channel — whoever can write to it is calling
  into the application — so this closes a hole, not a nicety. Defaults to
  `JOB_SIGNING_SECRET` and stays OFF when that is unset, preserving today's
  behaviour. It deliberately does **not** fall back to `APP_KEY`: that would
  switch verification on for every existing application at once and reject every
  job already in flight, since no `QueuePort` adapter signs by default. Turning
  it on is a two-sided change — roll it out producer-first, teaching the adapter
  to stamp `JobPayload::signatureFor()` at `push()` time.
- **Graceful worker shutdown, a memory ceiling, and per-job timeouts.** There
  was no `pcntl` anywhere in the kernel, so SIGTERM — what every process
  supervisor and container runtime sends to stop a worker — killed PHP outright,
  including in the window between `handle()` returning and `ack()` removing the
  message. A job that had already run its side effects came back on the next
  boot and ran them **again**. The loop now traps SIGTERM/SIGINT/SIGQUIT,
  finishes the job it is on, resolves its ack/release/fail, and exits.
  `run()` takes a `memoryLimitMb` so a supervised worker exits between jobs
  rather than being OOM-killed inside one, and a job's declared `timeout` is
  enforced with `pcntl_alarm` — best effort, since SIGALRM is dispatched between
  opcodes and cannot preempt a job blocked inside one long query.
- **`Request::withAttributes()`** — set several attributes in a single new
  instance. Every `with*()` deep-clones all seven parameter bags, so a chain of
  them pays that price once per link. `ResolveStage` attaching `route_entry`,
  `route_params` and `target_service` is one logical step that cost three full
  clones of a request nothing had read yet: **10.02 µs → 3.57 µs, 64% less**.
- **`SecurityVerdict::allowWithIdentity()`** — allow while carrying an identity
  that is not yet attached to a request. `allow()` reads the identity back *off*
  a request, forcing a layer that has just resolved one to clone the entire
  request so the constructor can read a single property.

### Fixed
- **`BOOT_CACHE` never hit for the essentials shape the docs recommend.**
  `Kernel::build()` computed `buildHash()` twice — before and after
  `resolveEssentialModules()`, which rewrites `essentials` from proj.json's
  DOMAINS (`tenancy.routing`) into provider CLASSES. So the stamp was written
  under one hash and read under another, and every request recompiled all ten
  manifests **and** rewrote the stamp on top of the recompile it had failed to
  skip — measurably *worse* than leaving the flag off. Measured on a three-route
  application: **2604 µs → 39 µs per request under PHP-FPM.** The hash is now
  taken once, from the raw builder inputs; the derived class list rides in the
  stamp's payload, never its key. `BootStampTest` tests the stamp in isolation
  and could not see this, so `KernelBootCacheTest` builds twice through the real
  `Kernel::build()` and watches the manifest inode.
- **A job payload that failed verification was silently deleted.** The check
  returned `skipped()`, which `processWithPort` then **acked** — removing the one
  piece of evidence that something is writing to your queue. A misconfigured
  producer and an active attacker were indistinguishable, and both looked like
  nothing happening at all. An unverifiable payload now raises
  `RejectedJobException`, goes through the `ErrorPipeline`, and is dead-lettered
  via `fail()`. It is never retried: a signature that does not verify will not
  verify on the second attempt.
- **`retry` and `timeout` in `module.json` compiled to nothing.**
  `CompileJobManifestStage` read `handler`, `queue`, `module` and `solves` and
  dropped the other two, so every job in every application shared one hardcoded
  exponential strategy and ran unbounded — while its manifest said otherwise. A
  declaration that compiles to nothing is worse than no declaration: it reads as
  a guarantee. Both are compiled through now and honoured per job, including the
  `"retry": 5` shorthand; an unknown strategy falls back rather than failing the
  boot, and `"max": 0` is raised to 1 (a job that can never run is never what it
  meant).
- **`hkm run` ignored its own documented default of `./`.** An `args.len <= 2`
  guard printed usage and exited 2 before the resolver ever ran, contradicting
  the command's module docblock, its help text, and the `resolveRoot()` call
  below it — which already handled an empty target. It bit `hkm run --dev`
  hardest: `--dev` is stripped before command parsing, so that invocation
  arrived as exactly `["hkm", "run"]` and failed, while adding any unrelated flag
  (`--port=8000`) got past the count and worked perfectly — making the failure
  look like it was about `--dev`, or about the directory, rather than about how
  many words were typed. Resolution now belongs entirely to `resolveRoot()`, and
  a bare `hkm run` outside a project names the actual problem instead of dumping
  a usage screen that does not mention it.
- **The Homebrew bump job failed a release that had already published.** Its PR
  fallback pushed the bump branch, then called `gh pr create` — which the API
  refuses unless *Settings → Actions → General → "Allow GitHub Actions to create
  and approve pull requests"* is on, and it is off by default. The step exited
  non-zero, so v1.5.0 shipped correctly with every asset in place while the run
  was marked failed. Both blocked paths now degrade to warnings that name the
  branch, a ready-made compare link, and the two settings that make the bump
  fully automatic. The release itself was never at risk; only the report was.
- **The documented Homebrew install did not work as written.** Homebrew 6
  refuses to load a formula from a third-party tap until it is trusted, and it
  refuses at `brew install` rather than at `brew tap` — so the two-line
  instruction appeared to succeed and then failed with "Refusing to load formula
  … from untrusted tap". `brew trust alfacode-team/hkm` is now part of the
  documented sequence, in the README and in the formula's own header.

### Changed
- **A job signature now covers the whole envelope, not just `data`.** Signing
  `data` alone left `jobClass` — the field that decides WHICH CODE RUNS —
  unauthenticated. Capturing one legitimately signed envelope and swapping its
  class for any other `JobContract` was enough; nothing about that required
  forging a signature, only reusing one. The material is now
  `jobId | jobClass | queue | maxAttempts | canonical(data)`. `attempts` is
  deliberately excluded: the driver increments it on every `release()`, so
  covering it would invalidate a job on its first retry — `maxAttempts` is signed
  instead, so the retry budget cannot be widened in transit. The payload is
  canonicalised (associative keys sorted at every depth, list order preserved)
  because a driver round-tripping the envelope through JSON is under no
  obligation to keep key order, and an unstable input makes an HMAC reject its
  own legitimate messages. **No migration is required**: verification was
  unreachable before this release, so no deployment has signed payloads in
  flight. `JobPayload::signatureFor()` is the one implementation both producer
  and verifier use.
- **`SecurityGateway` no longer clones the request on its final layer.** The
  clone existed so `SecurityVerdict::allow()` could read the identity back off
  it, and `SecurityStage` then cloned a second time to put that identity on the
  request the pipeline actually carries — so for the documented CSRF-then-Auth
  stack, where the last layer is the one that authenticates, a whole request copy
  was built and read once. A later layer still sees an earlier layer's identity;
  only the final layer takes the shortcut. **4.17 µs → 0.87 µs, 79% less.**

## [1.5.0] - 2026-08-28

### Added
- **Homebrew formula (`HomebrewFormula/hkm.rb`).**
  `brew tap alfacode-team/hkm https://github.com/AlfaCode-Team/hkm-kernel &&
  brew install hkm` — `php` and `composer` arrive as formula dependencies, and
  the Gatekeeper quarantine dance disappears entirely. This repository doubles
  as its own tap (Homebrew reads a `HomebrewFormula/` directory in any tapped
  repo), so there is no second `homebrew-hkm` repo to keep in sync. The formula
  consumes the same universal Mach-O the other macOS paths get rather than
  building from source: the launcher is pinned to a Zig *dev* toolchain that
  Homebrew's stable `zig` cannot compile. It reshapes the `.app` into the
  `libexec/bin` + `libexec/lib/hkm-kernel` pairing the launcher already
  self-locates against, and resolves `vendor/` by running the kernel's own
  `install.sh` against the PHP Homebrew just installed.
  - The `bin/` entry points are wrapper scripts, not symlinks — the same
    `_NSGetExecutablePath` reasoning as `install-macos.sh`, plus they default
    `HKM_USERDATA_DIR` to `~/.local/share/hkm`. Without that the project
    registry defaults to `<kernel>/projects/projects.json` *inside the Cellar*,
    which `brew upgrade` deletes wholesale.
  - `tools/homebrew-bump.sh` repoints the formula at a release, run by a new
    `homebrew` job in the release workflow. A tap formula pins one tarball by
    digest, so a stale one does not fail loudly — it silently installs the
    previous version. The digest is only knowable after the assets exist, so the
    job runs post-publish and lands its commit on `main` directly where the
    token allows it, else as an automatic pull request — `main` here requires
    reviews, and without the fallback the bump would simply be dropped. It
    never fails the workflow: the release is already out by then, and failing
    would only misreport a good release as broken.

### Changed
- **The macOS install is user-local by default — no root.** `install-macos.sh`
  put `HKM.app` in `/Applications` and escalated to `sudo` whenever that was
  not writable, which inverted on macOS the promise `tools/install.sh` makes in
  its own header on Linux: *no root, nothing written outside your home*. It now
  defaults to `~/Applications` — a first-class macOS location that Finder and
  Launchpad both show — and `--system` opts into `/Applications` as the only
  path that ever asks for a password. `--user` forces the home location.
  - An existing install is **updated where it is**. Without that, a plain
    re-run on a machine with `/Applications/HKM.app` would have left it in
    place and shadowed it with a second copy in `~/Applications`, leaving two
    launchers and `PATH` order alone to decide which `hkm` runs.
  - `--uninstall` sweeps **both** locations unless a scope is named. Removing
    only the one a given run resolved would leave a machine still answering
    `hkm` after printing "Removed."

### Fixed
- **`hkm doctor` and `hkm version` described a machine that does not exist on
  macOS.** `install_scope` modelled only the Debian/tarball pair, so both
  commands listed `/opt/hkm-kernel` and `~/.local/lib/hkm-kernel` as "not
  installed" beside a perfectly good bundle, and `hkm version` reported
  `scope: neither — a checkout or a custom prefix` for a stock install on the
  line below a table that had just marked it as the user scope. The module now
  models the scopes each platform's installer actually writes — on macOS
  `~/Applications/HKM.app` and `/Applications/HKM.app` — so `detect`,
  `scopeOf` and `Scope.how()` are right for every command that asks. The
  pre-1.4 legacy user root is no longer probed on macOS, where nothing ever
  wrote it. `hkm upgrade --user` / `--system` now select those two locations,
  while a bare `hkm upgrade` still updates the bundle it is running from — so a
  custom `HKM_APPDIR` install is updated rather than shadowed by a second copy
  in the canonical spot. The chosen root is passed down to the installer
  instead of being resolved a second time, which is what previously let the
  announced target and the written one differ.
- **`hkm version` called the wrapper that runs it a shadowing install.** Same
  wrapper blindness already fixed in `hkm doctor`, and the check now shares the
  same helper (`util.leadsTo`) rather than a second copy of the comparison.
- **A macOS upgrade could destroy the project registry.** `install-macos.sh`'s
  wrappers did not pin `HKM_USERDATA_DIR`, so the registry defaulted to
  `<kernel>/projects/projects.json` — inside the bundle that `hkm upgrade`
  replaces from an archive shipping its own default `projects.json`. Every
  registered project would have gone on the next update, warned about only by a
  `doctor` hint suggesting you pin it by hand. The wrappers now default it to
  `~/.local/share/hkm` and create it, matching the Homebrew formula.
- **`hkm upgrade --user` on macOS installed system-wide.** The macOS branch
  ignored the requested scope entirely and fell back to `/Applications`, so a
  command asking for a user-local install wrote outside `$HOME` — while the
  "Target" section above it named `~/.local/lib/hkm-kernel`, a third path that
  was neither. macOS installs are `.app` bundles, so `install_scope`'s
  Debian/tarball pair describes nothing that exists there; upgrade now reports
  and acts on the bundle it actually self-locates into, defaulting a fresh
  install to `~/Applications`. It also creates the destination (absent on a
  fresh account) and refuses on an unwritable directory *before* downloading,
  naming the no-root alternative — discovering that halfway through `tar`
  leaves a partly replaced bundle.
- **macOS: every child process the launcher spawns failed with
  `error.FileNotFound`.** `hkm` and `hkm-config` built their `std.Io.Threaded`
  instance with `.init(page_allocator, .{})`, leaving `InitOptions.environ` at
  its `.empty` default. Zig resolves a bare command name against the `PATH`
  held by the *Io instance* — not the `environ_map` handed to each spawn — so
  with no environ it fell back to `Threaded.default_PATH`
  (`/usr/local/bin:/bin/:/usr/bin`). On Linux that works by accident, since a
  distro `php` lands in `/usr/bin`; on Apple Silicon nothing Homebrew installs
  is on that list, so `php`, `composer`, `git`, and `tar` were all unreachable.
  The symptom was maximally confusing: `hkm doctor` printed
  `php  /opt/homebrew/bin/php` and then, one line later, "could not execute the
  PHP binary". Every PHP passthrough command (`hkm list`, `hkm new`, `hkm run`)
  was equally dead, making a macOS install non-functional even when it reported
  success. Both entry points now pass `.{ .environ = init.environ }`, which also
  fixes TTY/colour detection, and `HKM_PHP_BIN=/full/path/to/php` is no longer
  needed as a workaround.
- **`install-macos.sh` aborting on the first download with `REPO: unbound
  variable`.** The progress line interpolated `$REPO…` — a bare variable
  followed directly by a multi-byte `…`. macOS `/bin/sh` is bash 3.2, whose
  parser is not multi-byte-aware and swallowed the `…` bytes into the variable
  name, producing an unbound-variable abort under `set -u`. This hit *every*
  macOS user of the default (auto-download) path, since it fires before
  anything is fetched. Braced as `${REPO}`. `install.sh` carried the identical
  line and is fixed too — it survives only because `/bin/sh` is dash on most
  Linux distros.
- **Every `sudo hkm …` on macOS silently lost its configuration.** `SUDO_USER`
  was resolved to a hardcoded `/home/<user>` in two places
  (`lib/userconfig.zig`, `lib/install_scope.zig`), which is not merely
  non-native on macOS: `/home` there is an autofs automount (`auto_home`,
  `nobrowse`), so the path cannot even be created. Reads found nothing, so
  `sudo hkm --dev` lost `HKM_DEV_HOME`, `HKM_KERNEL_HOME` and
  `HKM_USERDATA_DIR`; `sudo hkm version` reported no user install on a machine
  that had one; `sudo hkm uninstall` looked for the user's files under `/home`
  and left them behind; and `sudo hkm-config set-kernel-home …` failed with a
  bare `error: Unexpected`. Home is now reconstructed per platform
  (`util.sudoUserHome`), and the tests assert the platform's convention rather
  than the Linux spelling that let this look covered. `hkm-config`'s setters
  also report an unwritable config file with the path and the key instead of a
  raw Zig error.
- **`hkm-config check` under `sudo` pinned root's registry into your config.**
  `ensureUserdata` read `env.HOME` while `userconfig.path` honoured
  `SUDO_USER`, so the two disagreed about whose home this was — and the value
  that got written was root's. Every later plain `hkm` run then pointed at a
  registry under `/root` (or `/var/root`) it cannot read. Both now resolve home
  the same way. This half was wrong on Linux too.
- **`hkm upgrade` on macOS was a silent no-op that nested a second bundle.**
  The extraction target was three `dirname()` calls off the kernel root, which
  lands on `HKM.app/Contents` — but the tarball's top-level entry is `HKM.app/`,
  so tar unpacked a whole second bundle at `HKM.app/Contents/HKM.app` and left
  the real kernel untouched. `install.sh` then re-resolved the OLD tree and the
  command printed "updated." The target is now found by walking up to the
  `.app` component (`appContainerDir`), which is independent of nesting depth
  and install prefix, and only the `HKM.app` member is extracted so the
  archive's `install-macos.sh` no longer lands in `/Applications`.
- **`hkm upgrade` did not know Homebrew, or its own limits, on macOS.** A
  Cellar install is replaced wholesale by `brew upgrade`, so unpacking a bundle
  into it is undone at best; it is now detected and refused up front — before
  any download, and before the scope machinery prints a plan for a kernel that
  is not the one running. A layout that is neither a `.app` nor a Cellar (a
  portable tree, or an `HKM_KERNEL_HOME` pin) is refused too rather than
  unpacked somewhere invented.
- **`isSystemBinDir` claimed Intel Homebrew's bin as the system install.** It
  is a `.deb` concept — "a launcher here belongs to the system package, whose
  kernel is `/opt/hkm-kernel`" — and `/usr/local/bin` is exactly where Homebrew
  installs on an Intel Mac. It now returns false on macOS, where no `.deb`
  exists for it to be true of.
- **Downloads went to a hardcoded `/tmp`.** They now honour `$TMPDIR`, falling
  back to `/tmp`. macOS gives each user a private, auto-cleaned `TMPDIR`; the
  artifact name is entirely predictable, so a world-writable `/tmp` is a file
  another user on a shared machine can pre-create.
- **`hkm doctor` giving advice that breaks a wrapper-based install.** Both
  macOS install paths reach the launcher through a wrapper script, so the
  binary's own directory is deliberately never on `PATH`. Doctor judged that by
  directory alone and reported "on PATH: NO" on an install that worked, then
  advised adding the real directory — which for Homebrew is the *version-scoped*
  Cellar path, so following it breaks at the next `brew upgrade`. It now
  recognises a wrapper that execs this launcher, which also stops it calling
  that wrapper a shadowing copy. Separately, a self-contained install (Homebrew,
  `HKM.app`, a portable tarball) no longer draws "no kernel installed in either
  scope — `hkm upgrade --user` installs one": that would build a second,
  competing kernel arbitrated by nothing but `PATH` order. Finally, the userdata
  dir is now read from the environment rather than from `config.env` alone —
  `registry.zig` consults the environment first and `userconfig.load()` folds
  the file into it, so the file was only ever part of the answer, and an
  exported `HKM_USERDATA_DIR` was reported "not pinned" while actively working.
  The row now also says which of the two pinned it.
- **`--help` on both installers printing past the end of the help text.**
  The `sed -n` ranges in `usage()` overran their comment blocks:
  `install-macos.sh` printed a dangling `WHY A WRAPPER SCRIPT…` heading and its
  underline, and `install.sh` printed a literal `set -eu` as if it were help.

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
