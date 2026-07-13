<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Pageflow\Http\PageflowResponder;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\ListUsersQuery;

/**
 * Pageflow (SPA) controller for the User plugin — the server half of the pages
 * shipped in this plugin's ui/. It renders COMPONENT NAMES + props; the client
 * resolves them from the federated plugin pages:
 *
 *   admin face  →  ui/admin/Pages/User/{Index,Show}.tsx   (component "User/Index"/"User/Show")
 *   public face →  ui/site/Pages/User/{Register,Profile}.tsx
 *
 * Routes declare `requires: ["http.pageflow","user.management"]` so both the
 * responder and this service resolve for the request.
 */
final class UserFlowController
{
    public function __construct(
        private readonly PageflowResponder $pageflow,
        private readonly UserServiceContract $users,
    ) {
    }

    /** Admin: paginated user list → component "User/Index". */
    public function adminIndex(Request $request): Response
    {
        $page = $this->users->list(ListUsersQuery::fromRequest($request));

        return $this->pageflow->render($request, 'User/Index', 'admin', [
            'users'      => array_map([$this, 'row'], $page->items),
            'hasMore'    => $page->hasMore,
            'nextCursor' => $page->nextCursor(),
        ]);
    }

    /** Admin: single user → component "User/Show". */
    public function adminShow(Request $request, string $id): Response
    {
        $user = $this->users->find($id);

        return $this->pageflow->render($request, 'User/Show', 'admin', [
            'user' => $user !== null ? $this->row($user) : null,
        ]);
    }

    /** Public: registration form → component "User/Register". */
    public function register(Request $request): Response
    {
        return $this->pageflow->render($request, 'User/Register', 'admin');
    }

    /**
     * Public: email-verification landing → component "User/VerifyEmail". The
     * emailed link points here (`/verify-email?token=...`); the token is passed
     * as a prop so the page can prefill and POST it to /ajx/users/verify.
     */
    public function verifyEmail(Request $request): Response
    {
        return $this->pageflow->render($request, 'User/VerifyEmail', 'admin', [
            'token' => (string) $request->query('token', ''),
        ]);
    }

    /** Public: the signed-in user's own profile → component "User/Profile". */
    public function profile(Request $request): Response
    {
        $identity = $request->identity();
        $user     = $identity !== null && !$identity->isGuest()
            ? $this->users->find($identity->userId)
            : null;

        return $this->pageflow->render($request, 'User/Profile', 'admin', [
            'user' => $user !== null ? $this->row($user) : null,
        ]);
    }

    /** Map a UserDTO to the plain props the client expects (no secrets). */
    private function row(object $u): array
    {
        return [
            'id'            => $u->id,
            'username'      => $u->username,
            'email'         => $u->email,
            'emailVerified' => $u->emailVerified,
            'createdAt'     => $u->createdAt,
        ];
    }
}
