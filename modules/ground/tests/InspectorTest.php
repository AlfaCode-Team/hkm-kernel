<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use AlfacodeTeam\Ground\PluginManifest;
use AlfacodeTeam\Ground\Inspection\Finding;
use AlfacodeTeam\Ground\Inspection\Inspector;

/**
 * The inspector, on a correct plugin and on a deliberately wrong one.
 *
 * Both directions matter. A checker that reports nothing is useless; one that
 * reports on a clean plugin is worse, because the first false positive teaches
 * everyone to ignore the output.
 */
#[CoversClass(Inspector::class)]
final class InspectorTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $dir) {
            foreach (glob($dir . '/**/*') ?: [] as $path) {
                @unlink($path);
            }
            foreach (glob($dir . '/*') ?: [] as $path) {
                is_dir($path) ? @rmdir($path) : @unlink($path);
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }

    // ── The clean case ────────────────────────────────────────────────────────

    public function testACorrectPluginProducesNoErrorsOrWarnings(): void
    {
        $findings = Inspector::forProvider(Fixtures\Sample\Provider::class)->run();

        $loud = array_filter($findings, static fn(Finding $f): bool => $f->severity !== Finding::NOTE);

        self::assertSame([], array_map(static fn(Finding $f): string => $f->code, $loud));
    }

    public function testAnUnreadDeclaredVariableIsANoteNotAnError(): void
    {
        // GROUND_SAMPLE_SECRET is declared and never read. That is worth
        // mentioning and is not a defect — grading it as one would make the
        // exit code useless for CI.
        $codes = $this->codes(Inspector::forProvider(Fixtures\Sample\Provider::class)->run());

        self::assertContains('config.unused', $codes);
    }

    // ── Provider ↔ manifest drift ─────────────────────────────────────────────

    public function testItReportsSolvesDrift(): void
    {
        $codes = $this->codes(Inspector::forProvider(Fixtures\Drifted\Provider::class)->run());

        self::assertContains('manifest.solves-drift', $codes);
    }

    public function testItReportsRequiresAndExposesDrift(): void
    {
        $codes = $this->codes(Inspector::forProvider(Fixtures\Drifted\Provider::class)->run());

        self::assertContains('manifest.requires-drift', $codes);
        self::assertContains('manifest.exposes-drift', $codes);
    }

    public function testItReportsAClassNameInRequires(): void
    {
        $codes = $this->codes(Inspector::forProvider(Fixtures\Drifted\Provider::class)->run());

        self::assertContains('manifest.requires-class', $codes);
    }

    public function testItReportsAnExposedContractThatDoesNotExist(): void
    {
        $codes = $this->codes(Inspector::forProvider(Fixtures\Drifted\Provider::class)->run());

        self::assertContains('exposes.missing', $codes);
    }

    public function testItReportsAHandlerClassThatDoesNotExist(): void
    {
        $codes = $this->codes(Inspector::forProvider(Fixtures\Drifted\Provider::class)->run());

        self::assertContains('route.handler-class', $codes);
    }

    // ── Config declarations ───────────────────────────────────────────────────

    public function testItReportsAnUndeclaredEnvRead(): void
    {
        $dir = $this->plugin(['config' => []], [
            'Service.php' => '<?php $limit = env("PAGE_SIZE", 10);',
        ]);

        self::assertContains('config.undeclared', $this->codes($this->inspect($dir)));
    }

    public function testItReportsGetenv(): void
    {
        $dir = $this->plugin(['config' => ['API_KEY']], [
            'Service.php' => '<?php $key = getenv("API_KEY");',
        ]);

        // getenv() is a bug on its own in this framework: LoadEnvironment never
        // calls putenv(), so it returns nothing for any .env value.
        self::assertContains('config.getenv', $this->codes($this->inspect($dir)));
    }

    public function testAnEnvNameInsideACommentIsNotReported(): void
    {
        // The regression this exists for: a text scan matched this project's own
        // docblock and demanded a variable that only appears in a sentence.
        $dir = $this->plugin(['config' => []], [
            'Service.php' => <<<'PHP'
                <?php
                // Reads env('DOCUMENTED_ONLY') when configured.
                /** Set env("ALSO_DOCUMENTED") to enable. */
                final class Service {}
                PHP,
        ]);

        self::assertNotContains('config.undeclared', $this->codes($this->inspect($dir)));
    }

