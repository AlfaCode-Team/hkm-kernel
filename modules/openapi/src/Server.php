<?php

declare(strict_types=1);

namespace AlfacodeTeam\OpenApi;

/**
 * One base URL the API answers on.
 *
 * An application that serves several hosts gets one entry per host, which is
 * how a multi-brand or multi-tenant deployment is described without generating
 * a separate document per domain.
 */
final class Server
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $description = null,
    ) {}

    public function url(): string
    {
        return $this->url;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['url' => $this->url];

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        return $out;
    }
}
