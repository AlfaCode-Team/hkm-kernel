<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;
use Project\Http\Controllers\Concerns\HasRequest;
use Project\Http\Controllers\ViewController;

/**
 * Serves the HTML shells for the user UI. The pages hydrate over AJAX by calling
 * the JSON endpoints under /ajx/users; the browser session cookie authenticates
 * those calls automatically (same-site — no bearer token).
 *
 * Every page is handed a freshly minted CSRF token (kernel CsrfTokenLayer,
 * HMAC, bound to the session cookie). Views embed it as a <meta> tag, a hidden
 * form field, and send it back in the X-CSRF-Token header on every unsafe
 * request so the SecurityGateway accepts the mutation.
 */
final class UserPageController extends ViewController
{
    protected const API_BASE = '/ajx/users';

    public function index(): Response
    {
        return $this->page('user::users/index', ['title' => 'Users']);
    }

    public function create(): Response
    {
        return $this->page('user::users/create', ['title' => 'Create account']);
    }

    public function show(string $id): Response
    {
        return $this->page('user::users/show', ['title' => 'User detail', 'userId' => $id]);
    }

    public function edit(string $id): Response
    {
        return $this->page('user::users/edit', ['title' => 'Edit user', 'userId' => $id]);
    }

    /** Account settings demo — read/update CRUD for the 4 settings resources. */
    public function settings(): Response
    {
        // These pages call several /ajx/* resources, so the base is just /ajx.
        return $this->page('user::account/settings', ['title' => 'Account settings'], '/ajx');
    }

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data, string $apiBase = self::API_BASE): Response
    {
        // The JSON endpoints live under /ajx/...; hand the base to the layout
        // so the AJAX UI calls the real routes instead of the view's default.
        return $this->view($view, $data + ['apiBase' => $apiBase], 'user::layouts/app');
    }
}
