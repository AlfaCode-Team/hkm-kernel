<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Routing\Labels;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * The integration event a route's `emits` label publishes.
 *
 * Primitives only, like every integration event: a subscriber in another module
 * has none of this module's value objects. It says WHICH route succeeded and
 * for whom — not what the handler produced, which the kernel does not know. A
 * subscriber that needs the resource follows the route parameters back to it.
 */
final readonly class RouteEmittedEvent implements IntegrationEventContract
{
    /**
     * @param array<string, string> $params route parameters, as captured
     */
    public function __construct(
        private string $eventName,
        public string $route,
        public string $method,
        public int $status,
        public array $params,
        public string $userId,
        public string $tenantId,
        public string $correlationId,
        public string $occurredAt,
    ) {}

    public function name(): string
    {
        return $this->eventName;
    }

    public function version(): string
    {
        return '1.0';
    }

    public function payload(): array
    {
        return [
            'route'         => $this->route,
            'method'        => $this->method,
            'status'        => $this->status,
            'params'        => $this->params,
            'userId'        => $this->userId,
            'tenantId'      => $this->tenantId,
            'correlationId' => $this->correlationId,
            'occurredAt'    => $this->occurredAt,
            'version'       => $this->version(),
        ];
    }
}
