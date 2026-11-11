<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Rules;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Contracts\WafRuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\WafMatch;

/**
 * Requests no browser or well-behaved client produces.
 *
 * These checks look at the SHAPE of a request, not its words, so they have
 * almost no false positives — which is why they are safe to block on from day
 * one, unlike {@see InjectionSignatureRule}.
 *
 *   - control bytes in the decoded path (a NUL truncates the path at the first
 *     C-level call that sees it; CR/LF is header and log injection);
 *   - a path that is not valid UTF-8 once decoded;
 *   - a NUL anywhere in the query string or a header value;
 *   - Content-Length AND Transfer-Encoding together — the request-smuggling
 *     signature, which RFC 9112 §6.1 says a server must treat as an error;
 *   - an over-long URL, or more input fields than any form has.
 */
final class MalformedRequestRule implements WafRuleContract
{
    public function __construct(
        private readonly int $maxUrlLength = 8192,
        /** Total input values, counted recursively. PHP's max_input_vars does not apply to JSON bodies. */
        private readonly int $maxInputs = 2000,
    ) {}

    public function id(): string
    {
        return 'malformed';
    }

    public function inspect(Request $request): ?WafMatch
    {
        $rawPath = $request->path();
        $query   = (string) $request->server('QUERY_STRING', '');

        if ($this->maxUrlLength > 0 && \strlen($rawPath) + \strlen($query) > $this->maxUrlLength) {
            return WafMatch::of($this->id(), 'url-too-long', 'request', (string) (\strlen($rawPath) + \strlen($query)));
        }

        $path = rawurldecode($rawPath);
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return WafMatch::of($this->id(), 'control-byte', 'path', $rawPath);
        }
        if (preg_match('//u', $path) !== 1) {
            return WafMatch::of($this->id(), 'invalid-utf8', 'path', $rawPath);
        }

        if (str_contains(rawurldecode($query), "\0")) {
            return WafMatch::of($this->id(), 'nul-byte', 'query', $query);
        }

        foreach ($request->headersAll() as $name => $values) {
            foreach ((array) $values as $value) {
                if (\is_string($value) && str_contains($value, "\0")) {
                    return WafMatch::of($this->id(), 'nul-byte', 'header:' . $name, $value);
                }
            }
        }

        if ($request->hasHeader('Content-Length') && $request->hasHeader('Transfer-Encoding')) {
            return WafMatch::of($this->id(), 'smuggling', 'request', 'Content-Length + Transfer-Encoding');
        }

        if ($this->maxInputs > 0) {
            // Counted per bag: all() would build a merged copy of both just to count it.
            $count = \count($request->body(), COUNT_RECURSIVE) + \count($request->queryAll(), COUNT_RECURSIVE);
            if ($count > $this->maxInputs) {
                return WafMatch::of($this->id(), 'too-many-inputs', 'request', (string) $count);
            }
        }

        return null;
    }
}
