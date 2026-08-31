# hkm-plugin-ground

> HKM Kernel plugin — provides **`dev.ground`**.
> A test bench for **developing** and **testing** plugins.

[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)

Boot one plugin, on a real kernel, with no project, no database and no
configuration — then send requests at it, resolve its contracts, and read what
it recorded.

```php
$ground = PluginGround::for(Provider::class)->boot();

$ground->db()->onQuery('select', ['id' => 1, 'title' => 'First']);

$ground->get('/tasks')->status();              // 200, through the real pipeline
$ground->events()->dispatched('task.created'); // what the plugin emitted
$ground->service(TaskServiceContract::class);  // resolved in the plugin's scope

$ground->destroy();
```

## Getting started

```bash
cd ~/…/PLUGINS/PHP/hkm-plugin-user

ground              # where am I, and what can I do
ground install      # dependencies, from the checkouts next door
ground check        # is this plugin well-formed
ground test         # run its tests
ground serve        # browse it at http://127.0.0.1:8321
```

Six verbs, no arguments. **The plugin is the one you are standing in** — naming
one is for reaching a different plugin (`ground check view`). It works from
`ui/` and `tests/` too, not just the top of the repo.

`ground` lives at `bin/ground` in this repo. Put it on your PATH:

```bash
alias ground='~/…/PLUGINS/PHP/hkm-plugin-ground/bin/ground'
```

Running it with no arguments tells you the state of the plugin you are in and
what to do next:

```
  user  —  user.management

  installed   yes
  routes      31
  requires    database.management, crypto.services, cache.redis, …
  ui          5 page(s)

  install     dependencies, from the checkouts next door
  check       static checks — the mistakes a boot cannot catch
  probe       boot it and report what compiled
  serve       browse it at http://127.0.0.1:8321
  test        phpunit, and vitest if there are page tests
  new test    scaffold tests from module.json  (new ui-test for pages)
```

### The five verbs

| | |
|---|---|
| `ground install` | composer from the sibling checkouts, plus the UI test setup and `npm install` if the plugin ships pages. One command, from nothing to running tests. |
| `ground check` | static conformance — manifest drift, undeclared `env()`, unbound contracts, access-rule violations. Exits 1 on any error, so it gates CI. Add `--all` for every plugin. |
| `ground probe` | boot it on a real kernel and report what compiled: routes, events, whether every exposed contract resolves. |
| `ground serve` | the real `HttpPipeline` behind `php -S`, against fake ports. Ctrl-C to stop. |
| `ground test` | `phpunit`, then `vitest` if there are page tests. |
| `ground new test` | scaffold tests from `module.json` — one per route, contract and event, with `dependencies()` already filled in. `ground new ui-test` for the page components. |

Anything `ground` does not recognise is passed to the kernel CLI, so the long
forms and every flag still work: `ground plugin:check --json`, `ground list`,
`ground help plugin:serve`. Inside a project they are reached through `hkm`
under those same names.

### Using the harness in a test

```php
composer require --dev alfacode-team/hkm-plugin-ground
```

**Keep it in `require-dev`.** It binds nothing at runtime and cannot break a
running application, but it ships a hasher that is fast by design and an
encrypter that only base64-encodes. Both exist to be bound in a test.

## What it is for

A plugin is hard to test for a structural reason: it is not a library. It is
declared in `module.json`, compiled by a boot pipeline, loaded through a
dependency graph, and resolved inside a scoped container. Almost everything that
goes wrong with one goes wrong in that machinery, not in its classes — so a unit
test of its service proves very little, and standing up a whole project to find
out is the reason plugins go untested.

This runs the machinery. **The real BootPipeline, the real route compiler, the
real dependency graph, the real scope isolation.** What it replaces is only the
infrastructure underneath (the ports) and the project around it.

The difference is load-bearing. When a ground test says `{id:num}` rejects
`abc`, that is the kernel's matcher answering — not something this package
reimplemented and could reimplement wrongly.

## The three problems it solves

**Ports.** A plugin requiring `DatabasePort` cannot boot without one. Every port
is pre-bound to a fake, so there is nothing to wire before the first test.

