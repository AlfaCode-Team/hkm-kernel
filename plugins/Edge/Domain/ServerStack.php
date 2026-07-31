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
    /**
     * @param list<string> $apacheModules loaded Apache module short names (no
     *        `_module` suffix), e.g. ['headers','deflate','brotli','ssl'].
     *        Empty means "could not probe" — treated as unknown, not absent.
     */
    public function __construct(
        public bool $nginxInstalled,
        public bool $nginxActive,
        public bool $nginxHasStream,
        public bool $apacheInstalled,
        public bool $apacheActive,
        public bool $nginxHasBrotli = false,
        public array $apacheModules = [],
        // The RUNNING nginx already declares an SNI stream splitter (a `stream {}`
        // block using ssl_preread) in a file Edge does not manage. When true Edge
        // reuses it instead of emitting a second, conflicting splitter.
        public bool $nginxHasStreamConfig = false,
    ) {}

    /**
     * Is an Apache module loaded? Accepts a short name (`headers`) or the full
     * `headers_module`. When the module list is empty (probe unavailable) this
     * returns true so features aren't silently dropped — a genuinely missing
     * module is then caught by `apachectl configtest` before reload.
     */
    public function apacheHasModule(string $name): bool
    {
        if ($this->apacheModules === []) {
            return true; // unknown → assume present; configtest is the backstop
        }
        $short = str_ends_with($name, '_module') ? substr($name, 0, -7) : $name;

        return in_array($short, $this->apacheModules, true);
    }

    /**
     * Pick the routing strategy. A non-null $force is an EXPLICIT operator
     * override (`--nginx-only` / `--apache-only`, or EDGE_FORCE_STRATEGY): the
     * chosen single server is used verbatim with NO fallback, regardless of what
     * else is running. Auto-detection (the default) is:
     *   both active  → stream if nginx has it, else nginx-only (nginx is front)
     *   nginx only   → nginx-only
     *   apache only  → apache-only
     *   neither      → none
     */
    public function strategy(?Strategy $force = null): Strategy
    {
        if ($force === Strategy::NginxOnly || $force === Strategy::ApacheOnly) {
            return $force;
        }
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
            'nginx_installed'      => $this->nginxInstalled,
            'nginx_active'         => $this->nginxActive,
            'nginx_has_stream'     => $this->nginxHasStream,
            'nginx_has_stream_cfg' => $this->nginxHasStreamConfig,
            'nginx_has_brotli'     => $this->nginxHasBrotli,
            'apache_installed'     => $this->apacheInstalled,
            'apache_active'        => $this->apacheActive,
            'strategy'             => $this->strategy()->value,
        ];
    }
}
