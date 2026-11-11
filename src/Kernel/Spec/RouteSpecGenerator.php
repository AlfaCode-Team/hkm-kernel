<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Spec;

use AlfacodeTeam\OpenApi\Document;
use AlfacodeTeam\OpenApi\Info;
use AlfacodeTeam\OpenApi\Operation;
use AlfacodeTeam\OpenApi\Parameter;
use AlfacodeTeam\OpenApi\Response;
use AlfacodeTeam\OpenApi\Schema;
use AlfacodeTeam\OpenApi\SecurityScheme;
use AlfacodeTeam\OpenApi\Server;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteParameter;

/**
 * Turns the compiled route manifest into an OpenAPI document.
 *
 * WHY THIS IS CHEAP HERE AND EXPENSIVE ELSEWHERE
 * ----------------------------------------------
 * Frameworks that register routes at runtime have to boot a server and
 * introspect a live route table to produce a spec. This kernel compiles routes
 * to `route-manifest.php` at boot, already validated, so generating a spec is
 * reading a file. Nothing is executed, no controller is autoloaded, and the
 * output cannot drift from the routing — the manifest IS what the matcher
 * matches against.
 *
 * WHAT IT CAN AND CANNOT KNOW
 * ---------------------------
 * The manifest describes the REQUEST surface completely: method, path, typed
 * placeholders, filters, route name, domain, owning module. It says nothing
 * about response or request BODIES, because no declaration in `module.json`
 * carries them.
 *
 * So this generator is deliberately honest about the gap. Bodies are emitted as
 * undescribed objects rather than invented shapes: a client generated from an
 * undescribed body compiles and hands back raw data, whereas one generated from
 * a guessed shape does not compile at all. The parts it does know — paths,
 * parameter types and patterns, which endpoints need auth, which are rate
 * limited — are the parts a hand-written spec gets wrong.
 *
 * THE PATTERNS ARE THE ROUTER'S OWN
 * ---------------------------------
 * Parameter schemas take their `pattern` straight from
 * {@see RouteParameter::TYPES}, so a documented constraint is literally the
 * regex the matcher enforces. A `{id:num}` is documented as `^[0-9]+$` because
 * that is what will be accepted, not because someone wrote it down twice.
 */
final class RouteSpecGenerator
{
    /** Security scheme names declared once in components and referenced per operation. */
    public const SCHEME_SESSION = 'session';
    public const SCHEME_BEARER = 'bearer';

    /**
     * @param list<string> $excludePrefixes paths beginning with any of these are
     *        left out. Intended for internal or mock surfaces that should not
     *        appear in a published client.
     */
    public function __construct(
        private readonly string $title = 'API',
        private readonly string $version = '1.0.0',
        private readonly ?string $description = null,
        private readonly array $excludePrefixes = [],
        private readonly ?string $serverUrl = null,
    ) {}

    /**
     * @param array<string, array<string, mixed>> $manifest the decoded route-manifest.php
     */
    public function generate(array $manifest): Document
    {
        $document = new Document(new Info(
            title: $this->title,
            version: $this->version,
            description: $this->description,
        ));

        if ($this->serverUrl !== null && $this->serverUrl !== '') {
            $document->addServer(new Server(rtrim($this->serverUrl, '/')));
        }

        $document->addSecurityScheme(self::SCHEME_SESSION, SecurityScheme::cookieSession(
            'hkm_session',
            'Session cookie issued at sign-in.',
        ));
        $document->addSecurityScheme(self::SCHEME_BEARER, SecurityScheme::bearer(
            'JWT',
            'Bearer token or personal access token.',
        ));

        // Sorted so the walk order — and therefore any error the walk reports —
        // does not depend on the manifest's own key order.
        $keys = array_keys($manifest);
        sort($keys);

        foreach ($keys as $key) {
            $entry = $manifest[$key];
            $parsed = $this->parseKey($key);

            if ($parsed === null) {
                continue;
            }

            [$method, $path, $domain] = $parsed;

            if ($this->isExcluded($path)) {
                continue;
            }

            // A per-domain server would change the base URL rather than the
            // path, and OpenAPI has no way to say "this path exists only on this
            // host". Recording the host in the description is the honest
            // compromise; a caller wanting one document per brand filters the
            // manifest before calling this.
            foreach ($this->expandOptionalSegments($path, $entry) as [$templatePath, $params, $idSuffix]) {
                $operation = $this->buildOperation($entry, $method, $params, $domain, $idSuffix);

                // A domain-grouped route can share METHOD+path with the shared
                // table's version of it. The first one wins and the rest are
                // skipped, because emitting the duplicate would make the whole
                // document invalid.
                if ($document->has($templatePath, $method)) {
                    continue;
                }

                $document->addOperation($templatePath, $method, $operation);
            }

            $tag = $this->tagFor($entry);
            if ($tag !== null) {
                $document->addTag($tag);
            }
        }

        return $document;
    }

