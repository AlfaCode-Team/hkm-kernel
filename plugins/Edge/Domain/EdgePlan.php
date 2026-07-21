<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * The full result of planning an edge apply: the detected stack, the chosen
 * strategy, the per-project Sites that fed the config, the dev-only local
 * domains (→ /etc/hosts), and the rendered file (path + contents).
 */
final readonly class EdgePlan
{
    /**
     * @param list<Site>   $sites        per-project sites in the server config
     * @param list<string> $localDomains dev-only domains (.local / .test / …) → /etc/hosts
     */
    public function __construct(
        public ServerStack $stack,
        public Strategy $strategy,
        public array $sites,
        public array $localDomains,
        public string $targetPath,
        public string $contents,
        // NginxStream only: an existing nginx stream splitter was found and is
        // being reused, so this file emits ONLY the internal backend vhosts.
        public bool $reuseStream = false,
    ) {}
}
