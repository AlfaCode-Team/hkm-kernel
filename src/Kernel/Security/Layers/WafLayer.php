<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{CachePort, ClockPort, LoggerPort, SystemClock};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\PortAwareLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Contracts\WafRuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Rules\{InjectionSignatureRule, MalformedRequestRule, PathTraversalRule, ScannerProbeRule};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\{WafMatch, WafMode};
use Psr\Container\ContainerInterface;

/**
 * WafLayer — an application firewall in the SecurityGateway.
 *
 * It runs before any module loads, so a refused request costs a few regex
 * passes and nothing else. Opt in by listing it in `withSecurity([...])`, FIRST,
 * ahead of CsrfTokenLayer: it is cheap, and a scanner should be turned away
 * before anything else spends time on it.
 *
 *   ->withSecurity([
 *       new WafLayer(mode: WafMode::Monitor),   // watch the log, then switch to Block
 *       new CsrfTokenLayer(...),
 *   ])
 *
 * The default rules ({@see defaultRules()}), cheapest first:
 *   malformed  — request shapes no client produces (NUL bytes, smuggling, …)
 *   traversal  — `..` segments, climbs to system dirs, stream wrappers
 *   probe      — scanner paths: dotfiles, WordPress, phpMyAdmin, backups, …
 *   injection  — SQLi / XSS / Log4Shell / PHP-code signatures (no body by default)
 *
 * WHAT IT IS NOT. It is not a substitute for bound parameters or output
 * escaping, and it is not a network WAF: by the time it runs, PHP has already
 * accepted the connection. Volumetric abuse belongs at the edge (nginx, a CDN);
 * per-route rate limiting is the `throttle` route filter.
 *
 * BANS (opt-in, `banAfter > 0`, needs a CachePort). Every match is a strike
 * against the client IP; `banAfter` strikes inside `banWindow` seconds ban it for
 * `banSeconds`. Off by default for a concrete reason: carrier-grade NAT puts
 * thousands of mobile users behind one address, and one scanner among them would
 * lock all of them out. Turn it on where clients have their own addresses.
 *
 * FAILURE. The layer fails OPEN: if a rule or the cache throws, the request is
 * let through and the error is logged. A firewall whose cache outage takes the
 * whole site down has become the attack.
 *
 * The 403 body never says which rule fired. The log does.
 */
final class WafLayer implements PortAwareLayerContract
{
    /** @var list<array{WafRuleContract, list<string>}> each rule with the normalised paths it skips */
    private readonly array $rules;

    /** @var list<string> normalised {@see $exemptPaths} */
    private readonly array $exempt;

    /** @var list<array{string, int}> trusted networks as [packed address, prefix bits] */
    private readonly array $trusted;

    private ?ContainerInterface $ports = null;

    /**
     * @param list<WafRuleContract>|null $rules       null = {@see defaultRules()}
     * @param list<string>               $exemptPaths path prefixes the WAF never inspects (segment-aware)
     * @param array<string, list<string>> $ruleExemptPaths rule id => path prefixes that rule skips,
     *                                                e.g. ['injection' => ['/admin/posts']]
     * @param list<string>               $trustedIps  IPs or CIDR ranges that bypass the WAF (monitoring, office).
     *                                                An entry that does not parse is ignored, never "trust all".
     * @param int                        $banAfter    strikes before an IP is banned; 0 = never ban.
     *                                                IPv6 clients are banned by /64: one subscriber holds the
     *                                                whole prefix and can rotate through it at will.
     * @param int                        $banWindow   seconds the strikes are counted over
     * @param int                        $banSeconds  how long a ban lasts
     * @param CachePort|null             $cache       defaults to the app's CachePort, if one is bound
     * @param LoggerPort|null            $logger      defaults to the app's LoggerPort, if one is bound
     */
    public function __construct(
        ?array $rules = null,
        private readonly WafMode $mode = WafMode::Block,
        array $exemptPaths = [],
        array $ruleExemptPaths = [],
        array $trustedIps = [],
        private readonly int $banAfter = 0,
        private readonly int $banWindow = 600,
        private readonly int $banSeconds = 900,
        private ?CachePort $cache = null,
        private ?LoggerPort $logger = null,
        private readonly ?ClockPort $clock = null,
    ) {
        // Everything a request would otherwise recompute — lower-casing prefixes,
        // parsing CIDR ranges — is done once here. Under OpenSwoole the layer
        // lives for the worker; under FPM it is still once per request, not once
        // per rule per request.
        $this->exempt  = self::normalisePrefixes($exemptPaths);
        $this->trusted = self::parseNetworks($trustedIps);

        $compiled = [];
        foreach ($rules ?? self::defaultRules() as $rule) {
            $compiled[] = [$rule, self::normalisePrefixes($ruleExemptPaths[$rule->id()] ?? [])];
        }
        $this->rules = $compiled;
    }

