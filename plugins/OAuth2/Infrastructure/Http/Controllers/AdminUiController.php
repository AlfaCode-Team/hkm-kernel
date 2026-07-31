<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\OAuth2\Infrastructure\Http\Concerns\ChecksOAuthAdmin;
use Plugins\Pageflow\Http\PageflowResponder;

/**
 * GET /oauth/admin — the OAuth2 admin dashboard, rendered through Pageflow.
 *
 * Returns the component name "OAuth2/Admin" + props; the client resolves it from
 * this plugin's federated page (ui/admin/Pages/OAuth2/Admin.tsx). Full loads get
 * the HTML shell, SPA hops get the JSON page object. Gated by the browser
 * SESSION: a guest is bounced to /login, a non-admin gets 403. The page's own
 * fetches hit the /oauth/admin/* JSON API same-origin (session cookie auth).
 *
 * Route declares `requires: ["http.pageflow"]` so PageflowResponder resolves.
 */
final class AdminUiController
{
    use ChecksOAuthAdmin;

    public function __construct(private readonly PageflowResponder $pageflow)
    {
    }

    /** GET /oauth/admin/simulate — per-client Android OAuth simulation (Pageflow). */
    public function simulate(Request $request): Response
    {
        $identity = $request->identity();

        if ($identity === null || $identity->isGuest()) {
            return Response::redirect('/login?return=' . urlencode('/oauth/admin/simulate'));
        }

        if (!$this->isOAuthAdmin($identity)) {
            return Response::forbidden('Administrator access required.');
        }

        return $this->pageflow->render($request, 'OAuth2/Simulate', 'admin', []);
    }

    public function dashboard(Request $request): Response
    {
        $identity = $request->identity();

        if ($identity === null || $identity->isGuest()) {
            return Response::redirect('/login?return=' . urlencode('/oauth/admin'));
        }

        if (!$this->isOAuthAdmin($identity)) {
            return Response::forbidden('Administrator access required.');
        }

        return $this->pageflow->render($request, 'OAuth2/Admin', 'admin', [
            'userId' => $identity->userId,
            'email'  => $identity->email,
        ]);
    }
}