    public function testAMethodCalledEnvIsNotMistakenForTheHelper(): void
    {
        $dir = $this->plugin(['config' => []], [
            'Builder.php' => '<?php final class B { public function env(string $k) {} '
                . 'public function go(): void { $this->env("SOME_KEY"); } }',
        ]);

        self::assertNotContains('config.undeclared', $this->codes($this->inspect($dir)));
    }

    // ── Access rules ──────────────────────────────────────────────────────────

    public function testItReportsADomainClassImportingAnythingExternal(): void
    {
        $dir = $this->plugin([], [
            'Domain/Entities/Thing.php' => '<?php namespace X\Domain\Entities; '
                . 'use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort; final class Thing {}',
        ]);

        self::assertContains('access.domain', $this->codes($this->inspect($dir)));
    }

    public function testADomainClassImportingItsOwnDomainIsFine(): void
    {
        $dir = $this->plugin([], [
            'Domain/Entities/Thing.php' => '<?php namespace X\Domain\Entities; '
                . 'use X\Domain\ValueObjects\Money; final class Thing {}',
        ]);

        self::assertNotContains('access.domain', $this->codes($this->inspect($dir)));
    }

    public function testItReportsARepositoryReachingForOutboundHttp(): void
    {
        $dir = $this->plugin([], [
            'Infrastructure/Persistence/R.php' => '<?php namespace X; '
                . 'use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{DatabasePort, HttpClientPort}; final class R {}',
        ]);

        $codes = $this->codes($this->inspect($dir));

        // The grouped import must be expanded: DatabasePort is legal here and
        // HttpClientPort is not, and a parser that read the group as one string
        // would either miss the violation or invent one.
        self::assertContains('access.infrastructure-persistence', $codes);
    }

    public function testItReportsAControllerTakingTheDatabaseDirectly(): void
    {
        $dir = $this->plugin([], [
            'Infrastructure/Http/Controllers/C.php' => '<?php namespace X; '
                . 'use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort; final class C {}',
        ]);

        self::assertContains('access.infrastructure-http-controllers', $this->codes($this->inspect($dir)));
    }

    public function testAPipelineStageUnderHttpIsNotMistakenForAController(): void
    {
        $dir = $this->plugin([], [
            'Infrastructure/Http/Stages/TenantContextStage.php' => '<?php namespace X; '
                . 'use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort; final class S {}',
        ]);

        // Tenancy's real TenantContextStage exists precisely to REBIND
        // DatabasePort per request. Matching the whole Infrastructure/Http tree
        // reported that as a violation — one of the false positives found by
        // running this checker against the first-party plugins.
        self::assertSame([], array_filter(
            $this->codes($this->inspect($dir)),
            static fn(string $code): bool => str_starts_with($code, 'access.'),
        ));
    }

    public function testItReportsAServiceTypedAgainstTheRequest(): void
    {
        $dir = $this->plugin([], [
            'Application/Services/S.php' => '<?php namespace X; '
                . 'use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request; final class S {}',
        ]);

        self::assertContains('access.application-services', $this->codes($this->inspect($dir)));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param list<Finding> $findings @return list<string> */
    private function codes(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->code, $findings);
    }

    // ── Variables a DEPENDENCY owns ───────────────────────────────────────────

    /**
     * `user` declares HASH_BCRYPT_COST and never reads it, because `crypto` —
     * which it requires — does. Calling that "no source file reads it" is true
     * of this plugin's files and false about the system, and it sends someone
     * to delete a line that is doing a job.
     */
    public function testAVariableReadByAREQUIREDPluginIsNotReportedAsUnread(): void
    {
        $dir = $this->plugin(
            ['requires' => ['crypto.services'], 'config' => [['key' => 'HASH_BCRYPT_COST', 'type' => 'int']]],
            [],
        );

        $codes = $this->codes($this->inspect($dir, $this->neighbour('crypto', 'crypto.services', ['HASH_BCRYPT_COST'])));

        self::assertContains('config.dependency-var', $codes);
        self::assertNotContains('config.unused', $codes);
    }

