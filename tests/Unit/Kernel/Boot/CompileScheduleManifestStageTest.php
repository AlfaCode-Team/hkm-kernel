<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileScheduleManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling\ScheduledTask;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ScheduleModule\Provider as ScheduleProvider;

/**
 * schedule[] → schedule-manifest.php.
 *
 * The reason this stage exists at all is the failure it prevents: a bad cron
 * expression neither throws nor 404s. The task simply never fires, and nobody
 * finds out until somebody asks where last month's report went. So the tests
 * that matter most here are the ones asserting the BOOT fails.
 */
#[CoversClass(CompileScheduleManifestStage::class)]
#[CoversClass(ScheduledTask::class)]
final class CompileScheduleManifestStageTest extends TestCase
{
    private string $root;
    private ?string $previousBase = null;
    private ?string $previousProject = null;

    /** Fixture module dirs created per test for the failure cases. */
    private array $temporaryModules = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-schedule-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);
    }

    protected function tearDown(): void
    {
        Paths::setBase((string) $this->previousBase);
        Paths::setProject($this->previousProject);

        foreach ($this->temporaryModules as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    /** @return array<string, array<string, mixed>> */
    private function compile(): array
    {
        (new CompileScheduleManifestStage([ScheduleProvider::class], new ManifestReader()))->run();

        return ManifestReader::readCompiled('schedule-manifest.php');
    }

    public function test_it_compiles_a_job_task_with_every_declared_field(): void
    {
        $task = $this->compile()['reports.nightly'];

        self::assertSame('0 2 * * *', $task['at']);
        self::assertSame(ScheduledTask::KIND_JOB, $task['kind']);
        self::assertSame('App\\Jobs\\NightlyReport', $task['target']);
        self::assertSame('reports', $task['queue']);
        self::assertSame(['format' => 'pdf'], $task['payload']);
        self::assertTrue($task['withoutOverlapping']);
        self::assertSame(7200, $task['expiresAfter']);
        self::assertSame('Africa/Nairobi', $task['timezone']);
        self::assertSame('schedule.demo', $task['solves']);
    }

    public function test_it_compiles_a_command_task(): void
    {
        $task = $this->compile()['cache.prune'];

        self::assertSame(ScheduledTask::KIND_COMMAND, $task['kind']);
        self::assertSame('cache:prune --stale', $task['target']);
        self::assertSame('@hourly', $task['at']);
    }

    /** An overlap lock shorter than a minute cannot outlive the task it guards. */
    public function test_it_clamps_an_unusably_short_overlap_lock(): void
    {
        self::assertSame(60, $this->compile()['clamped.expiry']['expiresAfter']);
    }

    public function test_a_compiled_task_round_trips(): void
    {
        $task = ScheduledTask::fromArray($this->compile()['reports.nightly']);

        self::assertSame('reports.nightly', $task->name);
        self::assertSame('Africa/Nairobi', $task->timezone);
        self::assertTrue($task->withoutOverlapping);
        self::assertSame('schedule:lock:reports.nightly', $task->lockKey());
    }

    /**
     * The timezone is the difference between "9am for the business" and "9am
     * wherever this container happened to be scheduled".
     */
    public function test_a_task_is_due_in_its_own_timezone_not_the_servers(): void
    {
        $task = ScheduledTask::fromArray($this->compile()['reports.nightly']); // 02:00 Africa/Nairobi (UTC+3)

        self::assertTrue($task->dueAt(new \DateTimeImmutable('2026-01-05 23:00:00', new \DateTimeZone('UTC'))));
        self::assertFalse($task->dueAt(new \DateTimeImmutable('2026-01-05 02:00:00', new \DateTimeZone('UTC'))));
    }

    // ── The boot failures ────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function badDeclarations(): iterable
    {
        yield 'hour out of range' => [
            '{"name":"x","at":"0 25 * * *","job":"J"}',
            'between 0 and 23',
        ];
        yield 'six fields' => [
            '{"name":"x","at":"* * * * * *","job":"J"}',
            'Seconds are not supported',
        ];
        yield 'no name' => [
            '{"at":"@daily","job":"J"}',
            'no "name"',
        ];
        yield 'no at' => [
            '{"name":"x","job":"J"}',
            'has no "at"',
        ];
        yield 'neither job nor command' => [
            '{"name":"x","at":"@daily"}',
            'declares neither "job" nor "command"',
        ];
        yield 'both job and command' => [
            '{"name":"x","at":"@daily","job":"J","command":"c"}',
            'declares both "job" and "command"',
        ];
        yield 'unknown timezone' => [
            '{"name":"x","at":"@daily","job":"J","timezone":"Mars/Olympus"}',
            'which PHP does not know',
        ];
        yield 'payload is not an object' => [
            '{"name":"x","at":"@daily","job":"J","payload":"nope"}',
            'it must be an object',
        ];
    }

    #[DataProvider('badDeclarations')]
    public function test_a_bad_declaration_fails_the_boot(string $entryJson, string $needle): void
    {
        $provider = $this->fixtureModule('[' . $entryJson . ']');

        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');

        (new CompileScheduleManifestStage([$provider], new ManifestReader()))->run();
    }

    public function test_a_duplicate_task_name_fails_the_boot(): void
    {
        $a = $this->fixtureModule('[{"name":"dupe","at":"@daily","job":"A"}]');
        $b = $this->fixtureModule('[{"name":"dupe","at":"@daily","job":"B"}]');

        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/Duplicate scheduled task name \[dupe\]/');

        (new CompileScheduleManifestStage([$a, $b], new ManifestReader()))->run();
    }

    public function test_a_module_with_no_schedule_compiles_to_nothing(): void
    {
        $provider = $this->fixtureModule(null);

        (new CompileScheduleManifestStage([$provider], new ManifestReader()))->run();

        self::assertSame([], ManifestReader::readCompiled('schedule-manifest.php'));
    }

    /**
     * Write a throwaway module (Provider + module.json) and return its provider
     * class-string.
     *
     * ManifestReader finds module.json by reflecting on the Provider to get its
     * FILE and taking the dirname, so the class must genuinely be declared in a
     * file inside the temp directory — an eval'd class reports no usable path.
     */
    private function fixtureModule(?string $scheduleJson): string
    {
        $id  = 'Sched' . bin2hex(random_bytes(6));
        $dir = $this->root . '/modules/' . $id;
        mkdir($dir, 0775, true);
        $this->temporaryModules[] = $dir;

        $schedule = $scheduleJson === null ? '' : ',"schedule":' . $scheduleJson;
        file_put_contents(
            $dir . '/module.json',
            '{"name":"' . $id . '","solves":"demo.' . strtolower($id) . '","requires":[],"exposes":[]' . $schedule . '}',
        );

        $file = $dir . '/Provider.php';
        file_put_contents(
            $file,
            "<?php\ndeclare(strict_types=1);\nnamespace Tests\\Tmp\\{$id};\nfinal class Provider {}\n",
        );
        require $file;

        return 'Tests\\Tmp\\' . $id . '\\Provider';
    }
}
