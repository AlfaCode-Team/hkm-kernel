<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use PHPUnit\Framework\Assert;

/**
 * A Response wrapped in assertions.
 *
 * The point is the FAILURE MESSAGE. `assertSame(200, $r->status())` on a broken
 * route says "failed asserting that 404 is identical to 200", which sends you
 * hunting; these assertions print the body, so a 500 shows you the exception
 * message and a 422 shows you which field failed. That difference is most of
 * what makes a feature suite worth running.
 */
final readonly class TestResponse
{
    public function __construct(public Response $response) {}

    public function status(): int
    {
        return $this->response->status();
    }

    public function body(): string
    {
        return $this->response->body();
    }

    /** @return array<string, mixed> the JSON body, or [] when it is not JSON */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->status(), $this->context("Expected HTTP {$expected}."));

        return $this;
    }

    public function assertOk(): self          { return $this->assertStatus(200); }
    public function assertCreated(): self     { return $this->assertStatus(201); }
    public function assertNoContent(): self   { return $this->assertStatus(204); }
    public function assertNotFound(): self    { return $this->assertStatus(404); }
    public function assertUnauthorized(): self{ return $this->assertStatus(401); }
    public function assertForbidden(): self   { return $this->assertStatus(403); }
    public function assertUnprocessable(): self { return $this->assertStatus(422); }
    public function assertServerError(): self { return $this->assertStatus(500); }

    /** 2xx of any kind — for routes whose exact success code is not the point. */
    public function assertSuccessful(): self
    {
        Assert::assertTrue(
            $this->status() >= 200 && $this->status() < 300,
            $this->context('Expected a 2xx response.'),
        );

        return $this;
    }

    /** @param array<string, mixed> $expected asserted as a SUBSET of the JSON body */
    public function assertJsonFragment(array $expected): self
    {
        $actual = $this->json();

        foreach ($expected as $key => $value) {
            Assert::assertArrayHasKey($key, $actual, $this->context("Missing JSON key [{$key}]."));
            Assert::assertSame($value, $actual[$key], $this->context("JSON key [{$key}] differs."));
        }

        return $this;
    }

    /** @param array<string, mixed> $expected asserted as the WHOLE JSON body */
    public function assertExactJson(array $expected): self
    {
        Assert::assertSame($expected, $this->json(), $this->context('JSON body differs.'));

        return $this;
    }

    /**
     * A response header, case-insensitively.
     *
     * Response exposes headers() (a flat map in ORIGINAL case) and no per-name
     * accessor, so the lookup is done here. Matching case-insensitively is not a
     * convenience — HTTP header names are case-insensitive, and a test asserting
     * on 'Content-Type' must not fail because the stage that set it wrote
     * 'content-type'.
     */
    public function header(string $name): ?string
    {
        foreach ($this->response->headers() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function assertHeader(string $name, ?string $expected = null): self
    {
        $actual = $this->header($name);

        Assert::assertNotNull($actual, $this->context("Missing header [{$name}]."));

        if ($expected !== null) {
            Assert::assertSame($expected, $actual, $this->context("Header [{$name}] differs."));
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertNull($this->header($name), $this->context("Header [{$name}] should be absent."));

        return $this;
    }

    public function assertRedirect(?string $to = null): self
    {
        Assert::assertTrue(
            $this->status() >= 300 && $this->status() < 400,
            $this->context('Expected a redirect.'),
        );

        if ($to !== null) {
            $this->assertHeader('Location', $to);
        }

        return $this;
    }

    public function assertSee(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->body(), $this->context("Body does not contain [{$needle}]."));

        return $this;
    }

    public function assertDontSee(string $needle): self
    {
        Assert::assertStringNotContainsString($needle, $this->body(), $this->context("Body should not contain [{$needle}]."));

        return $this;
    }

    /**
     * The error envelope this framework guarantees:
     * { "error": { "code", "message", "requestId"[, "fields"] } }
     */
    public function assertErrorCode(string $code): self
    {
        $body = $this->json();

        Assert::assertArrayHasKey('error', $body, $this->context('Expected an error envelope.'));
        Assert::assertSame($code, $body['error']['code'] ?? null, $this->context("Expected error code [{$code}]."));

        return $this;
    }

    /** @param list<string> $fields */
    public function assertInvalid(array $fields): self
    {
        $this->assertUnprocessable();
        $actual = $this->json()['error']['fields'] ?? [];

        foreach ($fields as $field) {
            Assert::assertArrayHasKey($field, $actual, $this->context("Expected a validation error on [{$field}]."));
        }

        return $this;
    }

    public function assertEmptyBody(): self
    {
        Assert::assertSame('', $this->body(), $this->context('Expected an empty body.'));

        return $this;
    }

    /** Every failure message carries the status and a bounded slice of the body. */
    private function context(string $message): string
    {
        $body = $this->body();

        if (strlen($body) > 2000) {
            $body = substr($body, 0, 2000) . "\n… (truncated)";
        }

        return $message . "\n\nHTTP " . $this->status() . "\n" . ($body === '' ? '(empty body)' : $body) . "\n";
    }
}
