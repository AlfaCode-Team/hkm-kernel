<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\PrefixedModule\Provider;

/**
 * BOOT_CACHE, exercised through the REAL Kernel::build().
 *
 * BootStampTest covers the stamp in isolation, which is why it could stay green
 * while the cache never actually hit: the stamp was WRITTEN under a hash of the
 * RESOLVED essential providers and READ under a hash of the RAW proj.json
 * domains, so the two never matched. The cost of that was worse than leaving the
 * flag off — every request recompiled all ten manifests AND rewrote the stamp.
 *
 * Only a round trip through build() can catch that, so these tests build twice
 * and watch whether the manifests were actually rewritten.
 */
#[CoversClass(Kernel::class)]
final class KernelBootCacheTest extends TestCase
{
    private string $root;
    private ?string $previousBase = null;
    private ?string $previousProject = null;
    private string|false $previousEnv = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-bootcache-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();
        $this->previousEnv     = $_ENV['BOOT_CACHE'] ?? false;
    }

    protected function tearDown(): void
    {
        Paths::setBase((string) $this->previousBase);
        Paths::setProject($this->previousProject);

        if ($this->previousEnv === false) {
            unset($_ENV['BOOT_CACHE']);
        } else {
            $_ENV['BOOT_CACHE'] = $this->previousEnv;
        }

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    /** @param list<string> $essentials */
    private function build(array $essentials): Kernel
    {
        return Kernel::configure()
            ->withBasePath($this->root)
            ->withProjectPath($this->root)
            ->withPorts([
                DatabasePort::class => self::database(),
                CachePort::class    => self::cache(),
            ])
            ->withSecurity([self::securityLayer()])
            ->withModules([Provider::class])
            ->withEssentialModules($essentials)
            ->build();
    }

    /**
     * Whether the SECOND build recompiled. ManifestWriter renames a fresh temp
     * file into place, so a rewrite always changes the inode — which mtime, at
     * one-second granularity, would miss entirely inside one test run.
     *
     * @param list<string> $essentials
     */
    private function secondBuildRecompiled(array $essentials): bool
    {
        $manifest = $this->root . '/var/cache/manifests/route-manifest.php';

        $this->build($essentials);
        clearstatcache(true, $manifest);
        $before = fileinode($manifest);

        $this->build($essentials);
        clearstatcache(true, $manifest);

        return fileinode($manifest) !== $before;
    }

    // ── The regression ──────────────────────────────────────────────────────

    public function test_essentials_declared_as_domains_still_hit_the_cache(): void
    {
        $_ENV['BOOT_CACHE'] = '1';

        // 'prefixed.demo' is the fixture's solves domain — the shape proj.json
        // "essentials" uses, and the shape the docs recommend. Hashing the
        // resolved provider class instead made this case miss forever.
        self::assertFalse(
            $this->secondBuildRecompiled(['prefixed.demo']),
            'a second build with domain-form essentials must skip the compile',
        );
    }

    public function test_it_hits_with_no_essentials_at_all(): void
    {
        $_ENV['BOOT_CACHE'] = '1';

        self::assertFalse($this->secondBuildRecompiled([]));
    }

    public function test_it_hits_with_essentials_already_given_as_classes(): void
    {
        $_ENV['BOOT_CACHE'] = '1';

        self::assertFalse($this->secondBuildRecompiled([Provider::class]));
    }

    // ── The flag still has to mean something ────────────────────────────────

    public function test_it_recompiles_every_build_when_the_flag_is_off(): void
    {
        unset($_ENV['BOOT_CACHE']);

        self::assertTrue(
            $this->secondBuildRecompiled(['prefixed.demo']),
            'without BOOT_CACHE every build must recompile — that is the default',
        );
    }

    public function test_changing_a_builder_input_invalidates_the_cache(): void
    {
        $_ENV['BOOT_CACHE'] = '1';

        $manifest = $this->root . '/var/cache/manifests/route-manifest.php';

        $this->build([]);
        clearstatcache(true, $manifest);
        $before = fileinode($manifest);

        // A different project route is a different application, cache or not.
        Kernel::configure()
            ->withBasePath($this->root)
            ->withProjectPath($this->root)
            ->withPorts([DatabasePort::class => self::database(), CachePort::class => self::cache()])
            ->withSecurity([self::securityLayer()])
            ->withModules([Provider::class])
            ->withRoutes([['method' => 'GET', 'path' => '/added', 'handler' => 'A\\C@index']])
            ->build();

        clearstatcache(true, $manifest);
        self::assertNotSame($before, fileinode($manifest));
    }

    // ── Port stubs ──────────────────────────────────────────────────────────

    private static function database(): DatabasePort
    {
        return new class implements DatabasePort {
            public function query(string $sql, array $params = []): array { return []; }
            public function queryOne(string $sql, array $params = []): ?array { return null; }
            public function execute(string $sql, array $params = []): int { return 0; }
            public function upsert(string $t, array $v, array $c, ?array $u = null): int { return 0; }
            public function lastInsertId(?string $sequence = null): string { return '1'; }
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollback(): void {}
            public function inTransaction(): bool { return false; }
            public function driver(): string { return 'sqlite'; }
        };
    }

    private static function cache(): CachePort
    {
        return new class implements CachePort {
            public function get(string $key): mixed { return null; }
            public function set(string $key, mixed $value, ?int $ttl = null): bool { return true; }
            public function delete(string $key): bool { return true; }
            public function has(string $key): bool { return false; }
            public function remember(string $key, int $ttl, callable $callback): mixed { return $callback(); }
            public function increment(string $key, int $by = 1): int { return $by; }
            public function deletePattern(string $pattern): int { return 0; }
            public function flush(): bool { return true; }
            public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
            {
                throw new \RuntimeException('not needed for boot');
            }
            public function restoreLock(string $name, string $owner): Lock
            {
                throw new \RuntimeException('not needed for boot');
            }
        };
    }

    private static function securityLayer(): SecurityLayerContract
    {
        return new class implements SecurityLayerContract {
            public function check(Request $request): SecurityVerdict
            {
                return SecurityVerdict::allow($request);
            }
        };
    }
}
