<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

/**
 * Collects the platform's known hostnames from the project registries
 * (projects.json — each project's domains[]) plus any EDGE_EXTRA_DOMAINS, minus
 * EDGE_EXCLUDE_DOMAINS. Every hostname is validated against a strict charset
 * before it can reach a rendered config, so a malformed registry entry can never
 * inject nginx/Apache directives.
 */
final class DomainCollector
{
    /** @return list<string> sorted, unique, validated hostnames */
    public function collect(): array
    {
        $domains = [];

        $registry = (string) edge_config('projects_registry', '');
        if ($registry !== '' && is_file($registry)) {
            $json = json_decode((string) file_get_contents($registry), true);
            if (is_array($json)) {
                foreach ($json as $project) {
                    foreach ((array) ($project['domains'] ?? []) as $domain) {
                        $domains[] = strtolower(trim((string) $domain));
                    }
                }
            }
        }

        foreach ((array) edge_config('extra_domains', []) as $domain) {
            $domains[] = strtolower(trim((string) $domain));
        }

        $exclude = array_map('strtolower', (array) edge_config('exclude_domains', []));

        $domains = array_filter(
            array_unique($domains),
            fn (string $d): bool => $d !== '' && $this->isValid($d) && !in_array($d, $exclude, true),
        );

        sort($domains);

        return array_values($domains);
    }

    /** A conservative hostname whitelist — letters, digits, dot, hyphen only. */
    private function isValid(string $host): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
    }
}