**Config.** `ValidateConfigStage` fails the boot on a missing required env var.
Declared defaults are seeded from `module.json`; a required var with **no**
default gets a placeholder — and `placeholders()` lists them, so a test is never
quietly asserting against a stand-in.

**Isolation.** The kernel writes compiled manifests under the active project
root. Booting against a real project would overwrite that project's manifests
with one describing only the plugin under test, and leave them that way. Every
ground gets its own temp workspace.

---

## Writing a test

Extend `PluginGroundTestCase`. It boots lazily on first use and tears down
whether or not the test passed.

```php
use AlfacodeTeam\Ground\PluginGroundTestCase;

final class TaskRoutesTest extends PluginGroundTestCase
{
    protected function plugin(): string
    {
        return \Plugins\Task\Provider::class;
    }

    /** Providers for every domain in requires[] — the kernel demands them. */
    protected function dependencies(): array
    {
        return [\Plugins\Database\Provider::class];
    }

    /** Optional: identity, env, ports, security layers. */
    protected function configure(PluginGround $ground): PluginGround
    {
        return $ground->as(Identity::asAdmin('tenant-1'))
                      ->env(['TASK_PAGE_SIZE' => 5]);
    }

    public function testIndexListsTasks(): void
    {
        $this->ground()->db()->onQuery('from tasks', ['id' => 1, 'title' => 'Write it']);

        $response = $this->ground()->get('/tasks');

        $this->assertOk($response);
        $this->assertJsonPath($response, 'items.0.title', 'Write it');
    }

    public function testCreatingATaskEmitsTheEvent(): void
    {
        $this->ground()->post('/tasks', ['title' => 'New']);

        $this->assertDispatched('task.created', times: 1);
        $this->assertCommitted();
    }
}
```

Generate that file from the manifest instead of writing it by hand:

```bash
hkm make:ground-test task
```

It emits one test per declared route, one per exposed contract, and one per
emitted event — already naming the things this plugin has.

### Assertions

| Assertion | Asserts |
|---|---|
| `assertOk`, `assertStatus`, `assertRedirect` | the response |
| `assertJsonPath($r, 'a.b', $v)` | a dotted path in the JSON body |
| `assertValidationFailed($r, 'email')` | the kernel's 422 envelope named a field |
| `assertRouteExists('GET', '/tasks')` | the route COMPILED, not that it responds |
| `assertDispatched`, `assertNotDispatched` | integration events |
| `assertCommitted`, `assertRolledBack` | the transaction |
| `assertQueued(Job::class)`, `assertMailSent($view)` | queue and mail |
| `assertJobDeclared`, `assertJobAcked`, `assertJobReleased` | the worker (see below) |
| `assertJobExhausted($queue)` | the job ran out of attempts and stopped being retried |
| `assertCommandRegistered`, `assertCommandSucceeds`, `assertCommandOutputs` | CLI |
| `assertComponent`, `assertPageProp`, `assertNotProp` | the Pageflow page object |
| `assertPageResolves`, `assertRendersPage`, `assertPageResolvesEverywhere` | the page file |

Every failure message carries the response's error envelope and anything logged
at error severity during that request. A 500 in a ground test usually explains
itself in the failure output.

---

## The ground API

