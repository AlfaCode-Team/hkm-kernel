<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * Immutable snapshot of the host's web-server stack, as probed from the system.
 * The decision of WHICH configuration to apply lives here (strategy()), so the
 * rule the user described is expressed once, in the domain, not scattered
 * across the CLI or the renderer.
 */
final readonly class ServerStack
{
    public function __construct(
        public bool $nginxInstalled,
        public bool $nginxActive,
        public bool $nginxHasStream,
        public bool $apacheInstalled,
        public bool $apacheActive,
    ) {}

    /**
     * Pick the routing strategy:
     *   both active  → stream if nginx has it, else nginx-only (nginx is front)
     *   nginx only   → nginx-only
     *   apache only  → apache-only
     *   neither      → none
     */
    public function strategy(): Strategy
    {
        if ($this->nginxActive && $this->apacheActive) {
            return $this->nginxHasStream ? Strategy::NginxStream : Strategy::NginxOnly;
        }
        if ($this->nginxActive) {
            return Strategy::NginxOnly;
        }
        if ($this->apacheActive) {
            return Strategy::ApacheOnly;
        }
        return Strategy::None;
    }

    /** @return array<string, bool|string> */
    public function toArray(): array
    {
        return [
            'nginx_installed'  => $this->nginxInstalled,
            'nginx_active'     => $this->nginxActive,
            'nginx_has_stream' => $this->nginxHasStream,
            'apache_installed' => $this->apacheInstalled,
            'apache_active'    => $this->apacheActive,
            'strategy'         => $this->strategy()->value,
        ];
    }
}
