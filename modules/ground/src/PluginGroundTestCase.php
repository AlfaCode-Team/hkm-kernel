<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

use PHPUnit\Framework\TestCase;
use AlfacodeTeam\Ground\Ui\PageResolver;

/**
 * A PHPUnit base for plugin tests: boots one ground per test, tears it down, and
 * supplies assertions whose failure messages say what went wrong.
 *
 *     final class TaskRoutesTest extends PluginGroundTestCase
 *     {
 *         protected function plugin(): string { return Provider::class; }
 *
 *         public function testIndexListsTasks(): void
 *         {
 *             $this->ground()->db()->onQuery('select', ['id' => 1, 'title' => 'Write it']);
 *
 *             $this->assertOk($this->ground()->get('/tasks'));
 *         }
 *     }
 *
 * The ground boots lazily on first use, so a test that only inspects the
 * manifest pays nothing, and tearDown() destroys it whether or not the test
 * passed — a failing test that leaked $_ENV would otherwise take unrelated tests
 * down with it.
 */
abstract class PluginGroundTestCase extends TestCase
{
    private ?BootedGround $ground = null;

    /**
     * The Provider class of the plugin under test.
     *
     * @return class-string
     */
    abstract protected function plugin(): string;

    /**
     * Providers for the domains this plugin requires[]. Every one must be
     * registered or the boot fails — which is the kernel telling you the
     * dependency is real, not the harness being strict.
     *
     * @return list<class-string>
     */
    protected function dependencies(): array
    {
        return [];
    }

    /** Hook for per-test configuration: identity, env, ports, security layers. */
    protected function configure(PluginGround $ground): PluginGround
    {
        return $ground;
    }

    final protected function ground(): BootedGround
    {
        return $this->ground ??= $this->configure(
            PluginGround::for($this->plugin(), ...$this->dependencies()),
        )->boot();
    }

    /** Boot a second, independently configured ground — remember to destroy it. */
    final protected function bootGround(callable $configure): BootedGround
    {
        return $configure(PluginGround::for($this->plugin(), ...$this->dependencies()))->boot();
    }

    protected function tearDown(): void
    {
        $this->ground?->destroy();
        $this->ground = null;

        parent::tearDown();
    }

    // ── HTTP assertions ───────────────────────────────────────────────────────

    protected function assertStatus(int $expected, GroundResponse $response, string $message = ''): void
    {
        self::assertSame(
            $expected,
            $response->status(),
            self::because($message, "Expected HTTP {$expected}.\n" . $response->describe()),
        );
    }

    protected function assertOk(GroundResponse $response, string $message = ''): void
    {
        self::assertTrue(
            $response->ok(),
            self::because($message, "Expected a 2xx response.\n" . $response->describe()),
        );
    }

    protected function assertRedirect(GroundResponse $response, ?string $to = null, string $message = ''): void
    {
        self::assertTrue(
            $response->redirected(),
            self::because($message, "Expected a redirect.\n" . $response->describe()),
        );

        if ($to !== null) {
            self::assertSame(
                $to,
                $response->redirectTarget(),
                self::because($message, "Expected a redirect to [{$to}].\n" . $response->describe()),
            );
        }
    }

    /** Assert a dotted path in the JSON body equals a value. */
    protected function assertJsonPath(GroundResponse $response, string $path, mixed $expected, string $message = ''): void
    {
        self::assertSame(
            $expected,
            $response->at($path),
            self::because($message, "Expected [{$path}] in the JSON body.\n" . $response->describe()),
        );
    }

    /** Assert the kernel's 422 envelope named this field. */
    protected function assertValidationFailed(GroundResponse $response, ?string $field = null, string $message = ''): void
    {
        $this->assertStatus(422, $response, $message);

        if ($field !== null) {
            $fields = $response->at('error.fields', []);
            self::assertIsArray($fields, self::because($message, 'Expected error.fields to be an object.'));
            self::assertArrayHasKey(
                $field,
                $fields,
                self::because($message, "Expected a validation error on [{$field}].\n" . $response->describe()),
            );
        }
    }

