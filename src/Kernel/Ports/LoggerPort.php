<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * LoggerPort — structured application logging.
 *
 * PSR-3 SHAPED, NOT PSR-3 DEPENDENT
 * ---------------------------------
 * The method surface and the RFC 5424 levels match `Psr\Log\LoggerInterface`, so
 * adapting either direction is a one-line wrapper. But the kernel DEFINES this
 * contract rather than importing one, for the same reason it defines DatabasePort
 * instead of typing against PDO: the kernel owns its interfaces, adapters live in
 * plugins.
 *
 * WHY IT EXISTS
 * -------------
 * Before this port there was no logging contract at all. Error reporting went
 * through Error\Notifiers, which is error-path only, so anything non-fatal —
 * "tenant connection failed over to central", "event listener threw", "slow
 * query" — had nowhere to go. Three components independently reached for
 * `Psr\Log\LoggerInterface` and resolved it from a container that bound it in
 * exactly one place, to a **NullLogger**. Every one of those lines was silently
 * discarded, which is the same failure HKM 0.3 had with its no-op `log_message()`
 * stub swallowing every driver error.
 *
 * RELATIONSHIP TO THE ERROR PIPELINE
 * ----------------------------------
 * They are different jobs and should stay separate:
 *
 *   ErrorPipeline  a Throwable escaped — classify it, notify humans (Slack,
 *                  mail, DB), always fall back to a file. Exceptional.
 *   LoggerPort     a fact worth recording. Routine, high-volume, no notification.
 *
 * An adapter MUST NOT throw. A logger that fails takes down the operation it was
 * only meant to observe — the one thing worse than losing a log line.
 */
interface LoggerPort
{
    /** System is unusable. */
    public function emergency(string|\Stringable $message, array $context = []): void;

    /** Action must be taken immediately (e.g. the whole site is down). */
    public function alert(string|\Stringable $message, array $context = []): void;

    /** Critical condition — a component is unavailable, an invariant broke. */
    public function critical(string|\Stringable $message, array $context = []): void;

    /** Runtime error that does not need immediate action but should be reviewed. */
    public function error(string|\Stringable $message, array $context = []): void;

    /** Not an error, but worth attention — deprecated API use, poor API use. */
    public function warning(string|\Stringable $message, array $context = []): void;

    /** Normal but significant event. */
    public function notice(string|\Stringable $message, array $context = []): void;

    /** Interesting events — user logs in, SQL logs. */
    public function info(string|\Stringable $message, array $context = []): void;

    /** Detailed debug information. */
    public function debug(string|\Stringable $message, array $context = []): void;

    /**
     * Log at an arbitrary level.
     *
     * @param string               $level   one of the LogLevel constants
     * @param array<string, mixed> $context structured data; keys named in the
     *                                      message as {placeholder} are interpolated
     */
    public function log(string $level, string|\Stringable $message, array $context = []): void;
}
