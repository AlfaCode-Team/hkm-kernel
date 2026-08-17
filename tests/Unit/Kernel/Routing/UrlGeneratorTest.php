<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UrlGenerator::class)]
final class UrlGeneratorTest extends TestCase
{
    private const SECRET = 'test-signing-key-0123456789abcdef';

    private function generator(string $secret = self::SECRET): UrlGenerator
    {
        return new UrlGenerator(
            [
                'GET /'                        => ['name' => 'home',          'handler' => 'C@m'],
                'GET /users/{id:num}'          => ['name' => 'user.show',     'handler' => 'C@m'],
                'GET /posts/{year:num}/{slug}' => ['name' => 'post.show',     'handler' => 'C@m'],
                'GET /search'                  => ['name' => 'search',        'handler' => 'C@m'],
                'GET /files/{path:any}'        => ['name' => 'file.download', 'handler' => 'C@m'],
                'POST /users'                  => ['name' => 'user.store',    'handler' => 'C@m'],
                'GET /unnamed'                 => ['handler' => 'C@m'],
            ],
            base: 'https://app.example.test',
            secret: $secret,
        );
    }

    // ── Named routes ────────────────────────────────────────────────────────

    public function test_generates_a_static_route(): void
    {
        self::assertSame('/', $this->generator()->route('home'));
    }

    public function test_substitutes_parameters(): void
    {
        self::assertSame('/users/7', $this->generator()->route('user.show', ['id' => 7]));
        self::assertSame(
            '/posts/2026/hello-world',
            $this->generator()->route('post.show', ['year' => 2026, 'slug' => 'hello-world']),
        );
    }

    public function test_extra_parameters_become_a_query_string(): void
    {
        self::assertSame('/search?q=router', $this->generator()->route('search', ['q' => 'router']));
    }

    public function test_can_generate_an_absolute_url(): void
    {
        self::assertSame(
            'https://app.example.test/users/7',
            $this->generator()->route('user.show', ['id' => 7], absolute: true),
        );
    }

    public function test_reports_the_method_for_a_named_route(): void
    {
        self::assertSame('POST', $this->generator()->methodFor('user.store'));
        self::assertNull($this->generator()->methodFor('nope'));
    }

    public function test_an_unnamed_route_is_not_addressable(): void
    {
        self::assertFalse($this->generator()->has('unnamed'));
    }

    // ── Failure modes that would otherwise be silent 404s ───────────────────

    public function test_an_unknown_name_throws(): void
    {
        $this->expectExceptionMessageMatches('/Unknown route name \[nope\]/');
        $this->generator()->route('nope');
    }

    public function test_a_missing_parameter_throws(): void
    {
        $this->expectExceptionMessageMatches('/needs a value for \{id\}/');
        $this->generator()->route('user.show');
    }

    public function test_a_value_violating_its_type_throws(): void
    {
        // Generating a URL the matcher provably cannot match is always a bug —
        // fail at the call site instead of producing a mystery 404.
        $this->expectExceptionMessageMatches('/does not satisfy type \[num\]/');
        $this->generator()->route('user.show', ['id' => 'abc']);
    }

    public function test_a_value_may_not_smuggle_in_a_path_separator(): void
    {
        // '/' in an untyped segment would change the route the URL resolves to.
        $this->expectExceptionMessageMatches('/does not satisfy type/');
        $this->generator()->route('post.show', ['year' => 2026, 'slug' => 'a/b']);
    }

    public function test_an_any_parameter_may_contain_slashes(): void
    {
        // rawurlencode escapes them; the catch-all still matches on the way back in.
        $url = $this->generator()->route('file.download', ['path' => 'a/b.txt']);

        self::assertSame('/files/a%2Fb.txt', $url);
    }

    // ── Signed URLs ─────────────────────────────────────────────────────────

    public function test_a_signed_url_validates(): void
    {
        $url = $this->generator()->signedRoute('user.show', ['id' => 7]);

        self::assertStringContainsString('signature=', $url);
        self::assertTrue($this->generator()->hasValidSignature($url));
    }

