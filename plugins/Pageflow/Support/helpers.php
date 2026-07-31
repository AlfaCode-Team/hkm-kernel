<?php

declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Pageflow\Http\PageflowAuth;
use Plugins\Pageflow\Http\PageflowShares;

if (!function_exists('pageflow_auth_projection')) {
    /**
     * Override what identity data is shared to the browser as `pageflow_auth`.
     * Call ONCE at bootstrap. Pass null to restore the default projection.
     *
     *   pageflow_auth_projection(fn(?Identity $id) => [
     *       'userId'    => $id?->userId ?? '',
     *       'canManage' => (bool) $id?->hasPermission('admin:manage'),
     *   ]);
     *
     * SECURITY: keep it minimal — never expose tokens, and prefer capability
     * booleans over raw permission strings if the naming is sensitive.
     */
    function pageflow_auth_projection(?callable $projector): void
    {
        PageflowAuth::project($projector);
    }
}

if (!function_exists('pageflow_precognition')) {
    /**
     * True when the current request is a Pageflow PRECOGNITION request — the
     * client wants validation run WITHOUT executing the action.
     *
     * A precognitive controller MUST short-circuit before any side effect:
     *
     *   public function store(Request $request): Response
     *   {
     *       $dto = CreateUserDTO::fromRequest($request); // throws ValidationException
     *       if (pageflow_precognition($request)) {
     *           return $this->pageflow->precognitionSuccess(); // validated, no writes
     *       }
     *       // ... real work only reached on a normal submit
     *   }
     *
     * The PageflowStage turns any ValidationException into the 422
     * error envelope the client reads, so you only handle the success path.
     */
    function pageflow_precognition(Request $request): bool
    {
        return strtolower((string) ($request->header('Precognition') ?? '')) === 'true';
    }
}

if (!function_exists('pageflow_precognition_fields')) {
    /**
     * The subset of fields a precognition request asked to validate (empty = all).
     *
     * @return list<string>
     */
    function pageflow_precognition_fields(Request $request): array
    {
        $raw = (string) ($request->header('Precognition-Validate-Only') ?? '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $s): bool => $s !== '',
        ));
    }
}

if (!function_exists('pageflow_share')) {
    /**
     * Register shared Pageflow props, present on every rendered page.
     *
     * Call ONCE at bootstrap (or a plugin's boot) — you register a definition,
     * not a value; the value is resolved per request. Two forms:
     *
     *   // keyed share — resolver receives the current Request
     *   pageflow_share('auth', fn($request) => $request->identity()?->userId);
     *   pageflow_share('year', fn() => date('Y'));
     *
     *   // raw contributor — full control, share()/mergeShared() many keys
     *   pageflow_share(function ($request, $responder) {
     *       $responder->mergeShared(['appName' => 'HKM', 'locale' => 'en']);
     *   });
     *
     * @param string|callable $key      Share key, or a raw contributor callable
     *                                   fn(Request, PageflowResponder): void.
     * @param callable|null   $resolver When $key is a string: fn(Request): mixed.
     */
    function pageflow_share(string|callable $key, ?callable $resolver = null): void
    {
        if (!is_string($key)) {
            PageflowShares::add($key);
            return;
        }

        if ($resolver === null) {
            throw new InvalidArgumentException(
                'pageflow_share(string $key, callable $resolver): a resolver is required when a key is given.'
            );
        }

        PageflowShares::key($key, $resolver);
    }
}
