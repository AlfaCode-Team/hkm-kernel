<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Edge\Domain\CacheProfile;
use Plugins\Edge\Domain\ServeModel;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Site;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Domain\TlsConfig;
use Plugins\Edge\Domain\TlsMode;
use Plugins\Edge\Infrastructure\ConfigRenderer;

/**
 * Regression coverage for the nginx config generator — the DEVELOPMENT and
 * PRODUCTION profiles across every --tls mode. See the bug catalogue in the Edge
 * plugin README / USAGE.
 */
final class NginxConfigRendererTest extends TestCase
{
    private function site(): Site
    {
        return new Site(
            name: 'hkmstd',
            docroot: '/srv/hkmstd/app/public',
            publicDomains: ['hkmstd.com', 'www.hkmstd.com'],
            localDomains: [],
            model: ServeModel::Fpm,
            upstream: 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production'],
            root: '/srv/hkmstd',
        );
    }

    private function stack(bool $streamRouter = false): ServerStack
    {
        // nginx-only (Apache inactive), no brotli → deterministic gzip output.
        // $streamRouter models a host already running an SNI stream splitter on :443.
        return new ServerStack(true, true, false, false, false, false, [], $streamRouter);
    }

    private function render(CacheProfile $profile, TlsMode $mode, bool $streamRouter = false): string
    {
        $tls = new TlsConfig($mode, '/etc/ssl/certs/x.pem', '/etc/ssl/private/x.key');
        [, $body] = (new ConfigRenderer())->render(Strategy::NginxOnly, [$this->site()], $tls, $this->stack($streamRouter), $profile);

        return $body;
    }

    /** @return array<string, array{CacheProfile, TlsMode}> */
    public static function matrix(): array
    {
        return [
            'dev ssl'   => [CacheProfile::Development, TlsMode::Ssl],
            'dev none'  => [CacheProfile::Development, TlsMode::None],
            'dev both'  => [CacheProfile::Development, TlsMode::Both],
            'prod ssl'  => [CacheProfile::Production, TlsMode::Ssl],
            'prod none' => [CacheProfile::Production, TlsMode::None],
            'prod both' => [CacheProfile::Production, TlsMode::Both],
        ];
    }

    // ── Bug 1: --tls=both redirect + ACME ─────────────────────────────────────

    #[DataProvider('matrix')]
    public function test_tls_both_emits_redirect_and_acme_others_do_not(CacheProfile $profile, TlsMode $mode): void
    {
        $out = $this->render($profile, $mode);

        if ($mode === TlsMode::Both) {
            self::assertStringContainsString('listen 80;', $out, 'both must open a :80 listener');
            self::assertStringContainsString('return 301 https://$host$request_uri;', $out);
            self::assertStringContainsString('location ^~ /.well-known/acme-challenge/', $out);
            // ACME location precedes the redirect.
            self::assertLessThan(
                strpos($out, 'return 301'),
                strpos($out, 'acme-challenge'),
                'ACME passthrough must come before the redirect',
            );
        }
        if ($mode === TlsMode::Ssl) {
            self::assertStringNotContainsString('listen 80;', $out);
            self::assertStringNotContainsString('return 301', $out);
        }
        if ($mode === TlsMode::None) {
            // Plain HTTP serves on :80 directly — no redirect, no ACME passthrough.
            self::assertStringNotContainsString('return 301', $out);
            self::assertStringNotContainsString('acme-challenge', $out);
            self::assertStringContainsString('listen 80;', $out);
        }
    }

    public function test_tls_none_serves_plain_http_only(): void
    {
        $out = $this->render(CacheProfile::Production, TlsMode::None);
        self::assertStringContainsString('listen 80;', $out);
        self::assertStringNotContainsString('listen 443', $out);
        self::assertStringNotContainsString('ssl_certificate', $out);
        self::assertStringNotContainsString('Strict-Transport-Security', $out, 'no HSTS on plain HTTP');
    }

    // ── Bug 3: every header-bearing location emits the full set incl security ──

    #[DataProvider('matrix')]
    public function test_index_php_and_asset_locations_repeat_security_headers(CacheProfile $profile, TlsMode $mode): void
    {
        $out = $this->render($profile, $mode);

        // The front-controller location declares add_header of its own, so it MUST
        // re-emit the security set (otherwise nginx drops inherited headers).
        $indexBlock = $this->slice($out, 'location = /index.php {', '}');
        self::assertStringContainsString('X-Content-Type-Options', $indexBlock);
        self::assertStringContainsString('X-Frame-Options', $indexBlock);
        self::assertStringContainsString('Referrer-Policy', $indexBlock);

        $assetBlock = $this->slice($out, 'location ~* \.(css', '}');
        self::assertStringContainsString('X-Content-Type-Options', $assetBlock);
    }

    public function test_cors_is_off_by_default_and_never_wildcard(): void
    {
        $out = $this->render(CacheProfile::Development, TlsMode::Ssl);
        self::assertStringNotContainsString('Access-Control-Allow-Origin "*"', $out, 'wildcard CORS must be opt-in');
        self::assertStringNotContainsString('Access-Control-Allow-Origin', $out, 'CORS off by default');
    }

