<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts;

use Psr\Container\ContainerInterface;

/**
 * A security layer that wants the application's ports (CachePort, LoggerPort, …).
 *
 * Layers are constructed in the bootstrap, before the CoreContainer exists, so
 * they cannot be handed a port the usual way. Passing the same `withPorts()`
 * closure to the layer would build a SECOND adapter — a second Redis connection,
 * a second log handle. Instead the kernel calls usePorts() once, at materialize,
 * with the CoreContainer the ports are bound in, and the layer resolves what it
 * needs from it lazily.
 *
 * The container is app-lifetime. Resolve only app-lifetime ports from it; never
 * keep request state on the layer.
 */
interface PortAwareLayerContract extends SecurityLayerContract
{
    public function usePorts(ContainerInterface $ports): void;
}
