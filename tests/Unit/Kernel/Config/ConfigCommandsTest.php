<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Config;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\BufferIO;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestWriter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\DevTools\Commands\ConfigClearCommand;
use Plugins\DevTools\Commands\ConfigShowCommand;

#[CoversClass(ConfigShowCommand::class)]
#[CoversClass(ConfigClearCommand::class)]
final class ConfigCommandsTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-config-cmd-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);

        ManifestWriter::write('config-manifest.php', [
            'mail' => [
                'from'      => ['address' => 'noreply@example.test'],
                'transport' => 'smtp',
                'debug'     => false,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Paths::setProject($this->previousProject);

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    /** @param list<string> $argv */
    private function exec(AbstractCommand $command, array $argv, BufferIO $io): int
    {
        return $command->execute($argv, $io);
    }

    /**
     * NOTE: the table renderer writes straight to STDOUT rather than through the
     * injected IO, so BufferIO cannot observe it. Assertions therefore go through
     * --json (which writes via info()) or the scalar path — both exercise the
     * command's own logic, which is what these tests are about.
     */
    public function test_show_lists_groups_by_default(): void
    {
        $io = new BufferIO();

        self::assertSame(AbstractCommand::SUCCESS, $this->exec(new ConfigShowCommand(), ['--json'], $io));
        self::assertStringContainsString('"mail"', $io->getOutput());
    }

    public function test_show_resolves_a_dotted_key(): void
    {
        $io = new BufferIO();

        $this->exec(new ConfigShowCommand(), ['mail.from.address'], $io);

        self::assertStringContainsString('noreply@example.test', $io->getOutput());
    }

    public function test_show_renders_a_whole_group(): void
    {
        $io = new BufferIO();

        $this->exec(new ConfigShowCommand(), ['mail', '--json'], $io);
        $out = $io->getOutput();

        self::assertStringContainsString('"transport"', $out);
        self::assertStringContainsString('noreply@example.test', $out);
    }

    public function test_a_false_value_renders_as_false_not_an_empty_string(): void
    {
        $io = new BufferIO();

        // PHP casts false to '' — printing that would read as "not configured".
        $this->exec(new ConfigShowCommand(), ['mail.debug'], $io);

        self::assertStringContainsString('false', $io->getOutput());
    }

    public function test_show_fails_on_an_unknown_key(): void
    {
        $io = new BufferIO();

        self::assertSame(
            AbstractCommand::FAILURE,
            $this->exec(new ConfigShowCommand(), ['mail.nope'], $io),
        );
    }

    public function test_clear_deletes_the_manifest_then_reports_nothing_to_do(): void
    {
        $path = Paths::cache('manifests/config-manifest.php');
        self::assertFileExists($path);

        self::assertSame(AbstractCommand::SUCCESS, $this->exec(new ConfigClearCommand(), [], new BufferIO()));
        self::assertFileDoesNotExist($path);

        // Idempotent — clearing twice is not an error.
        $io = new BufferIO();
        self::assertSame(AbstractCommand::SUCCESS, $this->exec(new ConfigClearCommand(), [], $io));
        self::assertStringContainsString('nothing to clear', $io->getOutput());
    }
}
