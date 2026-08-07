<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\HttpStatusAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\ErrorStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorStage::class)]
final class HttpStatusAwareTest extends TestCase
{
    private function statusFor(\Throwable $e): int
    {
        $stage   = new ErrorStage(ErrorPipeline::default());
        $request = Request::build(method: 'GET', path: '/api/things');

        $response = $stage->handle($request, static function () use ($e): Response {
            throw $e;
        });

        return $response->getStatusCode();
    }

    public function test_an_exception_declaring_its_status_gets_it(): void
    {
        // Before this, ANY exception outside the kernel hierarchy became a 500 —
        // opaque to the client and classified CRITICAL, paging someone about an
        // ordinary expected outcome.
        $e = new class('seat taken') extends \RuntimeException implements HttpStatusAware {
            public function httpStatus(): int { return 409; }
        };

        self::assertSame(409, $this->statusFor($e));
    }

    public function test_a_plain_exception_is_still_a_500(): void
    {
        self::assertSame(500, $this->statusFor(new \RuntimeException('boom')));
    }

    public function test_the_kernel_hierarchy_is_unchanged(): void
    {
        self::assertSame(422, $this->statusFor(new ValidationException(['email' => 'Required.'])));
        self::assertSame(502, $this->statusFor(new GatewayException('upstream down')));
    }

    public function test_a_declared_status_wins_over_the_built_in_mapping(): void
    {
        // Lets a project correct the status of an exception type it does not own,
        // by subclassing rather than editing the kernel.
        $e = new class('too big') extends ValidationException implements HttpStatusAware {
            public function __construct(string $message) { parent::__construct([], $message); }
            public function httpStatus(): int { return 413; }
        };

        self::assertSame(413, $this->statusFor($e));
    }

    public function test_a_nonsense_status_is_ignored(): void
    {
        // 200 would turn an error path into an apparent success; 302 would be a
        // redirect with no Location. Fall through to the normal mapping instead.
        $ok = new class('weird') extends \RuntimeException implements HttpStatusAware {
            public function httpStatus(): int { return 200; }
        };
        $redirect = new class('weird') extends \RuntimeException implements HttpStatusAware {
            public function httpStatus(): int { return 302; }
        };

        self::assertSame(500, $this->statusFor($ok));
        self::assertSame(500, $this->statusFor($redirect));
    }
}
