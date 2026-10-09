# ground — Plugin Development Harness

The `alfacode-team/hkm-plugin-ground` package provides a test harness for developing and testing HKM Kernel plugins in isolation, without a project, without a database, and without configuration. Boot a plugin on a real kernel, send requests through real pipelines, and inspect what it emitted.

## What ground solves

A plugin cannot be unit-tested in isolation: it is declared in `module.json`, compiled by the boot pipeline, loaded through a dependency graph, and resolved inside a scoped container. Almost everything that goes wrong with a plugin goes wrong in that machinery, not in its classes — so a test of a service proves very little, and standing up a whole project to see if it works is why plugins go untested.

Ground runs the real machinery:

- the real **BootPipeline** (validation, manifest compilation, security binding)
- the real **HTTP/CLI/Worker pipelines** (request resolution, loading, execution)
- the real **dependency graph** and **scoped containers** (scope isolation enforced)
- the real **route compiler** and **matcher** (parameter types, domain grouping)

What it replaces is only the infrastructure underneath — the ports — and the project around it. A plugin that passes ground tests passes on a real kernel.

## Installation

Add to the plugin's `composer.json` dev dependencies:

```bash
composer require --dev alfacode-team/hkm-plugin-ground
```

Keep it in `require-dev` — it binds only test doubles and has no runtime role.

The harness is available in tests immediately:

```php
use AlfacodeTeam\Ground\PluginGroundTestCase;

final class YourPluginTest extends PluginGroundTestCase
{
    protected function plugin(): string
    {
        return \Plugins\YourPlugin\Provider::class;
    }

    public function testSomething(): void
    {
        $this->ground()->get('/path');
    }
}
```

## PluginGround — The builder

Construct a ground with the plugin(s) under test and configure it:

```php
$ground = PluginGround::for(
    \Plugins\Invoice\Provider::class,
    \Plugins\Database\Provider::class  // dependencies
)
    ->with(\Plugins\Security\Provider::class)           // extra plugins
    ->essential('session.management')                    // loaded on every request
    ->port(DatabasePort::class, $customDouble)          // replace one fake
    ->security(new JwtAuthLayer(...))                   // install security layers
    ->routes([...])                                     // project routes
    ->domains('shop.test', 'api.shop.test')             // required for domain groups
    ->as(Identity::asUser('user-1', 'tenant-1'))        // set the acting Identity
    ->env(['INVOICE_TAX_RATE' => 0.20])                 // override env vars
    ->boot();
```

### Methods

| Method | Parameters | Returns | Purpose |
|---|---|---|---|
| `for()` | `provider: class-string`, `...$dependencies: class-string[]` | `self` | Boot this plugin and its required dependencies |
| `with()` | `...$providers: class-string[]` | `self` | Add optional plugins (de-duplicated) |
| `essential()` | `...$modules: string[]` | `self` | Load on every request (domains or provider classes) |
| `port()` | `abstract: string`, `implementation: object` | `self` | Replace one fake with a custom double or real adapter |
| `ports()` | `ports: array<string, object>` | `self` | Replace multiple ports at once |
| `security()` | `...$layers: SecurityLayerContract[]` | `self` | Install security layers (replaces the allow-all stand-in) |
| `routes()` | `routes: array` | `self` | Declare project-layer routes (as `proj.json` declares them) |
| `routeGroups()` | `groups: array` | `self` | Declare project-layer route groups |
| `domains()` | `...$hosts: string[]` | `self` | Hosts the project serves (required before a group may name a domain) |
| `as()` | `identity: ?Identity` | `self` | Set the Identity requests carry (defaults to guest) |
| `env()` | `vars: array<string, string\|int\|float\|bool>` | `self` | Override env vars for this boot |
| `realFilters()` | none | `self` | Make unregistered route filters fail (instead of stubbing them) |
| `boot()` | none | `BootedGround` | Compile, wire, and materialize the kernel |

## BootedGround — The booted plugin

