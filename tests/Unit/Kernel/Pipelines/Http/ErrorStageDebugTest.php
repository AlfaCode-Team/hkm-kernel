<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Pipelines\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\ErrorStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * APP_DEBUG means one thing.
 *
 * It used to mean two. isDebug() parsed the value with FILTER_VALIDATE_BOOL
 * while publicError() compared it `=== 'true'`, so APP_DEBUG=1 turned ON the
 * HTML debug page — stack trace and source excerpt — for anything sending
 * `Accept: text/html`, while every JSON response still masked its message as
 * "An internal error occurred.". One flag, two behaviours, and the more
 * revealing of the two was the one that engaged.
 *
 * FILTER_VALIDATE_BOOL is the surviving parse because it is what every other
 * kernel flag uses (HttpPipeline::flag()).
 */
#[CoversClass(ErrorStage::class)]
final class ErrorStageDebugTest extends TestCase
{
    private const MESSAGE = 'connection refused to db-primary:5432';

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        $this->previousEnv = [
            'env'    => $_ENV['APP_DEBUG'] ?? null,
            'server' => $_SERVER['APP_DEBUG'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
            if ($this->previousEnv[$key] === null) {
                unset($GLOBALS[$global]['APP_DEBUG']);
            } else {
                $GLOBALS[$global]['APP_DEBUG'] = $this->previousEnv[$key];
            }
        }
    }

    /**
     * Every spelling FILTER_VALIDATE_BOOL accepts must reach the JSON body, not
     * just the literal string 'true'.
     */
    public function test_every_truthy_spelling_reveals_the_message(): void
    {
        foreach (['1', 'true', 'on', 'yes', 'TRUE'] as $spelling) {
            $body = $this->jsonErrorBody($spelling);

            self::assertSame(
                self::MESSAGE,
                $body['error']['message'],
                "APP_DEBUG={$spelling} must reveal the message on the JSON path, "
                . 'as it already does on the HTML debug page',
            );
        }
    }

    public function test_debug_off_still_masks_the_message(): void
    {
        foreach (['0', 'false', 'off', 'no', ''] as $spelling) {
            $body = $this->jsonErrorBody($spelling);

            self::assertSame(
                'An internal error occurred.',
                $body['error']['message'],
                "APP_DEBUG={$spelling} must not leak the message",
            );
        }
    }

    public function test_an_absent_flag_masks_the_message(): void
    {
        unset($_ENV['APP_DEBUG'], $_SERVER['APP_DEBUG']);

        $body = $this->decode($this->dispatch());

        self::assertSame('An internal error occurred.', $body['error']['message']);
    }

    public function test_the_error_envelope_keeps_its_shape(): void
    {
        $response = $this->dispatch('1');
        $body     = $this->decode($response);

        self::assertSame(500, $response->status());
        self::assertArrayHasKey('code', $body['error']);
        self::assertArrayHasKey('requestId', $body['error']);
        self::assertSame('trace-42', $body['error']['requestId']);
    }

    // ── harness ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function jsonErrorBody(string $appDebug): array
    {
        return $this->decode($this->dispatch($appDebug));
    }

    /**
     * Drive the stage over a throwing $next on an /api path, which forces the
     * structured JSON body rather than the HTML debug page.
     */
    private function dispatch(?string $appDebug = null): Response
    {
        if ($appDebug === null) {
            unset($_ENV['APP_DEBUG'], $_SERVER['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG']    = $appDebug;
            $_SERVER['APP_DEBUG'] = $appDebug;
        }

        $request = Request::create('/api/invoices', 'GET')
            ->withAttribute('correlation_id', 'trace-42');

        // notifiers([]) has no fallback, so consume() writes nothing to disk.
        $stage = new ErrorStage(ErrorPipeline::notifiers([]));

        return $stage->handle($request, static function (): Response {
            throw new \RuntimeException(self::MESSAGE);
        });
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true);

        return $decoded;
    }
}
