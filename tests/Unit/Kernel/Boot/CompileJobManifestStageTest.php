<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileJobManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\JobModule\Provider as JobProvider;

/**
 * jobs[] → job-manifest.php.
 *
 * `retry` and `timeout` have always been part of the documented module.json
 * grammar, and this stage used to drop both. Every job in every application
 * therefore shared one hardcoded backoff and ran unbounded, while its manifest
 * said otherwise — a declaration that compiles to nothing reads as a guarantee.
 */
#[CoversClass(CompileJobManifestStage::class)]
final class CompileJobManifestStageTest extends TestCase
{
    private string $root;
    private ?string $previousBase = null;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-jobs-' . bin2hex(random_bytes(6));
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
        (new CompileJobManifestStage([JobProvider::class], new ManifestReader()))->run();

        return ManifestReader::readCompiled('job-manifest.php');
    }

    public function test_it_compiles_the_declared_retry_policy(): void
    {
        $jobs = $this->compile();

        self::assertSame(
            ['max' => 5, 'strategy' => 'linear', 'base' => 30, 'jitter' => false],
            $jobs['jobs.send-invoice']['retry'],
        );
    }

    public function test_it_compiles_the_declared_timeout(): void
    {
        self::assertSame(30, $this->compile()['jobs.send-invoice']['timeout']);
    }

    public function test_an_undeclared_retry_stays_null(): void
    {
        // Not a default: "this job said nothing" must stay distinguishable from
        // "this job asked for the defaults", so WorkerLoop's own fallback
        // remains the single place the default lives.
        $jobs = $this->compile();

        self::assertNull($jobs['jobs.rebuild-index']['retry']);
        self::assertNull($jobs['jobs.rebuild-index']['timeout']);
    }

    public function test_the_shorthand_retry_count_is_understood(): void
    {
        $retry = $this->compile()['jobs.prune']['retry'];

        self::assertSame(5, $retry['max']);
        self::assertSame('exponential', $retry['strategy'], 'the default strategy');
    }

    public function test_an_unknown_strategy_falls_back_rather_than_failing(): void
    {
        $retry = $this->compile()['jobs.report']['retry'];

        self::assertSame('exponential', $retry['strategy']);
    }

    public function test_a_zero_max_is_raised_to_one(): void
    {
        // "retry": {"max": 0} would mean the job can never run, which is never
        // what it means.
        self::assertSame(1, $this->compile()['jobs.report']['retry']['max']);
    }

    public function test_a_nonsensical_timeout_is_dropped(): void
    {
        self::assertNull($this->compile()['jobs.report']['timeout']);
    }

    public function test_the_existing_fields_are_unchanged(): void
    {
        $job = $this->compile()['jobs.send-invoice'];

        self::assertSame('emails', $job['queue']);
        self::assertSame('App\\Jobs\\SendInvoice', $job['handler']);
        self::assertSame('jobs.demo', $job['solves']);
        self::assertSame(JobProvider::class, $job['module']);
    }
}
