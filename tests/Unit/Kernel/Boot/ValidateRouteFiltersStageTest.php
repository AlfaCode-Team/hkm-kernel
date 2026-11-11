<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\ValidateRouteFiltersStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\FilterBadConsumerModule\Provider as BadConsumer;
use Tests\Fixtures\FilterConsumerModule\Provider as Consumer;
use Tests\Fixtures\FilterProviderModule\Provider as FilterProvider;
use Tests\Fixtures\FilterRivalProviderModule\Provider as RivalProvider;
use Tests\Fixtures\ScheduleModule\Provider as ScheduleProvider;

/**
 * A route opts into a filter by naming an alias, and the alias is published at
 * runtime by whichever plugin calls $http->filter(). Nothing connected the two,
 * so a plugin's dependency on another plugin's alias was undeclarable — and the
 * resulting failure named the alias rather than the plugin that owns it.
 *
 * These cover the declaration that closes that gap.
 */
#[CoversClass(ValidateRouteFiltersStage::class)]
final class ValidateRouteFiltersStageTest extends TestCase
{
    private string $root;

    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-filters-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);
    }

    protected function tearDown(): void
    {
        Paths::setProject($this->previousProject);

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root);
    }

    /** @param list<class-string> $modules */
    private function runStage(array $modules): void
    {
        (new ValidateRouteFiltersStage($modules, reader: new ManifestReader()))->run();
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        /** @var array<string, mixed> */
        return require $this->root . '/var/cache/manifests/filter-manifest.php';
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_a_satisfied_requirement_compiles_and_records_the_owner(): void
    {
        $this->runStage([FilterProvider::class, Consumer::class]);

        $manifest = $this->manifest();

        $this->assertSame(
            ['auth' => 'filters.provider', 'throttle' => 'filters.provider'],
            $manifest['provides'],
        );
        $this->assertSame(['filters.consumer' => ['auth']], $manifest['requires']);
    }

    /**
     * Sixty-odd existing plugins publish and consume aliases without declaring
     * anything. Failing their boots to introduce paperwork would be the platform
     * breaking working applications, so a module that declares neither key is
     * not checked and contributes nothing to the manifest.
     */
    public function test_modules_that_declare_nothing_are_not_checked(): void
    {
        $this->runStage([ScheduleProvider::class]);

        $this->assertSame(['provides' => [], 'requires' => []], $this->manifest());
    }

    public function test_an_empty_declaration_set_still_writes_a_manifest(): void
    {
        $this->runStage([]);

        $this->assertSame(['provides' => [], 'requires' => []], $this->manifest());
    }

    // ── The failures ─────────────────────────────────────────────────────────

    /**
     * The whole point: the error names the REQUIRING module and lists who
     * provides what, instead of leaving the reader to already know which plugin
     * owns the alias.
     */
    public function test_an_unprovided_requirement_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessage('requiresFilters [nope]');

        $this->runStage([FilterProvider::class, BadConsumer::class]);
    }

    public function test_the_failure_lists_the_declared_providers(): void
    {
        try {
            $this->runStage([FilterProvider::class, BadConsumer::class]);
            $this->fail('Expected a BootException.');
        } catch (BootException $e) {
            $this->assertStringContainsString('auth (by filters.provider)', $e->getMessage());
            $this->assertStringContainsString('throttle (by filters.provider)', $e->getMessage());
        }
    }

    public function test_the_failure_says_so_when_nothing_declares_a_provider(): void
    {
        try {
            $this->runStage([BadConsumer::class]);
            $this->fail('Expected a BootException.');
        } catch (BootException $e) {
            $this->assertStringContainsString('no module declares providesFilters', $e->getMessage());
        }
    }

    /**
     * One alias, one owner. The FilterRegistry already enforces this at runtime;
     * catching it at boot means the ambiguity is reported before whichever
     * plugin happened to boot last silently wins.
     */
    public function test_two_modules_claiming_one_alias_fail_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessage('declared by two modules');

        $this->runStage([FilterProvider::class, RivalProvider::class]);
    }

    public function test_the_same_module_listed_twice_is_not_a_conflict(): void
    {
        $this->runStage([FilterProvider::class, FilterProvider::class]);

        $this->assertArrayHasKey('auth', $this->manifest()['provides']);
    }

    /**
     * A route writes `throttle:60,1`; the alias is the part before the colon.
     * Declaring the arguments would never match, so it is rejected rather than
     * silently ignored — the same anti-typo stance the route compiler takes.
     */
    public function test_an_alias_declared_with_arguments_is_rejected(): void
    {
        $module = $this->fixtureModule('WithArgs', ['providesFilters' => ['throttle:60,1']]);

        $this->expectException(BootException::class);
        $this->expectExceptionMessage('with arguments');

        $this->runStage([$module]);
    }

    public function test_a_non_list_declaration_is_rejected(): void
    {
        $module = $this->fixtureModule('NotAList', ['providesFilters' => ['a' => 'b']]);

        $this->expectException(BootException::class);
        $this->expectExceptionMessage('must be a list of alias strings');

        $this->runStage([$module]);
    }

    public function test_a_non_string_entry_is_rejected(): void
    {
        $module = $this->fixtureModule('NotAString', ['requiresFilters' => [42]]);

        $this->expectException(BootException::class);
        $this->expectExceptionMessage('non-string or empty entry');

        $this->runStage([$module]);
    }

    /**
     * Write a throwaway module on disk and return its class name.
     *
     * The declaration-shape failures need manifests that the committed fixtures
     * should not carry — a fixture whose module.json is deliberately malformed
     * would break any other stage that happened to read it.
     *
     * @param array<string, mixed> $manifest
     * @return class-string
     */
    private function fixtureModule(string $name, array $manifest): string
    {
        $dir = $this->root . '/tmp-modules/' . $name;
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/module.json', json_encode([
            'name' => strtolower($name),
            'version' => '1.0.0',
            'solves' => 'tmp.' . strtolower($name),
            'type' => 'module',
            'requires' => [],
            'exposes' => [],
            ...$manifest,
        ], JSON_THROW_ON_ERROR));

        $class = 'HkmFilterFixture' . $name . '\\Provider';

        file_put_contents($dir . '/Provider.php', <<<PHP
        <?php
        namespace HkmFilterFixture{$name};
        final class Provider {}
        PHP);

        require_once $dir . '/Provider.php';

        /** @var class-string */
        return $class;
    }
}