    public function test_tampering_with_a_parameter_invalidates_the_signature(): void
    {
        $url = $this->generator()->signedRoute('user.show', ['id' => 7]);

        self::assertFalse($this->generator()->hasValidSignature(str_replace('/7', '/8', $url)));
    }

    public function test_an_unsigned_url_is_not_valid(): void
    {
        self::assertFalse($this->generator()->hasValidSignature('/users/7'));
    }

    public function test_an_expired_signed_url_is_rejected(): void
    {
        $url = $this->generator()->signedRoute('user.show', ['id' => 7], expiresIn: -60);

        // Signature is intact; the deadline has passed.
        self::assertFalse($this->generator()->hasValidSignature($url));
    }

    public function test_a_future_expiry_is_accepted(): void
    {
        $url = $this->generator()->signedRoute('user.show', ['id' => 7], expiresIn: 3600);

        self::assertTrue($this->generator()->hasValidSignature($url));
    }

    public function test_the_expiry_itself_cannot_be_extended(): void
    {
        $url  = $this->generator()->signedRoute('user.show', ['id' => 7], expiresIn: -60);
        $past = (string) (time() - 60);

        // Pushing 'expires' forward breaks the signature, because it is signed.
        self::assertFalse(
            $this->generator()->hasValidSignature(str_replace($past, (string) (time() + 3600), $url)),
        );
    }

    public function test_signing_fails_closed_without_a_secret(): void
    {
        // A URL signed with an empty key is forgeable by anyone and would look
        // identical to a real one — refuse to produce it at all.
        $this->expectExceptionMessageMatches('/no signing secret configured/');
        $this->generator(secret: '')->signedRoute('user.show', ['id' => 7]);
    }

    public function test_verification_fails_closed_without_a_secret(): void
    {
        $signed = $this->generator()->signedRoute('user.show', ['id' => 7]);

        self::assertFalse($this->generator(secret: '')->hasValidSignature($signed));
    }

    public function test_a_signature_from_a_different_key_is_rejected(): void
    {
        $signed = $this->generator()->signedRoute('user.show', ['id' => 7]);

        self::assertFalse($this->generator(secret: 'a-completely-different-key')->hasValidSignature($signed));
    }

    public function test_a_query_key_php_would_mangle_still_validates(): void
    {
        // parse_str() rewrites '.', ' ' and '[' inside parameter NAMES, so
        // round-tripping the query through it made a legitimately signed URL
        // impossible to verify. The comparison is now byte-for-byte.
        $url = $this->generator()->signedRoute('search', ['user.name' => 'ada', 'a b' => 'c']);

        self::assertTrue($this->generator()->hasValidSignature($url));
    }

    public function test_a_second_injected_signature_is_rejected(): void
    {
        $url = $this->generator()->signedRoute('user.show', ['id' => 7]);

        self::assertFalse($this->generator()->hasValidSignature($url . '&signature=deadbeef'));
    }

    public function test_tampering_with_the_query_invalidates_the_signature(): void
    {
        $url = $this->generator()->signedRoute('search', ['q' => 'safe']);

        self::assertFalse($this->generator()->hasValidSignature(str_replace('safe', 'evil', $url)));
    }

    // ── Repeated and optional placeholders ──────────────────────────────────

    public function test_a_repeated_placeholder_is_substituted_everywhere(): void
    {
        $url = new UrlGenerator(
            ['GET /a/{id}/b/{id}' => ['name' => 'twice', 'handler' => 'C@m']],
            secret: self::SECRET,
        );

        // Consumption used to remove the value, so the second {id} reported a
        // missing parameter.
        self::assertSame('/a/7/b/7', $url->route('twice', ['id' => 7]));
    }

