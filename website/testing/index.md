---
outline: [2, 3]
---

# Testing

HKM Kernel's architecture is designed for testability. The domain is pure PHP with zero external dependencies. Port interfaces allow fake implementations. Scoped containers let you test modules in isolation. This page covers test patterns, port fakes, and integration tests with the real kernel.


::: warning Which test helpers you can actually import
`Tests\Feature\Support\KernelTestCase`, `TestResponse` and the `Array*` fakes shown on this page belong to the **kernel repository's own test suite**. They are `autoload-dev` classes and are **not installed** with the kernel, so a project or plugin cannot `use` them. Either:

- for a **plugin**, use [ground](/packages/ground): `PluginGround` boots your plugin in isolation with fakes for every port (`AlfacodeTeam\Ground\Fakes\FakeDatabase`, `FakeCache`, `FakeQueue`, `FakeMail`, `FrozenClock`, …) and gives you a `PluginGroundTestCase` with assertions; or
- copy the fakes you need from [`tests/Feature/Support/`](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/tests/Feature/Support) into your own test suite. They are small, and every one implements a public port interface.

The examples below use the kernel's fakes because they are the shortest way to show each pattern.
:::

## Test Philosophy

Three principles guide testing in HKM:

1. **Domain tests use no fakes** — pure PHP domain entities, value objects, and events need only the standard library. A domain test runs in < 1ms.

2. **Service tests use port fakes** — never real databases or external APIs. A fake repository and fake transaction manager make testing the transaction + event pattern predictable and fast.

3. **Integration tests use the real kernel** — boot a test kernel, wire real modules and fake ports, and push a real HTTP request through the real pipeline. These catch the class of defect no unit test can: "does this controller still match the route signature? Does this binding actually resolve from the scope that needs it?"

## Running Tests

The repository includes both Unit and Feature test suites:

```bash
# Run all tests
vendor/bin/phpunit

# Run only unit tests (pure domain logic, no fakes)
vendor/bin/phpunit --testsuite=Unit

# Run only feature tests (real kernel + fakes)
vendor/bin/phpunit --testsuite=Feature

# Run a single file
vendor/bin/phpunit tests/Unit/Domain/InvoiceTest.php

# Run with verbose output
vendor/bin/phpunit -v

# Stop on first failure
vendor/bin/phpunit --stop-on-failure
```

The test suites are configured in `phpunit.xml.dist`:

```xml
<testsuites>
    <testsuite name="Unit">
        <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="Feature">
        <directory>tests/Feature</directory>
    </testsuite>
</testsuites>
```

## Domain Layer Tests

Domain entities and value objects are pure PHP — no fakes needed. Test them directly:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Invoices\Domain;

use PHPUnit\Framework\TestCase;
use App\Invoices\Domain\Entities\Invoice;
use App\Invoices\Domain\ValueObjects\{InvoiceId, InvoiceStatus, Money};
use App\Invoices\Domain\Events\InvoiceCreatedDomainEvent;

class InvoiceTest extends TestCase
{
    public function test_new_invoice_is_draft(): void
    {
        $invoice = Invoice::create(
            InvoiceId::generate(),
            new \DateTimeImmutable('+30 days'),
        );

        $this->assertEquals(InvoiceStatus::DRAFT, $invoice->status());
    }

    public function test_cannot_issue_empty_invoice(): void
    {
        $invoice = Invoice::create(
            InvoiceId::generate(),
            new \DateTimeImmutable('+30 days'),
        );

        $this->expectException(\DomainException::class);
        $invoice->issue();
    }

