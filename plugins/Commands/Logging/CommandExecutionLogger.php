<?php

declare(strict_types=1);

namespace Plugins\Commands\Logging;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Psr\Log\LogLevel;

final class CommandExecutionLogger
{
    private float $startTime;
    private string $commandName = '';

    public function __construct(
        private readonly LoggerPort $logger,
    ) {
        $this->startTime = microtime(true);
    }

    public function logStart(string $command, array $argv): void
    {
        $this->commandName = $command;

        $this->logger->log(LogLevel::INFO, 'CLI command started', [
            'command' => $command,
            'argv' => $this->sanitizeArgv($argv),
            'user' => $this->getCurrentUser(),
            'pid' => getmypid(),
            'hostname' => gethostname(),
            'timestamp' => date('c'),
        ]);
    }

    public function logEnd(int $exitCode, ?string $error = null): void
    {
        $duration = microtime(true) - $this->startTime;
        $level = $exitCode === 0 ? LogLevel::INFO : LogLevel::WARNING;

        $this->logger->log($level, 'CLI command completed', [
            'command' => $this->commandName,
            'exit_code' => $exitCode,
            'duration_ms' => (int) ($duration * 1000),
            'error' => $error,
            'user' => $this->getCurrentUser(),
            'pid' => getmypid(),
        ]);
    }

    public function logMigration(string $migration, string $direction, bool $success, float $duration, ?string $error = null): void
    {
        $level = $success ? LogLevel::INFO : LogLevel::ERROR;

        $this->logger->log($level, 'Migration execution', [
            'migration' => $migration,
            'direction' => $direction,
            'success' => $success,
            'duration_ms' => (int) ($duration * 1000),
            'error' => $error,
            'user' => $this->getCurrentUser(),
            'pid' => getmypid(),
        ]);
    }

    public function logDestructiveOperation(string $operation, array $details): void
    {
        $this->logger->log(LogLevel::WARNING, 'Destructive operation executed', [
            'operation' => $operation,
            'user' => $this->getCurrentUser(),
            'details' => $details,
            'timestamp' => date('c'),
        ]);
    }

    private function sanitizeArgv(array $argv): array
    {
        return array_map(function ($arg) {
            $sensitive = ['password', 'secret', 'token', 'key', 'api-key', 'aws-'];
            foreach ($sensitive as $keyword) {
                if (str_contains(strtolower((string) $arg), $keyword)) {
                    return '***REDACTED***';
                }
            }
            return $arg;
        }, $argv);
    }

    private function getCurrentUser(): string
    {
        return get_current_user() ?: 'unknown';
    }
}
