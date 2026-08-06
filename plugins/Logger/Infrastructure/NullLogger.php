<?php

declare(strict_types=1);

namespace Plugins\Logger\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;

/**
 * NullLogger — discards everything.
 *
 * Legitimate in exactly two places: a unit test that does not assert on logging,
 * and a deliberate LOG_CHANNEL=null in config.
 *
 * It is NOT a legitimate default. Binding this as the application logger is how
 * logging silently disappears — which is precisely what happened here before the
 * LoggerPort existed: the only binding of Psr\Log\LoggerInterface in the whole
 * codebase pointed at a null logger, so every line written by the Database,
 * Tenancy and EventBus components went nowhere and nobody noticed.
 */
final class NullLogger implements LoggerPort
{
    public function emergency(string|\Stringable $message, array $context = []): void {}
    public function alert(string|\Stringable $message, array $context = []): void     {}
    public function critical(string|\Stringable $message, array $context = []): void  {}
    public function error(string|\Stringable $message, array $context = []): void     {}
    public function warning(string|\Stringable $message, array $context = []): void   {}
    public function notice(string|\Stringable $message, array $context = []): void    {}
    public function info(string|\Stringable $message, array $context = []): void      {}
    public function debug(string|\Stringable $message, array $context = []): void     {}

    public function log(string $level, string|\Stringable $message, array $context = []): void {}
}
