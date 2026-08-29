<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

final class SecurityVerdict
{
    private function __construct(
        private readonly bool $allowed,
        private readonly int $statusCode,
        private readonly string $reason,
        private readonly ?Identity $identity,
    ) {}

    public static function allow(Request $request): self
    {
        return new self(true, 200, '', $request->identity());
    }

    /**
     * Allow, carrying an identity that is NOT yet attached to a request.
     *
     * allow() reads the identity back off a request, which forces a caller that
     * has only just resolved one to clone the whole request first — seven
     * parameter bags — purely so this constructor can read one property off the
     * copy. SecurityStage attaches the identity to the request the pipeline
     * carries anyway, so that first clone was always thrown away.
     */
    public static function allowWithIdentity(?Identity $identity): self
    {
        return new self(true, 200, '', $identity);
    }

    public static function deny(int $statusCode, string $reason): self
    {
        return new self(false, $statusCode, $reason, null);
    }

    public function isDenied(): bool { return !$this->allowed; }
    public function isAllowed(): bool { return $this->allowed; }
    public function statusCode(): int { return $this->statusCode; }
    public function reason(): string { return $this->reason; }
    public function identity(): ?Identity { return $this->identity; }
}
