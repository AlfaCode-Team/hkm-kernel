<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\Ground\Database\DatabaseMatrix;
use AlfacodeTeam\Ground\Database\DatabaseTarget;
use AlfacodeTeam\Ground\Database\MigrationHarness;
use PHPUnit\Framework\TestCase;

/**
 * Migrations against a REAL database.
 *
 * SQLite is used because it is the one engine present on every machine — the
 * file is the database. The value is not that SQLite is important; it is that
 * SQLite is a genuinely DIFFERENT dialect from MySQL, so a migration that only
 * ever compiled MySQL syntax fails here. That is exactly the defect class a
 * fake cannot reach: FakeDatabase records the SQL it is handed and never parses
 * it, so every wrong statement passes.
 */
final class MigrationTest extends TestCase
{
    private string $plugin = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->plugin = sys_get_temp_dir() . '/ground-migrate-' . bin2hex(random_bytes(6));
        mkdir($this->plugin . '/database', 0o700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->plugin);

        parent::tearDown();
    }

    // ── The happy path, for real ──────────────────────────────────────────────

    public function testItAppliesEveryMigrationAndRollsThemAllBack(): void
    {
        $this->useFixture('good');

        $run = $this->migrate();

        self::assertSame('', $run->error, $run->error);
        self::assertSame(2, $run->applied);
        self::assertContains('widgets', $run->tables);
        self::assertTrue($run->rolledBackClean, 'down() must leave nothing behind.');
        self::assertTrue($run->ok());
    }

    /**
     * The regression guard for the shared ALTER compiler.
     *
     * `AbstractGrammar::compileAlter()` batched every addition into one
     * `ALTER TABLE t ADD COLUMN a, ADD COLUMN b, ADD KEY …` — MySQL syntax,
     * emitted for every driver because no grammar overrode it. SQLite takes
     * exactly one ADD COLUMN per statement and rejected the whole thing, so
     * "add two columns and an index" was broken on every engine but MySQL.
     */
    public function testTwoColumnsAndAnIndexApplyOnANonMysqlEngine(): void
    {
        $this->useFixture('good');

        $run = $this->migrate();

        self::assertSame('', $run->error, 'A multi-column table() must compile per-engine: ' . $run->error);
    }

    // ── The half nobody tests ─────────────────────────────────────────────────

    /**
     * A down() that forgets a table is reported, not passed.
     *
     * This is what `hkm plugins disable` depends on: unpublishing rolls the
     * migrations back before deleting the files, so a migration that cannot
     * undo itself strands the schema.
     */
    public function testAMigrationThatDoesNotUndoItselfIsReported(): void
    {
        $this->useFixture('leaky');

        $run = $this->migrate();

        self::assertSame('', $run->error, 'up() should still succeed here.');
        self::assertFalse($run->rolledBackClean, 'A table left behind after reset() must fail the run.');
        self::assertFalse($run->ok());
    }

    public function testTheScratchDatabaseIsRemovedAfterwards(): void
    {
        $this->useFixture('good');

        $before = glob(sys_get_temp_dir() . '/ground_*.sqlite') ?: [];
        $this->migrate();
        $after = glob(sys_get_temp_dir() . '/ground_*.sqlite') ?: [];

        self::assertSame(
            \count($before),
            \count($after),
            'Every run creates its own database and must drop it.',
        );
    }

    public function testAPluginWithNoMigrationsIsNotAFailure(): void
    {
        $harness = new MigrationHarness($this->plugin, ['database/migrations']);

        self::assertSame([], $harness->migrationPaths());
    }

    // ── The matrix ────────────────────────────────────────────────────────────

    public function testSqliteIsAlwaysAvailable(): void
    {
        $matrix = DatabaseMatrix::discover($this->plugin);
        $names  = array_map(static fn(DatabaseTarget $t): string => $t->driver, $matrix->usable());

        self::assertContains('sqlite', $names);
    }

    /**
     * A driver nobody configured is SKIPPED with the reason, never silently
     * dropped — the count of untested engines is the most important line the
     * report prints.
     */
    public function testAnUnconfiguredDriverIsSkippedWithAReason(): void
    {
        $skipped = DatabaseMatrix::discover($this->plugin)->skipped();

        self::assertNotSame([], $skipped);

        foreach ($skipped as $target) {
            self::assertNotSame('', $target->skipReason, "{$target->driver} was skipped with no reason given.");
        }
    }

    public function testEverySupportedDriverIsAccountedFor(): void
    {
        $matrix = DatabaseMatrix::discover($this->plugin);

        self::assertCount(\count(DatabaseMatrix::DRIVERS), $matrix->targets);
    }

    /** `"enabled": false` is how you say "not this one, and stop asking". */
    public function testAnExplicitlyDisabledDriverIsNotProbed(): void
    {
        file_put_contents(DatabaseMatrix::configPath($this->plugin), (string) json_encode([
            'mysql' => ['enabled' => false, 'host' => '127.0.0.1', 'port' => 1],
        ]));

        $mysql = $this->targetFor('mysql');

        self::assertFalse($mysql->reachable);
        self::assertStringContainsString('not configured', $mysql->skipReason);
    }

    public function testAConfiguredButUnreachableServerReportsTheConnectionError(): void
    {
        file_put_contents(DatabaseMatrix::configPath($this->plugin), (string) json_encode([
            // Port 1 is reserved and nothing listens there.
            'mysql' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'root', 'password' => ''],
        ]));

        $mysql = $this->targetFor('mysql');

        self::assertFalse($mysql->reachable);
        self::assertStringNotContainsString('not configured', $mysql->skipReason);
    }

    /** A description is printed in reports, so it must never carry the password. */
    public function testTheDescriptionNeverLeaksThePassword(): void
    {
        $target = DatabaseTarget::reachable('mysql', [
            'host' => 'db.internal', 'port' => 3306, 'username' => 'root', 'password' => 'hunter2',
        ]);

        self::assertStringNotContainsString('hunter2', $target->describe());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function useFixture(string $name): void
    {
        $dest = $this->plugin . '/database/migrations';
        mkdir($dest, 0o700, true);

        foreach (glob(__DIR__ . '/Fixtures/Migrations/' . $name . '/*.php') ?: [] as $file) {
            copy($file, $dest . '/' . basename($file));
        }
    }

    private function migrate(): \AlfacodeTeam\Ground\Database\MigrationRun
    {
        return (new MigrationHarness($this->plugin, ['database/migrations']))
            ->run($this->targetFor('sqlite'));
    }

    private function targetFor(string $driver): DatabaseTarget
    {
        foreach (DatabaseMatrix::discover($this->plugin)->targets as $target) {
            if ($target->driver === $driver) {
                return $target;
            }
        }

        self::fail("No target for [{$driver}].");
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
