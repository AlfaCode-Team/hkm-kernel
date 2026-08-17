<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Pipelines\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\RouteMatcher;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The matcher's security and correctness guarantees, as opposed to its routing
 * semantics (those live in RouteMatcherTest).
 *
 * Every case here corresponds to a way a request could previously reach a
 * controller with a value the route's own type declaration forbade.
 */
#[CoversClass(RouteMatcher::class)]
#[CoversClass(RouteIndex::class)]
#[CoversClass(RouteParameter::class)]
final class RouteMatcherHardeningTest extends TestCase
{
    /** @param list<string> $paths */
    private function matcher(array $paths, string $method = 'GET', bool $head = true): RouteMatcher
    {
        $manifest = [];
        foreach ($paths as $path) {
            $manifest[$method . ' ' . $path] = ['handler' => 'C@m', 'solves' => 'x'];
        }

        return new RouteMatcher($manifest, headFallback: $head);
    }

    // ── Percent-decoding: the segment guarantee must survive the decode ──────

    public function test_an_encoded_slash_cannot_smuggle_a_path_separator(): void
    {
        $m = $this->matcher(['/files/{name}']);

        // '%2F' is three ordinary characters, so it sails through [^/]+ — and the
        // moment the controller decodes it, the "one segment" promise is gone.
        self::assertNull($m->match('GET', '/files/..%2F..%2Fetc%2Fpasswd'));
    }

    public function test_an_encoded_slash_is_rejected_for_a_typed_segment_too(): void
    {
        $m = $this->matcher(['/p/{slug:slug}']);

        self::assertNull($m->match('GET', '/p/a%2Fb'));
    }

    public function test_a_captured_value_reaches_the_controller_decoded(): void
    {
        $m = $this->matcher(['/users/{name}']);

        // Previously the controller received the raw 'Jos%C3%A9'.
        self::assertSame('José', $m->match('GET', '/users/Jos%C3%A9')['params']['name']);
    }

    public function test_a_nul_byte_is_never_delivered(): void
    {
        $m = $this->matcher(['/files/{name:any}']);

        self::assertNull($m->match('GET', '/files/report%00.pdf'));
    }

    public function test_an_undecodable_percent_is_passed_through_unchanged(): void
    {
        $m = $this->matcher(['/p/{code}']);

        // '%zz' is not a valid escape; rawurldecode leaves it alone and so do we.
        self::assertSame('100%zz', $m->match('GET', '/p/100%zz')['params']['code']);
    }

    // ── Anchoring ───────────────────────────────────────────────────────────

    public function test_a_trailing_newline_does_not_satisfy_the_end_anchor(): void
    {
        $m = $this->matcher(['/users/{id:num}']);

        // Without the D modifier, PCRE's '$' also matches before a final newline.
        self::assertNull($m->match('GET', "/users/12\n"));
        self::assertNotNull($m->match('GET', '/users/12'));
    }

    public function test_a_literal_dot_in_a_path_is_not_a_wildcard(): void
    {
        $m = $this->matcher(['/feed.xml/{id:num}']);

        self::assertNotNull($m->match('GET', '/feed.xml/1'));
        self::assertNull($m->match('GET', '/feedXxml/1'), 'the dot must be quoted');
    }

    // ── New types ───────────────────────────────────────────────────────────

    public function test_the_path_type_crosses_slashes_but_refuses_traversal(): void
    {
        $m = $this->matcher(['/dl/{file:path}']);

        self::assertSame('a/b/c.txt', $m->match('GET', '/dl/a/b/c.txt')['params']['file']);
        self::assertNull($m->match('GET', '/dl/../../etc/passwd'));
        self::assertNull($m->match('GET', '/dl/a/..%2Fb'));
    }

    public function test_any_is_unchanged_and_still_a_bare_catch_all(): void
    {
        // `any` keeps its exact previous meaning so no existing route regresses;
        // `path` is the safe alternative to opt into.
        $m = $this->matcher(['/files/{p:any}']);

        self::assertSame('../secret', $m->match('GET', '/files/../secret')['params']['p']);
    }

    /** @return array<string, array{string, bool}> */
    public static function enumCases(): array
    {
        return [
            'a member matches'      => ['draft', true],
            'another member'        => ['published', true],
            'a non-member does not' => ['deleted', false],
            'a prefix does not'     => ['draf', false],
        ];
    }

    #[DataProvider('enumCases')]
    public function test_an_enum_type_admits_only_its_members(string $value, bool $expected): void
    {
        $m = $this->matcher(['/posts/{status:enum(draft|published)}']);

        self::assertSame($expected, $m->match('GET', '/posts/' . $value) !== null);
    }

    public function test_enum_members_cannot_inject_regex(): void
    {
        // Members are preg_quote'd, so '.' is a literal dot, not "any character".
        $m = $this->matcher(['/v/{v:enum(1.0|2.0)}']);

        self::assertNotNull($m->match('GET', '/v/1.0'));
        self::assertNull($m->match('GET', '/v/1x0'));
    }

    // ── Optional parameters ─────────────────────────────────────────────────

