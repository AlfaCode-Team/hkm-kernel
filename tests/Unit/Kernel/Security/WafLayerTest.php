<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{CachePort, ClockPort, LoggerPort};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\WafLayer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Contracts\WafRuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\{WafMatch, WafMode};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Support\Fakes\ArrayCache;

#[CoversClass(WafLayer::class)]
final class WafLayerTest extends TestCase
{
    private const SCANNER = '203.0.113.7';

    private static function from(string $ip, string $path = '/', array $query = []): Request
    {
        return Request::build('GET', $path, query: $query, server: ['REMOTE_ADDR' => $ip]);
    }

    /** @return LoggerPort&object{lines: list<array{string, string, array}>} */
    private static function logger(): LoggerPort
    {
        return new class implements LoggerPort {
            /** @var list<array{string, string, array}> */
            public array $lines = [];

            public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
            public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message, $context); }
            public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message, $context); }
            public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message, $context); }
            public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message, $context); }
            public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message, $context); }
            public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message, $context); }
            public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message, $context); }

            public function log(string $level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = [$level, (string) $message, $context];
            }
        };
    }

    private static function clock(ArrayCache $cache): ClockPort
    {
        return new class ($cache) implements ClockPort {
            public function __construct(private readonly ArrayCache $cache) {}
            public function now(): \DateTimeImmutable { return (new \DateTimeImmutable())->setTimestamp($this->cache->now); }
            public function timestamp(): int { return $this->cache->now; }
        };
    }

    public function test_a_clean_request_is_allowed(): void
    {
        self::assertTrue((new WafLayer())->check(self::from('198.51.100.1', '/events', ['q' => 'awards']))->isAllowed());
    }

    public function test_block_mode_refuses_without_naming_the_rule_and_logs_it(): void
    {
        $logger  = self::logger();
        $verdict = (new WafLayer(logger: $logger))->check(self::from(self::SCANNER, '/.env'));

        self::assertTrue($verdict->isDenied());
        self::assertSame(403, $verdict->statusCode());
        self::assertSame('Request blocked.', $verdict->reason());

        [$level, $message, $context] = $logger->lines[0];
        self::assertSame(['warning', 'waf.blocked'], [$level, $message]);
        self::assertSame('probe', $context['rule']);
        self::assertSame('dotfile', $context['signature']);
        self::assertSame(self::SCANNER, $context['ip']);
    }

    public function test_monitor_mode_lets_the_request_through_and_logs_it(): void
    {
        $logger  = self::logger();
        $verdict = (new WafLayer(mode: WafMode::Monitor, logger: $logger))->check(self::from(self::SCANNER, '/wp-login.php'));

        self::assertTrue($verdict->isAllowed());
        self::assertSame(['notice', 'waf.would_block'], [$logger->lines[0][0], $logger->lines[0][1]]);
    }

    public function test_exempt_paths_are_never_inspected(): void
    {
        $waf = new WafLayer(exemptPaths: ['/api/webhooks']);

        self::assertTrue($waf->check(self::from(self::SCANNER, '/api/webhooks/x', ['p' => '<script>']))->isAllowed());
        self::assertTrue($waf->check(self::from(self::SCANNER, '/api/webhooksx', ['p' => '<script>']))->isDenied());
    }

    public function test_an_empty_exempt_entry_does_not_exempt_everything(): void
    {
        self::assertTrue((new WafLayer(exemptPaths: ['']))->check(self::from(self::SCANNER, '/.env'))->isDenied());
    }

    public function test_a_rule_can_be_exempted_for_some_paths_only(): void
    {
        $waf     = new WafLayer(ruleExemptPaths: ['injection' => ['/admin/posts']]);
        $payload = ['body' => '<script>'];

        self::assertTrue($waf->check(self::from(self::SCANNER, '/admin/posts/7', $payload))->isAllowed());
        self::assertTrue($waf->check(self::from(self::SCANNER, '/search', $payload))->isDenied());
        // The other rules still run on the exempted path.
        self::assertTrue($waf->check(self::from(self::SCANNER, '/admin/posts/.env'))->isDenied());
    }

    public function test_trusted_ips_and_ranges_bypass_the_waf(): void
    {
        $waf = new WafLayer(trustedIps: ['10.0.0.0/8', '2001:db8::/32', '192.0.2.10']);

        self::assertTrue($waf->check(self::from('10.20.30.40', '/.env'))->isAllowed());
        self::assertTrue($waf->check(self::from('2001:db8::1', '/.env'))->isAllowed());
        self::assertTrue($waf->check(self::from('192.0.2.10', '/.env'))->isAllowed());
        self::assertTrue($waf->check(self::from('192.0.2.11', '/.env'))->isDenied());
        self::assertTrue($waf->check(self::from('11.0.0.1', '/.env'))->isDenied());
    }

    public function test_a_malformed_trusted_entry_trusts_nobody(): void
    {
        // '10.0.0.0/' and '10.0.0.0/abc' used to read as /0 — every IPv4 address.
        $waf = new WafLayer(trustedIps: ['10.0.0.0/', '10.0.0.0/abc', 'not-an-ip', '10.0.0.0/33']);

        self::assertTrue($waf->check(self::from('198.51.100.1', '/.env'))->isDenied());
    }

    public function test_ipv6_clients_are_banned_by_their_slash_64(): void
    {
        $cache = new ArrayCache(1_800_000_000);
        $waf   = new WafLayer(banAfter: 2, cache: $cache, clock: self::clock($cache));

        $waf->check(self::from('2001:db8:1:2::a', '/.env'));
        $waf->check(self::from('2001:db8:1:2::b', '/.git'));

        self::assertTrue($waf->check(self::from('2001:db8:1:2:ffff::1', '/events'))->isDenied(), 'same /64');
        self::assertTrue($waf->check(self::from('2001:db8:1:3::a', '/events'))->isAllowed(), 'next /64');
    }

    public function test_the_logged_user_agent_is_clipped_and_stripped(): void
    {
        $logger  = self::logger();
        $request = Request::build('GET', '/.env', headers: ['User-Agent' => "evil\r\nforged" . str_repeat('x', 5000)], server: ['REMOTE_ADDR' => self::SCANNER]);

        (new WafLayer(logger: $logger))->check($request);

        $ua = $logger->lines[0][2]['userAgent'];
        self::assertStringNotContainsString("\n", $ua);
        self::assertLessThanOrEqual(204, strlen($ua));
    }

    public function test_repeated_strikes_ban_the_ip_from_clean_requests_too(): void
    {
        $cache = new ArrayCache(1_800_000_000);
        $waf   = new WafLayer(banAfter: 3, banWindow: 600, banSeconds: 900, cache: $cache, clock: self::clock($cache));

        foreach (['/.env', '/.git/config', '/wp-login.php'] as $probe) {
            self::assertTrue($waf->check(self::from(self::SCANNER, $probe))->isDenied());
        }

        self::assertTrue($waf->check(self::from(self::SCANNER, '/events'))->isDenied(), 'banned IP');
        self::assertTrue($waf->check(self::from('198.51.100.1', '/events'))->isAllowed(), 'other IPs unaffected');

        $cache->now += 901;
        self::assertTrue($waf->check(self::from(self::SCANNER, '/events'))->isAllowed(), 'ban expired');
    }

    public function test_strikes_below_the_threshold_do_not_ban(): void
    {
        $cache = new ArrayCache(1_800_000_000);
        $waf   = new WafLayer(banAfter: 3, cache: $cache, clock: self::clock($cache));

        $waf->check(self::from(self::SCANNER, '/.env'));
        $waf->check(self::from(self::SCANNER, '/.git'));

        self::assertTrue($waf->check(self::from(self::SCANNER, '/events'))->isAllowed());
    }

    public function test_bans_are_off_by_default_and_in_monitor_mode(): void
    {
        $cache = new ArrayCache();
        foreach ([new WafLayer(cache: $cache), new WafLayer(mode: WafMode::Monitor, banAfter: 1, cache: $cache)] as $waf) {
            for ($i = 0; $i < 5; $i++) {
                $waf->check(self::from(self::SCANNER, '/.env'));
            }
            self::assertTrue($waf->check(self::from(self::SCANNER, '/events'))->isAllowed());
        }
    }

    public function test_it_fails_open_and_logs_when_a_rule_throws(): void
    {
        $broken = new class implements WafRuleContract {
            public function id(): string { return 'broken'; }
            public function inspect(Request $request): ?WafMatch { throw new \RuntimeException('cache down'); }
        };
        $logger = self::logger();

        $verdict = (new WafLayer(rules: [$broken], logger: $logger))->check(self::from(self::SCANNER, '/'));

        self::assertTrue($verdict->isAllowed());
        self::assertSame(['error', 'waf.error'], [$logger->lines[0][0], $logger->lines[0][1]]);
    }

    public function test_ports_come_from_the_core_container_when_not_passed(): void
    {
        $cache  = new ArrayCache(1_800_000_000);
        $logger = self::logger();
        $core   = new CoreContainer();
        $core->instance(CachePort::class, $cache);
        $core->instance(LoggerPort::class, $logger);

        $waf = new WafLayer(banAfter: 1, clock: self::clock($cache));
        $waf->usePorts($core);

        $waf->check(self::from(self::SCANNER, '/.env'));

        self::assertTrue($waf->check(self::from(self::SCANNER, '/events'))->isDenied(), 'ban written to the core CachePort');
        self::assertNotEmpty($logger->lines);
    }

    public function test_without_any_ports_it_still_blocks(): void
    {
        $waf = new WafLayer(banAfter: 3);
        $waf->usePorts(new CoreContainer());

        self::assertTrue($waf->check(self::from(self::SCANNER, '/.env'))->isDenied());
    }
}
