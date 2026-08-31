<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\Ground\EnvFile;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's `.env` — what ground reads, and what `init` writes into it.
 *
 * The distinction these tests defend is that a COMMENTED var is absent, not
 * empty. An empty string is a value: it would override the type-correct
 * placeholder ground injects and fail the boot on a bench whose whole promise
 * is booting with no configuration.
 */
final class EnvFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ground-env-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.env');
        @rmdir($this->dir);
    }

    public function testReadsPlainAssignments(): void
    {
        $this->write("A=1\nB=two\n");

        self::assertSame(['A' => '1', 'B' => 'two'], EnvFile::read($this->dir));
    }

    /** A commented var is DELIBERATELY absent — reading it as '' undoes the point. */
    public function testCommentedVarsAreNotRead(): void
    {
        $this->write("# A=1\n#B=2\n   # C=3\nD=4\n");

        self::assertSame(['D' => '4'], EnvFile::read($this->dir));
    }

    public function testStripsOneMatchingPairOfQuotesAndTheExportPrefix(): void
    {
        $this->write("A=\"quoted\"\nB='single'\nexport C=exported\nD=pa\"ss\n");

        self::assertSame(
            ['A' => 'quoted', 'B' => 'single', 'C' => 'exported', 'D' => 'pa"ss'],
            EnvFile::read($this->dir),
        );
    }

    public function testAnEmptyValueIsReadAsAnEmptyString(): void
    {
        // Explicitly `KEY=` is a VALUE the author chose, unlike a commented one.
        $this->write("A=\n");

        self::assertSame(['A' => ''], EnvFile::read($this->dir));
    }

    public function testMissingFileIsNotAnError(): void
    {
        self::assertSame([], EnvFile::read($this->dir));
    }

    /** Vars with a default are active; everything else is commented out. */
    public function testRenderCommentsOutEveryVarWithoutADefault(): void
    {
        $rendered = EnvFile::render([$this->manifest()]);

        self::assertStringContainsString("WITH_DEFAULT=hello\n", $rendered);
        self::assertMatchesRegularExpression('/^# REQUIRED_SECRET=/m', $rendered);
        self::assertMatchesRegularExpression('/^# OPTIONAL_THING=/m', $rendered);

        // Round-trips: only the defaulted one is actually set.
        $this->write($rendered);
        self::assertSame(['WITH_DEFAULT' => 'hello'], EnvFile::read($this->dir));
    }

    /** Re-running init must never touch a value someone pasted in. */
    public function testMergeAppendsOnlyWhatIsMissing(): void
    {
        $this->write("REQUIRED_SECRET=a-real-key\n");

        $added = EnvFile::merge($this->dir, [$this->manifest()]);

        self::assertContains('WITH_DEFAULT', $added);
        self::assertNotContains('REQUIRED_SECRET', $added, 'An existing var must be left alone.');
        self::assertSame('a-real-key', EnvFile::read($this->dir)['REQUIRED_SECRET']);
    }

    /** A var the author deliberately commented out counts as present. */
    public function testMergeDoesNotUncommentAVarTheAuthorSilenced(): void
    {
        $this->write("# WITH_DEFAULT=\n");

        self::assertNotContains('WITH_DEFAULT', EnvFile::merge($this->dir, [$this->manifest()]));
        self::assertArrayNotHasKey('WITH_DEFAULT', EnvFile::read($this->dir));
    }

    public function testMergeIsIdempotent(): void
    {
        EnvFile::merge($this->dir, [$this->manifest()]);
        $before = (string) file_get_contents($this->dir . '/.env');

        self::assertSame([], EnvFile::merge($this->dir, [$this->manifest()]));
        self::assertSame($before, (string) file_get_contents($this->dir . '/.env'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function write(string $contents): void
    {
        file_put_contents($this->dir . '/.env', $contents);
    }

    /** A manifest declaring one of each config[] shape. */
    private function manifest(): \AlfacodeTeam\Ground\PluginManifest
    {
        $path = $this->dir . '/module.json';

        file_put_contents($path, json_encode([
            'name'   => 'sample',
            'solves' => 'sample.domain',
            'type'   => 'module',
            'config' => [
                ['key' => 'WITH_DEFAULT', 'type' => 'string', 'required' => false, 'default' => 'hello'],
                ['key' => 'REQUIRED_SECRET', 'type' => 'string', 'required' => true],
                ['key' => 'OPTIONAL_THING', 'type' => 'string', 'required' => false],
            ],
        ]));

        $manifest = \AlfacodeTeam\Ground\PluginManifest::fromPath('Sample\\Provider', $path);

        @unlink($path);

        return $manifest;
    }
}
