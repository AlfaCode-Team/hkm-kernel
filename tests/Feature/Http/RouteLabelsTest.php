<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\RouteLabelStage;
use AlfacodeTeam\Ground\Fakes\FakeLogger;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{LoggerPort, MetricsPort};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Support\KernelTestCase;
use Tests\Fixtures\LabelsModule\{Provider, Recorder};
use Tests\Support\Fakes\RecordingMetrics;

/**
 * Route labels through the REAL pipeline: a compiled manifest, a real matcher,
 * real stages, a real request-scoped container. Only the sinks are fixtures.
 */
#[CoversClass(RouteLabelStage::class)]
#[Group('feature')]
final class RouteLabelsTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Recorder::reset();
    }

    protected function tearDown(): void
    {
        Recorder::reset();
        parent::tearDown();
    }

    private function bootLabels(): void
    {
        $this->boot(modules: [Provider::class]);
    }

    public function test_a_successful_route_records_all_three_labels(): void
    {
        $this->bootLabels();

        $this->post('/lab/orders')->assertStatus(201);

        self::assertSame(['orders.created' => 1, 'orders.total' => 1], Recorder::$counts);

        self::assertCount(1, Recorder::$audits);
        self::assertSame('order.create', Recorder::$audits[0]['action']);
        self::assertSame('POST', Recorder::$audits[0]['meta']['method']);
        self::assertSame(201, Recorder::$audits[0]['meta']['status']);
        self::assertSame('POST /lab/orders', Recorder::$audits[0]['meta']['route']);

        self::assertCount(1, Recorder::$events);
        self::assertSame('order.created', Recorder::$events[0]['name']);
        self::assertSame(201, Recorder::$events[0]['payload']['status']);
        // The listener was bound with bind(): it was built by the MODULE's binding,
        // not autowired from the kernel scope (it has a required constructor argument).
        self::assertSame('from-module-scope', Recorder::$events[0]['payload']['marker']);
    }

    public function test_route_parameters_travel_as_templates_and_values(): void
    {
        $this->bootLabels();

        $this->get('/lab/orders/42')->assertStatus(200);

        self::assertSame(['orders.viewed' => 1], Recorder::$counts);
        self::assertSame('GET /lab/orders/{id:num}', Recorder::$audits[0]['meta']['route']);
        self::assertSame('42', Recorder::$audits[0]['meta']['param.id']);
        self::assertSame(['id' => '42'], Recorder::$events[0]['payload']['params']);
    }

    public function test_a_route_without_labels_records_nothing(): void
    {
        $this->bootLabels();

        $this->get('/lab/plain')->assertStatus(201);

        self::assertSame([], Recorder::$counts);
        self::assertSame([], Recorder::$audits);
        self::assertSame([], Recorder::$events);
    }

    /**
     * THE DECISION, pinned: a request refused before the handler ran is NOT
     * counted under the route's label — "orders.created" must not tick for an
     * order that was not created. Refusals are measured by ObservabilityStage,
     * which wraps the whole pipeline (see the next test).
     */
    public function test_a_request_refused_by_a_route_filter_records_no_label(): void
    {
        $this->bootLabels();

        $this->get('/lab/guarded')->assertStatus(403);

        self::assertSame([], Recorder::$counts);
        self::assertSame([], Recorder::$audits);
        self::assertSame([], Recorder::$events);
    }

    public function test_an_unmatched_request_records_no_label(): void
    {
        $this->bootLabels();

        $this->get('/lab/nowhere')->assertStatus(404);

        self::assertSame([], Recorder::$counts);
    }

    /** A handler that answers with an error status did not do the thing the label names. */
    public function test_an_error_response_from_the_handler_records_no_label(): void
    {
        $this->bootLabels();

        $this->get('/lab/invalid')->assertStatus(422);

        self::assertSame([], Recorder::$counts);
        self::assertSame([], Recorder::$audits);
        self::assertSame([], Recorder::$events);
    }

    public function test_a_handler_that_throws_records_no_label(): void
    {
        $this->bootLabels();

        $this->get('/lab/boom')->assertStatus(500);

        self::assertSame([], Recorder::$counts);
    }

    public function test_a_head_probe_is_not_the_action_and_records_nothing(): void
    {
        $this->bootLabels();

        $this->call('HEAD', '/lab/orders/42')->assertStatus(200);

        self::assertSame([], Recorder::$counts);
        self::assertSame([], Recorder::$audits);
        self::assertSame([], Recorder::$events);
    }

    /**
     * Where the refusals ARE counted: ObservabilityStage sees a request refused
     * by a filter, with its matched route template and status. Pinned so the
     * claim in RouteLabelStage's docblock stays true.
     */
    public function test_a_refused_request_is_still_measured_by_the_outer_observability_stage(): void
    {
        $recorder = new RecordingMetrics();
        $this->boot(modules: [Provider::class], ports: [MetricsPort::class => $recorder]);

        $this->get('/lab/guarded')->assertStatus(403);

        self::assertContains(
            ['method' => 'GET', 'route' => 'GET /lab/guarded', 'status' => '403'],
            $recorder->labelsFor('http.server.requests'),
        );
    }

    // ── Absent sinks are inert ───────────────────────────────────────────────

    public function test_with_no_sinks_installed_every_label_is_an_inert_no_op(): void
    {
        Recorder::$installUsageHook = false;
        Recorder::$bindAuditSink    = false;
        $this->bootLabels();

        $response = $this->post('/lab/orders');

        $response->assertStatus(201);
        self::assertSame([], Recorder::$counts);
        self::assertSame([], Recorder::$audits);
        // `emits` needs no plugin (the bus is the kernel's) and still delivers.
        self::assertCount(1, Recorder::$events);
    }

    public function test_labels_on_a_project_route_with_nothing_installed_serve_normally(): void
    {
        $this->boot(routes: [[
            'method' => 'GET', 'path' => '/ping',
            'handler' => 'Tests\\Feature\\Kernel\\ProjectPingController@ping',
            'audit' => 'ping.seen', 'metric' => 'ping.count', 'emits' => 'ping.happened',
        ]]);

        $response = $this->get('/ping');

        $response->assertStatus(200);
        self::assertSame(
            ['audit' => 'ping.seen', 'metric' => ['ping.count'], 'emits' => ['ping.happened']],
            $this->manifest('route-manifest.php')['GET /ping']['labels'],
        );
    }

    /** An audit sink bound with bindInternal() cannot be read from the label stage: inert, not a 500. */
    public function test_an_internally_bound_audit_sink_is_inert_not_an_error(): void
    {
        Recorder::$auditSinkInternal = true;
        $this->bootLabels();

        $this->post('/lab/orders')->assertStatus(201);

        self::assertSame([], Recorder::$audits);
        self::assertSame(1, Recorder::$counts['orders.created'] ?? 0, 'the other labels still applied');
    }

    /** A `usage` attribute that is merely \Countable is not a metric sink and is never called. */
    public function test_a_countable_usage_attribute_is_not_mistaken_for_a_sink(): void
    {
        Recorder::$usageIsCountable = true;
        $this->bootLabels();

        $this->post('/lab/orders')->assertStatus(201);

        self::assertSame(0, Recorder::$countableCalls);
    }

    /** A sink that throws must not turn the successful response into an error. */
    public function test_a_throwing_sink_never_fails_the_response(): void
    {
        Recorder::$metricSinkThrows = true;
        $this->bootLabels();

        $this->post('/lab/orders')->assertStatus(201);

        // The throwing metric sink did not stop the other two labels.
        self::assertCount(1, Recorder::$audits);
        self::assertCount(1, Recorder::$events);
    }

    // ── Compilation ──────────────────────────────────────────────────────────

    public function test_labels_are_compiled_into_the_manifest_and_the_index(): void
    {
        $this->bootLabels();

        $manifest = $this->manifest('route-manifest.php');

        self::assertSame(
            ['audit' => 'order.create', 'metric' => ['orders.created', 'orders.total'], 'emits' => ['order.created']],
            $manifest['POST /lab/orders']['labels'],
        );
        self::assertArrayNotHasKey('labels', $manifest['GET /lab/plain']);
        // The derived index carries the same entry the matcher hands the pipeline.
        self::assertStringContainsString("'orders.total'", file_get_contents($this->root . '/var/cache/manifests/route-index.php'));
    }

    /**
     * Preserved property: every consumer falls back to the flat manifest when the
     * derived files are missing — a stale deploy must still serve, labels included.
     */
    public function test_labels_survive_a_deploy_whose_cache_has_no_derived_index(): void
    {
        $this->bootLabels();
        @unlink($this->root . '/var/cache/manifests/route-index.php');
        @unlink($this->root . '/var/cache/manifests/route-names.php');

        $this->post('/lab/orders')->assertStatus(201);

        self::assertSame(1, Recorder::$counts['orders.created'] ?? 0);
    }

    /** A project declares no emits[] list, so its routes may emit any well-formed name. */
    public function test_a_project_route_is_not_checked_against_an_emits_list(): void
    {
        $this->boot(modules: [Provider::class], routes: [[
            'method' => 'GET', 'path' => '/x',
            'handler' => 'Tests\\Feature\\Kernel\\ProjectPingController@ping',
            'emits' => 'whatever.else',
        ]]);

        self::assertSame(['emits' => ['whatever.else']], $this->manifest('route-manifest.php')['GET /x']['labels']);
    }

    // ── Diagnosability: an inert label says why, at debug; a failing one warns ──

    private function bootWithLogger(): FakeLogger
    {
        $logger = new FakeLogger();
        $this->boot(modules: [Provider::class], ports: [LoggerPort::class => $logger]);

        return $logger;
    }

    public function test_an_absent_sink_is_logged_at_debug_and_never_as_a_warning(): void
    {
        Recorder::$installUsageHook = false;
        Recorder::$bindAuditSink    = false;
        $logger = $this->bootWithLogger();

        $this->post('/lab/orders')->assertStatus(201);

        self::assertTrue($logger->logged('debug', 'metric label has no sink'));
        self::assertTrue($logger->logged('debug', 'audit label has no sink'));
        self::assertSame([], $logger->at('warning'));
    }

    public function test_an_internally_bound_sink_is_reported_as_not_installed_not_as_a_failure(): void
    {
        Recorder::$auditSinkInternal = true;
        $logger = $this->bootWithLogger();

        $this->post('/lab/orders')->assertStatus(201);

        self::assertTrue($logger->logged('debug', 'audit label has no sink'));
        self::assertSame([], $logger->at('warning'));
    }

    /** An object that has no count() at all is "no sink", reported as such. */
    public function test_a_usage_attribute_without_count_is_reported_as_no_sink(): void
    {
        Recorder::$usageIsPlainObject = true;
        $logger = $this->bootWithLogger();

        $this->post('/lab/orders')->assertStatus(201);

        self::assertTrue($logger->logged('debug', 'metric label has no sink'));
        self::assertSame([], $logger->at('warning'));
    }

    public function test_a_sink_that_throws_is_logged_as_a_warning_naming_the_label(): void
    {
        Recorder::$metricSinkThrows = true;
        $logger = $this->bootWithLogger();

        $this->post('/lab/orders')->assertStatus(201);

        self::assertTrue($logger->logged('warning', 'could not be applied'));
        self::assertSame('metric', $logger->at('warning')[0]['context']['label']);
    }
}