    /** @return list<WafRuleContract> */
    public static function defaultRules(): array
    {
        return [
            new MalformedRequestRule(),
            new PathTraversalRule(),
            new ScannerProbeRule(),
            new InjectionSignatureRule(),
        ];
    }

    public function usePorts(ContainerInterface $ports): void
    {
        $this->ports = $ports;
    }

    public function check(Request $request): SecurityVerdict
    {
        try {
            return $this->inspect($request);
        } catch (\Throwable $e) {
            // Fail open (see the class docblock). Logging is itself guarded: a
            // broken logger must not turn into a broken request.
            try {
                $this->logger()?->error('waf.error', [
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                    'path'      => $request->path(),
                ]);
            } catch (\Throwable) {
            }

            return SecurityVerdict::allow($request);
        }
    }

    private function inspect(Request $request): SecurityVerdict
    {
        $path = strtolower($request->path());
        if ($this->exempt !== [] && self::underAny($path, $this->exempt)) {
            return SecurityVerdict::allow($request);
        }

        $ip = $request->ip();
        if ($ip !== null && $this->trusted !== [] && self::ipTrusted($ip, $this->trusted)) {
            return SecurityVerdict::allow($request);
        }

        $banning = $this->mode === WafMode::Block && $this->banAfter > 0 && $ip !== null;
        $client  = $banning ? self::clientKey($ip) : '';

        if ($banning && ($cache = $this->cache()) !== null && $cache->has('waf_ban:' . $client)) {
            return self::refuse();
        }

        foreach ($this->rules as [$rule, $skip]) {
            if ($skip !== [] && self::underAny($path, $skip)) {
                continue;
            }

            $match = $rule->inspect($request);
            if ($match === null) {
                continue;
            }

            $banned = $banning && $this->strike($client);
            $this->report($request, $match, $banned);

            return $this->mode === WafMode::Block ? self::refuse() : SecurityVerdict::allow($request);
        }

        return SecurityVerdict::allow($request);
    }

    /** Count a strike; ban the client on reaching the threshold. True when this strike banned it. */
    private function strike(string $client): bool
    {
        $cache = $this->cache();
        if ($cache === null) {
            return false;
        }

        $window = max(1, $this->banWindow);
        $bucket = \intdiv(($this->clock ?? new SystemClock())->timestamp(), $window);
        $key    = 'waf_strikes:' . $client . ':' . $bucket;

        // Same idiom as the throttle filter: increment() is atomic on every
        // adapter, and creates the key with no TTL, so the creator sets one.
        $count = $cache->increment($key);
        if ($count === 1) {
            $cache->set($key, 1, $window + 60);
        }

        if ($count < $this->banAfter) {
            return false;
        }

        $cache->set('waf_ban:' . $client, 1, \max(1, $this->banSeconds));

        return $count === $this->banAfter;
    }

