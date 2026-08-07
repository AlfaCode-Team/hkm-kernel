<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Pipelines\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\RouteMatcher;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteMatcher::class)]
#[CoversClass(RouteParameter::class)]
final class RouteMatcherTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $manifest */
    private function matcher(array $manifest): RouteMatcher
    {
        return new RouteMatcher($manifest);
    }

    /** @param list<string> $paths */
    private function manifest(array $paths, string $method = 'GET'): array
    {
        $manifest = [];
        foreach ($paths as $path) {
            $manifest[$method . ' ' . $path] = ['handler' => 'C@m', 'solves' => 'x'];
        }

        return $manifest;
    }

    // ── Backward compatibility ──────────────────────────────────────────────

    public function test_a_static_route_matches_exactly(): void
    {
        $m = $this->matcher($this->manifest(['/health']));

        self::assertNotNull($m->match('GET', '/health'));
        self::assertNull($m->match('GET', '/healthz'));
    }

    public function test_an_untyped_placeholder_still_means_one_segment(): void
    {
        $m = $this->matcher($this->manifest(['/users/{id}']));

        // Unchanged from before typing existed — this must never regress.
        self::assertSame(['id' => 'abc'], $m->match('GET', '/users/abc')['params']);
        self::assertNull($m->match('GET', '/users/a/b'), 'must not cross a slash');
    }

    public function test_a_static_route_wins_over_a_dynamic_one(): void
    {
        // Declaration order puts the dynamic route first on purpose.
        $m = $this->matcher($this->manifest(['/users/{id}', '/users/me']));

        self::assertSame([], $m->match('GET', '/users/me')['params'], 'static must win');
    }

    public function test_a_route_is_scoped_to_its_method(): void
    {
        $m = $this->matcher($this->manifest(['/users/{id}'], 'POST'));

        self::assertNull($m->match('GET', '/users/1'));
        self::assertNotNull($m->match('POST', '/users/1'));
    }

    // ── Typed parameters — the regression this phase closes ─────────────────

    public function test_num_rejects_a_non_numeric_id(): void
    {
        $m = $this->matcher($this->manifest(['/users/{id:num}']));

        self::assertNotNull($m->match('GET', '/users/42'));
        // This is the whole point: previously /users/abc matched and the check
        // silently moved into the controller.
        self::assertNull($m->match('GET', '/users/abc'));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function typedCases(): array
    {
        return [
            'num accepts digits'          => ['num',      '2026',                                   true],
            'num rejects letters'         => ['num',      'x1',                                     false],
            'alpha accepts letters'       => ['alpha',    'draft',                                  true],
            'alpha rejects digits'        => ['alpha',    'draft2',                                 false],
            'alphanum accepts mixed'      => ['alphanum', 'a1b2',                                   true],
            'alphanum rejects dash'       => ['alphanum', 'a-1',                                    false],
            'slug accepts dashes'         => ['slug',     'my-post_1',                              true],
            'slug rejects a dot'          => ['slug',     'my.post',                                false],
            'uuid accepts a uuid'         => ['uuid',     '3f2504e0-4f89-11d3-9a0c-0305e82c3301',   true],
            'uuid rejects a short string' => ['uuid',     'not-a-uuid',                             false],
            'segment accepts anything'    => ['segment',  'any.thing-here',                         true],
        ];
    }

    #[DataProvider('typedCases')]
    public function test_typed_parameters_constrain_the_segment(string $type, string $value, bool $expected): void
    {
        $m = $this->matcher($this->manifest(['/r/{p:' . $type . '}']));

        self::assertSame($expected, $m->match('GET', '/r/' . $value) !== null);
    }

    public function test_any_is_a_catch_all_that_crosses_slashes(): void
    {
        // 0.3's (:any). No equivalent existed before this phase.
        $m = $this->matcher($this->manifest(['/files/{path:any}']));

        $match = $m->match('GET', '/files/a/b/c.txt');
        self::assertNotNull($match);
        self::assertSame('a/b/c.txt', $match['params']['path']);
    }

    public function test_several_typed_parameters_in_one_path(): void
    {
        $m = $this->matcher($this->manifest(['/posts/{year:num}/{slug:slug}']));

        $match = $m->match('GET', '/posts/2026/hello-world');
        self::assertNotNull($match);
        self::assertSame(['year' => '2026', 'slug' => 'hello-world'], $match['params']);

        self::assertNull($m->match('GET', '/posts/twenty/hello-world'));
    }

    public function test_a_typed_route_and_an_untyped_route_can_coexist(): void
    {
        // /users/{id:num} is declared first, so a numeric id takes it and
        // anything else falls through to the untyped route.
        $m = $this->matcher($this->manifest(['/users/{id:num}', '/users/{name}']));

        self::assertSame(['id' => '7'], $m->match('GET', '/users/7')['params']);
        self::assertSame(['name' => 'ada'], $m->match('GET', '/users/ada')['params']);
    }

    // ── RouteParameter ──────────────────────────────────────────────────────

    public function test_parse_reports_names_and_types(): void
    {
        self::assertSame(
            [['name' => 'year', 'type' => 'num'], ['name' => 'slug', 'type' => '']],
            RouteParameter::parse('/posts/{year:num}/{slug}'),
        );
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        self::assertFalse(RouteParameter::isValidType('number'));

        $this->expectExceptionMessageMatches('/Unknown route parameter type \[number\]/');
        RouteParameter::pattern('number');
    }
}
