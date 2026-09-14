<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteIndex;

/**
 * DomainComposer — works out which HOSTS a route, group or module answers on.
 *
 * This is the one part of route compilation with genuinely surprising rules, and
 * the reason it is its own class: `domain` is both a host in its own right AND
 * the parent a `subdomain` attaches to, a bare label spans every domain on
 * purpose, and a wildcard may not take a subdomain. Each of those has a security
 * story behind it, spelled out at the method that implements it.
 *
 * It holds the project's registered domains, because validating a declared host
 * against `proj.json` "domains" is the only stateful thing here.
 */
final readonly class DomainComposer
{
    /**
     * @param list<string> $projectDomains hosts this project serves — proj.json
     *        "domains", via Kernel::withProjectDomains(). Empty (a project that
     *        registers no domains) disables the check entirely.
     */
    public function __construct(private array $projectDomains = []) {}

    /**
     * The DOMAIN a group of routes answers on, taken verbatim.
     *
     *   "domain":    "africavoting.local"     a host
     *   "domain":    "*.africavoting.local"   a wildcard
     *   "subdomain": "organizer"              a bare label
     *
     * This GROUPS — it does not resolve. The compiler does not look the string up
     * in any registry beyond the project's own domain list: a domain nothing
     * requests simply never matches, exactly like a path nothing requests. Only
     * case and surrounding whitespace are normalised, plus the two characters that
     * would break the route key round-trip ({@see RouteIndex::parseKey}) — a space
     * and an '@', neither of which occurs in a hostname.
     */
    public static function normalize(mixed $domain): string
    {
        if (!is_string($domain)) {
            return '';
        }

        $domain = strtolower(trim($domain));

        return str_contains($domain, ' ') || str_contains($domain, RouteIndex::DOMAIN_SEPARATOR)
            ? ''
            : $domain;
    }

    /**
     * The domains a route or group answers on — ONE OR MORE.
     *
     * `"domain"` and `"subdomain"` accept either a single string or a LIST, so
     * one group can serve several hosts without being written out N times:
     *
     *   "domain": "shop.example.com"
     *   "domain": ["shop.example.com", "shop.example.co.uk", "*.tenant.example.com"]
     *   "subdomain": ["admin", "staff"]
     *
     * Each entry is grouped verbatim and validated independently, exactly as a
     * single value is, and the caller emits one copy of the route per entry.
     * Groups already expand at boot into flat routes, so this costs nothing at
     * request time — it is the same expansion with a wider fan-out.
     *
     * A non-string, non-list value is a BOOT FAILURE. It used to fall through
     * `is_string()` to '', which silently turned "these routes belong to these
     * two hosts" into "these routes are global, on every host" — the widest
     * possible outcome, arrived at by accident, with nothing logged.
     *
     * @return list<string> normalised domains, de-duplicated. `['']` means the
     *                      shared (every-domain) table.
     */
    public function normalizeList(mixed $domain, string $context): array
    {
        if (is_string($domain)) {
            return [self::normalize($domain)];
        }

        if (!is_array($domain)) {
            throw new BootException(sprintf(
                '%s declares a domain of type [%s]. Use a string ("shop.example.com") '
                . 'or a list of strings (["a.example.com", "b.example.com"]).',
                $context,
                get_debug_type($domain),
            ));
        }

        if ($domain === []) {
            throw new BootException(sprintf(
                '%s declares an empty domain list. Remove the key to serve every '
                . 'domain, or name at least one host.',
                $context,
            ));
        }

        $out = [];
        foreach ($domain as $entry) {
            if (!is_string($entry)) {
                throw new BootException(sprintf(
                    '%s has a non-string entry of type [%s] in its domain list.',
                    $context,
                    get_debug_type($entry),
                ));
            }

            $normalized = self::normalize($entry);
            if ($normalized === '') {
                throw new BootException(sprintf(
                    '%s lists [%s] as a domain, which is not usable as one. A domain '
                    . 'may not be blank or contain a space or an "%s".',
                    $context,
                    $entry,
                    RouteIndex::DOMAIN_SEPARATOR,
                ));
            }

            // A repeat would compile the same route twice under one key and trip
            // the duplicate-route guard — reporting a conflict the author would
            // have to work backwards to recognise as their own copy-paste.
            if (!in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * The domains a route, group or module compiles under — always at least one.
     *
     * Declaring neither key inherits the enclosing scope, which is what makes an
     * ungrouped route global and a nested group stay on its parent's host.
     *
     * The key names are parameters because the module-wide form spells them
     * `routeDomain`/`routeSubdomain` while routes and groups use
     * `domain`/`subdomain` — the same rule, read from different keys.
     *
     * @param  array<string, mixed> $source    the route, group or module object
     * @param  array<string, mixed> $inherited the enclosing scope
     * @return list<string>
     */
    public function domainsFor(
        array $source,
        array $inherited,
        string $context,
        string $domainKey = 'domain',
        string $subdomainKey = 'subdomain',
    ): array {
        $hasDomain    = isset($source[$domainKey]);
        $hasSubdomain = isset($source[$subdomainKey]);

        if (!$hasDomain && !$hasSubdomain) {
            return [(string) $inherited['domain']];
        }

        $parents = $hasDomain
            ? $this->normalizeList($source[$domainKey], $context)
            : [];
        $labels = $hasSubdomain
            ? $this->normalizeList($source[$subdomainKey], $context)
            : [];

        // A declared `domain` is BOTH a host in its own right AND the parent that
        // `subdomain` attaches to.
        //
        //   { "domain": "hkm.local", "subdomain": ["api", "auth"] }
        //     → hkm.local, api.hkm.local, auth.hkm.local
        //
        // Without this, those labels compiled BARE — and a bare label spans every
        // domain by design, so the group also answered on api.somebody-else.com.
        // Reading "api under hkm.local" and getting "api under anything" is not a
        // difference anyone spots until it is exploited.
        //
        // A label with NO domain to attach to keeps the global meaning, because
        // there is nothing for it to be relative to — that is what makes an
        // `admin` panel appear on every brand.
        if ($parents === []) {
            foreach ($labels as $label) {
                $this->validate($label, $context);
            }

            return $labels;
        }

        foreach ($parents as $parent) {
            $this->validate($parent, $context);
        }

        // No labels to attach: the domains stand alone. Returning here also keeps
        // the wildcard check below from firing on `{"domain": "*.example.com"}`,
        // which composes nothing and is entirely valid on its own.
        if ($labels === []) {
            return $parents;
        }

        $domains = $parents;
        foreach ($parents as $parent) {
            if (str_starts_with($parent, '*.')) {
                throw new BootException(sprintf(
                    '%s attaches subdomain [%s] to wildcard domain [%s]. A wildcard '
                    . 'already covers every label under it, so the two cannot compose. '
                    . 'Drop the subdomain, or name the parent host literally.',
                    $context,
                    $labels[0],
                    $parent,
                ));
            }

            foreach ($labels as $label) {
                // The composed host is DERIVED from a parent already validated
                // above, and DomainResolver reaches this project by suffix match
                // on that same parent — so it needs no registration of its own.
                $composed = $label . '.' . $parent;
                if (!in_array($composed, $domains, true)) {
                    $domains[] = $composed;
                }
            }
        }

        return $domains;
    }

    /**
     * Check that a declared HOST is one this project actually serves.
     *
     * The registry is the project's own `proj.json` "domains" — the same list
     * DomainResolver matches an incoming Host against to build a DomainContext.
     * Grouping routes under a host the project never registered produces routes
     * that can never be reached: the request would have been routed to a
     * different project, or refused, long before the router saw it. That is the
     * same silent-404 class as an unknown `{name:type}` or a dead disable spec,
     * so it fails the boot with the list of hosts that WOULD have worked.
     *
     * TWO THINGS ARE DELIBERATELY NOT CHECKED:
     *
     *   - A BARE SUBDOMAIN (`"subdomain": "api"`, anything with no dot). It is
     *     domain-agnostic ON PURPOSE — it answers on api.example.com AND
     *     api.example2.com AND any future host with that first label — so there
     *     is no single registered host to check it against.
     *   - Anything at all when proj.json declares no "domains". A project that
     *     does not register its hosts has no registry to validate against, and
     *     inventing one is exactly the indirection this design avoids.
     *
     * A WILDCARD (`*.africavoting.local`) passes when the parent is registered or
     * when any registered host falls under it — which is what makes it the right
     * tool for tenant hosts that are added to the database, not to proj.json.
     */
    public function validate(string $domain, string $context): void
    {
        // No dot ⇒ a bare subdomain label, which spans every domain by design.
        if ($domain === '' || $this->projectDomains === [] || !str_contains($domain, '.')) {
            return;
        }

        $wildcard = str_starts_with($domain, '*.');
        $suffix   = $wildcard ? substr($domain, 2) : '';

        foreach ($this->projectDomains as $host) {
            $host = strtolower(trim((string) $host));

            if ($wildcard
                ? ($host === $suffix || str_ends_with($host, '.' . $suffix))
                : $host === $domain) {
                return;
            }
        }

        throw new BootException(sprintf(
            '%s groups routes under domain [%s], which this project does not serve. '
            . 'Registered domains (proj.json "domains"): %s. Add it there, use a wildcard '
            . 'like [*.%s], or declare a bare "subdomain" if the routes should answer on '
            . 'every domain.',
            $context,
            $domain,
            implode(', ', $this->projectDomains),
            ltrim(strstr($domain, '.') ?: $domain, '.'),
        ));
    }
}
