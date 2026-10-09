<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Error;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorClassifier;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\{
    DomainException,
    GatewayException,
    HttpStatusAware,
    KernelException,
    LockTimeoutException,
    OptimisticLockException,
    RepositoryException,
    SecurityException,
    ServiceException,
    ValidationException
};
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\ErrorStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exception → (HTTP status, severity) contract.
 *
 * Nothing pinned it before, which is how a client-correctable DomainException
 * answered 500, and a lost optimistic-lock race was classified CRITICAL.
 */
final class ErrorMappingTest extends TestCase
{
    /** @return iterable<string, array{\Throwable, int, string}> */
    public static function cases(): iterable
    {
        yield 'validation'      => [new ValidationException(['email' => 'Required.']), 422, ErrorClassifier::INFO];
        yield 'domain'          => [new DomainException('Invoice already issued'), 422, ErrorClassifier::INFO];
        yield 'service'         => [new ServiceException('invoice.create.failed'), 422, ErrorClassifier::WARNING];
        yield 'security 401'    => [new SecurityException('No token', code: 401), 401, ErrorClassifier::WARNING];
        yield 'security other'  => [new SecurityException('Nope'), 403, ErrorClassifier::WARNING];
        yield 'optimistic lock' => [new OptimisticLockException('Stale version'), 409, ErrorClassifier::WARNING];
        yield 'lock timeout'    => [LockTimeoutException::for('invoice:7', 10), 409, ErrorClassifier::WARNING];
        yield 'repository'      => [new RepositoryException('DB down'), 500, ErrorClassifier::CRITICAL];
        yield 'gateway'         => [new GatewayException('Stripe down'), 502, ErrorClassifier::CRITICAL];
        yield 'kernel'          => [new KernelException('Broken'), 500, ErrorClassifier::CRITICAL];
        yield 'unknown'         => [new \RuntimeException('?'), 500, ErrorClassifier::CRITICAL];
    }

    #[DataProvider('cases')]
    public function testStatusAndSeverity(\Throwable $e, int $status, string $severity): void
    {
        self::assertSame($severity, ErrorClassifier::severityFor($e));
        self::assertSame($status, $this->statusFor($e));
    }

    /** @return iterable<string, array{\Throwable, int, string}> */
    public static function legacyCases(): iterable
    {
        yield 'domain'          => [new DomainException('Invoice already issued'), 500, ErrorClassifier::INFO];
        yield 'optimistic lock' => [new OptimisticLockException('Stale version'), 500, ErrorClassifier::CRITICAL];
        yield 'lock timeout'    => [LockTimeoutException::for('invoice:7', 10), 500, ErrorClassifier::CRITICAL];
        // Unchanged by the flag — it restores old codes, it does not add new ones.
        yield 'validation'      => [new ValidationException(['email' => 'Required.']), 422, ErrorClassifier::INFO];
        yield 'gateway'         => [new GatewayException('Stripe down'), 502, ErrorClassifier::CRITICAL];
    }

    /** ERROR_STATUS_LEGACY restores the 1.17 status AND severity together. */
    #[DataProvider('legacyCases')]
    public function testTheLegacyFlagRestoresThePreviousMapping(\Throwable $e, int $status, string $severity): void
    {
        $_ENV['ERROR_STATUS_LEGACY'] = 'true';

        try {
            self::assertSame($severity, ErrorClassifier::severityFor($e));
            self::assertSame($status, $this->statusFor($e));
        } finally {
            unset($_ENV['ERROR_STATUS_LEGACY']);
        }
    }

    /** @return iterable<string, array{\Throwable, string}> */
    public static function concurrencyLeaks(): iterable
    {
        yield 'lock timeout'    => [LockTimeoutException::for('tenant:acme:invoice:7', 10), 'tenant:acme'];
        yield 'optimistic lock' => [new OptimisticLockException('Row invoices#7 version 3 != 4'), 'invoices#7'];
    }

    /**
     * A 409 must not carry the exception's own message to the client: the lock
     * key and the repository's wording are internal. Both used to be masked as
     * critical; moving them to warning must not unmask them.
     */
    #[DataProvider('concurrencyLeaks')]
    public function testAConcurrencyErrorDoesNotLeakItsMessage(\Throwable $e, string $internal): void
    {
        $previous = $_ENV['APP_DEBUG'] ?? null;
        unset($_ENV['APP_DEBUG']);

        try {
            $body = $this->responseFor($e)->body();
        } finally {
            if ($previous !== null) {
                $_ENV['APP_DEBUG'] = $previous;
            }
        }

        self::assertStringNotContainsString($internal, $body);
        self::assertStringContainsString('"message":"', $body);
    }

    public function testAnHttpStatusAwareExceptionDeclaresItsOwnStatus(): void
    {
        $e = new class ('Seat taken') extends \RuntimeException implements HttpStatusAware {
            public function httpStatus(): int { return 409; }
        };

        self::assertSame(409, $this->statusFor($e));
    }

    private function statusFor(\Throwable $e): int
    {
        return $this->responseFor($e)->status();
    }

    private function responseFor(\Throwable $e): Response
    {
        $stage = new ErrorStage(ErrorPipeline::notifiers([]));
        $request = Request::build(method: 'GET', path: '/api/thing', headers: ['Accept' => 'application/json']);

        return $stage->handle($request, static fn (): Response => throw $e);
    }
}