    public function test_issue_emits_domain_event(): void
    {
        $invoice = Invoice::create(
            InvoiceId::generate(),
            new \DateTimeImmutable('+30 days'),
        );
        $invoice->addLineItem('Widget', 1, Money::of(100, 'USD'));
        $invoice->releaseEvents(); // clear creation event

        $invoice->issue();

        $events = $invoice->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(InvoiceIssuedDomainEvent::class, $events[0]);
    }
}
```

Domain tests are fast because they are pure computation. No I/O, no fakes, no framework. A typical domain test suite runs in < 100ms.

## Service Layer Tests

Service tests verify the transaction + event pattern, authorization, and orchestration. Use port fakes:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Invoices\Application;

use PHPUnit\Framework\TestCase;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use Tests\Feature\Support\Fakes\{ArrayDatabase, ArrayQueue};
use App\Invoices\Application\Services\InvoiceService;
use App\Invoices\Application\DTOs\CreateInvoiceDTO;

class InvoiceServiceTest extends TestCase
{
    private InvoiceService $sut;
    private ArrayDatabase $db;
    private FakeEventBus $eventBus;
    private DomainEventCollector $collector;

    protected function setUp(): void
    {
        $this->db = new ArrayDatabase();
        $this->eventBus = new FakeEventBus();
        $this->collector = new DomainEventCollector();

        $txn = new TransactionManager($this->db);

        $this->sut = new InvoiceService(
            repository:  new InvoiceRepository($this->db, Identity::asUser('user-1', 'tenant-1')),
            transaction: $txn,
            collector:   $this->collector,
            eventBus:    $this->eventBus,
            identity:    Identity::asUser('user-1', 'tenant-1'),
        );
    }

    public function test_creates_invoice_and_commits(): void
    {
        $dto = new CreateInvoiceDTO(
            clientId: 'client-123',
            dueDate:  (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
        );

        $result = $this->sut->create($dto);

        $this->assertNotEmpty($result->invoiceId);
        $this->assertContains('INSERT INTO invoices', implode(' ', $this->db->sql()));
        $this->assertContains('commit', $this->db->transactions);
    }

    public function test_dispatches_event_after_commit(): void
    {
        $dto = new CreateInvoiceDTO(
            clientId: 'client-123',
            dueDate:  (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
        );

        $this->sut->create($dto);

        $this->eventBus->assertDispatched(InvoiceCreatedIntegrationEvent::class);
    }

    public function test_rollback_on_save_failure(): void
    {
        $this->db->failNextWrite = new \RuntimeException('Database down');

        $this->expectException(ServiceException::class);
        $this->sut->create(new CreateInvoiceDTO(...));

        $this->assertContains('rollback', $this->db->transactions);
        $this->eventBus->assertNotDispatched(InvoiceCreatedIntegrationEvent::class);
    }
}
```

### Port Fakes

The kernel repository's own test suite includes these fakes (`tests/Feature/Support/Fakes/`):

**ArrayDatabase** — A DatabasePort that records every SQL statement and responds with scripted results:

```php
$db = new ArrayDatabase();
$db->answer('SELECT * FROM invoices', [
    ['id' => '1', 'number' => 'INV-001', 'total_cents' => 10000],
]);

$invoices = $db->query('SELECT * FROM invoices WHERE id = ?');
// Returns the answerd rows

$this->assertTrue($db->ran('INSERT')); // Check if a statement with this fragment ran
```

**ArrayCache** — A CachePort that stores values in memory:

```php
$cache = new ArrayCache();
$cache->set('key', 'value', ttl: 300);
$this->assertEquals('value', $cache->get('key'));
```

**ArrayQueue** — A QueuePort that records pushed jobs:

```php
$queue = new ArrayQueue();
$queue->push(SendEmailJob::class, ['to' => 'user@example.com']);
$this->assertCount(1, $queue->pending());
```

### Writing Custom Fakes

When you need to test a service that depends on a port without a built-in fake, write one:

```php
<?php
declare(strict_types=1);

namespace Tests\Support\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use Psr\Container\ContainerInterface;

/**
 * A fake EventBus that records dispatched events instead of actually subscribing them.
 * Use for testing that your service dispatches the right events at the right time.
 */
final class FakeEventBus extends EventBus
{
    /** @var array<int, IntegrationEventContract> events in dispatch order */
    private array $dispatched = [];

    public function __construct()
    {
        // Skip calling parent constructor — we don't need a real container
        parent::__construct(new class implements ContainerInterface {
            public function get($id) { throw new \RuntimeException('FakeEventBus does not resolve'); }
            public function has($id): bool { return false; }
        });
    }

    /**
     * Record the event instead of dispatching to subscribers.
     *
     * @return array<class-string, \Throwable> empty (no failures)
     */
    public function dispatch(IntegrationEventContract $event): array
    {
        $this->dispatched[] = $event;
        return [];
    }

    /**
     * Assert that an event of the given class was dispatched.
     *
     * @param class-string $eventClass
     * @param int $times how many times (default 1)
     */
    public function assertDispatched(string $eventClass, int $times = 1): void
    {
        $count = count(array_filter(
            $this->dispatched,
            fn($e) => $e instanceof $eventClass
        ));

        if ($count !== $times) {
            throw new \AssertionError(
                "$eventClass was dispatched $count time(s), expected $times"
            );
        }
    }

    /**
     * Assert that an event was NOT dispatched.
     *
     * @param class-string $eventClass
     */
    public function assertNotDispatched(string $eventClass): void
    {
        $dispatched = array_filter(
            $this->dispatched,
            fn($e) => $e instanceof $eventClass
        );

        if (!empty($dispatched)) {
            throw new \AssertionError("$eventClass was dispatched but should not have been");
        }
    }

    /** All dispatched events, in order. */
    public function events(): array
    {
        return $this->dispatched;
    }
}
```