```php
// HTTP — through the real pipeline
$ground->get($path, $query, $headers);
$ground->post($path, $body, $headers);
$ground->json('PUT', $path, $body);              // sets Content-Type AND Accept
$ground->call('GET', $path, host: 'api.shop.test', face: 'admin');

// Resolution
$ground->service(TaskServiceContract::class);    // in the plugin's own scope
$ground->makeFromScope(TaskRepo::class, 'other'); // proves isolation holds
$ground->container();                            // request-scoped ModuleContainer
$ground->newRequest();                           // discard it, as end-of-request would

// Ports — the SAME instances the plugin resolved
$ground->db(); $ground->cache(); $ground->queue(); $ground->mail(); $ground->sms();
$ground->storage(); $ground->logger(); $ground->clock(); $ground->hasher(); $ground->encrypter();
$ground->portFor(SomePort::class);               // when you supplied your own

// What the boot produced
$ground->routes();                               // compiled route manifest
$ground->hasRoute('GET', '/tasks', domain: 'api.shop.test');
$ground->services();                             // compiled dependency graph
$ground->placeholders();                         // required config with no default

// Events
$ground->events();                               // the recorder
$ground->dispatch($event);                       // drive a listener the plugin subscribed

// CLI — the real CliPipeline, output captured
$ground->cli('task:prune', ['--days', '30']);    // → CliResult
$ground->commandNames(); $ground->hasCommand('task:prune');

// Workers
$ground->runJob(SendMail::class, ['to' => '…']); // the handler ONLY
$ground->work('reminders', maxIterations: 3);    // the real WorkerLoop
$ground->pushAndWork('task.remind', ['id' => 1]);
$ground->jobs(); $ground->hasJob('task.remind');

// The visual layer — see "Testing the visual half" below
$ground->pageflow('/panel');                     // page object via X-Pageflow
$ground->pageflowSend('POST', '/panel', $body);
$ground->pageflowLoad('/panel');                 // full load, page parsed from the shell
$ground->pages('admin');                         // does the component have a page file?
$ground->ui();                                   // the plugin's ui/ui.json
$ground->stubbedFilters();                       // aliases that DID NOT run
```

`host:` sets the **validated** `route_host` attribute — the one `ResolveStage`
selects a domain group with. It is how a domain-grouped route is reached.

### Builder

```php
PluginGround::for(Provider::class, DependencyProvider::class)
    ->with(AnotherProvider::class)
    ->essential('session.management')       // loaded on every request
    ->port(DatabasePort::class, $myDouble)  // replace one fake
    ->security(new JwtAuthLayer(...))       // replaces the allow-all stand-in
    ->routes([...])->routeGroups([...])     // project-layer routes, as proj.json declares them
    ->domains('shop.test', 'api.shop.test') // required before a group may name a domain
    ->as(Identity::asUser('u1'))
    ->env(['FEATURE_X' => true])
    ->boot();
```

### Security is open by default

`BindSecurityStage` refuses to boot with an empty layer list — fail-closed, so an
application that forgot to configure security never serves traffic. A ground must
still boot, so it installs an allow-all stand-in. Passing any layer to
`security()` replaces it, which is what a test **about** authentication or CSRF
wants.

### Dead-lettering does not look like `QueuePort::fail()`

Read `WorkerLoop::process()`: when a job's attempts are exhausted it calls the
job's own `failed()` hook, returns a **skipped** result rather than rethrowing,
and `processWithPort()` then **acks** the payload. `$port->fail()` is reached
only if that dead-letter hook itself throws.

So `assertJobExhausted()` — retried at least once, then acked, queue empty — is
the assertion to reach for. `assertJobFailedHard()` exists for the narrow
`QueuePort::fail()` case and is rarely what a test wants.

`FakeQueue::release()` re-queues with `attempts + 1` (JobPayload is
`final readonly`, so it builds a new one). Without that increment nothing ever
exhausts and a failing job is released forever — which is exactly the bug this
harness shipped with until a review caught it.

### Events are recorded from `emits[]`

`EventBus` is `final` and has no wildcard subscription, so the recorder subscribes
to every name in the plugin's `module.json` `emits[]`. **An event dispatched
under a name the manifest does not declare is not recorded** — it shows up as
"nothing recorded", and the failure message says so. `plugin:check` reports the
same drift from the other side.

---

## Testing the visual half

A plugin's UI fails in places PHP cannot see. The three that actually bite:

| Failure | What every other check says |
|---|---|
| Route names a component with no page file | HTTP 200, valid page object, `tsc` clean. Browser: `Page "Auth/Login" not found under Pages/` |
| A prop is renamed on the server | PHP tests pass; the component reads `undefined` |
| A secret ends up in props | Nothing objects — props ship to the browser and into the SW cache |

The ground covers all three, in that order of cheapness.

### 1. Assert the page object, never the HTML

```php
$page = $this->ground()->pageflow('/auth/login');   // sends X-Pageflow, gets the page object

$this->assertComponent('Auth/Login', $page);
$this->assertPageProp($page, 'canResetPassword', true);
$this->assertNotProp($page, 'user.password_hash');  // props ship to the browser
```