Everything the ground does after boot happens through a `BootedGround` instance. It manages containers, handles requests, records outcomes, and enforces scope isolation.

### HTTP methods

Send requests through the real HttpPipeline:

```php
$response = $ground->get('/path', $query, $headers);
$response = $ground->post('/path', $body, $headers);
$response = $ground->put('/path', $body, $headers);
$response = $ground->patch('/path', $body, $headers);
$response = $ground->delete('/path', $body, $headers);

// JSON with Content-Type and Accept both set to application/json
$response = $ground->json('POST', '/api/tasks', ['title' => 'New task']);

// General form with all options
$response = $ground->call(
    method: 'GET',
    path: '/tasks',
    query: ['page' => 2],
    body: [],
    headers: [],
    rawBody: '',
    cookies: [],
    host: 'api.shop.test',  // sets the validated route_host attribute
    face: 'admin'            // sets route_face for routes declaring faces[]
);
```

All methods return a `GroundResponse` (see below).

### Pageflow (visual layer)

Request a page as the Pageflow client does and inspect the page object:

```php
// Pageflow JSON response (page object only)
$page = $ground->pageflow('/admin/users');
$page->component;  // e.g. 'Users/Index'
$page->props;      // server-sent props
$page->url;        // the request path

// Same, for a mutating request
$page = $ground->pageflowSend('POST', '/admin/users', ['name' => 'Alice']);

// Full HTML page load (the shell, assets, CSRF meta tag)
$page = $ground->pageflowLoad('/admin/users');

// Component file resolution (does the page exist on this surface?)
$resolver = $ground->pages('admin');  // PageResolver for the 'admin' surface
$resolver->exists('Users/Index');     // bool

// Every surface this plugin ships pages for
$surfaces = $ground->surfaces();  // ['admin', 'project']

// The plugin's ui/ui.json
$ui = $ground->ui();
```

### Resolution

Resolve published contracts and containers:

```php
// Resolve a contract in the plugin's own scope (same as a request would)
$service = $ground->service(TaskServiceContract::class);

// Resolve as if another module were asking (cross-scope isolation test)
$service = $ground->makeFromScope(TaskServiceContract::class, 'other.domain');

// The request-scoped container (built from the plugin's dependency graph)
$container = $ground->container();

// The app-lifetime container (ports and kernel services)
$core = $ground->core();

// The built Kernel
$kernel = $ground->kernel();

// Discard the request container (simulates end-of-request)
$ground->newRequest();

// Change the acting Identity
$ground->as(Identity::asAdmin('tenant-1'));
```

### Ports

Access the fake ports the ground bound (same instances the plugin resolved):

```php
$ground->db()           // FakeDatabase
$ground->cache()        // FakeCache
$ground->queue()        // FakeQueue
$ground->mail()         // FakeMail
$ground->sms()          // FakeSms
$ground->storage()      // FakeStorage
$ground->logger()       // FakeLogger
$ground->clock()        // FrozenClock
$ground->hasher()       // FakeHasher
$ground->encrypter()    // FakeEncrypter

// Reach a custom port by its abstract
$ground->portFor(SomePort::class)
```

### Events

```php
// Everything the plugin dispatched (only names in module.json `emits[]`)
$recorder = $ground->events();

// Dispatch an event AT the plugin (to drive a listener)
$ground->dispatch(new MyIntegrationEvent(...));
```

See `EventRecorder` below for assertion methods.

### CLI

```php
// Run a command through the real CliPipeline
$result = $ground->cli('user:prune', ['--days', '30']);
$result->exitCode;      // int
$result->output();      // string
$result->succeeded();   // bool
$result->failed();      // bool
$result->sees('text');  // bool (output contains)
$result->lines();       // array of trimmed non-empty lines
$result->json();        // json_decode the output

// Every registered command name
$names = $ground->commandNames();

// Is this command registered?
$ground->hasCommand('user:prune');

// All command class instances (rarely needed)
$ground->commands();
```

### Jobs and workers

