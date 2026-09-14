<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Observability;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Observability\RequestTelemetry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\ObservabilityStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{NullMetrics, NullTracer, Span};
use Tests\Support\Fakes\{RecordingMetrics, RecordingTracer};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservabilityStage::class)]
#[CoversClass(RequestTelemetry::class)]
#[CoversClass(NullMetrics::class)]
#[CoversClass(NullTracer::class)]
final class ObservabilityStageTest extends TestCase
{
    private function dispatch(
        RecordingMetrics $metrics,
        RecordingTracer $tracer,
        callable $next,
        string $path = '/users/7',
        array $headers = [],
    ): Response {
        $stage   = new ObservabilityStage($metrics, $tracer);
        $request = Request::create($path, 'GET', server: $headers);

        return $stage->handle($request, $next);
    }

    public function testCountsAndTimesEveryRequest(): void
    {
        $metrics = new RecordingMetrics();
        $tracer  = new RecordingTracer();

        $this->dispatch($metrics, $tracer, static fn(Request $r): Response => Response::json(['ok' => true]));

        $labels = ['method' => 'GET', 'route' => 'unmatched', 'status' => '200'];

        self::assertSame([$labels], $metrics->labelsFor('http.server.requests'));
        self::assertSame([$labels], $metrics->labelsFor('http.server.duration'));
    }

    /**
     * The whole reason the telemetry sink is mutable: the stage runs outside the
     * router, so the template can only reach it by being written from within.
     */
    public function testUsesTheRouteTemplateWrittenByAnInnerStage(): void
    {
        $metrics = new RecordingMetrics();
        $tracer  = new RecordingTracer();

        $this->dispatch($metrics, $tracer, static function (Request $r): Response {
            $r->attribute('telemetry')->matched('GET /users/{id:num}', 'user.management');

            return Response::json([]);
        });

        self::assertSame(
            [['method' => 'GET', 'route' => 'GET /users/{id:num}', 'status' => '200']],
            $metrics->labelsFor('http.server.requests'),
        );
        self::assertSame('user.management', $tracer->span->attributes['hkm.module']);
    }

    /**
     * A raw path must never become a label — one time series per user id takes
     * the metrics store down. An unmatched request reports the constant instead.
     */
    public function testAnUnmatchedRequestIsLabelledWithAConstantNotThePath(): void
    {
        $metrics = new RecordingMetrics();

        $this->dispatch(
            $metrics,
            new RecordingTracer(),
            static fn(Request $r): Response => Response::notFound(),
            '/users/8134',
        );

        $labels = $metrics->labelsFor('http.server.requests')[0];

        self::assertSame('unmatched', $labels['route']);
        self::assertStringNotContainsString('8134', implode('|', $labels));
    }

    public function testStillEmitsWhenAnInnerStageThrows(): void
    {
        $metrics = new RecordingMetrics();
        $tracer  = new RecordingTracer();
        $boom    = new \RuntimeException('boom');

        try {
            $this->dispatch($metrics, $tracer, static fn(Request $r): Response => throw $boom);
            self::fail('The stage must rethrow — it observes, it does not handle.');
        } catch (\RuntimeException $e) {
            self::assertSame($boom, $e);
        }

        self::assertSame('500', $metrics->labelsFor('http.server.requests')[0]['status']);
        self::assertSame($boom, $tracer->span->exception);
        self::assertSame(1, $tracer->span->ended, 'A span left open reads as work still running.');
    }

    public function testA5xxIsAFailedSpanEvenWithNothingThrown(): void
    {
        $tracer = new RecordingTracer();

        $this->dispatch(
            new RecordingMetrics(),
            $tracer,
            static fn(Request $r): Response => Response::serverError(),
        );

        self::assertSame('HTTP 500', $tracer->span->error);
    }

    public function testA4xxIsNotAFailedSpan(): void
    {
        $tracer = new RecordingTracer();

        $this->dispatch(
            new RecordingMetrics(),
            $tracer,
            static fn(Request $r): Response => Response::notFound(),
        );

        self::assertNull($tracer->span->error);
    }

    public function testAdoptsAnInboundTraceparent(): void
    {
        $tracer = new RecordingTracer();

        $this->dispatch(
            new RecordingMetrics(),
            $tracer,
            static fn(Request $r): Response => Response::json([]),
            headers: ['HTTP_TRACEPARENT' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
        );

        self::assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', $tracer->continued);
    }

    public function testTheResponseIsReturnedUntouched(): void
    {
        $expected = Response::json(['a' => 1], 201);

        $actual = $this->dispatch(
            new RecordingMetrics(),
            new RecordingTracer(),
            static fn(Request $r): Response => $expected,
        );

        self::assertSame($expected, $actual);
    }

    /** The no-ops must still RUN the work — opting out changes what is observed, not what happens. */
    public function testTheNullTracerStillRunsTheWorkAndPropagatesThrowables(): void
    {
        self::assertSame(42, NullTracer::instance()->measure('x', static fn(Span $s): int => 42));
        self::assertSame('', NullTracer::instance()->traceparent());

        $this->expectException(\LogicException::class);
        NullTracer::instance()->measure('x', static fn(Span $s) => throw new \LogicException('up'));
    }

    public function testTheNoOpsAreSharedAndInert(): void
    {
        self::assertSame(NullMetrics::instance(), NullMetrics::instance());
        self::assertSame(NullTracer::instance(), NullTracer::instance());

        NullMetrics::instance()->counter('x', 1, ['a' => 'b']);
        self::assertTrue(true, 'A no-op that throws would defeat the point of a default.');
    }
}