    public function test_an_optional_parameter_may_be_omitted(): void
    {
        $url = new UrlGenerator(
            ['GET /posts/{page:num?}' => ['name' => 'posts', 'handler' => 'C@m']],
            secret: self::SECRET,
        );

        self::assertSame('/posts', $url->route('posts'), 'the separator goes with it');
        self::assertSame('/posts/2', $url->route('posts', ['page' => 2]));
    }

    public function test_an_optional_parameter_is_still_type_checked(): void
    {
        $url = new UrlGenerator(
            ['GET /posts/{page:num?}' => ['name' => 'posts', 'handler' => 'C@m']],
            secret: self::SECRET,
        );

        $this->expectExceptionMessageMatches('/does not satisfy type \[num\]/');
        $url->route('posts', ['page' => 'two']);
    }

    // ── Absolute URLs follow the route's own domain group ───────────────────

    private function multiBrand(string $base = 'https://hkmvote.local'): UrlGenerator
    {
        return new UrlGenerator(
            [
                'GET@hkmvote.local /'      => ['name' => 'vote.home',   'handler' => 'C@m'],
                'GET@africavoting.local /' => ['name' => 'africa.home', 'handler' => 'C@m'],
                'GET@*.africavoting.local /t' => ['name' => 'tenant.home', 'handler' => 'C@m'],
                'GET@api /ping'            => ['name' => 'api.ping',    'handler' => 'C@m'],
                'GET /health'              => ['name' => 'health',      'handler' => 'C@m'],
            ],
            base: $base,
            secret: self::SECRET,
        );
    }

    public function test_a_grouped_route_is_absolute_against_its_own_host(): void
    {
        // Generating both brands against one APP_URL would send half the links
        // to the wrong site.
        $url = $this->multiBrand();

        self::assertSame('https://hkmvote.local/', $url->route('vote.home', absolute: true));
        self::assertSame('https://africavoting.local/', $url->route('africa.home', absolute: true));
    }

    public function test_the_scheme_is_taken_from_the_configured_base(): void
    {
        self::assertSame(
            'http://africavoting.local/',
            $this->multiBrand(base: 'http://hkmvote.local')->route('africa.home', absolute: true),
        );
    }

    public function test_a_wildcard_or_bare_subdomain_falls_back_to_the_base(): void
    {
        // Neither names a single host, so there is no origin to build.
        $url = $this->multiBrand();

        self::assertSame('https://hkmvote.local/t', $url->route('tenant.home', absolute: true));
        self::assertSame('https://hkmvote.local/ping', $url->route('api.ping', absolute: true));
    }

    public function test_an_ungrouped_route_still_uses_the_configured_base(): void
    {
        self::assertSame('https://hkmvote.local/health', $this->multiBrand()->route('health', absolute: true));
    }

    public function test_relative_generation_is_unaffected_by_the_domain(): void
    {
        self::assertSame('/', $this->multiBrand()->route('africa.home'));
    }

    public function test_the_domain_of_a_named_route_is_reportable(): void
    {
        self::assertSame('africavoting.local', $this->multiBrand()->domainFor('africa.home'));
        self::assertSame('', $this->multiBrand()->domainFor('health'));
    }

    public function test_a_signed_absolute_url_uses_its_domain_and_still_verifies(): void
    {
        // The signature covers path+query only, never the host, so picking a
        // per-domain origin cannot invalidate it.
        $url    = $this->multiBrand();
        $signed = $url->signedRoute('africa.home', ['id' => 7], absolute: true);

        self::assertStringStartsWith('https://africavoting.local/', $signed);
        self::assertTrue($url->hasValidSignature(substr($signed, strlen('https://africavoting.local'))));
    }

    public function test_a_trailing_newline_cannot_be_smuggled_into_a_value(): void
    {
        // '$' without the D modifier would accept "7\n" here and generate a URL
        // the matcher then refuses.
        $this->expectExceptionMessageMatches('/does not satisfy type \[num\]/');
        $this->generator()->route('user.show', ['id' => "7\n"]);
    }
}