```php
// Run a job class's handle() directly (no dequeue, no retry, no signature)
$result = $ground->runJob(SendMailJob::class, ['to' => 'alice@example.com']);

// Drain the queue through the real WorkerLoop
$ground->work('default', maxIterations: 5);  // 100ms idle backoff per empty pop

// Push a job the usual way, then drain it
$ground->pushAndWork('SendMailJob', ['to' => 'alice@example.com']);

// The compiled job manifest — name => {handler, queue, module, solves}
$manifest = $ground->jobs();

// Did the plugin's module.json declare this job?
$ground->hasJob('task.remind');
```

### What the boot produced

```php
// The compiled route manifest — "METHOD /path" => entry
$routes = $ground->routes();

// Did the plugin's routes compile to this key?
$ground->hasRoute('GET', '/tasks', domain: 'api.shop.test');

// The compiled service manifest (dependency graph)
$services = $ground->services();

// Required env vars filled with a placeholder (not configured)
$placeholders = $ground->placeholders();  // ['INVOICE_API_KEY']

// The plugin's manifest
$manifest = $ground->manifest();

// The throwaway workspace (where manifests are compiled)
$workspace = $ground->workspace();
```

### Route filters

```php
// Aliases the ground stubbed because nothing registered them
// A stubbed filter did NOT run — load the providing plugin when it's the subject
$stubbed = $ground->stubbedFilters();  // ['auth', 'throttle']

// The pass-through stand-in itself (rarely used directly)
$stub = $ground->filterStub();
```

### Teardown

```php
// Always call this: removes workspace, restores $_ENV, cleans up state
$ground->destroy();

// Did destroy() already run?
$ground->isDestroyed();
```

## GroundResponse

What HTTP methods return:

```php
$response->status();        // int — 200, 404, 500, etc.
$response->body();          // string — raw response body
$response->json();          // array — json_decoded body (empty array if not JSON)
$response->header('name');  // ?string — case-insensitive lookup
$response->headers();       // array — all response headers
```

When a test fails, the response prints:
- the status and body
- the error envelope (for 422 and 5xx)
- every log record at error level during that request

## Fakes — What the ground binds

Every port is pre-bound to a double. Declarations:

### FakeDatabase

```php
$db = $ground->db();

// Queue results (FIFO, consumed by unmatched queries)
$db->willReturn(['id' => 1], ['id' => 2]);

// Return rows for any query containing this text (case-insensitive)
$db->onQuery('from users', ['id' => 1, 'name' => 'Alice']);
$db->onQuery('select', ['id' => 1]);  // substring match

// Make a statement throw (for testing the rollback path)
$db->failOn('insert', new \PDOException('Constraint violation'));

// Set the INSERT ID the next insert returns
$db->willInsertId('generated-id-123');

// Assert what happened
$db->queries;        // list of executed statements ([sql, params])
$db->commits;        // int — how many commits
$db->rollbacks;      // int — how many rollbacks
$db->affectedRows;   // int — rows affected by the last execute()

// Commit outside a transaction throws
$db->commit();  // \LogicException if !inTransaction()
```

### FakeCache

```php
$cache = $ground->cache();

// Set a value (TTL evaluated against FrozenClock)
$cache->set('key', 'value', ttl: 3600);

// Get a value
$cache->get('key');

// Check existence
$cache->has('key');

// Delete
$cache->delete('key');

// Increment (keeps the existing TTL window)
$cache->increment('counter');
$cache->increment('counter', 5);

// Delete keys matching a glob pattern
$cache->deletePattern('prefix:*');

// Clear all
$cache->flush();

// Lock with ownership (the returned Lock holds `token` and `owner`)
$lock = $cache->lock('resource', owner: 'request-id');
```

### FakeQueue