    // ── Compilation assertions ────────────────────────────────────────────────

    protected function assertRouteExists(string $method, string $path, ?string $domain = null, string $message = ''): void
    {
        $key = strtoupper($method) . ($domain === null ? '' : '@' . $domain) . ' ' . $path;

        self::assertTrue(
            $this->ground()->hasRoute($method, $path, $domain),
            self::because(
                $message,
                "Route [{$key}] is not in the compiled manifest.\nCompiled: "
                . implode(', ', array_keys($this->ground()->routes())),
            ),
        );
    }

    // ── Pageflow / UI assertions ──────────────────────────────────────────────

    /** Assert the route produced a Pageflow page object naming this component. */
    protected function assertComponent(string $expected, PageflowResponse $page, string $message = ''): void
    {
        self::assertTrue($page->isPage(), self::because($message, $page->describe()));
        self::assertSame($expected, $page->component(), self::because($message, $page->describe()));
    }

    /** Assert a dotted path in the page's PROPS — the server's actual contract with the client. */
    protected function assertPageProp(PageflowResponse $page, string $path, mixed $expected, string $message = ''): void
    {
        self::assertSame(
            $expected,
            $page->prop($path),
            self::because($message, "Expected prop [{$path}].\n" . $page->describe()),
        );
    }

    protected function assertHasProp(PageflowResponse $page, string $path, string $message = ''): void
    {
        self::assertTrue(
            $page->hasProp($path),
            self::because($message, "Expected a prop at [{$path}].\n" . $page->describe()),
        );
    }

    /**
     * Assert a prop is ABSENT — the assertion that keeps secrets out of a page.
     *
     * Props are serialized into the page object, which ships to the browser and
     * can land in the service-worker cache. A password hash or an internal id
     * added to a DTO leaks the moment that DTO is passed through as props, and
     * nothing else in the stack objects.
     */
    protected function assertNotProp(PageflowResponse $page, string $path, string $message = ''): void
    {
        self::assertFalse(
            $page->hasProp($path),
            self::because($message, "Prop [{$path}] must NOT be sent to the browser.\n" . $page->describe()),
        );
    }

    /**
     * Assert the named component EXISTS as a page file the surface can find.
     *
     * The gap this closes: a route naming a component with no page file returns
     * HTTP 200 with a valid page object, and fails only in the browser, as
     * `Page "X" not found under Pages/`. Nothing in PHP, and nothing in tsc,
     * sees it.
     */
    protected function assertPageResolves(string $component, string $surface = 'admin', string $message = ''): void
    {
        $resolver = $this->ground()->pages($surface);

        self::assertTrue(
            $resolver->resolves($component),
            self::because($message, self::pageContext($component, $surface, $resolver)),
        );
    }

    /**
     * Both halves at once: the route returns this component AND a page file
     * exists for it. The assertion to reach for by default.
     */
    protected function assertRendersPage(
        PageflowResponse $page,
        string $component,
        string $surface = 'admin',
        string $message = '',
    ): void {
        $this->assertComponent($component, $page, $message);
        $this->assertPageResolves($component, $surface, $message);
    }

    /**
     * The component resolves on EVERY surface this plugin ships pages for.
     *
     * A page under `site/Pages` is servable on both the project AND the admin
     * surface; one under `admin/Pages` only on admin. Asserting across the whole
     * set catches a page moved between faces, which changes where it can be
     * served without changing anything a PHP test would notice.
     */
    protected function assertPageResolvesEverywhere(string $component, string $message = ''): void
    {
        $surfaces = $this->ground()->surfaces();

        self::assertNotSame(
            [],
            $surfaces,
            self::because($message, 'This plugin ships no pages, so there is no surface to check.'),
        );

        foreach ($surfaces as $surface) {
            $this->assertPageResolves($component, $surface, $message);
        }
    }