`{component, props, url, version}` is the server's contract with the client.
Markup belongs to a React component a designer may rewrite this afternoon — a
test asserting on it breaks on a class-name change and passes when the props are
wrong, which is exactly backwards.

`pageflowLoad()` gets the **full page load** instead and parses the page object
back out of the HTML shell (both `data-page` and the legacy
`window.initialPage`). Use it when the shell itself — CSRF meta tag, Vite tags,
mount point — is the subject.

### 2. Assert the page file exists

```php
$this->assertRendersPage($page, 'Auth/Login', surface: 'admin');
```

This resolves the component the way the surface does, with its own rule from
`src/surfaces/<name>/index.tsx`:

```js
const key = Object.keys(pages).find((k) => k.endsWith(`/${name}.tsx`));
```

over the same globs, in the same order:

| Surface | Searched, in order |
|---|---|
| `admin`   | project `./Pages/**` → `plugins/*/admin/Pages/**` → `plugins/*/site/Pages/**` |
| `project` | project `./Pages/**` → `plugins/*/site/Pages/**` |

Two consequences are reproduced deliberately rather than "fixed": the admin
surface **does** serve a plugin's `site/Pages` (that is why Auth's
`site/Pages/Auth/Login.tsx` answers `render(…, 'Auth/Login', 'admin')`), and
`endsWith` means `User/Index` also matches `.../Admin/User/Index.tsx`. Resolving
more strictly here would report a page missing that the browser finds.

Files are read from the plugin **source** (`ui/admin/Pages/…`), so this works
before `hkm ui sync` has ever run. A failure prints what was searched, every
component found, and the nearest name — a typo shows itself.

### 3. Render the component against the server's real props

PHP cannot render React. What it can do is hand the component test **real** data:

```php
// In a ground test — dump what the server actually produced.
$this->ground()->pageflow('/panel')
     ->writeFixture(__DIR__ . '/../ui/__fixtures__/panel-dashboard.json');
```

```bash
hkm make:ui-test panel --config     # writes __tests__/*.test.tsx + vitest.config.ts
cd plugins/Panel/ui && npx vitest
```

The generated test imports that fixture:

```tsx
import Page from "../admin/Pages/Panel/Dashboard";
import fixture from "../__fixtures__/panel-dashboard.json";

render(<Page {...(fixture.props as any)} />);
expect(screen.getByRole("heading")).toHaveTextContent("Dashboard");
```

**This is the whole point of the fixture.** Hand-written mock props drift from
the server the moment a field is renamed, and the component test keeps passing
while the page breaks. With a dump, a server-side rename fails it here. Commit
the fixture; re-dump when props change.

A real dump also carries the *shared* props you would never think to mock —
Pageflow injects `pageflow_auth` and `errors` into every page.

### Static UI checks

`plugin:check` includes them; a plugin with no `ui/` produces none.

| Code | Reports |
|---|---|
| `ui.no-alias`, `ui.alias-shape`, `ui.entry-missing` | `ui.json` cannot be federated into `tsconfig.plugins.json` |
| `ui.surface-path`, `ui.surface-missing` | a declared surface the bundler does not glob — it federates nothing, silently |
| `ui.export-missing` | an `exports` entry pointing at a file that is not there |
| `ui.page-missing` | a component the plugin's own `render()` calls name, with no page file |
| `ui.page-no-default-export` | the surface resolves `mod.default ?? mod`; without one React gets a module object |
| `ui.pages-outside-convention` | `.tsx` under a face directory but no `Pages/` — never globbed as pages |
| `ui.no-admin-nav` | admin pages with no `ui/admin/nav.ts` — reachable only by typing the URL |

Component names are read by **tokenising** the PHP for literal `render()`
arguments; a dynamically-built name is skipped rather than guessed, which is what
the runtime `assertPageResolves` is for.

### Route filters get a stand-in

`ROUTE_STRICT_FILTERS` refuses to build a pipeline when a route names an alias
nothing registered — and `auth`/`throttle`/`shield`/`hmac` all come from
SecurityFilters. Pageflow's own `/pageflow/csrf` carries `throttle:60,1`, so
without help a ground could only boot filter-free plugins.

The ground registers a **pass-through** for unclaimed aliases and reports them:

