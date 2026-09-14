<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MetricsPort;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Fixtures\BlogModule\Provider as BlogProvider;
use Tests\Feature\Support\Fakes\DenyingLayer;
use Tests\Feature\Support\KernelTestCase;
use Tests\Support\Fakes\RecordingMetrics;

/**
 * The observability stage, wired into a real pipeline.
 *
 * The unit test proves the stage emits what it is told. This proves the thing
 * the unit test structurally cannot: that ResolveStage actually writes the route
 * TEMPLATE into the telemetry sink, so the label is '/posts/{id:num}' and not
 * one time series per post id.
 */
#[Group('feature')]
final class MetricsIntegrationTest extends KernelTestCase
{
    private RecordingMetrics $metrics;

    private function bootWithMetrics(array $security = []): void
    {
        $this->metrics = new RecordingMetrics();

        $this->boot(
            modules:  [BlogProvider::class],
            ports:    [MetricsPort::class => $this->metrics],
            security: $security,
        );
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        return $this->metrics->labelsFor('http.server.requests')[0]
            ?? self::fail('No request metric was emitted.');
    }

    public function testTheRouteLabelIsTheTemplateNotThePath(): void
    {
        $this->db->answer('WHERE id = :id', [['id' => '8134']]);
        $this->bootWithMetrics();

        $this->get('/posts/8134')->assertOk();

        $labels = $this->labels();

        self::assertSame('GET /posts/{id:num}', $labels['route']);
        self::assertStringNotContainsString('8134', $labels['route']);
    }

    public function testAStaticRouteIsLabelledWithItsOwnPath(): void
    {
        $this->bootWithMetrics();

        $this->get('/posts/me')->assertOk();

        self::assertSame('GET /posts/me', $this->labels()['route']);
    }

    public function testBothSignalsAreEmittedForOneRequest(): void
    {
        $this->bootWithMetrics();

        $this->get('/posts')->assertOk();

        self::assertCount(1, $this->metrics->labelsFor('http.server.requests'));
        self::assertCount(1, $this->metrics->labelsFor('http.server.duration'));
    }

    public function testTheStatusLabelReflectsTheRealResponse(): void
    {
        $this->bootWithMetrics();

        $this->post('/posts', ['title' => 'x'])->assertCreated();

        self::assertSame('201', $this->labels()['status']);
    }

    /** A 404 still counts — instrumentation that only sees successes is useless. */
    public function testAnUnmatchedRequestIsStillCounted(): void
    {
        $this->bootWithMetrics();

        $this->get('/nowhere')->assertNotFound();

        self::assertSame(
            ['method' => 'GET', 'route' => 'unmatched', 'status' => '404'],
            $this->labels(),
        );
    }

    /**
     * The stage sits OUTSIDE SecurityStage precisely so a denial is visible.
     * An attack is the one time the graph matters most.
     */
    public function testADeniedRequestIsStillCounted(): void
    {
        $this->bootWithMetrics(security: [new DenyingLayer(403)]);

        $this->get('/posts')->assertForbidden();

        self::assertSame('403', $this->labels()['status']);
    }

    public function testAThrownRequestIsCountedAs500(): void
    {
        $this->bootWithMetrics();

        $this->get('/posts/boom')->assertServerError();

        self::assertSame('500', $this->labels()['status']);
    }

    /** With no MetricsPort bound the request must behave identically. */
    public function testAnApplicationWithNoMetricsPortStillServes(): void
    {
        $this->db->answer('FROM posts', [['id' => '1']]);
        $this->boot(modules: [BlogProvider::class]);

        $this->get('/posts')->assertOk()->assertJsonFragment(['posts' => [['id' => '1']]]);
    }
}