    /**
     * Split a manifest key into method, path and domain.
     *
     * Keys are `"METHOD /path"` when ungrouped and `"METHOD@domain /path"` when
     * the route belongs to a domain group.
     *
     * @return array{string, string, string}|null
     */
    private function parseKey(string $key): ?array
    {
        $space = strpos($key, ' ');

        if ($space === false) {
            return null;
        }

        $methodPart = substr($key, 0, $space);
        $path = substr($key, $space + 1);

        if ($path === '' || !str_starts_with($path, '/')) {
            return null;
        }

        $at = strpos($methodPart, '@');

        return $at === false
            ? [$methodPart, $path, '']
            : [substr($methodPart, 0, $at), $path, substr($methodPart, $at + 1)];
    }

    /**
     * Produce one OpenAPI path template per reachable URL shape.
     *
     * OpenAPI cannot express an optional path segment: `/pages` and
     * `/pages/{page}` are two different templates, and a path parameter is
     * always required. A route declared `/pages/{page?}` is therefore emitted
     * TWICE — once without the segment and once with it — which is exactly the
     * set of URLs the matcher accepts. Describing it as a single required
     * parameter would hide the `/pages` form from every generated client.
     *
     * Each variant also carries an operationId suffix. The two forms are two
     * operations as far as OpenAPI is concerned, and operationIds must be unique
     * document-wide — so they cannot both be called `feed.index`. The short form
     * keeps the route's name and the parameterised form appends the segments it
     * adds, giving `feed.index` and `feed.index.withPage`, which is also how the
     * pair reads in a generated client.
     *
     * @param array<string, mixed> $entry
     * @return list<array{string, list<array<string, mixed>>, string}>
     */
    private function expandOptionalSegments(string $path, array $entry): array
    {
        /** @var list<array<string, mixed>> $params */
        $params = \is_array($entry['params'] ?? null) ? $entry['params'] : [];

        $required = [];
        $optional = [];

        foreach ($params as $param) {
            if (($param['optional'] ?? false) === true) {
                $optional[] = $param;
            } else {
                $required[] = $param;
            }
        }

        $full = $this->toTemplate($path);

        if ($optional === []) {
            return [[$full, $required, '']];
        }

        // Strip every optional placeholder — and the '/' it carries with it —
        // to get the short form.
        $short = $full;
        $names = [];

        foreach ($optional as $param) {
            $name = (string) ($param['name'] ?? '');
            $short = str_replace('/{' . $name . '}', '', $short);
            $names[] = ucfirst($name);
        }

        $short = $short === '' ? '/' : $short;

        return [
            [$short, $required, ''],
            [$full, [...$required, ...$optional], '.with' . implode('', $names)],
        ];
    }

    /**
     * `/things/{id:num}` → `/things/{id}`.
     *
     * The type moves into the parameter's schema. Leaving it in the path would
     * produce a template no client can match, which is why
     * {@see Document::addOperation()} rejects a path that still contains one.
     */
    private function toTemplate(string $path): string
    {
        return (string) preg_replace_callback(
            RouteParameter::PLACEHOLDER,
            static fn (array $m): string => '{' . $m[1] . '}',
            $path,
        );
    }

    /**
     * @param array<string, mixed>              $entry
     * @param list<array<string, mixed>>        $params
     */
    private function buildOperation(
        array $entry,
        string $method,
        array $params,
        string $domain,
        string $idSuffix = '',
    ): Operation {
        $id = $this->operationIdFor($entry, $method);

        $operation = Operation::create()
            ->withOperationId($id === null ? null : $id . $idSuffix)
            ->withSummary($this->summaryFor($entry));

        $tag = $this->tagFor($entry);
        if ($tag !== null) {
            $operation = $operation->withTag($tag);
        }

        if ($domain !== '') {
            $operation = $operation->withDescription("Available on host: {$domain}");
        }

        foreach ($params as $param) {
            $name = (string) ($param['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $operation = $operation->withParameter(Parameter::path(
                $name,
                $this->schemaForType((string) ($param['type'] ?? '')),
            ));
        }

        return $this->applyFilters($operation, $entry, $method, $params !== []);
    }

    /**
     * Map a route parameter type to a schema, taking the pattern from the
     * router's own table so the two can never disagree.
     */
    private function schemaForType(string $type): Schema
    {
        if (str_starts_with($type, 'enum(')) {
            $inner = substr($type, 5, -1);

            return Schema::enum(array_values(array_filter(explode('|', $inner), static fn (string $v): bool => $v !== '')));
        }

        // `num` is the one type that is not a string in a generated client.
        if ($type === 'num') {
            return Schema::integer();
        }

        if ($type === 'uuid') {
            return Schema::string(format: 'uuid');
        }

        $pattern = $type === ''
            ? RouteParameter::DEFAULT_PATTERN
            : (RouteParameter::TYPES[$type] ?? RouteParameter::DEFAULT_PATTERN);

        // `any` and `path` cross '/' and their raw fragments are not useful
        // documentation, so they are described as plain strings instead.
        if ($type === 'any' || $type === 'path') {
            return Schema::string()->withDescription(
                $type === 'path'
                    ? 'May contain "/". Traversal sequences are rejected by the router.'
                    : 'May contain "/".',
            );
        }

        return Schema::string(pattern: '^' . $pattern . '$');
    }