```php
$this->ground()->stubbedFilters();   // ['auth', 'throttle']
```

**A stubbed filter did not run.** A test asserting "this route is protected"
while `auth` is stubbed asserts nothing. Load the real plugin
(`->with(SecurityFilters\Provider::class)`) when the filter is the subject, or
`->realFilters()` to make the gap fail loudly instead.

---

## The fakes

Each is an honest double, not a stub that agrees with everything.

| Fake | Notable behaviour |
|---|---|
| `FakeDatabase` | runs no SQL; `willReturn()` FIFO or `onQuery('needle', …)`; `failOn()` drives the rollback path; `commit()` outside a transaction **throws** |
| `FakeCache` | TTLs evaluate against `FrozenClock`, not real time; `increment()` keeps the existing window; locks enforce **ownership** |
| `FrozenClock` | UTC, moves only via `travel('+5 minutes')` |
| `FakeQueue` | records pushes; `pop()` is explicit; payloads carry a verifiable signature |
| `FakeMail` | records the view NAME, not rendered output — so a template edit does not break the test |
| `FakeStorage` | in-memory; `get()` on a missing path **throws**, as every real adapter does |
| `FakeLogger` | keeps every record; `problems()` is what failure messages print |
| `FakeHasher` | fast and unsalted **by design** — bcrypt's slowness is its security property and a suite cannot pay it hundreds of times |
| `FakeEncrypter` | reversible base64; **rejects payloads it did not produce**, catching double-decrypts; `unserialize` with `allowed_classes: false` |

`FakeLogger` is bound by default even when a test asserts nothing about logging:
`EventBus` swallows a listener exception and reports it only through the logger,
so without one a throwing listener is indistinguishable from an event that never
fired.

---

## Reference — the long forms, and where plugins are found

Inside a project the commands come from the kernel's CLI (`hkm plugin:check`).
While DEVELOPING plugins there is usually no project to run them from, so this
package ships its own entry point:

```bash
ground check                  # == ground plugin:check
ground probe view --routes    # == ground plugin:probe view --routes
ground serve                  # == ground plugin:serve
```

`bin/ground` boots a real kernel in a throwaway workspace and runs the same
commands the kernel would. The **working directory is left alone** — that is how
you choose which plugins are in scope.

### `ground install` — what it actually does

Every plugin declares its siblings through `vcs` repositories pointing at
private GitHub repos, so `composer install` on a fresh checkout stops at
`Could not authenticate against github.com` and nothing can be tested. That is
the wrong failure to hit while developing: the code you want to test against is
not on GitHub at all, it is the checkout in the directory beside this one, with
your unpushed changes in it.

```bash
ground install          # this, for a plugin's PHP and UI dependencies
bin/link-local          # the PHP half alone — what CI calls
```

It writes **`composer.local.json`** — the same manifest with `path` repositories
— and installs through `COMPOSER=composer.local.json`. The tracked
`composer.json` is never touched, which is the point: a released install must
still resolve from GitHub for everyone else, and a local convenience that
rewrites the manifest gets committed by accident within a week. Add
`composer.local.*` to `.gitignore` and the arrangement is invisible.

It also adds `hkm-plugin-ground` to require-dev **in that local file only**, so a
plugin that has never been tested can run a scaffolded ground test immediately
instead of failing on a missing dev dependency. When you decide to keep the
tests, add the require-dev for real.

`HKM_KERNEL_PATH` and `HKM_PLUGINS_PATH` override where it looks.

### Discovery

Four layouts, searched **nearest-first**, so the copy you are editing beats a
sibling or a vendored release of the same plugin:

| # | Looked at | The layout it serves |
|---|---|---|
| 1 | the directory itself | you are standing INSIDE a plugin repo |
| 2 | `plugins/*`, `modules/*` | a project or the kernel checkout |
| 3 | the **parent's** children, and its `plugins/`, `modules/` | a plugin-development workspace, every plugin a sibling repo |
| 4 | `vendor/*/*` | plugins installed the ordinary way |

Layer 3 is what makes cross-plugin work possible before anything is published:
`plugin:probe user` resolves `auth.identity`, `view.rendering` and nine more to
the checkouts next door instead of demanding a `composer install`.

