<?php

declare(strict_types=1);

namespace AlfacodeTeam\OpenApi;

/**
 * One response of an operation.
 *
 * A response needs a description — the spec requires it — and may describe a
 * body. A generator working from routing metadata usually cannot know the body
 * shape, and {@see Schema::unknownObject()} is the honest answer in that case:
 * an undescribed object produces a client that compiles and returns raw data,
 * whereas an invented shape produces one that does not.
 */
final class Response
{
    private function __construct(
        private readonly string $description,
        private readonly ?Schema $schema,
        private readonly string $mediaType,
    ) {}

    public static function of(string $description, ?Schema $schema = null, string $mediaType = 'application/json'): self
    {
        return new self($description, $schema, $mediaType);
    }

    /** No body at all — a 204, or a DELETE that answers with nothing. */
    public static function empty(string $description): self
    {
        return new self($description, null, 'application/json');
    }

    /**
     * The kernel's error envelope, which is the same shape on every error
     * response: `{ "error": { "code", "message", "requestId"[, "fields"] } }`.
     *
     * Worth modelling explicitly because it is the one body shape a generator
     * CAN know from the framework alone, and a typed error is the difference
     * between a usable client and one that hands back untyped arrays on failure.
     */
    public static function error(string $description, bool $withFields = false): self
    {
        $properties = [
            'code' => Schema::string(),
            'message' => Schema::string(),
            'requestId' => Schema::string(),
        ];

        if ($withFields) {
            $properties['fields'] = Schema::unknownObject('Field name to message.');
        }

        return new self(
            $description,
            Schema::object(
                ['error' => Schema::object($properties, ['code', 'message'])],
                ['error'],
            ),
            'application/json',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['description' => $this->description];

        if ($this->schema !== null) {
            $out['content'] = [
                $this->mediaType => ['schema' => $this->schema->toArray()],
            ];
        }

        return $out;
    }
}
