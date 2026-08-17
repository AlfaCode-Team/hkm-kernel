<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileRouteManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\RouteMatcher;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Route GROUPS and DOMAIN grouping.
 *
 * A group states once what would otherwise be repeated on every route inside it.
 * A group may also name the DOMAIN its routes answer on — written literally, as a
 * host, a wildcard or a bare subdomain. The compiler groups by that string
 * VERBATIM: it does not resolve it, look it up, or check that this deployment
 * serves it. Because the domain is part of the route KEY (not a post-match
 * filter), one project can answer the same path differently per host.
 */
#[CoversClass(CompileRouteManifestStage::class)]
#[CoversClass(RouteIndex::class)]
#[CoversClass(RouteMatcher::class)]
final class RouteGroupTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-routegroup-' . bin2hex(random_bytes(6));
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
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    /**
     * @param array<string, mixed>       $groups
     * @param list<array<string, mixed>> $projectRoutes
     * @param list<string>               $disable
     * @param list<string>               $domains
     */
    private function compile(
        array $groups = [],
        array $projectRoutes = [],
        array $disable = [],
        array $domains = [],
    ): void {
        (new CompileRouteManifestStage(
            [],
            projectRoutes: $projectRoutes,
            disabledRoutes: $disable,
            reader: new ManifestReader(),
            projectGroups: $groups,
            projectDomains: $domains,
        ))->run();
    }

    /** @return array<string, mixed> */
    private function manifest(string $file = 'route-manifest.php'): array
    {
        return ManifestReader::readCompiled($file);
    }

    private function matcher(): RouteMatcher
    {
        return RouteMatcher::fromCompiled($this->manifest('route-index.php'));
    }

    /** @return array{entry: array<string, mixed>, params: array<string, string>}|null */
    private function matchHost(string $path, string $host): ?array
    {
        return $this->matcher()->match('GET', $path, RouteIndex::hostCandidates($host));
    }

    // ── Key format ──────────────────────────────────────────────────────────

    public function test_an_ungrouped_route_key_is_unchanged(): void
    {
        self::assertSame('GET /x', RouteIndex::key('GET', '', '/x'));
        self::assertSame(
            ['method' => 'GET', 'domain' => '', 'path' => '/x'],
            RouteIndex::parseKey('GET /x'),
        );
    }

    public function test_a_grouped_key_keeps_the_path_parseable_by_an_older_reader(): void
    {
        $key = RouteIndex::key('GET', 'africavoting.local', '/dash');

        self::assertSame('GET@africavoting.local /dash', $key);

        // The decisive property: a consumer that splits on the first space and
        // has never heard of domain groups still gets a clean, leading-slash
        // path, and sees a method no HTTP verb matches — so it SKIPS rather than
        // emitting a corrupted URL.
        [$verb, $path] = explode(' ', $key, 2);
        self::assertSame('/dash', $path);
        self::assertNotSame('GET', strtoupper($verb));
    }

    // ── Grouping ────────────────────────────────────────────────────────────

    public function test_a_group_applies_its_prefix_filters_and_name(): void
    {
        $this->compile([
            'groups' => [[
                'prefix'  => '/admin',
                'filters' => ['auth'],
                'name'    => 'admin.',
                'routes'  => [
                    ['method' => 'GET', 'path' => '/users', 'handler' => 'A\\C@index', 'name' => 'users'],
                ],
            ]],
        ]);

        $entry = $this->manifest()['GET /admin/users'];

        self::assertSame(['auth'], $entry['filters']);
        self::assertSame('admin.users', $entry['name']);
    }

    public function test_groups_nest_and_accumulate(): void
    {
        $this->compile([
            'routePrefix' => '/api',
            'groups' => [[
                'prefix'  => '/v1',
                'filters' => ['auth'],
                'name'    => 'api.',
                'groups'  => [[
                    'prefix'  => '/admin',
                    'filters' => ['shield'],
                    'name'    => 'admin.',
                    'routes'  => [
                        ['method' => 'GET', 'path' => '/stats', 'handler' => 'A\\C@stats', 'name' => 'stats'],
                    ],
                ]],
            ]],
        ]);

        $entry = $this->manifest()['GET /api/v1/admin/stats'];

        self::assertSame(['auth', 'shield'], $entry['filters']);
        self::assertSame('api.admin.stats', $entry['name']);
    }

    public function test_an_inner_declaration_overrides_an_outer_filter_of_the_same_alias(): void
    {
        $this->compile([
            'routeFilters' => ['throttle:60,1'],
            'groups' => [[
                'routes' => [
                    ['method' => 'GET', 'path' => '/burst', 'handler' => 'A\\C@x', 'filters' => ['throttle:5,1']],
                ],
            ]],
        ]);

        // Replaced, not doubled — running the throttle stage twice with two
        // different budgets is never what was meant.
        self::assertSame(['throttle:5,1'], $this->manifest()['GET /burst']['filters']);
    }

    public function test_an_unnamed_route_stays_unnamed_inside_a_named_group(): void
    {
        // A group's name is a PREFIX for routes that opted into a name; it does
        // not invent names for routes that never asked for one.
        $this->compile([
            'groups' => [[
                'name'   => 'admin.',
                'routes' => [['method' => 'GET', 'path' => '/a', 'handler' => 'A\\C@a']],
            ]],
        ]);

        self::assertNull($this->manifest()['GET /a']['name']);
        self::assertSame([], $this->manifest('route-names.php'));
    }

    public function test_routes_declared_alongside_groups_are_not_dropped(): void
    {
        // withRoutes() routes and a routes[] passed to withRouteGroups() are BOTH
        // the project's, so they concatenate. This was an array union, which keeps
        // the LEFT key — so the second list vanished without a word.
        $this->compile(
            ['routePrefix' => '/api/v2', 'routes' => [
                ['method' => 'GET', 'path' => '/ping', 'handler' => 'A\\C@ping'],
            ]],
            [['method' => 'GET', 'path' => '/direct', 'handler' => 'A\\C@direct']],
        );

        $manifest = $this->manifest();

        self::assertArrayHasKey('GET /api/v2/ping', $manifest, 'routes[] beside groups must survive');
        self::assertArrayHasKey('GET /api/v2/direct', $manifest, 'withRoutes() routes must survive');
    }

    public function test_a_route_replaces_a_module_wide_filter_of_the_same_alias(): void
    {
        $this->compile([
            'routeFilters' => ['throttle:60,1'],
            'routes' => [
                ['method' => 'GET',  'path' => '/ping',   'handler' => 'A\\C@ping'],
                ['method' => 'POST', 'path' => '/import', 'handler' => 'A\\C@import',
                 'filters' => ['auth', 'throttle:5,1']],
            ],
        ]);

        $manifest = $this->manifest();

        self::assertSame(['throttle:60,1'], $manifest['GET /ping']['filters']);
        self::assertSame(['auth', 'throttle:5,1'], $manifest['POST /import']['filters']);
    }

    public function test_runaway_group_nesting_fails_the_boot(): void
    {
        $group = ['routes' => [['method' => 'GET', 'path' => '/x', 'handler' => 'A\\C@x']]];
        for ($i = 0; $i < 20; $i++) {
            $group = ['groups' => [$group]];
        }

        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/nest more than/');

        $this->compile($group);
    }

    // ── The compiler GROUPS, it does not VERIFY ─────────────────────────────

    public function test_the_domain_is_taken_verbatim(): void
    {
        $this->compile([
            'groups' => [
                ['domain' => 'africavoting.local',   'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\Af@home']]],
                ['domain' => '*.africavoting.local', 'routes' => [['method' => 'GET', 'path' => '/w', 'handler' => 'A\\W@home']]],
                ['subdomain' => 'organizer',         'routes' => [['method' => 'GET', 'path' => '/o', 'handler' => 'A\\Or@home']]],
            ],
        ]);

        $manifest = $this->manifest();

        self::assertArrayHasKey('GET@africavoting.local /', $manifest);
        self::assertArrayHasKey('GET@*.africavoting.local /w', $manifest);
        self::assertArrayHasKey('GET@organizer /o', $manifest);
    }

    public function test_a_domain_this_deployment_does_not_serve_still_compiles(): void
    {
        // Nothing is resolved or checked. A domain nothing requests simply never
        // matches — exactly like a path nothing requests.
        $this->compile([
            'groups' => [['domain' => 'not-a-host-we-serve.example', 'routes' => [
                ['method' => 'GET', 'path' => '/x', 'handler' => 'A\\C@x'],
            ]]],
        ]);

        self::assertArrayHasKey('GET@not-a-host-we-serve.example /x', $this->manifest());
    }

    public function test_the_domain_is_lower_cased(): void
    {
        $this->compile([
            'groups' => [['domain' => '  AfricaVoting.LOCAL ', 'routes' => [
                ['method' => 'GET', 'path' => '/x', 'handler' => 'A\\C@x'],
            ]]],
        ]);

        self::assertArrayHasKey('GET@africavoting.local /x', $this->manifest());
    }

    // ── Host → group matching ───────────────────────────────────────────────

    public function test_host_candidates_are_ordered_most_specific_first(): void
    {
        self::assertSame(
            ['organizer.africavoting.local', '*.africavoting.local', '*.local', 'organizer'],
            RouteIndex::hostCandidates('organizer.africavoting.local'),
        );

        // No subdomain to speak of, so no bare label.
        self::assertSame(['hkmvote.local', '*.local'], RouteIndex::hostCandidates('hkmvote.local'));
        self::assertSame(['localhost'], RouteIndex::hostCandidates('localhost'));
        self::assertSame([], RouteIndex::hostCandidates(''));
    }

    public function test_a_port_and_case_are_stripped_from_the_request_host(): void
    {
        self::assertSame(['hkmvote.local', '*.local'], RouteIndex::hostCandidates('HKMVote.local:8443'));
    }

    public function test_two_domains_may_declare_the_same_path(): void
    {
        $this->compile([
            'groups' => [
                ['domain' => 'hkmvote.local',     'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\Vote@home']]],
                ['domain' => 'africavoting.local', 'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\Africa@home']]],
            ],
        ]);

        // As one key these would have been a duplicate-route boot failure, and
        // `faces` could only have hidden one of them.
        self::assertSame('A\\Vote@home', $this->matchHost('/', 'hkmvote.local')['entry']['handler']);
        self::assertSame('A\\Africa@home', $this->matchHost('/', 'africavoting.local')['entry']['handler']);
        self::assertNull($this->matchHost('/', 'unknown.example'));
    }

    public function test_a_bare_subdomain_group_matches_that_subdomain_on_any_host(): void
    {
        $this->compile([
            'groups' => [['subdomain' => 'organizer', 'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'A\\Org@home'],
            ]]],
        ]);

        self::assertSame('A\\Org@home', $this->matchHost('/', 'organizer.africavoting.local')['entry']['handler']);
        self::assertSame('A\\Org@home', $this->matchHost('/', 'organizer.hkmvote.local')['entry']['handler']);
        self::assertNull($this->matchHost('/', 'app.hkmvote.local'));
    }

    public function test_a_wildcard_group_matches_any_subdomain_of_its_parent(): void
    {
        $this->compile([
            'groups' => [['domain' => '*.africavoting.local', 'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'A\\Wild@home'],
            ]]],
        ]);

        self::assertSame('A\\Wild@home', $this->matchHost('/', 'news.africavoting.local')['entry']['handler']);
        self::assertNull($this->matchHost('/', 'africavoting.local'), 'the apex is not a subdomain of itself');
    }

    public function test_an_exact_host_beats_a_wildcard_which_beats_a_bare_subdomain(): void
    {
        $this->compile([
            'groups' => [
                ['subdomain' => 'organizer',                     'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\Sub@home']]],
                ['domain'    => '*.africavoting.local',          'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\Wild@home']]],
                ['domain'    => 'organizer.africavoting.local',  'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\Exact@home']]],
            ],
        ]);

        // All three could match; specificity decides, not declaration order.
        self::assertSame('A\\Exact@home', $this->matchHost('/', 'organizer.africavoting.local')['entry']['handler']);
        self::assertSame('A\\Wild@home', $this->matchHost('/', 'news.africavoting.local')['entry']['handler']);
        self::assertSame('A\\Sub@home', $this->matchHost('/', 'organizer.hkmvote.local')['entry']['handler']);
    }

    public function test_a_grouped_route_overrides_the_shared_one_on_that_host_only(): void
    {
        $this->compile(
            ['groups' => [['subdomain' => 'organizer', 'routes' => [
                ['method' => 'GET', 'path' => '/dashboard', 'handler' => 'A\\Organizer@dash'],
            ]]]],
            [['method' => 'GET', 'path' => '/dashboard', 'handler' => 'A\\Shared@dash']],
        );

        self::assertSame('A\\Organizer@dash', $this->matchHost('/dashboard', 'organizer.hkmvote.local')['entry']['handler']);
        self::assertSame('A\\Shared@dash', $this->matchHost('/dashboard', 'app.hkmvote.local')['entry']['handler']);
        self::assertSame('A\\Shared@dash', $this->matcher()->match('GET', '/dashboard')['entry']['handler']);
    }

    public function test_a_shared_static_route_still_beats_a_grouped_dynamic_one(): void
    {
        // Static-beats-dynamic is an invariant. Searching a domain group
        // end-to-end first would let its /users/{id} swallow the shared literal
        // /users/me.
        $this->compile(
            ['groups' => [['domain' => 'hkmvote.local', 'routes' => [
                ['method' => 'GET', 'path' => '/users/{id}', 'handler' => 'A\\Vote@show'],
            ]]]],
            [['method' => 'GET', 'path' => '/users/me', 'handler' => 'A\\Shared@me']],
        );

        self::assertSame('A\\Shared@me', $this->matchHost('/users/me', 'hkmvote.local')['entry']['handler']);
        self::assertSame('A\\Vote@show', $this->matchHost('/users/7', 'hkmvote.local')['entry']['handler']);
    }

    public function test_the_index_reports_which_domains_exist(): void
    {
        $this->compile([
            'groups' => [['domain' => 'hkmvote.local', 'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home'],
            ]]],
        ]);

        self::assertSame(['hkmvote.local'], $this->manifest('route-index.php')['domains']);
    }

    public function test_an_ungrouped_application_is_unaffected(): void
    {
        $this->compile([], [['method' => 'GET', 'path' => '/x', 'handler' => 'A\\C@x']]);

        self::assertSame([], $this->manifest('route-index.php')['domains']);
        self::assertNotNull($this->matcher()->match('GET', '/x'));
        self::assertNotNull($this->matchHost('/x', 'anything.example'));
    }

    // ── Checked against the hosts the project actually serves ───────────────

    public function test_a_registered_domain_compiles(): void
    {
        $this->compile(
            ['groups' => [['domain' => 'africavoting.local', 'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home'],
            ]]]],
            domains: ['hkmvote.local', 'africavoting.local'],
        );

        self::assertArrayHasKey('GET@africavoting.local /', $this->manifest());
    }

    public function test_an_unregistered_domain_fails_the_boot(): void
    {
        // Nothing could ever reach it: a request for that host would have been
        // routed to a different project, or refused, before the router ran.
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/this project does not serve/');

        $this->compile(
            ['groups' => [['domain' => 'typo.africavotng.local', 'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home'],
            ]]]],
            domains: ['hkmvote.local', 'africavoting.local'],
        );
    }

    public function test_the_failure_lists_the_registered_domains(): void
    {
        try {
            $this->compile(
                ['groups' => [['domain' => 'nope.local', 'routes' => [
                    ['method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home'],
                ]]]],
                domains: ['hkmvote.local'],
            );
            self::fail('expected a BootException');
        } catch (BootException $e) {
            self::assertStringContainsString('hkmvote.local', $e->getMessage());
            self::assertStringContainsString('subdomain', $e->getMessage(), 'points at the escape hatch');
        }
    }

    public function test_a_wildcard_passes_when_its_parent_is_registered(): void
    {
        // The right tool for tenant hosts, which land in the database rather
        // than in proj.json.
        $this->compile(
            ['groups' => [['domain' => '*.africavoting.local', 'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home'],
            ]]]],
            domains: ['africavoting.local'],
        );

        self::assertArrayHasKey('GET@*.africavoting.local /', $this->manifest());
    }

    public function test_a_wildcard_passes_when_a_registered_host_falls_under_it(): void
    {
        $this->compile(
            ['groups' => [['domain' => '*.africavoting.local', 'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home'],
            ]]]],
            domains: ['organizer.africavoting.local'],
        );

        self::assertArrayHasKey('GET@*.africavoting.local /', $this->manifest());
    }

    public function test_a_bare_subdomain_is_never_checked(): void
    {
        // It answers on that label across EVERY domain, so there is no single
        // registered host to check it against.
        $this->compile(
            ['groups' => [['subdomain' => 'api', 'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home'],
            ]]]],
            domains: ['example.com'],
        );

        self::assertArrayHasKey('GET@api /', $this->manifest());
    }

    public function test_a_project_that_registers_no_domains_is_not_checked(): void
    {
        $this->compile(['groups' => [['domain' => 'anything.at.all', 'routes' => [
            ['method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home'],
        ]]]]);

        self::assertArrayHasKey('GET@anything.at.all /', $this->manifest());
    }

    // ── The two "global" guarantees ─────────────────────────────────────────

    public function test_an_ungrouped_route_is_reachable_from_every_domain(): void
    {
        $this->compile(
            ['groups' => [['domain' => 'hkmvote.local', 'routes' => [
                ['method' => 'GET', 'path' => '/', 'handler' => 'A\\Vote@home'],
            ]]]],
            [['method' => 'GET', 'path' => '/health', 'handler' => 'A\\Shared@health']],
            domains: ['hkmvote.local', 'africavoting.local'],
        );

        foreach (['hkmvote.local', 'africavoting.local', 'anything.example', 'localhost'] as $host) {
            self::assertSame(
                'A\\Shared@health',
                $this->matchHost('/health', $host)['entry']['handler'],
                "ungrouped route must answer on {$host}",
            );
        }
    }

    public function test_a_subdomain_group_answers_on_that_label_of_every_domain(): void
    {
        // "api" without a domain ⇒ api.example.com AND api.example2.com AND any
        // future host with that first label.
        $this->compile(['groups' => [['subdomain' => 'api', 'prefix' => '/v1', 'routes' => [
            ['method' => 'GET', 'path' => '/ping', 'handler' => 'A\\Api@ping'],
        ]]]]);

        foreach (['api.example.com', 'api.example2.com', 'api.brand-new.test'] as $host) {
            self::assertSame(
                'A\\Api@ping',
                $this->matchHost('/v1/ping', $host)['entry']['handler'],
                "subdomain group must answer on {$host}",
            );
        }

        self::assertNull($this->matchHost('/v1/ping', 'www.example.com'));
    }

    // ── Names stay flat ─────────────────────────────────────────────────────

    public function test_route_names_remain_a_flat_namespace_across_domains(): void
    {
        // Two domains cannot both claim 'home'. UrlGenerator holds no request
        // state — it could not pick between them — so this stays a boot failure
        // and a group's name prefix is the intended fix.
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/Duplicate route name \[home\]/');

        $this->compile(['groups' => [
            ['domain' => 'hkmvote.local',     'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\V@h', 'name' => 'home']]],
            ['domain' => 'africavoting.local', 'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\A@h', 'name' => 'home']]],
        ]]);
    }

    public function test_a_group_name_prefix_disambiguates_two_domains(): void
    {
        $this->compile(['groups' => [
            ['domain' => 'hkmvote.local',      'name' => 'vote.',   'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\V@h', 'name' => 'home']]],
            ['domain' => 'africavoting.local', 'name' => 'africa.', 'routes' => [['method' => 'GET', 'path' => '/', 'handler' => 'A\\A@h', 'name' => 'home']]],
        ]]);

        self::assertSame(
            [
                'vote.home'   => ['path' => '/', 'method' => 'GET', 'domain' => 'hkmvote.local'],
                'africa.home' => ['path' => '/', 'method' => 'GET', 'domain' => 'africavoting.local'],
            ],
            $this->manifest('route-names.php'),
        );
    }

    // ── A LIST of domains ───────────────────────────────────────────────────

    public function test_a_group_may_name_several_domains_at_once(): void
    {
        $this->compile(['groups' => [[
            'domain' => ['a.test', 'b.test'],
            'routes' => [['method' => 'GET', 'path' => '/dash', 'handler' => 'A\\A@h']],
        ]]], domains: ['a.test', 'b.test']);

        // One route per host, each with its own key — so either can later be
        // overridden or disabled without touching the other.
        self::assertArrayHasKey('GET@a.test /dash', $this->manifest());
        self::assertArrayHasKey('GET@b.test /dash', $this->manifest());

        self::assertNotNull($this->matchHost('/dash', 'a.test'));
        self::assertNotNull($this->matchHost('/dash', 'b.test'));
        self::assertNull($this->matchHost('/dash', 'c.test'));
    }

    public function test_a_route_may_name_several_domains_at_once(): void
    {
        $this->compile(domains: ['a.test', 'b.test'], projectRoutes: [
            ['method' => 'GET', 'path' => '/p', 'handler' => 'A\\A@h', 'domain' => ['a.test', 'b.test']],
        ]);

        self::assertArrayHasKey('GET@a.test /p', $this->manifest());
        self::assertArrayHasKey('GET@b.test /p', $this->manifest());
    }

    public function test_a_subdomain_list_answers_on_each_label(): void
    {
        $this->compile(['groups' => [[
            'subdomain' => ['admin', 'staff'],
            'routes'    => [['method' => 'GET', 'path' => '/ops', 'handler' => 'A\\A@h']],
        ]]], domains: ['a.test', 'b.test']);

        self::assertNotNull($this->matchHost('/ops', 'admin.anything.test'));
        self::assertNotNull($this->matchHost('/ops', 'staff.other.test'));
        self::assertNull($this->matchHost('/ops', 'public.anything.test'));
    }

    public function test_a_nested_group_composes_with_an_outer_list(): void
    {
        $this->compile(['groups' => [[
            'domain' => ['a.test', 'b.test'],
            'groups' => [[
                'prefix' => '/admin',
                'routes' => [['method' => 'GET', 'path' => '/x', 'handler' => 'A\\A@h']],
            ]],
        ]]], domains: ['a.test', 'b.test']);

        self::assertArrayHasKey('GET@a.test /admin/x', $this->manifest());
        self::assertArrayHasKey('GET@b.test /admin/x', $this->manifest());
    }

    public function test_a_repeated_domain_does_not_compile_the_route_twice(): void
    {
        // Would otherwise trip the duplicate-route guard and report a conflict
        // the author has to work backwards to recognise as their own copy-paste.
        $this->compile(['groups' => [[
            'domain' => ['a.test', 'A.TEST', ' a.test '],
            'routes' => [['method' => 'GET', 'path' => '/dash', 'handler' => 'A\\A@h']],
        ]]], domains: ['a.test', 'b.test']);

        self::assertCount(1, $this->manifest());
    }

    public function test_every_domain_in_a_list_is_validated(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/does not serve/');

        $this->compile(['groups' => [[
            'domain' => ['a.test', 'not-registered.test'],
            'routes' => [['method' => 'GET', 'path' => '/dash', 'handler' => 'A\\A@h']],
        ]]], domains: ['a.test', 'b.test']);
    }

    public function test_a_non_string_domain_fails_the_boot_instead_of_going_global(): void
    {
        // The regression this guards: a non-string used to fall through
        // is_string() to '', which silently turned "these routes belong to this
        // host" into "these routes answer on EVERY host" — the widest possible
        // outcome, reached by accident, with nothing logged.
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/domain of type/');

        $this->compile(['groups' => [[
            'domain' => 42,
            'routes' => [['method' => 'GET', 'path' => '/dash', 'handler' => 'A\\A@h']],
        ]]], domains: ['a.test', 'b.test']);
    }

    public function test_an_empty_domain_list_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/empty domain list/');

        $this->compile(['groups' => [[
            'domain' => [],
            'routes' => [['method' => 'GET', 'path' => '/dash', 'handler' => 'A\\A@h']],
        ]]], domains: ['a.test', 'b.test']);
    }

    public function test_a_named_route_may_not_span_several_domains(): void
    {
        // Names are one flat namespace; one name cannot mean a different URL
        // per host, because UrlGenerator holds no request state.
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/one flat namespace/');

        $this->compile(domains: ['a.test', 'b.test'], projectRoutes: [
            ['method' => 'GET', 'path' => '/p', 'handler' => 'A\\A@h',
             'name' => 'p', 'domain' => ['a.test', 'b.test']],
        ]);
    }

    // ── domain AND subdomain together (subdomain is RELATIVE to domain) ────

    public function test_a_subdomain_is_attached_to_the_declared_domain(): void
    {
        // A declared `domain` is BOTH a host in its own right AND the parent the
        // subdomain attaches to. Before, the label compiled BARE — and a bare
        // label spans every domain, so this also answered on admin.anyone-else.com.
        $this->compile(['groups' => [[
            'domain'    => 'brand.test',
            'subdomain' => 'admin',
            'routes'    => [['method' => 'GET', 'path' => '/ops', 'handler' => 'A\\A@h']],
        ]]], domains: ['brand.test']);

        self::assertSame(
            ['GET@brand.test /ops', 'GET@admin.brand.test /ops'],
            array_keys($this->manifest()),
        );

        self::assertNotNull($this->matchHost('/ops', 'brand.test'));
        self::assertNotNull($this->matchHost('/ops', 'admin.brand.test'));
        // The decisive one: NOT that label on somebody else's domain.
        self::assertNull($this->matchHost('/ops', 'admin.anyone-else.com'));
    }

    public function test_every_label_attaches_to_every_domain(): void
    {
        $this->compile(['groups' => [[
            'domain'    => ['a.test', 'b.test'],
            'subdomain' => ['admin', 'staff'],
            'routes'    => [['method' => 'GET', 'path' => '/ops', 'handler' => 'A\\A@h']],
        ]]], domains: ['a.test', 'b.test']);

        foreach ([
            'GET@a.test /ops', 'GET@b.test /ops',
            'GET@admin.a.test /ops', 'GET@staff.a.test /ops',
            'GET@admin.b.test /ops', 'GET@staff.b.test /ops',
        ] as $key) {
            self::assertArrayHasKey($key, $this->manifest());
        }
        self::assertCount(6, $this->manifest());
    }

    public function test_a_subdomain_with_no_domain_stays_global(): void
    {
        // Nothing to be relative to, so the documented bare-label meaning holds —
        // this is what puts an `admin` panel on every brand.
        $this->compile(['groups' => [[
            'subdomain' => 'admin',
            'routes'    => [['method' => 'GET', 'path' => '/ops', 'handler' => 'A\\A@h']],
        ]]]);

        self::assertArrayHasKey('GET@admin /ops', $this->manifest());
        self::assertNotNull($this->matchHost('/ops', 'admin.anyone-else.com'));
    }

    public function test_a_composed_host_needs_no_registration_of_its_own(): void
    {
        // Only the PARENT is in proj.json domains[]. DomainResolver reaches this
        // project by suffix match on that parent, so the composed host is
        // reachable and validating it separately would just be busywork.
        $this->compile(['groups' => [[
            'domain'    => 'hkm.local',
            'subdomain' => ['api', 'auth'],
            'routes'    => [['method' => 'GET', 'path' => '/x', 'handler' => 'A\\A@h']],
        ]]], domains: ['hkm.local']);

        self::assertArrayHasKey('GET@api.hkm.local /x', $this->manifest());
        self::assertArrayHasKey('GET@auth.hkm.local /x', $this->manifest());
    }

    public function test_a_subdomain_may_not_attach_to_a_wildcard(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/cannot compose/');

        $this->compile(['groups' => [[
            'domain'    => '*.hkm.local',
            'subdomain' => 'api',
            'routes'    => [['method' => 'GET', 'path' => '/x', 'handler' => 'A\\A@h']],
        ]]], domains: ['hkm.local']);
    }

    public function test_a_wildcard_domain_alone_still_compiles(): void
    {
        // Regression: the wildcard guard above must not fire when there is no
        // subdomain to attach — `{"domain": "*.x"}` composes nothing and is valid.
        $this->compile(['groups' => [[
            'domain' => '*.hkm.local',
            'routes' => [['method' => 'GET', 'path' => '/x', 'handler' => 'A\\A@h']],
        ]]], domains: ['hkm.local']);

        self::assertArrayHasKey('GET@*.hkm.local /x', $this->manifest());
    }

    public function test_a_route_may_attach_a_subdomain_to_its_domain(): void
    {
        $this->compile(domains: ['a.test'], projectRoutes: [
            ['method' => 'GET', 'path' => '/p', 'handler' => 'A\\A@h',
             'domain' => 'a.test', 'subdomain' => 'api'],
        ]);

        self::assertArrayHasKey('GET@a.test /p', $this->manifest());
        self::assertArrayHasKey('GET@api.a.test /p', $this->manifest());
    }

    public function test_a_bad_subdomain_is_reported_even_when_domain_is_valid(): void
    {
        // The subdomain used to be skipped entirely when a domain was present,
        // so a mistake in it could not be reported at all.
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/domain of type/');

        $this->compile(['groups' => [[
            'domain'    => 'a.test',
            'subdomain' => 42,
            'routes'    => [['method' => 'GET', 'path' => '/ops', 'handler' => 'A\\A@h']],
        ]]], domains: ['a.test']);
    }
}
