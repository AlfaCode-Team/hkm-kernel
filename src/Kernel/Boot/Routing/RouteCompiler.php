<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteParameter;

/**
 * RouteCompiler — everything about ONE route that is constant, computed once.
 *
 * The handler split, the parsed filter specs, the dependency-graph cache key and
 * (for a dynamic path) the anchored regex. No request derives any of it.
 *
 * It is also where most of this framework's ANTI-TYPO GUARDS live. Each one
 * converts a permanent, silent 404 — the worst failure a router has, because it
 * is indistinguishable from a missing controller — into a boot failure that
 * names the route:
 *
 *   an unknown {name:type}          → would never match
 *   a PCRE-invalid pattern          → preg_match() false on EVERY request
 *   a handler with no/two '@'       → unresolvable
 *   an unknown requires[] domain    → a request-time 500 on one page
 *   a duplicate route name          → route('x') resolves to whoever loaded last
 */
final class RouteCompiler
{
    /**
     * @param list<string> $filters
     * @return array<string, mixed>
     */
    public function precompile(string $handler, string $path, string $solves, array $filters, string $context): array
    {
        if (substr_count($handler, '@') !== 1) {
            throw new BootException(
                "{$context} has handler [{$handler}] — it must be in 'Controller@method' format "
                . '(exactly one "@").'
            );
        }

        [$class, $action] = explode('@', $handler, 2);

        if ($class === '' || $action === '') {
            throw new BootException(
                "{$context} has handler [{$handler}] — both the controller class and the method are required."
            );
        }

        $compiled = ['class' => $class, 'action' => $action];

        $this->verifyHandler($class, $action, $handler, $context);

        $specs = [];
        foreach ($filters as $spec) {
            $specs[] = RouteNormalizer::parseFilterSpec($spec);
        }
        $compiled['filter_specs'] = $specs;
        $compiled['graph_key']    = $solves . '|';

        if (str_contains($path, '{')) {
            $this->validateParameterTypes($path, $context);

            try {
                $route = RouteParameter::compile($path);
            } catch (\InvalidArgumentException $e) {
                // A pattern that PCRE refuses compiles to a route which makes
                // preg_match() return false on EVERY request — a permanent silent
                // 404 that reads as a missing controller. Same anti-typo policy as
                // unknown types, unknown requires[] domains and dead disable specs.
                throw new BootException("{$context}: " . $e->getMessage(), previous: $e);
            }

            $compiled['regex']  = $route['regex'];
            $compiled['params'] = $route['params'];
        }

        return $compiled;
    }

    /**
     * OPT-IN: check that the handler class and method actually exist.
     *
     * Off by default because it forces the autoloader to load every controller in
     * the application at boot — real cost on a cold FPM process, and pointless in
     * production where the routes demonstrably worked when the build was cut.
     * Turn it on in development and CI (`ROUTE_VERIFY_HANDLERS=1`) and a renamed
     * action fails the build with the route that references it, instead of 500ing
     * the first time someone visits that page.
     */
    private function verifyHandler(string $class, string $action, string $handler, string $context): void
    {
        static $enabled = null;

        $enabled ??= \function_exists('env')
            && filter_var(env('ROUTE_VERIFY_HANDLERS', false), FILTER_VALIDATE_BOOL);

        if ($enabled !== true) {
            return;
        }

        if (!class_exists($class)) {
            throw new BootException("{$context} references controller [{$class}], which does not exist.");
        }

        if (!method_exists($class, $action)) {
            throw new BootException(
                "{$context} references [{$handler}], but [{$class}] has no method [{$action}]."
            );
        }

        if (!(new \ReflectionMethod($class, $action))->isPublic()) {
            throw new BootException(
                "{$context} references [{$handler}], but [{$action}] is not public — "
                . 'the pipeline can only invoke public controller actions.'
            );
        }
    }

    /**
     * Fail the BOOT on an unknown `{name:type}` placeholder type.
     *
     * A typo like `{id:number}` would otherwise compile to a route that simply
     * never matches — a silent 404 that looks like a missing controller.
     */
    private function validateParameterTypes(string $path, string $context): void
    {
        foreach (RouteParameter::parse($path) as $placeholder) {
            if ($placeholder['type'] === '' || RouteParameter::isValidType($placeholder['type'])) {
                continue;
            }

            throw new BootException(sprintf(
                '%s declares path [%s] with unknown parameter type [%s] on {%s}. Valid types: %s.',
                $context,
                $path,
                $placeholder['type'],
                $placeholder['name'],
                implode(', ', RouteParameter::names()),
            ));
        }
    }

    /**
     * Ensure every declared dependency names a domain some module solves(),
     * failing fast at boot with a descriptive message instead of a request-time
     * 500. Returns the list unchanged on success.
     *
     * @param list<string>         $requires
     * @param array<string, true>  $knownDomains
     * @return list<string>
     */
    public function validateRequires(array $requires, array $knownDomains, string $context): array
    {
        foreach ($requires as $dep) {
            if (!isset($knownDomains[$dep])) {
                throw new BootException(
                    "{$context} requires unknown module domain [{$dep}]. "
                    . 'No registered module solves it — check the spelling and that the '
                    . 'plugin is listed in withModules()/withEssentialModules().'
                );
            }
        }

        return $requires;
    }

    /**
     * Resolve and claim a route's optional `"name"`.
     *
     * Names are OPTIONAL — an unnamed route is unchanged in every way and simply
     * cannot be addressed by UrlGenerator::route(). They live in one flat,
     * application-wide namespace, so a duplicate fails the boot: silently letting
     * the last declaration win would make route('user.show') resolve to whichever
     * plugin happened to load last.
     *
     * @param array<string, mixed>  $route
     * @param array<string, string> $names  claimed names => owning route key
     */
    public function claimName(array $route, string $key, array &$names, string $owner): ?string
    {
        $name = $route['name'] ?? null;

        if ($name === null || $name === '') {
            return null;
        }

        if (!is_string($name)) {
            throw new BootException("Route [{$key}] in {$owner} has a non-string name.");
        }

        if (isset($names[$name])) {
            throw new BootException(
                "Duplicate route name [{$name}] in {$owner} - already claimed by [{$names[$name]}]. "
                . 'Route names are application-wide and must be unique.'
            );
        }

        $names[$name] = $key;

        return $name;
    }
}
