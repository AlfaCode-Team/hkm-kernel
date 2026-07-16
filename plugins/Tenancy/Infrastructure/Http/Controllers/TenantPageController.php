<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Pageflow\Http\PageflowResponder;
use Project\Http\Controllers\Concerns\InteractsWithGraphSeo;

/**
 * Pageflow (SPA) controller for the Tenancy plugin — the server half of the pages
 * shipped in this plugin's ui/. It renders COMPONENT NAMES + props; the client
 * resolves them from the federated plugin pages:
 *
 *   site face  →  ui/site/Pages/Tenant/{Index,Hosts}.tsx      (the tenant picker + host mgmt)
 *   admin face →  ui/admin/Pages/Tenant/{Manage,Create,Edit}.tsx  (the fleet control plane)
 *
 * The pages hydrate over the JSON endpoints under /ajx; the browser session
 * cookie authenticates those calls automatically (same-site — no bearer token),
 * and Pageflow embeds the kernel CSRF token in the HTML shell so every unsafe
 * request carries X-CSRF-Token.
 *
 * Routes declare `requires: ["http.pageflow"]` so the responder resolves for the
 * request; Tenancy itself is an essential module, so its services are always
 * registered.
 *
 * SEO — every page here is private control-plane surface, so each passes the
 * reserved `seoHead` prop with seoPrivate(): a correct <title> plus
 * noindex,nofollow (admin consoles must never enter a search index), with no
 * OG/graph cost. The helper no-ops ('') on Pageflow XHR navigations.
 */
final class TenantPageController
{
    use InteractsWithGraphSeo;

    public function __construct(
        private readonly PageflowResponder $pageflow,
    ) {}

    /** GET /tenants — the tenant picker for the authenticated user. */
    public function index(Request $request): Response
    {
        return $this->pageflow->render($request, 'Tenant/Index', 'admin', [
            'seoHead' => $this->seoPrivate('Choose a workspace', request: $request),
        ]);
    }

    /** GET /tenants/manage — the platform-admin tenant fleet (CRUD). */
    public function manage(Request $request): Response
    {
        return $this->pageflow->render($request, 'Tenant/Manage', 'admin', [
            'seoHead' => $this->seoPrivate('Tenant fleet', request: $request),
        ]);
    }

    /** GET /tenants/create — the new-tenant provisioning form. */
    public function create(Request $request): Response
    {
        return $this->pageflow->render($request, 'Tenant/Create', 'admin', [
            'seoHead' => $this->seoPrivate('New tenant', request: $request),
        ]);
    }

    /** GET /tenants/{tenantId}/edit — edit a tenant's metadata. */
    public function edit(Request $request, string $tenantId): Response
    {
        return $this->pageflow->render($request, 'Tenant/Edit', 'admin', [
            'tenantId' => $tenantId,
            'seoHead'  => $this->seoPrivate('Edit tenant', request: $request),
        ]);
    }

    /** GET /tenant/hosts — manage the current tenant's custom domains. */
    public function hosts(Request $request): Response
    {
        return $this->pageflow->render($request, 'Tenant/Hosts', 'admin', [
            'seoHead' => $this->seoPrivate('Custom domains', request: $request),
        ]);
    }
}
