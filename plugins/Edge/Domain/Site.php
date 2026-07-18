<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * One project as the edge sees it: its public hostnames, where its front
 * controller lives (`<path>/app/public`), how it's served (FPM vs Swoole + the
 * upstream), and the run-env that must be injected into its vhost so the project
 * boots (APP_ENV, HKM_USERDATA_DIR, PSP_GLOBAL_AUTOLOAD, HKM_KERNEL_HOME, …).
 *
 * Local (.local/.test) domains ride along on the owning site but are NOT put in
 * the server config — they go to /etc/hosts.
 */
final readonly class Site
{
    /**
     * @param list<string>          $publicDomains server-facing hostnames
     * @param list<string>          $localDomains  dev-only hostnames (→ /etc/hosts)
     * @param array<string, string> $env           run-env injected into the vhost
     */
    public function __construct(
        public string $name,
        public string $docroot,       // <project path>/app/public
        public array $publicDomains,
        public array $localDomains,
        public ServeModel $model,
        public string $upstream,      // fpm: fastcgi socket/addr · swoole: host:port
        public array $env,
        /** OpenSwoole settings (ws/health routes, service unit); null for FPM. */
        public ?SwooleOptions $swoole = null,
        /** Project root (PROJECT_ROOT). Every other path derives from it. */
        public string $root = '',
    ) {}

    /**
     * PROJECT_ROOT/app/public — the nginx `root`, where static assets live.
     * Falls back to the explicit docroot for sites built without a project root.
     */
    public function publicRoot(): string
    {
        return $this->root !== '' ? $this->root . '/app/public' : $this->docroot;
    }

    /** PROJECT_ROOT/app/swoole — the OpenSwoole runtime directory. */
    public function swooleRoot(): string
    {
        return ($this->root !== '' ? $this->root : dirname($this->docroot, 2)) . '/app/swoole';
    }

    /** Does this site have anything to put in the server config? */
    public function servesPublic(): bool
    {
        return $this->publicDomains !== [] && $this->docroot !== '';
    }
}
