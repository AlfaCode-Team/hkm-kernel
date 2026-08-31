<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\Ground\Init;
use PHPUnit\Framework\TestCase;

/**
 * `ground init` — and above all, what it keeps OUT of git.
 *
 * The .gitignore half is the part worth testing hardest. Everything it lists is
 * machine-specific, and one of them holds database passwords; a pattern that
 * looks right and does not match is indistinguishable from a working one until
 * the day someone runs `git add -A`.
 */
final class InitTest extends TestCase
{
    private string $plugin = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = sys_get_temp_dir() . '/ground-init-' . bin2hex(random_bytes(6));
        mkdir($this->plugin, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->plugin . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @unlink($this->plugin . '/phpunit.xml');
        self::removeTree($this->plugin);

        parent::tearDown();
    }

    // ── .gitignore ────────────────────────────────────────────────────────────

    /**
     * The regression this file exists for.
     *
     * The first version wrote `composer.local.json    # local dev manifest` —
     * aligned, readable, and WRONG: .gitignore has no inline comments, so the
     * pattern was the whole line and matched nothing. `git check-ignore` said
     * NOT IGNORED for every entry, including the one holding passwords.
     */
    public function testNoIgnoreLineCarriesAnInlineComment(): void
    {
        (new Init($this->plugin, 'demo'))->ignoreLocalFiles();

        foreach (explode("\n", (string) file_get_contents($this->plugin . '/.gitignore')) as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            self::assertStringNotContainsString(
                '#',
                $line,
                "A pattern with a trailing comment matches nothing: [{$line}]",
            );
        }
    }

    public function testEveryLocalPathIsListed(): void
    {
        (new Init($this->plugin, 'demo'))->ignoreLocalFiles();
        $written = (string) file_get_contents($this->plugin . '/.gitignore');

        foreach (array_keys(Init::IGNORED) as $entry) {
            self::assertStringContainsString($entry, $written);
        }
    }

    /** The credentials file is the one that must never be missed. */
    public function testTheDatabaseCredentialsFileIsIgnored(): void
    {
        (new Init($this->plugin, 'demo'))->ignoreLocalFiles();

        self::assertContains(
            'ground.databases.json',
            explode("\n", (string) file_get_contents($this->plugin . '/.gitignore')),
        );
    }

    public function testRunningTwiceAddsNothingTheSecondTime(): void
    {
        $init = new Init($this->plugin, 'demo');

        self::assertNotSame([], $init->ignoreLocalFiles());
        self::assertSame([], $init->ignoreLocalFiles(), 'init must be idempotent.');
    }

    /** An entry already present — however written — is not duplicated. */
    public function testAnExistingEntryIsRecognised(): void
    {
        file_put_contents($this->plugin . '/.gitignore', "vendor/\nground.databases.json\n");

        $added = (new Init($this->plugin, 'demo'))->ignoreLocalFiles();

        self::assertNotContains('ground.databases.json', $added);
        self::assertNotContains('/vendor/', $added);
    }

    // ── Committed files ───────────────────────────────────────────────────────

    public function testItWritesAPhpunitConfigNamedAfterThePlugin(): void
    {
        $init = new Init($this->plugin, 'file-manager');
        $init->phpunitConfig();

        self::assertContains('phpunit.xml', $init->wrote());
        self::assertStringContainsString(
            'name="FileManager"',
            (string) file_get_contents($this->plugin . '/phpunit.xml'),
        );
    }

    /** Never overwrite what the author already has. */
    public function testAnExistingPhpunitConfigIsKept(): void
    {
        file_put_contents($this->plugin . '/phpunit.xml', '<phpunit/>');

        $init = new Init($this->plugin, 'demo');
        $init->phpunitConfig();

        self::assertContains('phpunit.xml', $init->kept());
        self::assertSame('<phpunit/>', (string) file_get_contents($this->plugin . '/phpunit.xml'));
    }

    public function testTheCiWorkflowSuppliesRealDatabasesAndDemandsThemStrictly(): void
    {
        (new Init($this->plugin, 'demo'))->ciWorkflow();
        $yaml = (string) file_get_contents($this->plugin . '/.github/workflows/ground.yml');

        // Without services CI would test SQLite only, which is what the
        // migration matrix exists to stop being the whole story.
        self::assertStringContainsString('GROUND_DB_MYSQL', $yaml);
        self::assertStringContainsString('GROUND_DB_PGSQL', $yaml);
        self::assertStringContainsString('migrate --strict', $yaml);
    }

    // ── Detection ─────────────────────────────────────────────────────────────

    public function testItDetectsAPluginWithNoTests(): void
    {
        self::assertFalse((new Init($this->plugin, 'demo'))->hasTests());
    }

    public function testItDetectsMigrations(): void
    {
        $init = new Init($this->plugin, 'demo');
        self::assertFalse($init->hasMigrations());

        mkdir($this->plugin . '/database/migrations', 0o700, true);
        file_put_contents($this->plugin . '/database/migrations/2026_01_01_x.php', '<?php');

        self::assertTrue($init->hasMigrations());
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
