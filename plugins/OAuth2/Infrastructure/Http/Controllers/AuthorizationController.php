<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\OAuth2\Application\Services\AuthorizationService;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;
use Plugins\Pageflow\Http\PageflowResponder;
use Plugins\View\API\Contracts\ViewRendererContract;
use Project\Http\Controllers\ViewController;

/**
 * /oauth/authorize — the Authorization Code grant's user-facing half
 * (RFC 6749 §4.1, OAuth 2.1 + PKCE).
 *
 *   GET  → validate the request, require an authenticated user (else bounce to
 *          login), then render the consent screen.
 *   POST → the consent decision: approve issues a code and redirects back to the
 *          client; deny redirects with error=access_denied. Both preserve `state`.
 *
 * Requires a logged-in session (SessionAuthStage attaches the Identity). The
 * POST is CSRF-protected like any other form on this host.
 */
final class AuthorizationController extends ViewController
{
    /** Session key prefix for a pending authorization request. */
    private const SESSION_PREFIX = 'oauth.authz.';

    public function __construct(
        ViewRendererContract $renderer,
        private readonly AuthorizationService $authz,
        private readonly SessionPort $session,
        private readonly PageflowResponder $pageflow,
    ) {
        parent::__construct($renderer);
    }

    public function authorize(): Response
    {
        $request = $this->resolveRequest();

        try {
            $req = $this->authz->validate($request->queryAll());
        } catch (OAuthException $e) {
            return $this->renderError($e);
        }

        $identity = $request->identity();
        if ($identity === null || $identity->isGuest()) {
            // Not logged in — send to login, returning here afterwards. Auth's login
            // honours `redirectTo` (not `return`) and its open-redirect guard accepts
            // only a RELATIVE path, so hand it path+query, never the absolute URL.
            $query  = $request->uri()->getQuery();
            $target = $request->path() . ($query !== '' ? '?' . $query : '');

            return $this->redirect('/login?redirectTo=' . urlencode($target));
        }

        // Store the validated request SERVER-SIDE and hand the form only an opaque
        // reference. The client/redirect/scope/PKCE-challenge are never round-tripped
        // through the browser, so the consent POST cannot tamper with them.
        $authzId = bin2hex(random_bytes(16));
        $this->session->put(self::SESSION_PREFIX . $authzId, $req->toFormState());

        return $this->pageflow->render($request, 'OAuth2/Consent', 'admin', [
            'csrf'       => $this->_csrfToken(),
            'clientName' => (string) $req->client->name,
            'scopes'     => array_values($req->scopes),
            'authzId'    => $authzId,
        ]);
    }

    public function decision(): Response
    {
        $request  = $this->resolveRequest();
        $identity = $request->identity();
        if ($identity === null || $identity->isGuest()) {
            return Response::unauthorized('Login required.');
        }

        // Pull the stored request by its opaque id (single-use — removed on read).
        $authzId = (string) $request->input('authz_id');
        $stored  = $authzId !== '' ? $this->session->pull(self::SESSION_PREFIX . $authzId) : null;
        if (!is_array($stored) || $stored === []) {
            return $this->renderError(OAuthException::invalidRequest('The authorization request expired. Please try again.'));
        }

        try {
            // Re-validate the server-stored parameters (client/redirect/scope/PKCE).
            $req = $this->authz->validate($stored);
        } catch (OAuthException $e) {
            return $this->renderError($e);
        }

        $approved = in_array($request->input('action'), ['approve', 'allow'], true)
            || $request->boolean('approve');

        if (!$approved) {
            return $this->redirect($this->authz->buildRedirect($req->redirectUri, [
                'error' => 'access_denied',
                'state' => $req->state,
            ]));
        }

        return $this->redirect($this->authz->issueCode($req, $identity->userId));
    }

    /**
     * Hard errors (bad client / redirect_uri) cannot be redirected — show them
     * directly. We never redirect to an unverified redirect_uri.
     */
    private function renderError(OAuthException $e): Response
    {
        return Response::json($e->toArray(), $e->status);
    }
}
