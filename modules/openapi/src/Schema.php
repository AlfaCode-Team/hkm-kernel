<?php

declare(strict_types=1);

namespace AlfacodeTeam\OpenApi;

/**
 * A JSON Schema fragment, as OpenAPI 3.1 uses them.
 *
 * Deliberately not a full JSON Schema implementation. This models the handful of
 * shapes a route surface actually produces — a constrained scalar, an enum, an
 * array of something, a free-form object — because a generator that can express
 * every schema keyword is a generator nobody can read.
 */
final class Schema
{
    /**
     * @param list<string>|null              $enum
     * @param array<string, Schema>|null     $properties
     * @param list<string>|null              $required
     */
    private function __construct(
        private readonly ?string $type = null,
        private readonly ?string $format = null,
        private readonly ?string $pattern = null,
        private readonly ?array $enum = null,
        private readonly ?Schema $items = null,
        private readonly ?array $properties = null,
        private readonly ?array $required = null,
        private readonly ?string $description = null,
        private readonly ?int $minLength = null,
    ) {}

    public static function string(?string $pattern = null, ?string $format = null, ?int $minLength = null): self
    {
        return new self(type: 'string', format: $format, pattern: $pattern, minLength: $minLength);
    }

    public static function integer(?string $format = null): self
    {
        return new self(type: 'integer', format: $format);
    }

    public static function number(): self
    {
        return new self(type: 'number');
    }

    public static function boolean(): self
    {
        return new self(type: 'boolean');
    }

    /** @param list<string> $values */
    public static function enum(array $values): self
    {
        return new self(type: 'string', enum: array_values($values));
    }

    public static function array(Schema $items): self
    {
        return new self(type: 'array', items: $items);
    }

    /**
     * @param array<string, Schema> $properties
     * @param list<string>          $required
     */
    public static function object(array $properties = [], array $required = []): self
    {
        return new self(
            type: 'object',
            properties: $properties === [] ? null : $properties,
            required: $required === [] ? null : array_values($required),
        );
    }

    /**
     * An object whose shape is not described.
     *
     * Honest output for a route whose request or response body the manifest
     * cannot know. The alternative — inventing properties — produces a spec that
     * generates clients which do not compile.
     */
    public static function unknownObject(?string $description = null): self
    {
        return new self(type: 'object', description: $description);
    }

    public function withDescription(?string $description): self
    {
        return new self(
            $this->type,
            $this->format,
            $this->pattern,
            $this->enum,
            $this->items,
            $this->properties,
            $this->required,
            $description,
            $this->minLength,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [];

        if ($this->type !== null) {
            $out['type'] = $this->type;
        }
        if ($this->format !== null) {
            $out['format'] = $this->format;
        }
        if ($this->pattern !== null) {
            $out['pattern'] = $this->pattern;
        }
        if ($this->minLength !== null) {
            $out['minLength'] = $this->minLength;
        }
        if ($this->enum !== null) {
            $out['enum'] = $this->enum;
        }
        if ($this->items !== null) {
            $out['items'] = $this->items->toArray();
        }
        if ($this->properties !== null) {
            $properties = [];
            foreach ($this->properties as $name => $schema) {
                $properties[$name] = $schema->toArray();
            }
            $out['properties'] = $properties;
        }
        if ($this->required !== null) {
            $out['required'] = $this->required;
        }
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        return $out;
    }
}
