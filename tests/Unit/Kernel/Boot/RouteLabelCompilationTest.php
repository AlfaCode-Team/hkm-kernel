<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing\RouteLabels;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileRouteManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `audit` / `metric` / `emits` on a route: compiled when well-formed, a BOOT
 * failure when not, and ABSENT — not empty — when undeclared, so an existing
 * module.json compiles to the manifest it always did.
 */
#[CoversClass(RouteLabels::class)]
#[CoversClass(CompileRouteManifestStage::class)]
final class RouteLabelCompilationTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-routelabel-' . bin2hex(random_bytes(6));
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
        foreach (glob($this->root . '/plugin*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->root . '/plugin*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @param array<string, mixed>       $extra  extra module.json keys (emits, groups, …)
     * @return class-string
     */
    private function plugin(array $routes, array $extra = []): string
    {
        $dir = $this->root . '/plugin' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/module.json', json_encode([
            'name'   => 'demo',
            'solves' => 'demo.' . bin2hex(random_bytes(3)),
            'routes' => $routes,
        ] + $extra));

        $class = 'LabelStub' . bin2hex(random_bytes(4));
        file_put_contents($dir . '/Provider.php', "<?php class {$class} {}");
        require $dir . '/Provider.php';

        return $class;
    }

    /**
     * @param list<class-string>         $modules
     * @param list<array<string, mixed>> $projectRoutes
     * @param array<string, mixed>       $projectGroups
     * @return array<string, array<string, mixed>>
     */
    private function compile(array $modules, array $projectRoutes = [], array $projectGroups = []): array
    {
        (new CompileRouteManifestStage(
            $modules,
            projectRoutes: $projectRoutes,
            reader: new ManifestReader(),
            projectGroups: $projectGroups,
        ))->run();

        return ManifestReader::readCompiled('route-manifest.php');
    }

    /** @param array<string, mixed> $labels */
    private function labelled(array $labels, string $emitsDeclared = ''): string
    {
        return $this->plugin(
            [['method' => 'GET', 'path' => '/x', 'handler' => 'P\\C@a'] + $labels],
            $emitsDeclared === '' ? [] : ['emits' => [$emitsDeclared]],
        );
    }

    private function failureOf(callable $compile): string
    {
        try {
            $compile();
        } catch (BootException $e) {
            return $e->getMessage();
        }

        self::fail('Expected the boot to fail.');
    }

    // ── Each key compiles ────────────────────────────────────────────────────

    public function test_audit_compiles_to_one_action_name(): void
    {
        $m = $this->compile([$this->labelled(['audit' => 'invoice.create'])]);

        self::assertSame(['audit' => 'invoice.create'], $m['GET /x']['labels']);
    }

    public function test_metric_compiles_from_a_string_or_a_list(): void
    {
        $one  = $this->compile([$this->labelled(['metric' => 'a.b'])]);
        $many = $this->compile([$this->labelled(['metric' => ['a.b', 'c:d']])]);

        self::assertSame(['metric' => ['a.b']], $one['GET /x']['labels']);
        self::assertSame(['metric' => ['a.b', 'c:d']], $many['GET /x']['labels']);
    }

    public function test_emits_compiles_when_the_module_declares_the_event(): void
    {
        $m = $this->compile([$this->labelled(['emits' => 'invoice.created'], 'invoice.created')]);

        self::assertSame(['emits' => ['invoice.created']], $m['GET /x']['labels']);
    }

    public function test_duplicates_in_a_list_collapse(): void
    {
        $m = $this->compile([$this->labelled(['metric' => ['a.b', 'a.b']])]);

        self::assertSame(['metric' => ['a.b']], $m['GET /x']['labels']);
    }

    public function test_labels_flow_through_groups_and_the_project_layer(): void
    {
        $m = $this->compile(
            [$this->plugin([], ['groups' => [[
                'prefix' => '/g',
                'routes' => [['method' => 'GET', 'path' => '/y', 'handler' => 'P\\C@a', 'metric' => 'g.y']],
            ]]])],
            [['method' => 'GET', 'path' => '/p', 'handler' => 'P\\C@a', 'audit' => 'p.seen']],
        );

        self::assertSame(['metric' => ['g.y']], $m['GET /g/y']['labels']);
        self::assertSame(['audit' => 'p.seen'], $m['GET /p']['labels']);
    }

    // ── ABSENT by default ────────────────────────────────────────────────────

    public function test_a_route_without_labels_has_no_labels_key_and_the_manifest_is_unchanged(): void
    {
        $plain = $this->compile([$this->plugin([['method' => 'GET', 'path' => '/x', 'handler' => 'P\\C@a']])]);

        self::assertArrayNotHasKey('labels', $plain['GET /x']);

        // Null and empty-list values mean "none declared" and add no key either.
        $nulls = $this->compile([$this->labelled(['audit' => null, 'metric' => [], 'emits' => null])]);
        self::assertArrayNotHasKey('labels', $nulls['GET /x']);

        // Byte-identical: only the plugin-specific bits differ between the two builds.
        $a = $plain['GET /x'];
        $b = $nulls['GET /x'];
        unset($a['module'], $a['solves'], $a['graph_key'], $b['module'], $b['solves'], $b['graph_key']);
        self::assertSame($a, $b);
    }

    // ── Malformed values FAIL THE BOOT, and say why ──────────────────────────

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function malformed(): array
    {
        return [
            'audit not a string'      => [['audit' => 5], 'audit label that is not a string'],
            'audit a list'            => [['audit' => ['a.b']], 'audit label that is not a string'],
            'audit empty string'      => [['audit' => ''], 'audit label []'],
            'audit upper case'        => [['audit' => 'Invoice.Create'], 'audit label [Invoice.Create]'],
            'audit leading digit'     => [['audit' => '1abc'], 'audit label [1abc]'],
            'audit with a space'      => [['audit' => 'a b'], 'audit label [a b]'],
            'metric a number'         => [['metric' => 7], 'neither a name nor a list'],
            'metric true'             => [['metric' => true], 'neither a name nor a list'],
            'metric object'           => [['metric' => ['k' => 'a.b']], 'neither a name nor a list'],
            'metric non-string item'  => [['metric' => ['a.b', 3]], 'metric label that is not a string'],
            'metric bad name'         => [['metric' => ['a.b', 'bad name']], 'metric label [bad name]'],
            'metric too long'         => [['metric' => str_repeat('a', 129)], 'at most 128'],
            'emits bad name'          => [['emits' => 'Order Created'], 'emits label [Order Created]'],
            'too many names'          => [['metric' => array_map(static fn(int $i): string => "m.$i", range(1, 17))], 'the limit is 16'],
        ];
    }

    /** @param array<string, mixed> $labels */
    #[DataProvider('malformed')]
    public function test_a_malformed_label_fails_the_boot_naming_the_route(array $labels, string $reason): void
    {
        $message = $this->failureOf(fn() => $this->compile([$this->labelled($labels, 'declared.event')]));

        self::assertStringContainsString($reason, $message);
        self::assertStringContainsString('GET /x', $message);
    }

    public function test_a_plugin_route_emitting_an_undeclared_event_fails_the_boot(): void
    {
        $message = $this->failureOf(fn() => $this->compile([$this->labelled(['emits' => 'invoice.created'], 'invoice.paid')]));

        self::assertStringContainsString('emits [invoice.created]', $message);
        self::assertStringContainsString('emits[]', $message);
    }

    public function test_a_plugin_with_no_emits_list_at_all_cannot_emit(): void
    {
        $message = $this->failureOf(fn() => $this->compile([$this->labelled(['emits' => 'invoice.created'])]));

        self::assertStringContainsString('emits [invoice.created]', $message);
    }

    /** @return array<string, array{string}> */
    public static function labelKeys(): array
    {
        return ['audit' => ['audit'], 'metric' => ['metric'], 'emits' => ['emits']];
    }

    /**
     * On a group a label would apply to nothing — the very silent no-op the
     * compiler exists to refuse.
     */
    #[DataProvider('labelKeys')]
    public function test_a_label_on_a_group_fails_the_boot(string $key): void
    {
        $value   = $key === 'metric' ? ['a.b'] : 'a.b';
        $message = $this->failureOf(fn() => $this->compile([$this->plugin([], ['groups' => [[
            'prefix' => '/g', $key => $value,
            'routes' => [['method' => 'GET', 'path' => '/y', 'handler' => 'P\\C@a']],
        ]]])]));

        self::assertStringContainsString("declares [{$key}]", $message);
        self::assertStringContainsString('individual route', $message);
    }

    public function test_a_label_on_the_project_group_root_fails_the_boot(): void
    {
        $message = $this->failureOf(fn() => $this->compile([], [], ['metric' => ['a.b'], 'routes' => []]));

        self::assertStringContainsString('declares [metric]', $message);
    }

    /** A module root's own `emits` is the module's published-event list, not a label. */
    public function test_a_module_roots_emits_list_is_not_mistaken_for_a_label(): void
    {
        $m = $this->compile([$this->plugin(
            [['method' => 'GET', 'path' => '/x', 'handler' => 'P\\C@a']],
            ['emits' => ['a.b']],
        )]);

        self::assertArrayNotHasKey('labels', $m['GET /x']);
    }

    public function test_a_module_root_audit_or_metric_fails_the_boot(): void
    {
        $message = $this->failureOf(fn() => $this->compile([$this->plugin([], ['metric' => 'a.b'])]));

        self::assertStringContainsString('declares [metric]', $message);
    }
}
