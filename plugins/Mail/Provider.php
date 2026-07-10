<?php

declare(strict_types=1);

namespace Plugins\Mail;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\Application\Jobs\SendMailJob;
use Plugins\Mail\Application\Mailer;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;
use Plugins\Mail\Infrastructure\Security\DkimSigner;
use Plugins\Mail\Infrastructure\Transport\ArrayTransport;
use Plugins\Mail\Infrastructure\Transport\LogTransport;
use Plugins\Mail\Infrastructure\Transport\MailTransport;
use Plugins\Mail\Infrastructure\Transport\SendmailTransport;
use Plugins\Mail\Infrastructure\Transport\SmtpTransport;
use Plugins\Mail\Infrastructure\Transport\Transport;
use Plugins\View\API\Contracts\ViewRendererContract;

/**
 * Mail plugin — native, dependency-free mail delivery.
 *
 * Binds the Transport (from config), the MimeBuilder, an optional DkimSigner and
 * the Mailer — which satisfies BOTH the kernel `MailPort` (so any module's
 * view-based `send()`/`queue()` just works) and the richer `MailerContract`.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'mail.delivery';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [MailPort::class, MailerContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $config = $this->config();

        $container->bindInternal(Transport::class, fn(ModuleContainer $c): Transport => $this->makeTransport($config));

        $container->bindInternal(MimeBuilder::class, static fn(): MimeBuilder => new MimeBuilder());

        $container->bind(Mailer::class, function (ModuleContainer $c) use ($config): Mailer {
            return new Mailer(
                transport: $c->make(Transport::class),
                mime:      $c->make(MimeBuilder::class),
                dkim:      $this->makeDkim($config),
                views:     $c->has(ViewRendererContract::class) ? $c->make(ViewRendererContract::class) : null,
                queue:     $c->has(QueuePort::class) ? $c->make(QueuePort::class) : null,
                fromEmail: (string) ($config['from']['address'] ?? ''),
                fromName:  (string) ($config['from']['name'] ?? ''),
                charset:   (string) ($config['charset'] ?? 'UTF-8'),
                queueName: (string) ($config['queue'] ?? 'mail'),
            );
        });

        // One instance satisfies MailPort, MailerContract and the concrete class.
        $container->bind(MailPort::class, static fn(ModuleContainer $c): Mailer => $c->make(Mailer::class));
        $container->bind(MailerContract::class, static fn(ModuleContainer $c): Mailer => $c->make(Mailer::class));

        // Background delivery job resolves the same Transport.
        $container->bindInternal(SendMailJob::class, static fn(ModuleContainer $c): SendMailJob =>
            new SendMailJob($c->make(Transport::class)));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Job is declared in module.json; nothing to hook here.
    }

    /** @param array<string,mixed> $config */
    private function makeTransport(array $config): Transport
    {
        $smtp = $config['smtp'] ?? [];

        return match ((string) ($config['transport'] ?? 'smtp')) {
            'sendmail' => new SendmailTransport((string) ($config['sendmail']['binary'] ?? '/usr/sbin/sendmail')),
            'mail'     => new MailTransport(),
            'array'    => new ArrayTransport(),
            'log'      => new LogTransport(),
            default    => new SmtpTransport(
                hosts:      array_values(array_filter(array_map('trim', explode(',', (string) ($smtp['hosts'] ?? 'localhost'))))),
                port:       (int) ($smtp['port'] ?? 587),
                encryption: (string) ($smtp['encryption'] ?? 'tls'),
                username:   (string) ($smtp['username'] ?? ''),
                password:   (string) ($smtp['password'] ?? ''),
                authMode:   (string) ($smtp['auth_mode'] ?? 'auto'),
                oauthToken: (string) ($smtp['oauth_token'] ?? ''),
                heloDomain: (string) ($smtp['helo_domain'] ?? ''),
                timeout:    (int) ($smtp['timeout'] ?? 30),
                verifyPeer: (bool) ($smtp['verify_peer'] ?? true),
                keepAlive:  (bool) ($smtp['keep_alive'] ?? false),
                allowInsecureAuth: (bool) ($smtp['allow_insecure_auth'] ?? false),
            ),
        };
    }

    /** @param array<string,mixed> $config */
    private function makeDkim(array $config): ?DkimSigner
    {
        $dkim     = $config['dkim'] ?? [];
        $domain   = (string) ($dkim['domain'] ?? '');
        $selector = (string) ($dkim['selector'] ?? '');
        $key      = (string) ($dkim['private_key'] ?? '');

        if ($domain === '' || $selector === '' || $key === '') {
            return null;
        }
        if (is_file($key) && is_readable($key)) {
            $key = (string) file_get_contents($key);
        }

        return new DkimSigner($domain, $selector, $key);
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        $default = __DIR__ . '/config/mail.php';
        $path = function_exists('config_path') && is_file(config_path('mail.php'))
            ? config_path('mail.php')
            : $default;

        /** @var array<string,mixed> $config */
        $config = is_file($path) ? require $path : [];

        return is_array($config) ? $config : [];
    }
}