    public function testTheNoteNamesThePluginThatOwnsTheVariable(): void
    {
        $dir = $this->plugin(
            ['requires' => ['crypto.services'], 'config' => [['key' => 'HASH_BCRYPT_COST', 'type' => 'int']]],
            [],
        );

        $findings = $this->inspect($dir, $this->neighbour('crypto', 'crypto.services', ['HASH_BCRYPT_COST']));
        $note     = array_values(array_filter(
            $findings,
            static fn(Finding $f): bool => $f->code === 'config.dependency-var',
        ))[0] ?? null;

        self::assertNotNull($note);
        self::assertStringContainsString('[crypto]', $note->message);
    }

    /** A plugin that is installed but NOT required does not excuse the variable. */
    public function testAVariableOwnedByAPluginThisOneDoesNotRequireIsStillUnread(): void
    {
        $dir = $this->plugin(
            ['requires' => [], 'config' => [['key' => 'HASH_BCRYPT_COST', 'type' => 'int']]],
            [],
        );

        $codes = $this->codes($this->inspect($dir, $this->neighbour('crypto', 'crypto.services', ['HASH_BCRYPT_COST'])));

        self::assertContains('config.unused', $codes);
        self::assertNotContains('config.dependency-var', $codes);
    }

    /** Transitively, because requires[] is a graph and not a list. */
    public function testItLooksThroughAChainOfDependencies(): void
    {
        $dir = $this->plugin(
            ['requires' => ['mid.domain'], 'config' => [['key' => 'DEEP_VAR', 'type' => 'string']]],
            [],
        );

        $neighbours = [
            ...$this->neighbour('mid', 'mid.domain', [], requires: ['deep.domain']),
            ...$this->neighbour('deep', 'deep.domain', ['DEEP_VAR']),
        ];

        self::assertContains('config.dependency-var', $this->codes($this->inspect($dir, $neighbours)));
    }

    /** With no workspace to consult, the old conclusion is the only honest one. */
    public function testWithNoNeighboursItStillReportsTheVariableAsUnread(): void
    {
        $dir = $this->plugin(
            ['requires' => ['crypto.services'], 'config' => [['key' => 'HASH_BCRYPT_COST', 'type' => 'int']]],
            [],
        );

        self::assertContains('config.unused', $this->codes($this->inspect($dir)));
    }

    /**
     * One neighbour, as the locator's byDomain() index shapes it.
     *
     * @param  list<string>                  $configKeys
     * @param  list<string>                  $requires
     * @return array<string, PluginManifest>
     */
    private function neighbour(string $name, string $solves, array $configKeys, array $requires = []): array
    {
        $dir = $this->plugin([
            'name'     => $name,
            'solves'   => $solves,
            'requires' => $requires,
            'config'   => array_map(static fn(string $k): array => ['key' => $k], $configKeys),
        ], []);

        return [$solves => PluginManifest::fromPath('Ground\\Neighbour\\Provider', $dir . '/module.json')];
    }

    /**
     * @param  array<string, PluginManifest> $neighbours
     * @return list<Finding>
     */
    private function inspect(string $dir, array $neighbours = []): array
    {
        // A provider class-string that does not exist: the provider checks
        // report that (and the test ignores it) while the file-scanning checks
        // still run over the temp directory.
        return (new Inspector(
            PluginManifest::fromPath('Ground\\Temp\\Provider', $dir . '/module.json'),
            $neighbours,
        ))->run();
    }

    /**
     * Write a throwaway plugin directory.
     *
     * @param array<string, mixed>  $manifest merged over a minimal valid one
     * @param array<string, string> $files    relative path => source
     */
    private function plugin(array $manifest, array $files): string
    {
        $dir = sys_get_temp_dir() . '/ground-inspect-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->temporary[] = $dir;

        file_put_contents($dir . '/module.json', (string) json_encode([
            'name'     => 'temp',
            'solves'   => 'temp.thing',
            'requires' => [],
            'exposes'  => [],
            'routes'   => [],
            'config'   => [],
            ...$manifest,
        ]));

        foreach ($files as $path => $source) {
            $full = $dir . '/' . $path;
            if (!is_dir(\dirname($full))) {
                mkdir(\dirname($full), 0o700, true);
            }
            file_put_contents($full, $source);
        }

        return $dir;
    }
}