    /**
     * Translate the route's declared filters into security requirements and the
     * responses they imply.
     *
     * This is the part a hand-written spec always gets wrong: which endpoints
     * need a token, which are rate limited, which return 422. Here it is read
     * from the same declaration the pipeline enforces.
     *
     * @param array<string, mixed> $entry
     */
    private function applyFilters(Operation $operation, array $entry, string $method, bool $hasPathParams): Operation
    {
        /** @var list<array<string, mixed>> $specs */
        $specs = \is_array($entry['filter_specs'] ?? null) ? $entry['filter_specs'] : [];

        $aliases = [];
        foreach ($specs as $spec) {
            $alias = (string) ($spec['alias'] ?? '');
            if ($alias !== '') {
                $aliases[$alias] = $spec['args'] ?? [];
            }
        }

        $authenticated = false;
        $scopes = [];

        foreach ($aliases as $alias => $args) {
            switch ($alias) {
                case 'auth':
                case 'fresh-session':
                    $authenticated = true;
                    break;

                case 'can':
                    // `can:posts.manage` — the permission becomes a scope on the
                    // security requirement, which is how a generated client
                    // documents what the caller must be allowed to do.
                    $authenticated = true;
                    foreach ((array) $args as $arg) {
                        if (\is_scalar($arg) && (string) $arg !== '') {
                            $scopes[] = (string) $arg;
                        }
                    }
                    break;
            }
        }

        if ($authenticated) {
            $operation = $operation
                ->withSecurity(self::SCHEME_SESSION, $scopes)
                ->withSecurity(self::SCHEME_BEARER, $scopes);
        }

        // Bodies are undescribed on purpose — see the class docblock.
        $operation = $operation->withResponse(
            $this->successStatusFor($method),
            $this->successResponseFor($method),
        );

        if ($authenticated) {
            $operation = $operation
                ->withResponse(401, Response::error('Not authenticated.'))
                ->withResponse(403, Response::error('Authenticated but not permitted.'));
        }

        if (\in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $operation = $operation
                ->withRequestBody(Schema::unknownObject('Request body. Not described by the route manifest.'))
                ->withResponse(422, Response::error('Validation failed.', withFields: true));
        }

        // Driven by THIS variant's parameters, not the route entry's. The short
        // form of an optional-segment route takes no parameter, so documenting a
        // "not found" for an identifier it never receives would be noise.
        if ($hasPathParams) {
            $operation = $operation->withResponse(404, Response::error('Not found.'));
        }

        if (isset($aliases['throttle'])) {
            $operation = $operation->withResponse(429, Response::error('Rate limit exceeded.'));
        }

        return $operation->withResponse(500, Response::error('Server error.'));
    }

    private function successStatusFor(string $method): int
    {
        return match ($method) {
            'POST' => 201,
            'DELETE' => 204,
            default => 200,
        };
    }

    private function successResponseFor(string $method): Response
    {
        return match ($method) {
            'DELETE' => Response::empty('Deleted.'),
            'POST' => Response::of('Created.', Schema::unknownObject('Not described by the route manifest.')),
            default => Response::of('OK.', Schema::unknownObject('Not described by the route manifest.')),
        };
    }

    /**
     * A stable, unique identifier for the operation.
     *
     * The route NAME when it has one, because that is already an
     * application-wide unique string the project controls and keeps stable.
     * Otherwise it falls back to the controller and action, which is stable for
     * as long as the class is.
     *
     * @param array<string, mixed> $entry
     */
    private function operationIdFor(array $entry, string $method): ?string
    {
        $name = $entry['name'] ?? null;

        if (\is_string($name) && $name !== '') {
            return $name;
        }

        $class = $entry['class'] ?? null;
        $action = $entry['action'] ?? null;

        if (\is_string($class) && \is_string($action) && $action !== '') {
            $short = substr($class, (int) strrpos($class, '\\') + 1);

            return lcfirst($short) . '.' . $action;
        }

        return null;
    }

    /** @param array<string, mixed> $entry */
    private function summaryFor(array $entry): ?string
    {
        $class = $entry['class'] ?? null;
        $action = $entry['action'] ?? null;

        if (!\is_string($class) || !\is_string($action)) {
            return null;
        }

        $short = substr($class, (int) strrpos($class, '\\') + 1);

        return "{$short}::{$action}()";
    }

    /**
     * Group operations by the module that owns them.
     *
     * The `solves` domain is the natural tag: it is the one grouping the
     * application already believes in, so generated clients and documentation
     * pages come out organised the same way the codebase is.
     *
     * @param array<string, mixed> $entry
     */
    private function tagFor(array $entry): ?string
    {
        $solves = $entry['solves'] ?? null;

        if (!\is_string($solves) || $solves === '') {
            return null;
        }

        return $solves === '__project__' ? 'project' : $solves;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludePrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