    public function test_an_optional_parameter_may_be_omitted_with_its_separator(): void
    {
        $m = $this->matcher(['/posts/{page?}']);

        self::assertNotNull($m->match('GET', '/posts'));
        self::assertSame('', $m->match('GET', '/posts')['params']['page']);
        self::assertSame('2', $m->match('GET', '/posts/2')['params']['page']);
    }

    public function test_an_optional_parameter_still_honours_its_type(): void
    {
        $m = $this->matcher(['/posts/{page:num?}']);

        self::assertNotNull($m->match('GET', '/posts'));
        self::assertNotNull($m->match('GET', '/posts/3'));
        self::assertNull($m->match('GET', '/posts/three'));
    }

    // ── Ordering across the bucket split ────────────────────────────────────

    public function test_a_wildcard_route_declared_first_still_wins(): void
    {
        // '/{slug}' buckets as a wildcard and '/pages/{id}' under 'pages'; the
        // matcher must merge them back into declaration order.
        $m = $this->matcher(['/{slug}', '/pages/{id}']);

        self::assertSame(['slug' => 'pages'], $m->match('GET', '/pages')['params']);
    }

    public function test_a_bucketed_route_declared_first_wins_over_a_wildcard(): void
    {
        $m = $this->matcher(['/pages/{id}', '/{a}/{b}']);

        self::assertSame(['id' => '7'], $m->match('GET', '/pages/7')['params']);
    }

    public function test_a_route_in_another_bucket_is_never_reached(): void
    {
        $m = $this->matcher(['/users/{id}', '/posts/{id}']);

        self::assertNotNull($m->match('GET', '/posts/1'));
        self::assertNull($m->match('GET', '/nope/1'));
    }

    // ── HEAD and Allow ──────────────────────────────────────────────────────

    public function test_head_is_served_by_the_get_route(): void
    {
        $m = $this->matcher(['/health', '/users/{id:num}']);

        self::assertNotNull($m->match('HEAD', '/health'));
        self::assertSame(['id' => '9'], $m->match('HEAD', '/users/9')['params']);
    }

    public function test_head_fallback_can_be_switched_off(): void
    {
        $m = $this->matcher(['/health'], head: false);

        self::assertNull($m->match('HEAD', '/health'));
    }

    public function test_allowed_methods_reports_the_other_verbs(): void
    {
        $manifest = [
            'GET /things'     => ['handler' => 'C@m', 'solves' => 'x'],
            'POST /things'    => ['handler' => 'C@m', 'solves' => 'x'],
            'DELETE /t/{id}'  => ['handler' => 'C@m', 'solves' => 'x'],
        ];
        $m = new RouteMatcher($manifest);

        self::assertSame(['GET', 'POST', 'HEAD'], $m->allowedMethods('/things'));
        self::assertSame(['DELETE'], $m->allowedMethods('/t/1'));
        self::assertSame([], $m->allowedMethods('/nothing'));
    }

    // ── Trailing-slash policy ───────────────────────────────────────────────

    public function test_the_default_policy_is_strict(): void
    {
        $m = $this->matcher(['/users']);

        self::assertNull($m->match('GET', '/users/'));
        self::assertNull($m->canonicalPath('GET', '/users/'));
    }

    public function test_the_ignore_policy_matches_either_form(): void
    {
        $m = new RouteMatcher(
            ['GET /users' => ['handler' => 'C@m', 'solves' => 'x']],
            trailingSlash: RouteMatcher::TRAILING_IGNORE,
        );

        self::assertNotNull($m->match('GET', '/users/'));
    }

    public function test_the_redirect_policy_reports_the_canonical_path(): void
    {
        $m = new RouteMatcher(
            ['GET /users' => ['handler' => 'C@m', 'solves' => 'x']],
            trailingSlash: RouteMatcher::TRAILING_REDIRECT,
        );

        self::assertNull($m->match('GET', '/users/'), 'redirect policy does not match directly');
        self::assertSame('/users', $m->canonicalPath('GET', '/users/'));
        self::assertNull($m->canonicalPath('GET', '/'), 'the root has no alternate form');
    }

    // ── The precompiled index and the derived one must agree ────────────────

    public function test_a_precompiled_index_matches_identically(): void
    {
        $manifest = [
            'GET /users/{id:num}' => ['handler' => 'C@m', 'solves' => 'x'],
            'GET /users/me'       => ['handler' => 'C@m', 'solves' => 'x'],
            'GET /{slug}'         => ['handler' => 'C@m', 'solves' => 'x'],
        ];

        $derived  = new RouteMatcher($manifest);
        $compiled = RouteMatcher::fromCompiled(RouteIndex::build($manifest));

        foreach (['/users/7', '/users/me', '/anything', '/users/a/b'] as $path) {
            self::assertEquals(
                $derived->match('GET', $path),
                $compiled->match('GET', $path),
                "diverged on {$path}",
            );
        }
    }

    public function test_a_route_pcre_cannot_represent_is_dropped_not_fatal(): void
    {
        // A manifest compiled by an older kernel may contain a duplicate capture
        // name. That one route is unusable either way; the rest must still serve.
        $m = $this->matcher(['/a/{id}/b/{id}', '/ok/{id}']);

        self::assertNull($m->match('GET', '/a/1/b/2'));
        self::assertNotNull($m->match('GET', '/ok/1'));
    }
}
