<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * The full result of planning an edge apply: the detected stack, the chosen
 * strategy, the domains that fed the config, and the rendered file (path +
 * contents) that will be written.
 */
final readonly class EdgePlan
{
    /**
     * @param list<string> $domains      public (server-facing) domains in the config
     * @param list<string> $localDomains dev-only domains (.local / .test / …) → /etc/hosts
     */
    public function __construct(
        public ServerStack $stack,
        public Strategy $strategy,
        public array $domains,
        public array $localDomains,
        public string $targetPath,
        public string $contents,
    ) {}
}