    private function report(Request $request, WafMatch $match, bool $banned): void
    {
        $logger = $this->logger();
        if ($logger === null) {
            return;
        }

        $context = $match->toArray() + [
            'mode'      => $this->mode->value,
            // Attacker-controlled: clipped and stripped like the sample, so a
            // scanner cannot forge log lines or write 8 KB per refused request.
            'ip'        => (string) $request->ip(),
            'method'    => WafMatch::clip($request->method(), 16),
            'path'      => WafMatch::clip($request->path()),
            'userAgent' => WafMatch::clip((string) $request->userAgent()),
            'requestId' => (string) $request->attribute('correlation_id', ''),
        ];
        if ($banned) {
            $context['banned_for'] = $this->banSeconds;
        }

        $this->mode === WafMode::Block
            ? $logger->warning('waf.blocked', $context)
            : $logger->notice('waf.would_block', $context);
    }

    private static function refuse(): SecurityVerdict
    {
        return SecurityVerdict::deny(403, 'Request blocked.');
    }

    /**
     * The cache key a client's strikes and ban are filed under: the address for
     * IPv4, the /64 for IPv6. Hashed, so the key is fixed-length and no adapter
     * sees ':' from an IPv6 literal.
     */
    private static function clientKey(string $ip): string
    {
        $packed = @\inet_pton($ip);
        if ($packed !== false && \strlen($packed) === 16) {
            $ip = \substr($packed, 0, 8);
        }

        return \hash('xxh128', $ip);
    }

    private function cache(): ?CachePort
    {
        return $this->cache ??= $this->resolve(CachePort::class);
    }

    private function logger(): ?LoggerPort
    {
        return $this->logger ??= $this->resolve(LoggerPort::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $port
     * @return T|null
     */
    private function resolve(string $port): ?object
    {
        if ($this->ports === null || !$this->ports->has($port)) {
            return null;
        }

        $instance = $this->ports->get($port);

        return $instance instanceof $port ? $instance : null;
    }

    /**
     * Lower-case, drop the trailing slash; '/' becomes '' and means everything.
     * An EMPTY entry is dropped: it is a typo, not "everything" — that is '/'.
     *
     * @param list<string> $prefixes
     * @return list<string>
     */
    private static function normalisePrefixes(array $prefixes): array
    {
        $out = [];
        foreach ($prefixes as $prefix) {
            if (\is_string($prefix) && $prefix !== '') {
                $out[] = \rtrim(\strtolower($prefix), '/');
            }
        }

        return $out;
    }

    /** @param list<string> $prefixes normalised */
    private static function underAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix === '' || $path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $entries exact IPs or CIDR ranges, v4 or v6
     * @return list<array{string, int}>
     */
    private static function parseNetworks(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!\is_string($entry)) {
                continue;
            }

            [$network, $bits] = \str_contains($entry, '/')
                ? \explode('/', $entry, 2)
                : [$entry, null];

            $packed = @\inet_pton(\trim($network));
            if ($packed === false) {
                continue;
            }

            $max = \strlen($packed) * 8;
            if ($bits === null) {
                $bits = $max;
            } elseif (\ctype_digit(\trim($bits)) && (int) $bits <= $max) {
                $bits = (int) $bits;
            } else {
                continue; // "10.0.0.0/" or "/abc" would otherwise read as /0 — trust everyone
            }

            $out[] = [$packed, $bits];
        }

        return $out;
    }

    /** @param list<array{string, int}> $networks */
    private static function ipTrusted(string $ip, array $networks): bool
    {
        $address = @\inet_pton($ip);
        if ($address === false) {
            return false;
        }

        foreach ($networks as [$range, $bits]) {
            if (\strlen($range) !== \strlen($address)) {
                continue;
            }

            $bytes = \intdiv($bits, 8);
            if (\strncmp($address, $range, $bytes) !== 0) {
                continue;
            }

            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((\ord($address[$bytes]) & $mask) === (\ord($range[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