    // ── Bug 4: HSTS is short in dev, long in prod ─────────────────────────────

    public function test_dev_hsts_is_short_lived_without_subdomains_or_preload(): void
    {
        $out = $this->render(CacheProfile::Development, TlsMode::Ssl);
        self::assertStringContainsString('Strict-Transport-Security "max-age=300"', $out);
        self::assertStringNotContainsString('includeSubDomains', $out);
        self::assertStringNotContainsString('preload', $out);
    }

    public function test_prod_hsts_is_long_lived_without_preload_by_default(): void
    {
        $out = $this->render(CacheProfile::Production, TlsMode::Ssl);
        self::assertStringContainsString('max-age=31536000; includeSubDomains', $out);
        self::assertStringNotContainsString('preload', $out);
    }

    // ── Bug 5: /nginx-status is dev-only ──────────────────────────────────────

    public function test_nginx_status_dev_only(): void
    {
        self::assertStringContainsString('location = /nginx-status', $this->render(CacheProfile::Development, TlsMode::Ssl));
        self::assertStringNotContainsString('nginx-status', $this->render(CacheProfile::Production, TlsMode::Ssl));
    }

    // ── Bug 6: prod asset regex drops source maps & json ──────────────────────

    public function test_prod_asset_regex_drops_map_and_json(): void
    {
        $out   = $this->render(CacheProfile::Production, TlsMode::Ssl);
        $asset = $this->slice($out, 'location ~* \.(css', ')$ {');
        self::assertStringNotContainsString('map', $asset);
        self::assertStringNotContainsString('json', $asset);
        self::assertStringContainsString('css', $asset);
    }

    public function test_dev_asset_regex_keeps_map_for_debugging(): void
    {
        $out   = $this->render(CacheProfile::Development, TlsMode::Ssl);
        $asset = $this->slice($out, 'location ~* \.(css', ')$ {');
        self::assertStringContainsString('map', $asset);
    }

    public function test_prod_denies_source_maps_not_just_uncaches_them(): void
    {
        // Removing `.map` from the static rule is NOT enough — location / falls
        // through to try_files and serves any file that exists. It must be DENIED.
        $out  = $this->render(CacheProfile::Production, TlsMode::Ssl);
        $deny = $this->slice($out, 'location ~* \.(env', ')$ {');
        self::assertStringContainsString('|map)', $deny, 'prod must DENY .map');
    }

    public function test_dev_does_not_deny_maps(): void
    {
        $out  = $this->render(CacheProfile::Development, TlsMode::Ssl);
        $deny = $this->slice($out, 'location ~* \.(env', ')$ {');
        self::assertStringNotContainsString('|map)', $deny);
    }

    // ── Per-site logging (both profiles) ──────────────────────────────────────

    #[DataProvider('matrix')]
    public function test_per_site_logs_emitted_in_both_profiles(CacheProfile $profile, TlsMode $mode): void
    {
        $out = $this->render($profile, $mode);
        self::assertStringContainsString('access_log /var/log/nginx/hkmstd.access.log', $out);
        self::assertStringContainsString('error_log  /var/log/nginx/hkmstd.error.log', $out);
    }

    // ── Port collision with an SNI stream router ──────────────────────────────

    public function test_nginx_only_listens_on_internal_port_behind_sni_router(): void
    {
        // The host already runs a `stream {}` splitter on :443 → the vhost must
        // NOT bind :443 (nginx would fail: Address already in use). It listens on
        // the internal backend port (444) instead.
        $out = $this->render(CacheProfile::Production, TlsMode::Ssl, streamRouter: true);
        self::assertStringContainsString('listen 444 ssl;', $out);
        self::assertStringContainsString('listen [::]:444 ssl;', $out);
        self::assertStringNotContainsString('listen 443 ssl;', $out);
    }

    public function test_nginx_only_binds_443_standalone(): void
    {
        $out = $this->render(CacheProfile::Production, TlsMode::Ssl, streamRouter: false);
        self::assertStringContainsString('listen 443 ssl;', $out);
        self::assertStringNotContainsString('listen 444 ssl;', $out);
    }

    public function test_redirect_targets_public_port_even_behind_router(): void
    {
        // Vhost listens on 444, but the :80→HTTPS redirect must point at the PUBLIC
        // https port (443, portless), not the internal 444.
        $out = $this->render(CacheProfile::Production, TlsMode::Both, streamRouter: true);
        self::assertStringContainsString('listen 444 ssl;', $out);
        self::assertStringContainsString('return 301 https://$host$request_uri;', $out);
        self::assertStringNotContainsString(':444$request_uri', $out);
    }

    // ── Hardening: TLS pinning, deny lists, method guard, opt-in debug ────────