```php
$queue = $ground->queue();

// Push a job (signature computed and stored)
$queue->push(SendMailJob::class, ['to' => 'alice@example.com'], queue: 'mail');

// Pop the next job payload (if any)
$payload = $queue->pop('mail');

// Acknowledge (remove from queue)
$queue->ack($payload);

// Release back to queue with attempts incremented
$queue->release($payload);

// Dead-letter (move to failed state, ack'd)
$queue->fail($payload);

// Queue contents by queue name
$queue->size('mail');

// Implementation details
$queue->payloads;  // list of all pushed payloads
```

### FakeMail

```php
$mail = $ground->mail();

// Sending is recorded by view name, not rendered output
$mail->send('alice@example.com', 'Welcome', 'emails/welcome');

// Queue for later send
$mail->queue('alice@example.com', 'Welcome', 'emails/welcome');

// Assert what was sent
$mail->records;  // list of [to, subject, view, queue, data]
```

### FakeStorage

```php
$storage = $ground->storage();

// Store content
$storage->store('file content', 'filename.pdf', path: 'uploads', visibility: 'private');

// Store from a stream
$storage->storeStream(fopen(...), 'filename.pdf', path: 'uploads');

// Get file content (throws if missing)
$storage->get('uploads/filename.pdf');

// Check existence
$storage->exists('uploads/filename.pdf');

// Delete
$storage->delete('uploads/filename.pdf');

// Temporary URL (valid for 1 hour by default)
$url = $storage->temporaryUrl('uploads/filename.pdf', expiresInSeconds: 3600);

// Stored files
$storage->files;  // array<path, content>
```

### FakeSms

Records sent SMS:

```php
$sms = $ground->sms();
$sms->records;  // [phone, message, ...]
```

### FakeLogger

Logs everything at every level:

```php
$logger = $ground->logger();

// Log at a level (TRACE, DEBUG, INFO, WARNING, ERROR, CRITICAL)
$logger->info('User created', ['id' => '123']);
$logger->error('Payment failed');

// Inspect records
$logger->records;        // all records across all levels
$logger->problems();     // records at WARNING and higher
$logger->problemsSince($beforeCount);  // only after index N
```

### FrozenClock

Time is frozen (not real time). Move it with `travel()`:

```php
$clock = $ground->clock();

// Travel forward
$clock->travel('+5 minutes');
$clock->travel('+1 hour');

// Jump by seconds, or set an absolute time
$clock->travelSeconds(90);
$clock->setTo('2026-03-01 09:00:00');

$clock->now();        // \DateTimeImmutable
$clock->timestamp();  // int
```

A new `FrozenClock` starts at `2026-01-01 00:00:00` unless you pass another time to its constructor. `travel()`, `travelSeconds()` and `setTo()` return the clock, so they chain.

### FakeHasher

Fast by design (no bcrypt slowdown in tests):

```php
$hasher = $ground->hasher();

$hash = $hasher->make('password');
$hasher->check('password', $hash);  // bool
```

### FakeEncrypter

Reversible base64 encoding. Rejects payloads it did not encrypt:

```php
$enc = $ground->encrypter();

$encrypted = $enc->encrypt('data');
$decrypted = $enc->decrypt($encrypted);

// Throws if given a payload this instance did not encrypt
$enc->decrypt('random-garbage');  // \RuntimeException
```

## CliResult

What `$ground->cli()` returns:

```php
$result = $ground->cli('command', ['arg', '--flag']);

$result->exitCode;      // int (0 for success)
$result->succeeded();   // bool
$result->failed();      // bool

$result->output();      // string
$result->sees('text');  // bool — output contains this text
$result->lines();       // array of trimmed non-empty lines

$result->json();        // array — json_decode the output

$result->describe();    // string — full output for assertion messages
```

::: warning Reactive components

Table, Alert, ProgressBar, and Spinner write directly to STDOUT, bypassing the `BufferIO` that captures regular output. Their output does NOT appear in `result->output()`.

Assert on the exit code and the lines a command writes with `info()`, `error()`, `success()` — not on component output. For tabular output, assert on the data the command was given.

:::

## EventRecorder

What `$ground->events()` returns. Records every integration event the plugin declared in `module.json` `emits[]`:

```php
$recorder = $ground->events();

$recorder->dispatched('invoice.created');          // bool — by event NAME or class-string
$recorder->countOf('invoice.created');             // int
$recorder->first(InvoiceCreated::class);           // ?IntegrationEventContract
$recorder->payloadOf('invoice.created');           // array — payload of the first match, [] if none
$recorder->names();                                // list<string>, in dispatch order
$recorder->all();                                  // list<IntegrationEventContract>
$recorder->reset();
```

::: warning The recorder only sees events your `module.json` declares
`EventBus` has no wildcard subscription, so the ground subscribes the recorder to each name in the plugin's `emits[]`. An event missing from `emits[]` is never recorded, and a test asserting on it fails with "not dispatched" even though the code dispatched it. `plugin:check` reports the same drift.
:::

## Workspace

The throwaway directory the ground compiles into:

```php
$workspace = $ground->workspace();

$workspace->root;          // the temp directory path
$workspace->path('var/cache/manifests/route-manifest.php');  // append a path

// Read a compiled manifest
$workspace->manifest('route-manifest.php');  // array
```

When `destroy()` runs, the workspace is deleted. Set `GROUND_KEEP_WORKSPACE=true` to keep it for inspection after a failure (the manifests show what the kernel thought the plugin declared).

## Environment variables

### GROUND_WORKSPACE_DIR

Where to create temporary workspaces. Defaults to `sys_get_temp_dir()`. Use when the system temp directory is on a slow filesystem or fills up quickly:

```bash
export GROUND_WORKSPACE_DIR=/mnt/fast-scratch
```

### GROUND_KEEP_WORKSPACE

Keep the workspace after the ground is destroyed. Use to inspect manifests after a boot failure:

```bash
export GROUND_KEEP_WORKSPACE=true
ground serve .
# Boot fails; workspace path printed
ls $path/var/cache/manifests/
```

The path is printed when the workspace is kept. A single-use ground cleans it up on the next run.

## PluginGroundTestCase

Extend this to avoid booting/destroying by hand:

```php
use AlfacodeTeam\Ground\PluginGroundTestCase;

final class TaskRoutesTest extends PluginGroundTestCase
{
    protected function plugin(): string
    {
        return \Plugins\Task\Provider::class;
    }

    /** Providers for every domain in requires[] */
    protected function dependencies(): array
    {
        return [\Plugins\Database\Provider::class];
    }

    /** Optional: configure identity, env, ports, security, routes */
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
    }
}
```

Assertions available from the test case:

```php
$this->assertOk($response);               // 200
$this->assertStatus($response, 201);      // assert specific code
$this->assertRedirect($response);         // 301/302/303/307/308

$this->assertJsonPath($response, 'items.0.title', 'expected');
$this->assertValidationFailed($response, 'email');  // 422 with field

$this->assertRouteExists('GET', '/tasks');

$this->assertDispatched('event.name');
$this->assertDispatched('event.name', times: 2);
$this->assertNotDispatched('event.name');

$this->assertCommitted();
$this->assertRolledBack();

$this->assertQueued(JobClass::class);
$this->assertMailSent('email-view');

$this->assertJobDeclared('job.name');
$this->assertJobAcked();
$this->assertJobReleased();
$this->assertJobExhausted('queue-name');

$this->assertCommandRegistered('cmd:name');
$this->assertCommandSucceeds($result);
$this->assertCommandOutputs($result, 'text');

$this->assertComponent('Page/Name', $page);
$this->assertPageProp($page, 'propName', $value);
$this->assertNotProp($page, 'sensitive_field');

$this->assertPageResolves($page, 'Page/Name', surface: 'admin');
$this->assertRendersPage($page, 'Page/Name');
$this->assertPageResolvesEverywhere($page, 'Page/Name');
```

## Commands — CLI for plugin developers

When standing in a plugin directory, the `ground` CLI offers quick commands. These are for development workflows — use `phpunit` and `vitest` in CI.

### plugin:check

Static conformance checks without booting:

