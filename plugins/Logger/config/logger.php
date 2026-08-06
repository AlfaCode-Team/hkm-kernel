<?php

declare(strict_types=1);

/**
 * Logger configuration.
 *
 * A project overrides only the keys it cares about — the compiled config
 * manifest deep-merges projects/<name>/config/logger.php over this file.
 */
return [
    /*
     * Where records go.
     *
     *   file    append to LOG_FILE (default var/logs/app.log)
     *   stream  stdout/stderr — the right choice in containers
     *   null    discard. Only ever set this deliberately; see NullLogger's
     *           docblock for why a silently-null logger is a real hazard.
     */
    'channel' => env('LOG_CHANNEL', 'file'),

    /*
     * Minimum severity to record. Anything less severe is dropped.
     * emergency|alert|critical|error|warning|notice|info|debug
     *
     * 'debug' in development, 'info' or 'warning' in production.
     */
    'level' => env('LOG_LEVEL', 'debug'),

    /*
     * File channel target. Defaults to the project's var/logs/app.log.
     *
     * Deliberately NOT errors.log: that file belongs to the ErrorPipeline and
     * ErrorGuard, which record escaped Throwables. Mixing routine application
     * logging into it would bury the exceptions it exists to surface.
     */
    'file' => env('LOG_FILE', ''),
];
