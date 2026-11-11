<?php

declare(strict_types=1);

namespace AlfacodeTeam\OpenApi;

/**
 * One method on one path.
 *
 * Immutable and built up with `with*()` calls, because a generator assembles an
 * operation from several independent sources — the route key gives the method
 * and path, the placeholders give parameters, the filters give security — and
 * threading all of that through one constructor makes the call site unreadable.
 */
final class Operation
{
    /**
     * @param list<Parameter>                      $parameters
     * @param array<string, Response>              $responses   keyed by status code
     * @param list<array<string, list<string>>>    $security
     * @param list<string>                         $tags
     */
    private function __construct(
        private readonly ?string $operationId = null,
        private readonly ?string $summary = null,
        private readonly ?string $description = null,
        private readonly array $parameters = [],
        private readonly array $responses = [],
        private readonly array $security = [],
        private readonly array $tags = [],
        private readonly bool $deprecated = false,
        private readonly ?Schema $requestBody = null,
        private readonly bool $requestBodyRequired = false,
    ) {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * Exposed so {@see Document} can enforce uniqueness. OpenAPI requires
     * operationIds to be unique across the whole document, and a duplicate
     * makes most generators either fail or silently drop one of the two
     * methods it was supposed to produce.
     */
    public function operationId(): ?string
    {
        return $this->operationId;
    }

    public function withOperationId(?string $id): self
    {
        return $this->copy(operationId: $id);
    }

    public function withSummary(?string $summary): self
    {
        return $this->copy(summary: $summary);
    }

    public function withDescription(?string $description): self
    {
        return $this->copy(description: $description);
    }

    public function withParameter(Parameter $parameter): self
    {
        return $this->copy(parameters: [...$this->parameters, $parameter]);
    }

    /** @param list<Parameter> $parameters */
    public function withParameters(array $parameters): self
    {
        return $this->copy(parameters: [...$this->parameters, ...array_values($parameters)]);
    }

    /**
     * Declare one response.
     *
     * NOT built with array unpacking. A status like `'200'` is a numeric string,
     * which PHP silently stores as the INTEGER key 200 — and `[...$map]`
     * RENUMBERS integer keys from zero. Spreading this map therefore threw away
     * every response but the last, while looking perfectly correct. Assigning
     * into a copy keeps the keys.
     */
    public function withResponse(int|string $status, Response $response): self
    {
        $responses = $this->responses;
        $responses[(string) $status] = $response;

        return $this->copy(responses: $responses);
    }

    /**
     * Require a named security scheme.
     *
     * @param list<string> $scopes scope names, for schemes that carry them.
     */
    public function withSecurity(string $scheme, array $scopes = []): self
    {
        return $this->copy(security: [...$this->security, [$scheme => array_values($scopes)]]);
    }

    public function withTag(string $tag): self
    {
        return in_array($tag, $this->tags, true)
            ? $this
            : $this->copy(tags: [...$this->tags, $tag]);
    }

    public function withRequestBody(Schema $schema, bool $required = false): self
    {
        return $this->copy(requestBody: $schema, requestBodyRequired: $required);
    }

    public function deprecated(bool $deprecated = true): self
    {
        return $this->copy(deprecated: $deprecated);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [];

        if ($this->operationId !== null) {
            $out['operationId'] = $this->operationId;
        }
        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->tags !== []) {
            $out['tags'] = $this->tags;
        }
        if ($this->parameters !== []) {
            $out['parameters'] = array_map(
                static fn (Parameter $p): array => $p->toArray(),
                $this->parameters,
            );
        }
        if ($this->requestBody !== null) {
            $out['requestBody'] = [
                'required' => $this->requestBodyRequired,
                'content' => ['application/json' => ['schema' => $this->requestBody->toArray()]],
            ];
        }
        if ($this->deprecated) {
            $out['deprecated'] = true;
        }
        if ($this->security !== []) {
            $out['security'] = $this->security;
        }

        // `responses` is required by the spec, so an operation with none still
        // emits an empty object rather than omitting the key. Sorted by status so
        // the document is byte-stable regardless of the order they were declared.
        $keys = array_keys($this->responses);
        sort($keys, SORT_STRING);

        $responses = [];
        foreach ($keys as $status) {
            $responses[(string) $status] = $this->responses[$status]->toArray();
        }
        $out['responses'] = $responses;

        return $out;
    }

    /**
     * @param list<Parameter>|null                   $parameters
     * @param array<string, Response>|null           $responses
     * @param list<array<string, list<string>>>|null $security
     * @param list<string>|null                      $tags
     */
    private function copy(
        ?string $operationId = null,
        ?string $summary = null,
        ?string $description = null,
        ?array $parameters = null,
        ?array $responses = null,
        ?array $security = null,
        ?array $tags = null,
        ?bool $deprecated = null,
        ?Schema $requestBody = null,
        ?bool $requestBodyRequired = null,
    ): self {
        return new self(
            $operationId ?? $this->operationId,
            $summary ?? $this->summary,
            $description ?? $this->description,
            $parameters ?? $this->parameters,
            $responses ?? $this->responses,
            $security ?? $this->security,
            $tags ?? $this->tags,
            $deprecated ?? $this->deprecated,
            $requestBody ?? $this->requestBody,
            $requestBodyRequired ?? $this->requestBodyRequired,
        );
    }
}
