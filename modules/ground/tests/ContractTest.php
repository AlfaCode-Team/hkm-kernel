<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\Ground\Contract\ContractLock;
use AlfacodeTeam\Ground\Contract\ContractSurface;
use AlfacodeTeam\Ground\Inspection\Finding;
use AlfacodeTeam\Ground\Inspection\Inspector;
use AlfacodeTeam\Ground\PluginManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The contract lock, and the cross-plugin import rule beside it.
 *
 * Both answer the same question — "what may this plugin change without
 * breaking something it cannot see" — from the two ends: the lock guards the
 * strings it PUBLISHES, the import rule guards what it REACHES FOR.
 */
#[CoversClass(ContractLock::class)]
#[CoversClass(ContractSurface::class)]
#[CoversClass(Inspector::class)]
final class ContractTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $dir) {
            $this->removeTree($dir);
        }

        parent::tearDown();
    }

    // ── The surface ───────────────────────────────────────────────────────────

    public function testTheSurfaceCollectsTheFivePublishedKinds(): void
    {
        $surface = ContractSurface::of($this->manifest([
            'solves'      => 'posts.publishing',
            'emits'       => ['post.published', 'post.deleted'],
            'exposes'     => ['Plugins\\Posts\\API\\Contracts\\PostServiceContract'],
            'config'      => [['key' => 'POSTS_PER_PAGE', 'type' => 'int']],
            'routePrefix' => '/posts',
            'routes'      => [
                ['method' => 'GET', 'path' => '', 'handler' => 'C@index', 'name' => 'posts.index'],
                ['method' => 'GET', 'path' => '/{id:num}', 'handler' => 'C@show', 'name' => 'posts.show'],
            ],
        ]));

        self::assertSame(['posts.publishing'], $surface->kind('solves'));
        self::assertSame(['posts.index', 'posts.show'], $surface->kind('routes'));
        self::assertSame(['post.deleted', 'post.published'], $surface->kind('events'));
        self::assertSame(['Plugins\\Posts\\API\\Contracts\\PostServiceContract'], $surface->kind('contracts'));
        self::assertSame(['POSTS_PER_PAGE'], $surface->kind('config'));
    }

    /**
     * A route with no name publishes nothing lockable.
     *
     * The name is the stable handle; the PATH is deliberately not locked,
     * because moving a path behind a stable name is the entire reason names
     * exist. Locking paths would forbid the refactor the feature is for.
     */
    public function testAnUnnamedRouteIsNotLocked(): void
    {
        $surface = ContractSurface::of($this->manifest([
            'routes' => [['method' => 'GET', 'path' => '/anon', 'handler' => 'C@a']],
        ]));

        self::assertSame([], $surface->kind('routes'));
    }

    /** Group name prefixes are part of the published name, so the surface resolves them. */
    public function testGroupNamePrefixesAreResolved(): void
    {
        $surface = ContractSurface::of($this->manifest([
            'groups' => [[
                'prefix' => '/admin',
                'name'   => 'posts.admin.',
                'routes' => [['method' => 'GET', 'path' => '/audit', 'handler' => 'C@audit', 'name' => 'audit']],
            ]],
        ]));

        self::assertSame(['posts.admin.audit'], $surface->kind('routes'));
    }

    /**
     * Every kind present, sorted, de-duplicated — in that canonical form.
     *
     * Not cosmetic. Two maintainers locking the same plugin must produce the
     * same bytes, or the first diff reads as a format change and the review
     * that the file exists for does not happen.
     */
    public function testTheSurfaceIsCanonical(): void
    {
        $surface = ContractSurface::from([
            'events' => ['b.happened', 'a.happened', 'b.happened'],
        ]);

        self::assertSame(['a.happened', 'b.happened'], $surface->kind('events'));
        self::assertSame(ContractSurface::KINDS, array_keys($surface->values));
    }

    /**
     * A framework variable is not the plugin's to promise.
     *
     * Several first-party manifests list APP_KEY and APP_NAME in config[]
     * (posts does), and the first real run of `contract:lock` recorded both as
     * posts' own contract. The plugin would then have been told that tidying
     * those two lines out broke a published promise — about variables that
     * were never its own.
     */
    public function testFrameworkOwnedVariablesAreNotLocked(): void
    {
        $surface = ContractSurface::of($this->manifest([
            'config' => [
                ['key' => 'APP_KEY'],
                ['key' => 'APP_NAME'],
                ['key' => 'BOOT_CACHE'],
                ['key' => 'ROUTE_TRAILING_SLASH'],
                ['key' => 'POSTS_PER_PAGE'],
            ],
        ]));

        self::assertSame(['POSTS_PER_PAGE'], $surface->kind('config'));
    }

    public function testALeadingBackslashIsNormalisedAway(): void
    {
        // `use \Plugins\X\Y;` and `exposes: "Plugins\X\Y"` are the same class.
        // Without this, locking from the manifest and comparing against an
        // import would report a removal and an unlocked addition for one value.
        $surface = ContractSurface::from(['contracts' => ['\\Plugins\\X\\API\\Thing']]);

        self::assertSame(['Plugins\\X\\API\\Thing'], $surface->kind('contracts'));
    }

    // ── Writing the first lock ────────────────────────────────────────────────

    public function testAFreshLockMatchesTheSurfaceItWasWrittenFrom(): void
    {
        [$manifest, $lock] = $this->locked(['emits' => ['a.happened']]);

        self::assertSame([], $lock->compare(ContractSurface::of($manifest)));
    }

    public function testTheSavedFileRoundTripsAndIsStable(): void
    {
        [, $lock] = $this->locked(['emits' => ['b.happened', 'a.happened']]);
        $lock->save();

        $first = (string) file_get_contents($lock->path);

        ContractLock::read($lock->path)->save();

        self::assertSame($first, (string) file_get_contents($lock->path));
    }

    // ── Removal ───────────────────────────────────────────────────────────────

    public function testARemovedEventFailsTheCheck(): void
    {
        [, $lock, $dir] = $this->locked(['emits' => ['post.published']]);

        $findings = $lock->compare(ContractSurface::of($this->rewrite($dir, ['emits' => []])));

        self::assertSame(['contract.removed'], $this->codes($findings));
        self::assertStringContainsString('post.published', $findings[0]->message);
    }

    /**
     * The message says what the removal BREAKS, per kind.
     *
     * "Event [x] was published and is now gone" is a fact; "every listener
     * subscribed to this name simply stops running — no error anywhere" is the
     * reason anyone should care, and it is different for each of the five.
     */
    public function testTheRemovalMessageNamesTheConsequence(): void
    {
        [, $lock, $dir] = $this->locked(['emits' => ['post.published'], 'solves' => 'posts.publishing']);

        $findings = $lock->compare(ContractSurface::of($this->rewrite($dir, [
            'emits'  => [],
            'solves' => 'posts.renamed',
        ])));

        $fixes = implode("\n", array_map(static fn (Finding $f): string => $f->fix, $findings));

        self::assertStringContainsString('stops running', $fixes, 'the event consequence');
        self::assertStringContainsString('FAILS TO BOOT', $fixes, 'the solves consequence');
    }

    public function testARenamedDomainIsBothARemovalAndAnUnlockedAddition(): void
    {
        [, $lock, $dir] = $this->locked(['solves' => 'posts.publishing']);

        $codes = $this->codes($lock->compare(
            ContractSurface::of($this->rewrite($dir, ['solves' => 'posts.renamed'])),
        ));

        self::assertSame(['contract.removed', 'contract.unlocked'], $codes);
    }

    // ── Unlocked additions ────────────────────────────────────────────────────

    /**
     * The half that makes the other half trustworthy.
     *
     * A lock that only caught removals would silently stop covering everything
     * added after it was written — and would be most incomplete exactly when
     * the plugin had changed the most.
     */
    public function testANewEventIsReportedUntilItIsRecorded(): void
    {
        [, $lock, $dir] = $this->locked(['emits' => ['a.happened']]);

        $manifest = $this->rewrite($dir, ['emits' => ['a.happened', 'b.happened']]);

        self::assertSame(['contract.unlocked'], $this->codes($lock->compare(ContractSurface::of($manifest))));

        $added = $lock->record(ContractSurface::of($manifest));

        self::assertSame(['events' => ['b.happened']], $added);
        self::assertSame([], $lock->compare(ContractSurface::of($manifest)));
    }

    /** `record()` adds and never subtracts — there is no flag that drops a record. */
    public function testRecordingCannotEraseARemoval(): void
    {
        [, $lock, $dir] = $this->locked(['emits' => ['a.happened']]);

        $manifest = $this->rewrite($dir, ['emits' => []]);
        $lock->record(ContractSurface::of($manifest));

        self::assertSame(['contract.removed'], $this->codes($lock->compare(ContractSurface::of($manifest))));
        self::assertSame(['a.happened'], $lock->locked('events'));
    }

    // ── Retirement ────────────────────────────────────────────────────────────

    public function testADeprecatedValueMayDisappearFromTheManifest(): void
    {
        [, $lock, $dir] = $this->locked(['emits' => ['post.legacy']]);

        self::assertSame(['events'], $lock->deprecate('post.legacy'));
        self::assertSame([], $lock->compare(ContractSurface::of($this->rewrite($dir, ['emits' => []]))));
    }

    /**
     * Retirement keeps the record. That is the whole mechanism: a freed string
     * could be reused next release for something that means the opposite.
     */
    public function testRetirementKeepsTheRecord(): void
    {
        [, $lock] = $this->locked(['emits' => ['post.legacy']]);
        $lock->deprecate('post.legacy');

        self::assertSame(['post.legacy'], $lock->locked('events'));
        self::assertTrue($lock->isDeprecated('events', 'post.legacy'));
    }

    public function testADeprecatedValueThatIsStillPublishedIsNotAFinding(): void
    {
        // The soft period: announced as going, still served.
        [$manifest, $lock] = $this->locked(['emits' => ['post.legacy']]);
        $lock->deprecate('post.legacy');

        self::assertSame([], $lock->compare(ContractSurface::of($manifest)));
    }

    public function testRetiringSomethingThatWasNeverPublishedFindsNothing(): void
    {
        [, $lock] = $this->locked(['emits' => ['a.happened']]);

        self::assertSame([], $lock->deprecate('never.existed'));
    }

    // ── A lock that is itself wrong ───────────────────────────────────────────

    /**
     * Deleting the surfaces line and leaving the deprecated one is how someone
     * half-removes a record — and it frees the string, which is the one outcome
     * the file exists to prevent.
     */
    public function testDeprecatingWithoutRecordingIsReportedAsMalformed(): void
    {
        $dir      = $this->pluginDir(['emits' => ['a.happened']]);
        $manifest = PluginManifest::fromPath('Plugins\\Temp\\Provider', $dir . '/module.json');

        file_put_contents(ContractLock::pathFor($manifest), (string) json_encode([
            'lockVersion' => 1,
            'surfaces'    => ['events' => []],
            'deprecated'  => ['events' => ['a.happened']],
        ]));

        $findings = ContractLock::read(ContractLock::pathFor($manifest))->compare(ContractSurface::of($manifest));

        self::assertSame(['contract.lock-malformed'], $this->codes($findings));
    }

    /**
     * A malformed lock reports ONLY that.
     *
     * Burying "your lock file is broken" under twenty "this is unlocked" lines
     * describes the lock rather than the plugin, and is how a tool gets ignored.
     */
    public function testAMalformedLockShortCircuitsTheComparison(): void
    {
        $dir      = $this->pluginDir(['emits' => ['a.happened', 'b.happened']]);
        $manifest = PluginManifest::fromPath('Plugins\\Temp\\Provider', $dir . '/module.json');

        file_put_contents(ContractLock::pathFor($manifest), (string) json_encode([
            'surfaces'   => ['nonsense' => ['x']],
            'deprecated' => [],
        ]));

        $codes = $this->codes(
            ContractLock::read(ContractLock::pathFor($manifest))->compare(ContractSurface::of($manifest)),
        );

        self::assertSame(['contract.lock-malformed'], $codes);
        self::assertNotContains('contract.unlocked', $codes);
    }

    // ── Wired into plugin:check ───────────────────────────────────────────────

    public function testTheInspectorIsSilentForAPluginWithNoLock(): void
    {
        $dir   = $this->pluginDir(['emits' => ['a.happened']]);
        $codes = $this->codes($this->inspect($dir));

        self::assertNotContains('contract.unlocked', $codes);
        self::assertNotContains('contract.removed', $codes);
    }

    public function testTheInspectorReportsAContractRemovalOnceLocked(): void
    {
        $dir      = $this->pluginDir(['emits' => ['post.published']]);
        $manifest = PluginManifest::fromPath('Plugins\\Temp\\Provider', $dir . '/module.json');
        ContractLock::from(ContractSurface::of($manifest), ContractLock::pathFor($manifest))->save();

        $this->rewrite($dir, ['emits' => []]);

        self::assertContains('contract.removed', $this->codes($this->inspect($dir)));
    }

    // ── Cross-plugin imports ──────────────────────────────────────────────────

    public function testImportingANeighboursDomainClassIsAnError(): void
    {
        $dir = $this->pluginDir([], [
            'Application/Services/Thing.php' => <<<'PHP'
            <?php
            namespace Plugins\Temp\Application\Services;
            use Plugins\Image\Domain\ValueObjects\ResizeMode;
            final class Thing {}
            PHP,
        ]);

        $findings = $this->inspect($dir, $this->neighbours());

        self::assertContains('access.cross-plugin', $this->codes($findings));
    }

    public function testTheErrorNamesTheLayerAndPointsAtTheApiNamespace(): void
    {
        $dir = $this->pluginDir([], [
            'Infrastructure/Cli/Command.php' => <<<'PHP'
            <?php
            namespace Plugins\Temp\Infrastructure\Cli;
            use Plugins\Tenancy\Domain\Entities\Tenant;
            final class Command {}
            PHP,
        ]);

        $finding = $this->first($this->inspect($dir, $this->neighbours()), 'access.cross-plugin');

        self::assertStringContainsString('Domain layer', $finding->message);
        self::assertStringContainsString('Plugins\\Tenancy\\API\\Contracts\\', $finding->fix);
        self::assertStringContainsString('requires[]', $finding->fix);
    }

    /**
     * The override that makes the rule honest: module.json is the single source
     * of truth for what a module publishes. Cookie lists its CookieJar in
     * exposes[] and it sits under Infrastructure/ — public, by its owner's
     * declaration. Eight of the 25 real hits in the workspace were this.
     */
    public function testAnExposedClassIsAllowedEvenUnderInfrastructure(): void
    {
        $dir = $this->pluginDir([], [
            'Infrastructure/Store.php' => <<<'PHP'
            <?php
            namespace Plugins\Temp\Infrastructure;
            use Plugins\Cookie\Infrastructure\CookieJar;
            final class Store {}
            PHP,
        ]);

        $codes = $this->codes($this->inspect($dir, $this->neighbours()));

        self::assertNotContains('access.cross-plugin', $codes);
    }

    public function testApiImportsAreAlwaysFine(): void
    {
        $dir = $this->pluginDir([], [
            'Application/Services/Thing.php' => <<<'PHP'
            <?php
            namespace Plugins\Temp\Application\Services;
            use Plugins\User\API\Contracts\UserServiceContract;
            final class Thing {}
            PHP,
        ]);

        self::assertNotContains('access.cross-plugin', $this->codes($this->inspect($dir, $this->neighbours())));
    }

    /**
     * A neighbour's ROOT-level class, and its own published surface directory,
     * are not the three internal layers.
     *
     * Measured: the broad "anything but API/" form reported 54 imports across
     * the 69 first-party plugins, 29 of them exactly this shape and every one
     * of them deliberate. A check wrong about more than half of what it says
     * teaches people to ignore all of it.
     */
    public function testARootLevelOrPublishedSurfaceClassIsNotReported(): void
    {
        $dir = $this->pluginDir([], [
            'API/DTOs/Dto.php' => <<<'PHP'
            <?php
            namespace Plugins\Temp\API\DTOs;
            use Plugins\Validation\AbstractDto;
            use Plugins\Pageflow\Http\PageflowResponder;
            final class Dto extends AbstractDto {}
            PHP,
        ]);

        self::assertNotContains('access.cross-plugin', $this->codes($this->inspect($dir, $this->neighbours())));
    }

    public function testAPluginsOwnInternalsAreItsOwnBusiness(): void
    {
        $dir = $this->pluginDir([], [
            'Application/Services/Thing.php' => <<<'PHP'
            <?php
            namespace Plugins\Temp\Application\Services;
            use Plugins\Temp\Infrastructure\Persistence\ThingRepository;
            use Plugins\Temp\Domain\Entities\Thing as Entity;
            final class Thing {}
            PHP,
        ]);

        self::assertNotContains('access.cross-plugin', $this->codes($this->inspect($dir, $this->neighbours())));
    }

    /**
     * With no neighbours there is no exposes[] to consult, so every legitimate
     * import of a declared contract would be reported. The inspector's contract
     * is that neighbours-empty narrows what it concludes, not that it guesses.
     */
    public function testTheRuleIsInertWithoutNeighbours(): void
    {
        $dir = $this->pluginDir([], [
            'Infrastructure/Store.php' => <<<'PHP'
            <?php
            namespace Plugins\Temp\Infrastructure;
            use Plugins\Cookie\Infrastructure\CookieJar;
            final class Store {}
            PHP,
        ]);

        self::assertNotContains('access.cross-plugin', $this->codes($this->inspect($dir)));
    }

    /** `modules/` packages are not `Plugins\` — the rule has nothing to say about them. */
    public function testTheRuleIsInertForANonPluginNamespace(): void
    {
        $dir = $this->pluginDir([], [
            'Infrastructure/Store.php' => <<<'PHP'
            <?php
            namespace AlfacodeTeam\Thing\Infrastructure;
            use Plugins\Tenancy\Domain\Entities\Tenant;
            final class Store {}
            PHP,
        ]);

        $findings = (new Inspector(
            PluginManifest::fromPath('AlfacodeTeam\\Thing\\Provider', $dir . '/module.json'),
            $this->neighbours(),
        ))->run();

        self::assertNotContains('access.cross-plugin', $this->codes($findings));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed> $manifest
     * @return array{0: PluginManifest, 1: ContractLock, 2: string}
     */
    private function locked(array $manifest): array
    {
        $dir      = $this->pluginDir($manifest);
        $plugin   = PluginManifest::fromPath('Plugins\\Temp\\Provider', $dir . '/module.json');
        $lock     = ContractLock::from(ContractSurface::of($plugin), ContractLock::pathFor($plugin));

        return [$plugin, $lock, $dir];
    }

    /**
     * Replace a temp plugin's manifest and re-read it.
     *
     * PluginManifest memoizes by PROVIDER CLASS, and every fixture here shares
     * `Plugins\Temp\Provider` — so this reads by PATH, which is not cached.
     * Going through `of()` would hand back the first fixture's manifest and
     * every "removal" assertion would pass for the wrong reason.
     *
     * @param array<string, mixed> $manifest
     */
    private function rewrite(string $dir, array $manifest): PluginManifest
    {
        $existing = json_decode((string) file_get_contents($dir . '/module.json'), true);
        $existing = \is_array($existing) ? $existing : [];

        file_put_contents($dir . '/module.json', (string) json_encode([...$existing, ...$manifest]));

        return PluginManifest::fromPath('Plugins\\Temp\\Provider', $dir . '/module.json');
    }

    /** @param array<string, mixed> $manifest */
    private function manifest(array $manifest): PluginManifest
    {
        return PluginManifest::fromPath('Plugins\\Temp\\Provider', $this->pluginDir($manifest) . '/module.json');
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string> $files
     */
    private function pluginDir(array $manifest = [], array $files = []): string
    {
        $dir = sys_get_temp_dir() . '/ground-contract-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->temporary[] = $dir;

        file_put_contents($dir . '/module.json', (string) json_encode([
            'name'     => 'temp',
            'solves'   => 'temp.thing',
            'requires' => [],
            'exposes'  => [],
            'routes'   => [],
            'emits'    => [],
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

    /**
     * Neighbours whose exposes[] mirror the real first-party declarations.
     *
     * @return array<string, PluginManifest>
     */
    private function neighbours(): array
    {
        $out = [];

        foreach ([
            'cookie.jar'      => ['cookie', ['Plugins\\Cookie\\Infrastructure\\CookieJar']],
            'pageflow.render' => ['pageflow', ['Plugins\\Pageflow\\Http\\PageflowResponder']],
            'image.transform' => ['image', []],
            'tenancy.routing' => ['tenancy', []],
            'user.management' => ['user', ['Plugins\\User\\API\\Contracts\\UserServiceContract']],
            'validation.rules' => ['validation', ['Plugins\\Validation\\AbstractDto']],
        ] as $domain => [$name, $exposes]) {
            $dir = $this->pluginDir(['name' => $name, 'solves' => $domain, 'exposes' => $exposes]);

            $out[$domain] = PluginManifest::fromPath(
                'Plugins\\' . ucfirst($name) . '\\Provider',
                $dir . '/module.json',
            );
        }

        return $out;
    }

    /**
     * @param  array<string, PluginManifest> $neighbours
     * @return list<Finding>
     */
    private function inspect(string $dir, array $neighbours = []): array
    {
        return (new Inspector(
            PluginManifest::fromPath('Plugins\\Temp\\Provider', $dir . '/module.json'),
            $neighbours,
        ))->run();
    }

    /**
     * @param  list<Finding> $findings
     * @return list<string>
     */
    private function codes(array $findings): array
    {
        return array_values(array_map(static fn (Finding $f): string => $f->code, $findings));
    }

    /** @param list<Finding> $findings */
    private function first(array $findings, string $code): Finding
    {
        foreach ($findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }

        self::fail("No finding with code [{$code}]. Got: " . implode(', ', $this->codes($findings)) . '.');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
