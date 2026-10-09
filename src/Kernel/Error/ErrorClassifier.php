<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\{
    DomainException,
    GatewayException,
    KernelException,
    LockTimeoutException,
    OptimisticLockException,
    RepositoryException,
    SecurityException,
    ServiceException,
    ValidationException
};

/**
 * ErrorClassifier — maps a Throwable to a severity bucket.
 *
 * Severity drives which notifiers fire (see ErrorPipeline::rules()).
 *   critical → page someone (Repository, Gateway, Kernel, unknown)
 *   warning  → log + dashboard (Security, Service, OptimisticLock, LockTimeout)
 *   info     → log only (Domain, Validation — expected business outcomes)
 */
final class ErrorClassifier
{
    public const CRITICAL = 'critical';
    public const WARNING  = 'warning';
    public const INFO     = 'info';

    public static function severityFor(\Throwable $e): string
    {
        $concurrency = !self::legacyMapping()
            && ($e instanceof OptimisticLockException || $e instanceof LockTimeoutException);

        return match (true) {
            $e instanceof DomainException,
            $e instanceof ValidationException     => self::INFO,

            // Concurrency outcomes, not faults: checked BEFORE RepositoryException
            // because OptimisticLockException extends it, and a lost race is no
            // reason to page anyone.
            $concurrency,
            $e instanceof SecurityException,
            $e instanceof ServiceException        => self::WARNING,

            $e instanceof RepositoryException,
            $e instanceof GatewayException,
            $e instanceof KernelException         => self::CRITICAL,

            default                               => self::CRITICAL,
        };
    }

    /**
     * ERROR_STATUS_LEGACY — restore the exception mapping of 1.17 and earlier.
     *
     * The kernel now answers a DomainException with 422 (was 500), and an
     * OptimisticLockException / LockTimeoutException with 409 at severity
     * warning (was 500, critical). An application whose clients or alert rules
     * depend on the old codes sets this to keep them while it migrates. It
     * governs BOTH the status (ErrorStage) and the severity (here), so the
     * two can never disagree. Scheduled for removal in 2.0.
     *
     * Read through env() on every call, like ErrorStage's APP_DEBUG: the
     * environment loader skips putenv(), so getenv() would not see a .env value.
     */
    public static function legacyMapping(): bool
    {
        $value = \function_exists('env') ? env('ERROR_STATUS_LEGACY') : ($_ENV['ERROR_STATUS_LEGACY'] ?? null);

        if ($value === null || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