    // ── CLI assertions ────────────────────────────────────────────────────────

    protected function assertCommandRegistered(string $name, string $message = ''): void
    {
        self::assertTrue(
            $this->ground()->hasCommand($name),
            self::because(
                $message,
                "Command [{$name}] is not registered.\nRegistered: "
                . implode(', ', $this->ground()->commandNames()),
            ),
        );
    }

    protected function assertCommandSucceeds(CliResult $result, string $message = ''): void
    {
        self::assertSame(0, $result->exitCode, self::because($message, $result->describe()));
    }

    protected function assertCommandFails(CliResult $result, string $message = ''): void
    {
        self::assertNotSame(
            0,
            $result->exitCode,
            self::because($message, "Expected a non-zero exit code.\n" . $result->describe()),
        );
    }

    protected function assertCommandOutputs(CliResult $result, string $text, string $message = ''): void
    {
        self::assertTrue(
            $result->sees($text),
            self::because($message, "Expected the output to contain [{$text}].\n" . $result->describe()),
        );
    }

    // ── Worker assertions ─────────────────────────────────────────────────────

    protected function assertJobDeclared(string $name, string $message = ''): void
    {
        self::assertTrue(
            $this->ground()->hasJob($name),
            self::because(
                $message,
                "Job [{$name}] is not in the compiled job manifest.\nDeclared: "
                . (($jobs = array_keys($this->ground()->jobs())) === [] ? 'none' : implode(', ', $jobs)),
            ),
        );
    }

    /** The worker completed the job and removed it from the queue. */
    protected function assertJobAcked(string $message = ''): void
    {
        self::assertNotSame(
            [],
            $this->ground()->queue()->acked,
            self::because($message, 'Expected the worker to ack a job; none was acked.'),
        );
    }

    /**
     * The worker put the job BACK for another attempt.
     *
     * The assertion that proves a failing job is retried rather than silently
     * dropped — reachable only through the real WorkerLoop (`work()`), because
     * release-vs-fail is the kernel's decision, not the handler's.
     */
    protected function assertJobReleased(string $message = ''): void
    {
        self::assertNotSame(
            [],
            $this->ground()->queue()->released,
            self::because(
                $message,
                'Expected the worker to release the job for retry; it did not. '
                . 'A job whose attempts are already exhausted is FAILED instead.',
            ),
        );
    }

    /**
     * The job ran out of attempts and stopped being retried.
     *
     * Dead-lettering does NOT show up as `QueuePort::fail()`. Read
     * WorkerLoop::process(): when attempts are exhausted it calls the job's own
     * `failed()` hook, returns a SKIPPED result rather than rethrowing, and
     * processWithPort then ACKS the payload. `$port->fail()` is reached only if
     * that dead-letter hook ITSELF throws.
     *
     * So the observable signature of exhaustion is: it was retried at least
     * once, and then acked and not re-queued.
     */
    protected function assertJobExhausted(string $queue = 'default', string $message = ''): void
    {
        $q = $this->ground()->queue();

        self::assertNotSame(
            [],
            $q->released,
            self::because($message, 'Expected the job to be retried at least once before giving up.'),
        );
        self::assertNotSame(
            [],
            $q->acked,
            self::because($message, 'Expected the exhausted job to be acked (dead-lettered), not left queued.'),
        );
        self::assertSame(
            0,
            $q->size($queue),
            self::because($message, "Expected queue [{$queue}] to be empty after exhaustion."),
        );
    }

    /**
     * `QueuePort::fail()` was called.
     *
     * Narrower than it looks, and rarely what a test wants — see
     * {@see assertJobExhausted()}. The kernel reaches fail() only when a job's
     * `failed()` dead-letter hook throws.
     */
    protected function assertJobFailedHard(string $message = ''): void
    {
        self::assertNotSame(
            [],
            $this->ground()->queue()->failed,
            self::because(
                $message,
                'Expected QueuePort::fail(). The kernel calls it only when the job\'s own '
                . 'failed() hook throws — for ordinary exhaustion use assertJobExhausted().',
            ),
        );
    }