Similarly, a fake TransactionManager for testing without a real database:

```php
<?php
declare(strict_types=1);

namespace Tests\Support\Fakes;

/**
 * A fake TransactionManager that records begin/commit/rollback calls.
 */
final class FakeTransactionManager
{
    private int $depth = 0;
    private bool $committed = false;
    private bool $rolledBack = false;

    public function begin(): void
    {
        if ($this->depth === 0) {
            $this->committed = false;
            $this->rolledBack = false;
        }
        $this->depth++;
    }

    public function commit(): void
    {
        $this->depth = max(0, $this->depth - 1);
        if ($this->depth === 0) {
            $this->committed = true;
        }
    }

    public function rollback(): void
    {
        $this->depth = 0;
        $this->rolledBack = true;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    public function wasCommitted(): bool
    {
        return $this->committed;
    }

    public function wasRolledBack(): bool
    {
        return $this->rolledBack;
    }
}
```

## Integration Tests

Integration tests boot the real kernel and push real requests through the real HTTP pipeline. Use the test kernel and real modules with faked ports:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Invoices;

use PHPUnit\Framework\TestCase;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Tests\Feature\Support\KernelTestCase;
use Tests\Feature\Support\Fakes\ArrayDatabase;

class CreateInvoiceControllerTest extends KernelTestCase
{
    public function test_post_creates_invoice(): void
    {
        $response = $this->post('/api/invoices', [
            'clientId' => 'client-123',
            'dueDate'  => date('Y-m-d', strtotime('+30 days')),
        ]);

        $this->assertEquals(201, $response->status());
        $data = $response->json();
        $this->assertNotEmpty($data['invoiceId']);
    }

    public function test_requires_client_id(): void
    {
        $response = $this->post('/api/invoices', [
            'dueDate' => date('Y-m-d', strtotime('+30 days')),
        ]);

        $this->assertEquals(422, $response->status());
        $errors = $response->json('error.fields');
        $this->assertArrayHasKey('clientId', $errors);
    }

    public function test_requires_authentication(): void
    {
        $this->kernel = $this->makeKernel(identity: null); // No identity

        $response = $this->post('/api/invoices', [
            'clientId' => 'client-123',
            'dueDate'  => date('Y-m-d', strtotime('+30 days')),
        ]);

        $this->assertEquals(401, $response->status());
    }
}
```

The `KernelTestCase` base class provides helpers:

```php
// Build a kernel in a temporary root, with the modules, routes and fake ports you pass
protected function boot(
    array $modules = [], array $routes = [], array $ports = [], array $security = [],
    array $groups = [], array $essentials = [], array $domains = [], array $disable = [],
): Kernel;

protected function get(string $uri, array $headers = []): TestResponse;
protected function post(string $uri, array $data = [], array $headers = []): TestResponse;
protected function postJson(string $uri, array $data = [], array $headers = []): TestResponse;
protected function put(string $uri, array $data = [], array $headers = []): TestResponse;
protected function delete(string $uri, array $headers = []): TestResponse;
protected function call(string $method, string $uri, array $data = [], array $headers = [],
                        ?string $content = null, ?Identity $as = null): TestResponse;
protected function actingAs(Identity $identity, string $method, string $uri, array $data = []): TestResponse;
protected function manifest(string $file): array;      // read a compiled manifest
protected function setEnv(string $key, string $value): void;   // restored in tearDown()
```

## Testing Boot & Manifest Compilation

Test that your `module.json` is valid and the boot pipeline produces correct manifests:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Kernel;

use PHPUnit\Framework\TestCase;
use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\BootFailureException;

class BootTest extends TestCase
{
    public function test_boot_validates_config(): void
    {
        $this->expectException(BootFailureException::class);
        $this->expectExceptionMessageMatches('/INVOICE_CURRENCY/');

        // Unset a required env var and try to boot
        $old = $_ENV['INVOICE_CURRENCY'] ?? null;
        unset($_ENV['INVOICE_CURRENCY']);

        try {
            Kernel::configure()->withModules([...])->build();
        } finally {
            if ($old !== null) {
                $_ENV['INVOICE_CURRENCY'] = $old;
            }
        }
    }

    public function test_boot_detects_circular_dependencies(): void
    {
        $this->expectException(CircularDependencyException::class);

        // Create modules that depend on each other
        Kernel::configure()->withModules([
            ModuleA::class, // requires ModuleB
            ModuleB::class, // requires ModuleA
        ])->build();
    }

    public function test_manifests_are_compiled(): void
    {
        $kernel = Kernel::configure()->withModules([...])->build();

        $this->assertFileExists('var/cache/manifests/service-manifest.php');
        $this->assertFileExists('var/cache/manifests/route-manifest.php');
        $this->assertFileExists('var/cache/manifests/route-index.php');
    }
}
```