```bash
ground check                 # this plugin only
ground check task            # a specific plugin
ground check --all           # every installed plugin
ground check --strict        # treat warnings as failures
ground check --json          # machine-readable output
```

Checks for:
- manifest drift (`solves`, `requires`, `exposes` mismatch between `module.json` and `Provider`)
- undeclared env vars (`env()` calls not in `config[]`)
- unbound exposed contracts
- class/method visibility issues in handlers
- access rule violations (imports reaching outside scope)
- UI drift (missing page files, wrong exports)

Does NOT re-check what the boot refuses — malformed routes, unknown parameter types, unregistered filter aliases. Duplicating those would mean maintaining two definitions that drift apart.

### plugin:dev

Make `yarn dev` work inside a plugin for live component editing:

```bash
ground dev                      # generate workspace, start Vite
ground dev --setup              # generate only (no server start)
ground dev --surface admin      # which surface to serve
```

Two servers run together:
- **PHP** (`ground serve`): renders routes, page objects, CSRF meta tag
- **Vite** (`yarn dev`): module bundling with HMR

Edit a `.tsx` and it hot-reloads in a PHP-rendered page.

### plugin:drop

Remove scratch databases ground left behind (from `plugin:migrate` or crashed runs):

```bash
ground drop                     # drop all scratch databases
ground drop --list              # show what would be dropped (no action)
ground drop --only mysql,pgsql  # specific drivers only
```

Uses the same database config as `plugin:migrate`. Touches only databases matching ground's own prefix (`ground_*`).

### plugin:migrate

Run a plugin's migrations against every real database it claims to support:

```bash
ground migrate                      # central + tenant databases
ground migrate --init               # write config + docker-compose
ground migrate --only mysql,pgsql   # specific drivers only
ground migrate --strict             # fail if any database was skipped (for CI)
ground migrate --tables             # list resulting tables
ground migrate --alone              # this plugin's migrations only
ground migrate --with other,plugins # run these first (schema dependencies)
```

Each migration is:
1. **Applied** to a real database (not faked, not pretended)
2. **Rolled back** fully (every down() must undo its up())
3. **Verified** clean (no tables left behind)

If only SQLite is configured, MySQL/PostgreSQL/SQL Server are reported as SKIPPED (not FAILED). Exit code is non-zero only when a reachable database failed. Use `--strict` in CI to gate on every supported database being tested.

Central and tenant databases are separate (tenancy runs tenant-template migrations into its own scratch database). Runs dependency chain transitively via `requires[]` and declared `migrationRequires`.

### plugin:probe

Boot the plugin in an isolated ground and report what compiled:

```bash
ground probe                    # this plugin
ground probe task               # a specific plugin
ground probe --routes           # list every compiled route
ground probe --keep             # keep workspace; print path
```

