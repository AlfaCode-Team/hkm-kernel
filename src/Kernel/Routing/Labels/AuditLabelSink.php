<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Routing\Labels;

/**
 * Where a route's `audit` label is delivered.
 *
 * The kernel defines the contract and knows no implementation: a plugin that
 * keeps an audit trail binds one with `$container->bind(AuditLabelSink::class, …)`
 * in its Provider::register() — PUBLIC `bind()`, not `bindInternal()`, because
 * the label stage resolves it from outside that plugin's scope — and the route
 * must pull that plugin into its dependency graph (`requires[]`), or the binding
 * does not exist for that request and the label is a no-op.
 *
 * With nothing bound the label is inert: no error, no response change.
 *
 * Implementations MUST NOT throw for an unrecordable action (the stage guards
 * against it, but a trail that cannot be written should say so itself), and MUST
 * NOT be handed or store credentials: `$meta` holds route identifiers only.
 */
interface AuditLabelSink
{
    /**
     * @param string                     $action the route's `audit` label, verbatim
     * @param array<string, scalar|null> $meta   method, route template, status, correlation id,
     *                                           and `param.<name>` for each route parameter
     */
    public function record(string $action, array $meta): void;
}
