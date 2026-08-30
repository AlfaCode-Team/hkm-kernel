<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\{DebugPageRenderer, ErrorClassifier, ErrorContext, ErrorPipeline};
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\{
    FrameworkException,
    GatewayException,
    HttpStatusAware,
    SecurityException,
    ServiceException,
    ValidationException
};
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

final class ErrorStage implements HttpStageContract
{
    public function __construct(
        private readonly ErrorPipeline $errorPipeline
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (\Throwable $e) {
            
            $this->errorPipeline->consume(ErrorContext::fromThrowable(
                $e,
                correlationId: $request->attribute('correlation_id', ''),
                requestPath: $request->path(),
                requestMethod: $request->method(),
                userId: $request->identity()?->userId,
            ));

            return $this->buildErrorResponse($e, $request);
        }
    }

    private function buildErrorResponse(\Throwable $e, Request $request): Response
    {
        $status = $this->resolveHttpCode($e);

        // Developer debug page: only when debug is on AND the request is a real
        // browser navigation. expectsJson() covers Accept: */json, AJAX
        // (X-Requested-With) and JSON request bodies; the /api path prefix covers
        // header-less API hits (mirrors ErrorGuard's pre-kernel detection). Either
        // signal forces the structured JSON body instead of the HTML page.
        if ($this->isDebug() && !$request->expectsJson() && !self::isApiPath($request->path())) {
            return Response::html(DebugPageRenderer::renderHtml($e, base_path()), $status);
        }

        $body = $this->publicError($e);
        $body['requestId'] = $request->attribute('correlation_id', '');

        return Response::json(['error' => $body], $status);
    }

    /**
     * Whether the application is in debug mode — the ONE place this is decided.
     *
     * It used to be decided twice, and differently: this method parsed the value
     * with FILTER_VALIDATE_BOOL while publicError() compared it `=== 'true'`. So
     * APP_DEBUG=1 enabled the HTML debug page — stack trace and source excerpt —
     * for anything sending `Accept: text/html`, while every JSON response still
     * masked its message as "An internal error occurred.". One flag, two
     * behaviours, and the more revealing of the two was the one that turned on.
     *
     * FILTER_VALIDATE_BOOL is the surviving parse because it is what every other
     * kernel flag uses (see HttpPipeline::flag()), so `1`, `on`, `yes` and `true`
     * all mean the same thing across the kernel.
     *
     * Read through env(), not $_ENV/getenv(): the environment loader deliberately
     * skips putenv(), so getenv() is not the source of truth for a .env value.
     */
    private function isDebug(): bool
    {
        $value = \function_exists('env') ? env('APP_DEBUG') : ($_ENV['APP_DEBUG'] ?? null);

        if ($value === null || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /** API surface convention — same prefix the CSRF layer exempts. */
    private static function isApiPath(string $path): bool
    {
        return str_starts_with($path, '/api');
    }

    private function resolveHttpCode(\Throwable $e): int
    {
        // An exception that declares its own status wins. Without this, every
        // plugin/project exception outside the kernel hierarchy became a 500 —
        // opaque to the client AND classified CRITICAL, paging someone about an
        // ordinary expected outcome like "that seat is taken".
        if ($e instanceof HttpStatusAware) {
            $declared = $e->httpStatus();

            // 2xx/3xx would turn an error path into an apparent success or a
            // redirect with no Location. Ignore and fall through.
            if ($declared >= 400 && $declared <= 599) {
                return $declared;
            }
        }

        if ($e instanceof SecurityException) {
            $code = $e->getCode();
            return in_array($code, [401, 403, 429], true) ? $code : 403;
        }

        return match (true) {
            $e instanceof ValidationException => 422,
            $e instanceof ServiceException => 422,
            $e instanceof GatewayException => 502,
            default => 500,
        };
    }

    /** @return array<string, mixed> */
    private function publicError(\Throwable $e): array
    {
        $debug = $this->isDebug();

        if ($e instanceof ValidationException) {
            return ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->errors];
        }

        if ($e instanceof FrameworkException) {
            if (ErrorClassifier::severityFor($e) === ErrorClassifier::CRITICAL && !$debug) {
                return ['code' => $e->layer ?: 'server_error', 'message' => 'An internal error occurred.'];
            }
            return ['code' => $e->layer ?: 'error', 'message' => $e->getMessage()];
        }

        return $debug
            ? ['code' => 'server_error', 'message' => $e->getMessage()]
            : ['code' => 'server_error', 'message' => 'An internal error occurred.'];
    }
}