## Testing Jobs

Test background jobs the same way as services — with faked ports:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Invoices\Jobs;

use PHPUnit\Framework\TestCase;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\{JobPayload, JobResult};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Tests\Feature\Support\Fakes\ArrayDatabase;
use App\Invoices\Infrastructure\Jobs\GenerateInvoicePdfJob;

class GenerateInvoicePdfJobTest extends TestCase
{
    public function test_generates_pdf(): void
    {
        $db = new ArrayDatabase();
        $db->answer('SELECT * FROM invoices WHERE id', [
            ['id' => 'inv-1', 'number' => 'INV-001', 'total_cents' => 10000],
        ]);

        $job = new GenerateInvoicePdfJob(new InvoiceRepository($db, Identity::asUser('worker')));
        $payload = new JobPayload(
            jobId:       'job-1',
            jobClass:    GenerateInvoicePdfJob::class,
            data:        ['invoiceId' => 'inv-1'],
            queue:       'default',
            attempts:    0,
            maxAttempts: 3,
        );

        $result = $job->handle($payload);

        $this->assertEquals(JobResult::success(['path' => 'pdfs/inv-1.pdf']), $result);
    }

    public function test_skips_missing_invoice(): void
    {
        $db = new ArrayDatabase(); // no answers — returns empty
        $job = new GenerateInvoicePdfJob(new InvoiceRepository($db, Identity::asUser('worker')));

        $payload = new JobPayload(
            jobId:       'job-1',
            jobClass:    GenerateInvoicePdfJob::class,
            data:        ['invoiceId' => 'missing'],
            queue:       'default',
            attempts:    0,
            maxAttempts: 3,
        );

        $result = $job->handle($payload);

        $this->assertTrue($result->isSkipped());
    }

    public function test_calls_failed_when_out_of_retries(): void
    {
        $db = new ArrayDatabase();
        $db->failNextWrite = new \RuntimeException('Database down');

        $job = new GenerateInvoicePdfJob(new InvoiceRepository($db, Identity::asUser('worker')));
        $payload = new JobPayload(
            jobId:       'job-1',
            jobClass:    GenerateInvoicePdfJob::class,
            data:        ['invoiceId' => 'inv-1'],
            queue:       'default',
            attempts:    3,
            maxAttempts: 3,
        );

        $this->expectException(\RuntimeException::class);
        $job->handle($payload);

        // After the job throws, the worker will call failed()
        $job->failed($payload, new \RuntimeException('...'));
        // Your failed() can log, clean up, etc.
    }
}
```

## Common Mistakes

::: danger Do not create global test fixtures

```php
// ✗ WRONG — lives across tests, leaks state
private static InvoiceService $shared;

// ✓ Correct — setUp() runs before each test
protected function setUp(): void
{
    $this->sut = new InvoiceService(...);
}
```

:::

::: danger Do not assert via side-effects

```php
// ✗ WRONG — assumes implementation details
$this->assertEquals(1, $this->db->statements);

// ✓ Correct — assert what the service promised
$this->assertEquals(InvoiceStatus::ISSUED, $invoice->status());
```

:::

::: danger Do not skip rollback tests

```php
// ✗ WRONG — every service accepts a transaction manager, but you never test rollback?
// All services MUST test what happens when commit() fails.

// ✓ Correct — rollback path is as important as success
public function test_rollback_on_database_failure(): void
{
    $this->db->failNextWrite = new \PDOException('Deadlock');
    // Assert rollback was called, event was not dispatched, etc.
}
```

:::

## Source

- [tests/](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/tests/)
- [phpunit.xml.dist](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/phpunit.xml.dist)
- [src/Kernel/Events/EventBus.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Events/EventBus.php)
- [src/Kernel/Database/TransactionManager.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Database/TransactionManager.php)