A sibling repo is in nobody's composer autoloader and never will be — it is a
different package with its own `vendor/`. So each discovered plugin gets a PSR-4
mapping registered from the namespace in its `Provider.php` to its own
directory: the same `"Plugins\\X\\": ""` every one of these plugins declares in
its own `composer.json`. The autoloader is **appended**, so a properly installed
plugin still resolves through composer and nothing is shadowed.

```bash
--path <dir>   search from there instead of the working directory
--here         only this directory and its plugins/ — skip sibling repos (for CI)
```

When nothing is found, the commands print **the globs they used**. A plugin repo
one directory off looks identical to one that is not there at all.

---

## Static checks — `plugin:check`

```bash
hkm plugin:check            # every installed plugin
hkm plugin:check task       # one
hkm plugin:check --json     # machine-readable
hkm plugin:check --strict   # warnings fail too
```

Exits 1 on any **error**, so it gates CI. It reads the plugin; it boots nothing.

It **deliberately does not** re-check what the boot already refuses — a malformed
route path, an unknown parameter type, an unregistered filter alias. Duplicating
those would mean maintaining two definitions of one rule that drift apart. It
covers the mistakes nothing else reports:

| Check | Why nothing else catches it |
|---|---|
| `manifest.solves-drift`, `requires-drift`, `exposes-drift` | the kernel reads `module.json` and never calls those methods — a Provider disagreeing with its own manifest is invisible |
| `manifest.requires-class` | a class-string in `requires[]` fails the boot with a message about an unknown *domain*, which reads like the plugin is missing |
| `config.undeclared` | `ValidateConfigStage` checks declared vars are present; it cannot know about an `env()` call nobody declared. That boots fine and returns null in production |
| `config.getenv` | `LoadEnvironment` never calls `putenv()`, so `getenv()` returns nothing for any `.env` value |
| `exposes.unbound` | an exposed contract that `register()` never binds fails at request time, in the *consuming* module |
| `route.handler-class`, `-method`, `-visibility` | verified at boot only when `ROUTE_VERIFY_HANDLERS=true`, which is off in production |
| `access.*` | the five access rules are enforced at RUNTIME, so an unexercised path stays wrong until a user finds it |

### What the analysis can and cannot see

