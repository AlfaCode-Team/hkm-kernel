<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Identification;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * TenantIdentifier — decides WHICH tenant a request belongs to.
 *
 * This is the pluggable seam that lets the Tenancy control plane route either
 * from the authenticated Identity (`Identity.tenantId`, the SaaS/JWT model) or
 * from the request Host sub-domain (the storefront/domain model), selected by
 * the TENANCY_MODE env. It returns ONLY the tenant id; the connection routing,
 * registry lookup, breaker, and fail-closed behaviour stay in
 * {@see \Plugins\Tenancy\Infrastructure\Http\Stages\TenantContextStage} +
 * {@see \Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract}.
 *
 * Returning '' means "no tenant identified" — TenantContextStage fails closed
 * (404): every host must be assigned to a tenant, there is no unscoped
 * passthrough to the central DatabasePort. An identifier MAY also throw
 * UnknownTenantException to refuse a host explicitly — same 404 outcome.
 */
interface TenantIdentifier
{
    /**
     * Tenant id for this request, or '' when none could be identified.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\UnknownTenantException
     *         to refuse the host explicitly (fail closed)
     */
    public function identify(Request $request): string;
}
