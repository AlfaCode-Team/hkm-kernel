<?php

declare(strict_types=1);

namespace Plugins\Logger;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LogLevel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use Plugins\Logger\Infrastructure\FileLogger;
use Plugins\Logger\Infrastructure\NullLogger;
use Plugins\Logger\Infrastructure\StreamLogger;

/**
 * Logger plugin — supplies the LoggerPort adapter the kernel defines but does
 * not implement.
 *
 * Like Crypto, the adapter is normally wired app-lifetime in the project
 * bootstrap (->withPorts([...])); this Provider registers a config-driven
 * fallback so LoggerPort always resolves rather than silently being absent.
 *
 * That fallback matters more than usual here. The failure this plugin exists to
 * fix was not "logging is hard to configure" — it was that logging LOOKED
 * configured and went nowhere: the single binding of Psr\Log\LoggerInterface in
 * the codebase pointed at a null logger, so Database, Tenancy and EventBus all
 * wrote into a black hole. A resolvable, file-backed default is what makes that
 * failure mode impossible to reach by accident.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'logging.application';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [LoggerPort::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(LoggerPort::class)) {
            return; // the project wired it in withPorts() — respect that
        }

        $container->bind(LoggerPort::class, static fn(): LoggerPort => self::fromConfig());
    }

    /**
     * Build the adapter named by config/logger.php.
     *
     * Public + static so a project bootstrap can reuse the same resolution in
     * withPorts() without duplicating the channel switch.
     */
    public static function fromConfig(): LoggerPort
    {
        $config  = \function_exists('config') ? config('logger', []) : [];
        $channel = \is_array($config) ? (string) ($config['channel'] ?? 'file') : 'file';
        $level   = LogLevel::parse(\is_array($config) ? (string) ($config['level'] ?? 'debug') : 'debug');

        return match ($channel) {
            'null'   => new NullLogger(),
            'stream' => new StreamLogger(minimum: $level),
            default  => new FileLogger(self::file($config), $level),
        };
    }

    /** @param array<string, mixed>|mixed $config */
    private static function file(mixed $config): string
    {
        $configured = \is_array($config) ? (string) ($config['file'] ?? '') : '';

        // Separate from errors.log on purpose — that file belongs to the
        // ErrorPipeline/ErrorGuard and should stay exception-only.
        return $configured !== '' ? $configured : Paths::logs('app.log');
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
