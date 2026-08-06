<?php

declare(strict_types=1);

namespace Plugins\Logger\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Psr\Log\LoggerInterface;

/**
 * Adapts any PSR-3 logger (Monolog, Symfony, …) to the kernel's LoggerPort.
 *
 * This is the escape hatch that keeps LoggerPort from being a walled garden:
 * a project already standardised on Monolog binds
 *
 *     LoggerPort::class => new PsrLoggerBridge($monolog)
 *
 * and every kernel and plugin component logs through it unchanged.
 *
 * The reverse direction is deliberately NOT provided. Exposing a LoggerPort as
 * a PSR-3 logger would tempt code back into type-hinting Psr\Log\LoggerInterface,
 * which is the coupling this port exists to remove.
 */
final class PsrLoggerBridge extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $psr) {}

    public function log(string $level, string|\Stringable $message, array $context = []): void
    {
        // PSR-3 loggers do their own interpolation and context handling, so pass
        // both through untouched rather than pre-rendering.
        try {
            $this->psr->log($level, $message, $context);
        } catch (\Throwable) {
            // A logger must never break its caller.
        }
    }
}
