<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

/**
 * A kernel Response with the questions a test asks, and a failure message worth
 * reading.
 *
 * The reason this wrapper exists rather than assertions on Response directly:
 * when a ground request unexpectedly 500s, the kernel's ErrorStage has already
 * turned the throwable into a JSON envelope, so PHPUnit reports
 * "failed asserting 500 matches 200" and nothing else. Every assertion here
 * appends {@see describe()}, which prints the error envelope and any severe log
 * line — usually the actual cause, on the same screen as the failure.
 */
final class GroundResponse
{
    public function __construct(
        private readonly Response $response,
        /** @var list<string> severe log lines captured during this request */
        private readonly array $problems = [],
    ) {}

    public function raw(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    public function body(): string
    {
        return $this->response->body();
    }

    public function header(string $name): ?string
    {
        foreach ($this->response->headers() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return string[] raw Set-Cookie lines */
    public function cookies(): array
    {
        return $this->response->cookies();
    }

    /** The decoded JSON body, or [] when the body is not a JSON object/array. */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** Dotted read into the JSON body: `$res->at('error.code')`. */
    public function at(string $path, mixed $default = null): mixed
    {
        $value = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** The `error.code` of a kernel error envelope, when the body is one. */
    public function errorCode(): ?string
    {
        $code = $this->at('error.code');

        return \is_string($code) ? $code : null;
    }

    public function ok(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function redirected(): bool
    {
        return $this->status() >= 300 && $this->status() < 400;
    }

    /** Where a redirect points, or null when this is not a redirect. */
    public function redirectTarget(): ?string
    {
        return $this->redirected() ? $this->header('Location') : null;
    }

    /**
     * A one-block summary for a failure message: status, error envelope (or a
     * body excerpt) and anything logged at error severity or worse.
     */
    public function describe(): string
    {
        $lines = ['HTTP ' . $this->status()];

        $code = $this->errorCode();
        if ($code !== null) {
            $lines[] = 'error.code: ' . $code;
            $message = $this->at('error.message');
            if (\is_string($message)) {
                $lines[] = 'error.message: ' . $message;
            }
        } elseif (($debug = $this->debugPageError()) !== null) {
            $lines[] = 'exception: ' . $debug;
        } else {
            $body = trim($this->body());
            if ($body !== '') {
                $lines[] = 'body: ' . (\strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body);
            }
        }

        foreach ($this->problems as $problem) {
            $lines[] = 'log: ' . $problem;
        }

        return implode("\n", $lines);
    }

    /**
     * The exception a DEBUG PAGE is reporting, as `Class: message (file:line)`.
     *
     * The ground runs with APP_DEBUG on, and the kernel answers a browser-style
     * navigation (no `Accept: application/json`) with DebugPageRenderer's HTML
     * instead of the JSON error envelope. Without this, a 500 on an HTML route
     * fails as "expected 200, got 500" followed by 500 characters of inlined
     * CSS — the cause is IN the response and unreadable.
     *
     * Adding `Accept: application/json` to every ground request would be the
     * other fix and is worse: PageflowResponder treats that header as an XHR
     * navigation, so every plain get() would start returning page objects.
     */
    public function debugPageError(): ?string
    {
        $body = $this->body();
        if (!str_contains($body, 'exception-badge')) {
            return null;
        }

        $class   = self::between($body, 'class="exception-badge">', '</div>');
        $message = self::between($body, 'class="error-title">', '</div>');
        $meta    = self::between($body, 'class="error-meta">', '</div></div>');

        if ($class === null && $message === null) {
            return null;
        }

        $where = $meta === null ? '' : ' — ' . trim(html_entity_decode(strip_tags($meta), ENT_QUOTES, 'UTF-8'));

        return trim(html_entity_decode((string) $class, ENT_QUOTES, 'UTF-8'))
            . ': ' . trim(html_entity_decode((string) $message, ENT_QUOTES, 'UTF-8'))
            . preg_replace('/\s+/', ' ', $where);
    }

    private static function between(string $haystack, string $start, string $end): ?string
    {
        $from = strpos($haystack, $start);
        if ($from === false) {
            return null;
        }
        $from += \strlen($start);

        $to = strpos($haystack, $end, $from);

        return $to === false ? null : substr($haystack, $from, $to - $from);
    }

    public function __toString(): string
    {
        return $this->describe();
    }
}
