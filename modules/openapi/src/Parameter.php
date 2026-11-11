<?php

declare(strict_types=1);

namespace AlfacodeTeam\OpenApi;

/**
 * One operation parameter — a path segment, a query string value, a header or a
 * cookie.
 *
 * A path parameter is ALWAYS required in OpenAPI, even when the route treats it
 * as optional. There is no way to express "this segment may be absent" in a
 * single path item, because the two forms are different URL templates. A route
 * declared `{page?}` therefore has to become two paths — see
 * {@see \AlfacodeTeam\OpenApi\Document} for how that is handled — and this class
 * enforces the invariant so a malformed spec cannot be built by accident.
 */
final class Parameter
{
    public const IN_PATH = 'path';
    public const IN_QUERY = 'query';
    public const IN_HEADER = 'header';
    public const IN_COOKIE = 'cookie';

    private function __construct(
        private readonly string $name,
        private readonly string $in,
        private readonly Schema $schema,
        private readonly bool $required,
        private readonly ?string $description,
    ) {}

    public static function path(string $name, Schema $schema, ?string $description = null): self
    {
        // Not configurable on purpose: a non-required path parameter is invalid
        // OpenAPI, and every generator either rejects it or quietly produces a
        // broken client.
        return new self($name, self::IN_PATH, $schema, true, $description);
    }

    public static function query(string $name, Schema $schema, bool $required = false, ?string $description = null): self
    {
        return new self($name, self::IN_QUERY, $schema, $required, $description);
    }

    public static function header(string $name, Schema $schema, bool $required = false, ?string $description = null): self
    {
        return new self($name, self::IN_HEADER, $schema, $required, $description);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function in(): string
    {
        return $this->in;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'in' => $this->in,
            'required' => $this->required,
            'schema' => $this->schema->toArray(),
        ];

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        return $out;
    }
}
