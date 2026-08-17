<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootStamp;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestWriter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The opt-in boot cache.
 *
 * `build()` has no idempotence, so under PHP-FPM every request recompiles
 * manifests byte-identical to the last request's. This decides when that work
 * can be skipped — and, far more importantly, when it CANNOT.
 */
#[CoversClass(BootStamp::class)]
final class BootStampTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;
    private string|false $previousEnv = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-bootstamp-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);
        mkdir($this->root . '/config', 0775, true);

        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);

        $this->previousEnv = $_ENV['BOOT_CACHE'] ?? false;

        // A compiled manifest must exist for a cached boot to be usable at all.
        ManifestWriter::write('route-manifest.php', ['GET /' => ['handler' => 'C@m']]);
    }

    protected function tearDown(): void
    {
        Paths::setProject($this->previousProject);

        if ($this->previousEnv === false) {
            unset($_ENV['BOOT_CACHE']);
        } else {
            $_ENV['BOOT_CACHE'] = $this->previousEnv;
        }

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->root . '/config/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root . '/config');
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    private function hash(mixed $inputs = ['modules' => ['A']]): string
    {
        return BootStamp::hash(is_array($inputs) ? $inputs : [$inputs]);
    }

    // ── The flag ────────────────────────────────────────────────────────────

    public function test_it_is_off_unless_explicitly_enabled(): void
    {
        unset($_ENV['BOOT_CACHE']);
        self::assertFalse(BootStamp::enabled());

        $_ENV['BOOT_CACHE'] = '';
        self::assertFalse(BootStamp::enabled());

        $_ENV['BOOT_CACHE'] = '0';
        self::assertFalse(BootStamp::enabled(), 'a falsy value must not enable it');
    }

    public function test_it_is_on_for_a_truthy_value(): void
    {
        foreach (['1', 'true', 'on', 'yes'] as $value) {
            $_ENV['BOOT_CACHE'] = $value;
            self::assertTrue(BootStamp::enabled(), "[{$value}] should enable the cache");
        }
    }

    // ── Hit ─────────────────────────────────────────────────────────────────

    public function test_a_fresh_stamp_is_a_hit_and_returns_the_cached_essentials(): void
    {
        BootStamp::write($this->hash(), [], ['App\\SessionProvider']);

        $cached = BootStamp::read($this->hash());

        self::assertNotNull($cached);
        // Recomputing these means re-reading every module.json — the exact cost
        // the cache exists to avoid — so they ride along with it.
        self::assertSame(['App\\SessionProvider'], $cached['essentials']);
    }

    public function test_no_stamp_at_all_is_a_miss(): void
    {
        self::assertNull(BootStamp::read($this->hash()));
    }

    // ── Every way it must MISS ──────────────────────────────────────────────

    public function test_changed_builder_inputs_miss(): void
    {
        // proj.json and bootstrap/app.php reach the kernel as PHP arrays, so this
        // covers edits to both without stat'ing either.
        BootStamp::write($this->hash(['modules' => ['A']]), [], []);

        self::assertNull(BootStamp::read($this->hash(['modules' => ['A', 'B']])));
    }

    public function test_a_modified_source_file_misses(): void
    {
        $file = $this->root . '/module.json';
        file_put_contents($file, '{"solves":"a"}');

        BootStamp::write($this->hash(), [$file], []);
        self::assertNotNull(BootStamp::read($this->hash()));

        file_put_contents($file, '{"solves":"a","routes":[]}');
        clearstatcache();

        self::assertNull(BootStamp::read($this->hash()), 'size changed');
    }

    public function test_a_deleted_source_file_misses(): void
    {
        $file = $this->root . '/module.json';
        file_put_contents($file, '{"solves":"a"}');
        BootStamp::write($this->hash(), [$file], []);

        unlink($file);
        clearstatcache();

        self::assertNull(BootStamp::read($this->hash()));
    }

    public function test_a_modified_config_file_misses(): void
    {
        file_put_contents($this->root . '/config/mail.php', '<?php return ["from" => "a"];');
        BootStamp::write($this->hash(), [], []);
        self::assertNotNull(BootStamp::read($this->hash()));

        file_put_contents($this->root . '/config/mail.php', '<?php return ["from" => "bbbbb"];');
        clearstatcache();

        self::assertNull(BootStamp::read($this->hash()));
    }

    public function test_an_ADDED_config_file_misses(): void
    {
        // The subtle one: a new file appears in no recorded entry, so only the
        // per-directory count catches it.
        file_put_contents($this->root . '/config/mail.php', '<?php return [];');
        BootStamp::write($this->hash(), [], []);
        self::assertNotNull(BootStamp::read($this->hash()));

        file_put_contents($this->root . '/config/queue.php', '<?php return [];');
        clearstatcache();

        self::assertNull(BootStamp::read($this->hash()));
    }

    public function test_a_REMOVED_config_file_misses(): void
    {
        file_put_contents($this->root . '/config/mail.php', '<?php return [];');
        file_put_contents($this->root . '/config/queue.php', '<?php return [];');
        BootStamp::write($this->hash(), [], []);

        unlink($this->root . '/config/queue.php');
        clearstatcache();

        self::assertNull(BootStamp::read($this->hash()));
    }

    public function test_a_missing_compiled_manifest_misses(): void
    {
        // The stamp may be pristine while the manifests it vouches for were
        // cleared by a deploy. Never serve from a cache with nothing behind it.
        BootStamp::write($this->hash(), [], []);
        unlink(Paths::cache('manifests/route-manifest.php'));

        self::assertNull(BootStamp::read($this->hash()));
    }

    public function test_a_plugin_config_directory_beside_a_module_json_is_watched(): void
    {
        // BootStamp derives each plugin's config/ from where its module.json is,
        // because that is what CompileConfigManifestStage globs.
        mkdir($this->root . '/plugin/config', 0775, true);
        $module = $this->root . '/plugin/module.json';
        file_put_contents($module, '{"solves":"a"}');
        file_put_contents($this->root . '/plugin/config/thing.php', '<?php return [];');

        BootStamp::write($this->hash(), [$module], []);
        self::assertNotNull(BootStamp::read($this->hash()));

        file_put_contents($this->root . '/plugin/config/thing.php', '<?php return ["changed" => true];');
        clearstatcache();
        self::assertNull(BootStamp::read($this->hash()));

        @unlink($this->root . '/plugin/config/thing.php');
        @unlink($module);
        @rmdir($this->root . '/plugin/config');
        @rmdir($this->root . '/plugin');
    }
}
