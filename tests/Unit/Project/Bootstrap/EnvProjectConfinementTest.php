<?php

declare(strict_types=1);

namespace Tests\Unit\Project\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Project\Bootstrap\Domain\DomainContext;
use Project\Bootstrap\Domain\DomainType;
use Project\Bootstrap\Environment\LoadEnvironment;

/**
 * Tier 3 of the .env cascade is confined to the application root.
 *
 * DomainResolver matches the request's Host header against the MACHINE-GLOBAL
 * projects registry, which lists every project on the host by absolute path. A
 * Host owned by another project therefore resolves to THAT project's directory,
 * and tier 3 would read its .env — splicing a foreign APP_KEY, DB_* and
 * SESSION_* over ours, chosen by a header the caller controls.
 */
#[CoversClass(LoadEnvironment::class)]
final class EnvProjectConfinementTest extends TestCase
{
    private string $tmp;

    /** @var array<string, string> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/hkm-envconfine-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);

        foreach (['APP_KEY', 'DB_NAME', 'MARKER'] as $key) {
            if (isset($_ENV[$key])) {
                $this->savedEnv[$key] = (string) $_ENV[$key];
            }
            unset($_ENV[$key], $_SERVER[$key]);
        }

        LoadEnvironment::reset();
    }

    protected function tearDown(): void
    {
        foreach (['APP_KEY', 'DB_NAME', 'MARKER'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        foreach ($this->savedEnv as $key => $value) {
            $_ENV[$key] = $value;
        }

        LoadEnvironment::reset();
        $this->deleteTree($this->tmp);
    }

    public function test_a_foreign_projects_env_is_not_loaded(): void
    {
        // Refusing must be LOUD: a misconfigured registry is an operational
        // problem, and silence looks exactly like "the override did nothing".
        // Declaring the output here asserts the warning and keeps the suite's
        // beStrictAboutOutputDuringTests contract intact.
        $this->expectOutputRegex('/theirs\.local.*was NOT loaded/s');

        // Our application.
        $root = $this->tmp . '/ours';
        mkdir($root, 0775, true);
        file_put_contents($root . '/.env', "APP_KEY=ours\nMARKER=ours\n");

        // A completely separate application on the same machine, which the
        // global registry happens to map the attacker-supplied Host to.
        $foreign = $this->tmp . '/theirs';
        mkdir($foreign, 0775, true);
        file_put_contents($foreign . '/.env', "APP_KEY=THEIRS\nDB_NAME=their_db\n");

        LoadEnvironment::load($root, new DomainContext(
            name:        'theirs',
            projectPath: $foreign,
            type:        DomainType::Project,
            host:        'theirs.local',
        ));

        self::assertSame('ours', $_ENV['APP_KEY'] ?? null, 'a foreign APP_KEY must never win');
        self::assertSame('ours', $_ENV['MARKER'] ?? null);
        self::assertArrayNotHasKey('DB_NAME', $_ENV, 'no foreign key may leak in at all');
    }

    public function test_a_nested_project_under_the_root_still_loads(): void
    {
        // The non-flat layout, where tier 3 is exactly the point: the project
        // lives at <root>/projects/<name> and its .env must still be read.
        $root = $this->tmp . '/workspace';
        mkdir($root . '/projects/admin', 0775, true);
        file_put_contents($root . '/.env', "APP_KEY=base\nMARKER=base\n");
        file_put_contents($root . '/projects/admin/.env', "MARKER=admin\n");

        LoadEnvironment::load($root, new DomainContext(
            name:        'admin',
            projectPath: $root . '/projects/admin',
            type:        DomainType::Admin,
            host:        'admin.local',
        ));

        self::assertSame('base', $_ENV['APP_KEY'] ?? null);
        // No expectOutputRegex here on purpose: beStrictAboutOutputDuringTests
        // turns any warning into a failure, so this asserts the legitimate
        // nested layout is NOT warned about.
        self::assertSame('admin', $_ENV['MARKER'] ?? null, 'the nested project override must apply');
    }

    public function test_a_sibling_sharing_a_name_prefix_is_outside(): void
    {
        $this->expectOutputRegex('/was NOT loaded/');

        // /srv/app-backup must not count as inside /srv/app. Without the
        // separator on the prefix test, a plain str_starts_with says it does.
        $root = $this->tmp . '/app';
        mkdir($root, 0775, true);
        file_put_contents($root . '/.env', "MARKER=app\n");

        $sibling = $this->tmp . '/app-backup';
        mkdir($sibling, 0775, true);
        file_put_contents($sibling . '/.env', "MARKER=backup\n");

        LoadEnvironment::load($root, new DomainContext(
            name:        'backup',
            projectPath: $sibling,
            type:        DomainType::Project,
            host:        'backup.local',
        ));

        self::assertSame('app', $_ENV['MARKER'] ?? null);
    }

    public function test_the_flat_layout_loads_its_own_project_env_once(): void
    {
        // Flat: root IS the project. Tier 1 and tier 3 name the same file; the
        // guard must not turn that into a refusal.
        $root = $this->tmp . '/flat';
        mkdir($root, 0775, true);
        file_put_contents($root . '/.env', "MARKER=flat\n");

        LoadEnvironment::load($root, new DomainContext(
            name:        'flat',
            projectPath: $root,
            type:        DomainType::Project,
            host:        'flat.local',
        ));

        // Likewise: the flat layout is the normal case, and any output here
        // would fail this test under beStrictAboutOutputDuringTests.
        self::assertSame('flat', $_ENV['MARKER'] ?? null);
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
