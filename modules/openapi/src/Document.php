<?php

declare(strict_types=1);

namespace AlfacodeTeam\OpenApi;

/**
 * An OpenAPI document, assembled operation by operation.
 *
 * Mutable on purpose, unlike the value objects it holds. A generator walks a
 * route table and adds hundreds of operations; making each `add` return a new
 * document would copy the whole path map every time, which for a few hundred
 * routes is measurable work to produce a file that is written once.
 *
 * ORDER IS STABLE. Paths are sorted on output and methods within a path follow
 * a fixed verb order, so regenerating an unchanged route table produces a
 * byte-identical document. That is what makes the output safe to commit and to
 * diff in review — a spec that reshuffles itself on every build is a spec
 * nobody can review.
 */
final class Document
{
    public const VERSION = '3.1.0';

    /** Methods emit in this order within a path item, regardless of insertion order. */
    private const METHOD_ORDER = ['get', 'put', 'post', 'patch', 'delete', 'options', 'head', 'trace'];

    /** @var array<string, array<string, Operation>> path => method => operation */
    private array $paths = [];

    /** @var list<Server> */
    private array $servers = [];

    /** @var array<string, SecurityScheme> */
    private array $securitySchemes = [];

    /** @var array<string, string> tag name => description */
    private array $tags = [];

    /** @var array<string, string> operationId => the "METHOD /path" that claimed it */
    private array $operationIds = [];

    public function __construct(
        private readonly Info $info,
    ) {}

    public function addServer(Server $server): self
    {
        foreach ($this->servers as $existing) {
            if ($existing->url() === $server->url()) {
                return $this;
            }
        }

        $this->servers[] = $server;

        return $this;
    }

    public function addSecurityScheme(string $name, SecurityScheme $scheme): self
    {
        $this->securitySchemes[$name] = $scheme;

        return $this;
    }

    public function addTag(string $name, string $description = ''): self
    {
        $this->tags[$name] ??= $description;

        return $this;
    }

    /**
     * Add one operation.
     *
     * @param string $path   an OpenAPI path template — `/things/{id}`, with the
     *                       braces but WITHOUT any type suffix. A caller holding
     *                       a framework path like `/things/{id:num}` must convert
     *                       it first; the type belongs in the parameter's schema,
     *                       and a generator fed `{id:num}` would emit a path
     *                       template no client can match.
     * @param string $method case-insensitive HTTP verb.
     *
     * @throws \InvalidArgumentException when the path is not a template, the verb
     *         is not one OpenAPI knows, or the same path+method is added twice.
     */
    public function addOperation(string $path, string $method, Operation $operation): self
    {
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException("OpenAPI paths must start with '/': got '{$path}'.");
        }

        if (str_contains($path, ':')) {
            throw new \InvalidArgumentException(
                "Path '{$path}' still carries a parameter type. Convert '{name:type}' to '{name}' "
                . 'and express the type in the parameter schema.'
            );
        }

        $verb = strtolower($method);

        if (!in_array($verb, self::METHOD_ORDER, true)) {
            throw new \InvalidArgumentException("'{$method}' is not an HTTP method OpenAPI describes.");
        }

        if (isset($this->paths[$path][$verb])) {
            throw new \InvalidArgumentException("Duplicate operation {$method} {$path}.");
        }

        // OpenAPI requires operationIds to be unique document-wide. A duplicate
        // makes most client generators either fail outright or silently emit
        // only one of the two methods, so it is caught here rather than
        // discovered when someone tries to use the generated client.
        $id = $operation->operationId();

        if ($id !== null) {
            if (isset($this->operationIds[$id])) {
                throw new \InvalidArgumentException(
                    "Duplicate operationId '{$id}': already used by "
                    . $this->operationIds[$id] . ", now also " . strtoupper($verb) . " {$path}."
                );
            }

            $this->operationIds[$id] = strtoupper($verb) . ' ' . $path;
        }

        $this->paths[$path][$verb] = $operation;

        return $this;
    }

    public function has(string $path, string $method): bool
    {
        return isset($this->paths[$path][strtolower($method)]);
    }

    public function operationCount(): int
    {
        $count = 0;
        foreach ($this->paths as $methods) {
            $count += count($methods);
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'openapi' => self::VERSION,
            'info' => $this->info->toArray(),
        ];

        if ($this->servers !== []) {
            $out['servers'] = array_map(static fn (Server $s): array => $s->toArray(), $this->servers);
        }

        if ($this->tags !== []) {
            $names = array_keys($this->tags);
            sort($names);

            $out['tags'] = array_map(
                fn (string $name): array => array_filter(
                    ['name' => $name, 'description' => $this->tags[$name]],
                    static fn (string $v): bool => $v !== '',
                ),
                $names,
            );
        }

        $paths = $this->paths;
        ksort($paths);

        $out['paths'] = [];
        foreach ($paths as $path => $methods) {
            $item = [];
            foreach (self::METHOD_ORDER as $verb) {
                if (isset($methods[$verb])) {
                    $item[$verb] = $methods[$verb]->toArray();
                }
            }
            $out['paths'][$path] = $item;
        }

        if ($this->securitySchemes !== []) {
            $schemes = $this->securitySchemes;
            ksort($schemes);

            $out['components'] = [
                'securitySchemes' => array_map(
                    static fn (SecurityScheme $s): array => $s->toArray(),
                    $schemes,
                ),
            ];
        }

        return $out;
    }

    /**
     * The document as JSON.
     *
     * Pretty-printed with unescaped slashes, because the output is read by
     * people and reviewed in diffs. `JSON_THROW_ON_ERROR` so a malformed
     * document fails loudly rather than writing `false` to a file.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