Reports:
- required config vars with no default (still placeholder-filled)
- routes that compiled (only this plugin's, not dependencies)
- whether every exposed contract actually **resolves** (the check that matters)

A boot failure here is the exact failure a project would get enabling the plugin.

### plugin:serve

Serve the plugin over HTTP against fake ports:

```bash
ground serve                    # this plugin on http://127.0.0.1:8321
ground serve --port 9000        # different port
ground serve --with security-filters,session  # extra plugins to load
```

The real `HttpPipeline` runs per request behind `php -S`. Every port is fake (database empty, mail goes nowhere). A route added to `module.json` is live on reload — no restart needed. Fails fast with a real stack trace in the browser.

### make:ground-test

Scaffold a PluginGroundTestCase from the plugin's manifest:

```bash
ground make:ground-test              # creates tests/PluginGroundTest.php
ground make:ground-test --force      # overwrite existing file
ground make:ground-test --print      # write to stdout instead
```

Generates one test per route, per exposed contract, per emitted event — already naming what the manifest declares. Fill in the bodies.

### make:ui-test

Scaffold vitest component tests for the plugin's pages:

```bash
ground make:ui-test              # creates ui/__tests__/*.test.tsx
ground make:ui-test --config     # also vitest.config.ts + package.json
ground make:ui-test --force      # overwrite existing files
```

Writes:
- One test per page file
- A vitest config with aliases to sibling checkouts (so `@pageflow/*`, `@ui/*` resolve)
- A `package.json` with every dependency the pages actually reach for
- A setup file (localStorage, ResizeObserver, matchMedia stubs)

Component tests read their props from `__fixtures__/*.json`, which a ground test dumps:

```php
$this->ground()->pageflow('/route')->writeFixture(__DIR__ . '/../ui/__fixtures__/route.json');
```

That is the whole point: hand-written mocks drift when props are renamed; dumped fixtures fail the component test.

## Plugins on disk

Ground discovers plugins four ways (nearest-first):

1. The directory itself (you are inside a plugin repo)
2. `plugins/`, `modules/` (a project or kernel checkout)
3. The parent's children (plugin-development workspace, siblings)
4. `vendor/*` (plugins installed the ordinary way)

Pass `--path` to search from a different directory, or `--here` to skip sibling repos (for CI).

## Common patterns

### Test a service with mocked ports

```php
public function testInvoiceService(): void
{
    $ground = PluginGround::for(InvoiceProvider::class)->boot();
    $ground->db()->onQuery('from invoices', ['id' => 'inv-1', 'total' => 99.99]);

    $service = $ground->service(InvoiceServiceContract::class);
    $invoice = $service->find('inv-1');

    $this->assertSame('inv-1', $invoice->id());
    $ground->destroy();
}
```

### Test a route with event inspection

```php
public function testCreatingAnInvoiceEmitsEvent(): void
{
    $ground = PluginGround::for(InvoiceProvider::class)->boot();
    $ground->db()->onQuery('insert', ['id' => 'inv-1']);

    $response = $ground->post('/invoices', ['amount' => 99.99]);

    $this->assertSame(201, $response->status());
    $this->assertTrue($ground->events()->dispatched('invoice.created'));
    $ground->destroy();
}
```

### Test a CLI command

```php
public function testMigrateCommand(): void
{
    $ground = PluginGround::for(MigrationProvider::class)->boot();
    $ground->db()->onQuery('insert', ['id' => '1']);

    $result = $ground->cli('migrate:run');

    $this->assertTrue($result->succeeded());
    $this->assertCount(1, $ground->db()->queries);
    $ground->destroy();
}
```

### Test scope isolation

A plugin's internal bindings should throw `ScopeViolationException` when resolved from outside:

```php
public function testScopeIsolation(): void
{
    $ground = PluginGround::for(UserProvider::class)->boot();

    $this->expectException(ScopeViolationException::class);
    $ground->makeFromScope(UserRepository::class, 'other.domain');
}
```

### Assert page resolution

```php
public function testPageExists(): void
{
    $ground = PluginGround::for(PageProvider::class)->boot();

    $page = $ground->pageflow('/admin/users');
    $this->assertRendersPage($page, 'Users/Index', surface: 'admin');

    $ground->destroy();
}
```

### Dump props for component tests

```php
public function testUserPageProps(): void
{
    $ground = PluginGround::for(UserProvider::class)->boot();
    $ground->db()->onQuery('from users', ['id' => '1', 'name' => 'Alice']);

    $page = $ground->pageflow('/users/1');

    // Write real props from the server to a fixture
    $page->writeFixture(__DIR__ . '/../ui/__fixtures__/user-show.json');

    $ground->destroy();
}
```

Then in the component test (`ui/__tests__/user-show.test.tsx`):

```tsx
import fixture from "../__fixtures__/user-show.json";

render(<UserShowPage {...(fixture.props as any)} />);
expect(screen.getByText("Alice")).toBeInTheDocument();
```

## Source

- [modules/ground/](https://github.com/AlfaCode-Team/php-service-platform/tree/main/modules/ground)
- [modules/ground/README.md](https://github.com/AlfaCode-Team/php-service-platform/blob/main/modules/ground/README.md)