    public function test_tls_modes_emit_explicit_protocol_and_cipher_pinning(): void
    {
        $out = $this->render(CacheProfile::Production, TlsMode::Ssl);
        self::assertStringContainsString('ssl_protocols TLSv1.2 TLSv1.3;', $out);
        self::assertStringContainsString('ssl_ciphers ', $out);
        self::assertStringContainsString('ssl_session_tickets off;', $out);
        self::assertStringNotContainsString('ssl_stapling on', $out, 'stapling off by default (Origin CA)');
    }

    public function test_sensitive_files_and_dirs_are_denied(): void
    {
        $out = $this->render(CacheProfile::Production, TlsMode::Ssl);
        self::assertMatchesRegularExpression('/location ~\\* \\\\\\.\\(env\\|log\\|sql/', $out);
        // Directories use ^~ prefix locations (order-independent, cannot be shadowed).
        self::assertStringContainsString('location ^~ /vendor/ { deny all;', $out);
        self::assertStringContainsString('location ^~ /node_modules/ { deny all;', $out);
    }

    public function test_storage_is_not_denied_by_default(): void
    {
        // A Laravel-style public/storage symlink serves intended uploads — a
        // blanket deny would break it, so storage stays out of the default list.
        self::assertStringNotContainsString('location ^~ /storage/', $this->render(CacheProfile::Production, TlsMode::Ssl));
    }

    /**
     * The deny rules MUST precede the static-asset regex, or a file inside a
     * denied directory with a whitelisted extension (vendor/composer/installed.json)
     * would be served by the static rule instead — regex locations are first-match
     * in file order.
     */
    #[DataProvider('matrix')]
    public function test_deny_rules_precede_static_asset_location(CacheProfile $profile, TlsMode $mode): void
    {
        $out = $this->render($profile, $mode);

        $denyExt   = strpos($out, 'location ~* \\.(env|log|sql');
        $denyDot   = strpos($out, 'location ~ /\\.(?!well-known)');
        $staticLoc = strpos($out, 'location ~* \\.(css|js');

        self::assertNotFalse($denyExt);
        self::assertNotFalse($denyDot);
        self::assertNotFalse($staticLoc);
        self::assertLessThan($staticLoc, $denyExt, 'sensitive-ext deny must come before static');
        self::assertLessThan($staticLoc, $denyDot, 'dotfile deny must come before static');
    }

    #[DataProvider('matrix')]
    public function test_ipv4_and_ipv6_listeners_are_consistent(CacheProfile $profile, TlsMode $mode): void
    {
        $out = $this->render($profile, $mode);

        if ($mode->usesTls()) {
            self::assertStringContainsString('listen 443 ssl;', $out);
            self::assertStringContainsString('listen [::]:443 ssl;', $out, 'IPv6 :443 must match IPv4');
        }
        if ($mode === TlsMode::Both || $mode === TlsMode::None) {
            self::assertStringContainsString('listen 80;', $out);
            self::assertStringContainsString('listen [::]:80;', $out);
        }
    }

    public function test_method_guard_present(): void
    {
        $out = $this->render(CacheProfile::Production, TlsMode::Ssl);
        self::assertStringContainsString('if ($request_method !~ ^(GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS)$) { return 405; }', $out);
    }

    public function test_debug_log_is_not_the_default(): void
    {
        $out = $this->render(CacheProfile::Development, TlsMode::Ssl);
        self::assertStringNotContainsString('.error.log debug;', $out, 'debug log must be opt-in');
        self::assertStringContainsString('.error.log warn;', $out);
    }

    // ── Must NOT regress: the front-controller lockdown + cache guards ─────────

    #[DataProvider('matrix')]
    public function test_front_controller_lockdown_is_byte_stable(CacheProfile $profile, TlsMode $mode): void
    {
        $out = $this->render($profile, $mode);
        self::assertStringContainsString('fastcgi_param SCRIPT_FILENAME $document_root/index.php;', $out);
        self::assertStringContainsString('location ~ \\.php$ { return 404; }', $out);
        self::assertStringContainsString('fastcgi_cache off;', $out);
        self::assertStringContainsString('fastcgi_no_cache $cookie_PHPSESSID;', $out);
        self::assertStringContainsString('fastcgi_cache_bypass $cookie_PHPSESSID;', $out);
        self::assertStringContainsString('# Managed by the HKM Edge plugin', $out);
    }

    public function test_dev_caching_is_disabled(): void
    {
        $out = $this->render(CacheProfile::Development, TlsMode::Ssl);
        self::assertStringContainsString('Cache-Control "no-store, no-cache, must-revalidate"', $out);
        self::assertStringContainsString('expires off;', $out);
    }

    /** Substring between the first $start and the next $end after it. */
    private function slice(string $haystack, string $start, string $end): string
    {
        $a = strpos($haystack, $start);
        self::assertNotFalse($a, "marker not found: {$start}");
        $b = strpos($haystack, $end, $a + strlen($start));
        self::assertNotFalse($b, "closing marker not found: {$end}");

        return substr($haystack, $a, $b - $a + strlen($end));
    }
}
