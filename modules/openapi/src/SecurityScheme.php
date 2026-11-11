<?php

declare(strict_types=1);

namespace AlfacodeTeam\OpenApi;

/**
 * A named way of authenticating, declared once in `components.securitySchemes`
 * and referenced by name from each operation that needs it.
 */
final class SecurityScheme
{
    /**
     * No scope list here on purpose. For the schemes this models — http bearer
     * and apiKey — scopes are not part of the scheme; they belong on each
     * operation's security requirement, which is where
     * {@see Operation::withSecurity()} puts them. Only OAuth2 and OIDC declare
     * scopes on the scheme itself, and neither is modelled yet.
     */
    private function __construct(
        private readonly string $type,
        private readonly ?string $scheme = null,
        private readonly ?string $bearerFormat = null,
        private readonly ?string $name = null,
        private readonly ?string $in = null,
        private readonly ?string $description = null,
    ) {}

    public static function bearer(?string $format = null, ?string $description = null): self
    {
        return new self('http', scheme: 'bearer', bearerFormat: $format, description: $description);
    }

    public static function apiKeyHeader(string $header, ?string $description = null): self
    {
        return new self('apiKey', name: $header, in: 'header', description: $description);
    }

    public static function cookieSession(string $cookie, ?string $description = null): self
    {
        return new self('apiKey', name: $cookie, in: 'cookie', description: $description);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['type' => $this->type];

        foreach (
            [
                'scheme' => $this->scheme,
                'bearerFormat' => $this->bearerFormat,
                'name' => $this->name,
                'in' => $this->in,
                'description' => $this->description,
            ] as $key => $value
        ) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
