<?php

declare(strict_types=1);

namespace AlfacodeTeam\OpenApi;

/** The `info` block: who this API is and which version of it this document describes. */
final class Info
{
    public function __construct(
        private readonly string $title,
        private readonly string $version,
        private readonly ?string $description = null,
        private readonly ?string $contactEmail = null,
        private readonly ?string $licenseName = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'title' => $this->title,
            'version' => $this->version,
        ];

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->contactEmail !== null) {
            $out['contact'] = ['email' => $this->contactEmail];
        }
        if ($this->licenseName !== null) {
            $out['license'] = ['name' => $this->licenseName];
        }

        return $out;
    }
}
