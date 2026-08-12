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
}