    private static function pageContext(string $component, string $surface, PageResolver $resolver): string
    {
        $known   = $resolver->componentNames();
        $nearest = $resolver->nearest($component);

        return "No page file for component [{$component}] on the '{$surface}' surface.\n"
            . 'Searched: ' . ($resolver->roots() === [] ? '(no Pages directories found)' : implode(', ', $resolver->roots())) . "\n"
            . 'Found: ' . ($known === [] ? 'no pages at all' : implode(', ', $known)) . "\n"
            . ($nearest === [] ? '' : 'Did you mean: ' . implode(', ', $nearest) . "\n")
            . "At runtime this is: Page \"{$component}\" not found under Pages/";
    }

    // ── Event assertions ──────────────────────────────────────────────────────

    protected function assertDispatched(string $nameOrClass, ?int $times = null, string $message = ''): void
    {
        $recorder = $this->ground()->events();
        $count    = $recorder->countOf($nameOrClass);

        if ($times === null) {
            self::assertGreaterThan(
                0,
                $count,
                self::because($message, self::eventContext($nameOrClass, $recorder)),
            );

            return;
        }

        self::assertSame(
            $times,
            $count,
            self::because($message, "Expected [{$nameOrClass}] {$times}×, got {$count}."),
        );
    }

    protected function assertNotDispatched(string $nameOrClass, string $message = ''): void
    {
        self::assertSame(
            0,
            $this->ground()->events()->countOf($nameOrClass),
            self::because($message, "Expected [{$nameOrClass}] NOT to be dispatched."),
        );
    }

    // ── Port assertions ───────────────────────────────────────────────────────

    protected function assertCommitted(string $message = ''): void
    {
        self::assertTrue(
            $this->ground()->db()->committed(),
            self::because($message, 'Expected the transaction to commit; it did not.'),
        );
    }

    protected function assertRolledBack(string $message = ''): void
    {
        self::assertTrue(
            $this->ground()->db()->rolledBack(),
            self::because($message, 'Expected the transaction to roll back; it did not.'),
        );
    }

    protected function assertQueued(string $jobClass, ?int $times = null, string $message = ''): void
    {
        $queue = $this->ground()->queue();
        $count = $queue->pushCount($jobClass);

        if ($times === null) {
            self::assertGreaterThan(
                0,
                $count,
                self::because(
                    $message,
                    "Expected [{$jobClass}] to be queued. Queued: "
                    . (($jobs = array_column($queue->pushed, 'job')) === [] ? 'nothing' : implode(', ', $jobs)),
                ),
            );

            return;
        }

        self::assertSame($times, $count, self::because($message, "Expected [{$jobClass}] queued {$times}×, got {$count}."));
    }

    protected function assertMailSent(string $view, string $message = ''): void
    {
        self::assertTrue(
            $this->ground()->mail()->sentView($view),
            self::because($message, "Expected mail using view [{$view}] to be sent."),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function because(string $custom, string $fallback): string
    {
        return $custom !== '' ? $custom . "\n" . $fallback : $fallback;
    }

    /**
     * A dispatch failure has two very different causes, and the message should
     * distinguish them: the event never fired, or it fired under a name that
     * module.json does not declare, in which case nothing was subscribed and the
     * recorder could not have seen it.
     */
    private static function eventContext(string $nameOrClass, EventRecorder $recorder): string
    {
        $recorded = $recorder->names();

        return "Expected [{$nameOrClass}] to be dispatched.\n"
            . 'Recorded: ' . ($recorded === [] ? 'nothing' : implode(', ', $recorded)) . "\n"
            . 'Only names listed in module.json "emits" are recorded — check that first.';
    }
}