Both scanners are **tokenised**, not matched against text — so a comment
mentioning `env('FOO')`, a method named `env()`, and a closure's `use (...)` are
all correctly ignored. (The first version used regexes and reported an undeclared
variable that existed only in this project's own docblock.)

The limit that remains: imports are read from `use` statements, so a violation
written as an inline fully-qualified reference is not seen. **This catches drift;
it does not prove conformance.**

---

## Runtime probe — `plugin:probe`

```bash
hkm plugin:probe task --routes
hkm plugin:probe task --keep     # keep the workspace and print its path
```

`plugin:check` reads the plugin. `plugin:probe` **runs** it: the real boot
against fake ports, in a temp workspace. It resolves the plugin's `requires[]`
to installed providers transitively, so it works on a real plugin rather than
only on dependency-free ones.

It reports required config that had no default, the routes that compiled (this
plugin's only, not its dependencies'), and whether every exposed contract
actually **resolves** — the check worth running, because a contract that compiles
but does not resolve fails in the consuming module, a long way from the plugin
that broke it.

A boot failure here is printed as-is. It is the same failure a project would get
when enabling the plugin, without having to wire a project up to see it.

---

## Browse it — `plugin:serve`

```bash
ground plugin:serve user                       # http://127.0.0.1:8321
ground plugin:serve user --port=9000
ground plugin:serve user --with=session,security-filters
```

`plugin:check` reads the plugin, `plugin:probe` boots it, and this **serves** it:
the real `HttpPipeline` behind PHP's built-in server, against the same fake ports
every ground test uses. It is the third question a plugin author asks, and until
now the only one that needed a whole project wired up first.

It proves, cheaply: the route compiles, matches and reaches its handler; the
controller, service and view actually run; the markup, layout, assets and
Pageflow page object are what you expect; and when something breaks, the failure
arrives in the browser with a real stack trace.

**Every port is a fake.** The database returns whatever it was seeded with —
nothing, by default — mail goes nowhere and the cache is per-request. A page that
reads from storage renders empty here and is not broken.

`--with` loads plugins nothing *declares* a dependency on but a page still needs;
`session` and `security-filters` are the usual pair. Without SecurityFilters, a
route naming the `auth` filter is served with a pass-through stand-in — exactly
the wrong thing to debug an auth problem against.

The server **boots per request** (~35 ms), because `php -S` re-executes its
router each time. That is the behaviour you want while editing: every reload
picks up the code *and* the `module.json` you just saved, with nothing to
restart. A route added to `module.json` is live on refresh.

---

## Checking the UI — `make:ui-test`

PHP can prove a route returns `{component, props}` and that a page file exists
for it (`assertRendersPage`). It cannot prove the component **renders**. That
needs a DOM, and a DOM needs node.

```bash
cd ~/…/PLUGINS/PHP/hkm-plugin-user
../hkm-plugin-ground/bin/ground make:ui-test user --config   # tests + vitest config + package.json
cd ui && npm install                                         # once
npx vitest                                                   # or: npm test
```

`--config` writes a **runnable** setup: a `package.json` with every npm package
the pages actually reach for, and a `vitest.config.ts` whose aliases point at
the sibling checkouts on disk — `@pageflow/react`, `@pageflow/admin`, `@ui/*`,
`@lib`, `@providers`, and the plugin's own `@user`. Those aliases normally exist
only after `hkm ui sync` has federated everything into a project's `frontend/`,
which is why a plugin's UI was previously uncheckable until a project existed.
They are derived from the same `ui.json` and `package.json` declarations `hkm ui
sync` reads, and written **relative** to the config, so the file is committable
rather than pinned to one machine.

### Fixtures are dumped, not written

The component test reads its props from `ui/__fixtures__/<page>.json`, which a
**ground test dumps from a real response**:

```php
$this->ground()->pageflow('/admin/users')
    ->writeFixture(__DIR__ . '/../ui/__fixtures__/user-index.json');
```

That is the whole point. Hand-written mock props drift from the server the
moment a field is renamed — the mock is updated by whoever wrote it, or never —
and the component test keeps passing while the page breaks in the browser. With
a dump, a server-side rename changes the file and fails the component test.

A page with no fixture yet gets a **placeholder**, and its tests report as
skipped with the reason in the file:

```
 ✓ __tests__/user-index.test.tsx (3 tests)
 ↓ __tests__/user-profile.test.tsx (3 tests | 3 skipped)
```

Commit `__tests__/` and `__fixtures__/`. Ignore `ui/node_modules/`.

### Pages render inside `PageContext`

A Pageflow page reads its props through `usePage()`, which **throws** on an
empty context — so `render(<Page {...props} />)` renders nothing. The scaffold
wraps the page in `PageContext.Provider` with the whole fixture, which is the
same object `<App>` supplies at runtime. (`PageContext` is exported from
`@pageflow/react` for exactly this.)

---

## Notes

- **One ground at a time, per process, is the supported mode.** `Paths` is a
  static singleton and env lives in superglobals, so the ground re-asserts both
  before every operation. Two live grounds work (there is a test for it), but
  they share those globals.
- **Always `destroy()`.** `PluginGroundTestCase` does it in `tearDown()`. A
  leaked ground leaves `$_ENV` mutated and `Paths` pointing at a deleted
  directory, which surfaces as an unrelated later test failing for no visible
  reason.
- `GROUND_KEEP_WORKSPACE=true` keeps the temp workspace. When a boot fails, the
  compiled manifests in it are usually the fastest way to see what the kernel
  thought the plugin declared.
- `GROUND_WORKSPACE_DIR` relocates workspaces off `sys_get_temp_dir()`.

## Running this package's own tests

```bash
composer install
vendor/bin/phpunit
```

```
118 tests, 210 assertions
```

The suite boots a real fixture plugin (`tests/Fixtures/Sample`) and asserts the
harness's claims — including that internal bindings really are unreachable
cross-scope, and that two grounds do not steal each other's workspace.
`tests/Fixtures/Drifted` is **deliberately wrong**; the inspector tests assert
each of its faults is reported. Do not fix it.

## License

MIT — see [LICENSE](LICENSE).
