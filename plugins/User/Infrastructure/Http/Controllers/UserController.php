<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UpdateUserDTO;
use Plugins\User\API\DTOs\VerifyEmailDTO;
use Project\Http\Controllers\ApiController;

/**
 * Thin HTTP boundary — DTO → service → Response. No business logic here.
 *
 * RequestAware (via ApiController): actions take route params only; the active
 * request is available as $this->request / $this->resolveRequest().
 */
final class UserController extends ApiController
{
    public function __construct(
        private readonly UserServiceContract $users,
        /** Optional — when a MailPort is bound, signup queues a verification email. */
        private readonly ?MailPort $mailer = null,
    ) {}

    public function index(): Response
    {
        $query = ListUsersQuery::fromRequest($this->resolveRequest());
        $page  = $this->users->list($query);

        return Response::json([
            'data' => array_map(static fn($u) => $u->toArray(), $page->items),
            'meta' => $page->meta(),
        ]);
    }

    /**
     * PUBLIC self-signup. Returns 202 with a non-revealing body — no id, email
     * or verification state ever goes back to an unauthenticated registrant.
     * The verification token is emailed out-of-band (see the mailer seam below),
     * NOT echoed in the response. Location points at the "check your email" flow.
     */
    public function register(): Response
    {
        $dto   = RegisterUserDTO::fromRequest($this->resolveRequest());
        $token = $this->users->registerPublic($dto);
        $this->queueVerificationEmail($dto->email->value(), $token);

        return Response::json(['status' => 'pending_verification'], 202)
            ->withHeader('Location', '/account/verify');
    }

    /**
     * Queue the verification email. The token stays server-side (never in the
     * HTTP response); the emailed link points at the project's /verify-email
     * page, which POSTs the token to /ajx/users/verify. No-ops when no MailPort
     * is bound; a mail failure NEVER breaks signup (the user can request a resend).
     */
    private function queueVerificationEmail(string $email, string $token): void
    {
        if ($this->mailer === null) {
            return;
        }
        try {
            $url = $this->resolveRequest()->site()->to('verify-email', ['token' => $token]);
            $this->mailer->queue($email, 'Verify your email address', 'user::emails/verify', ['url' => $url]);
        } catch (\Throwable) {
            // Best-effort — signup already succeeded; swallow mail-transport faults.
        }
    }

    /**
     * ADMIN create — authenticated + permission-gated at the route (auth filter)
     * and in the service. Returns the FULL created record so it can be dropped
     * straight into the admin table; location points at the admin verify view.
     */
    public function adminCreate(): Response
    {
        $result = $this->users->register(RegisterUserDTO::fromRequest($this->resolveRequest()));

        return $this->created(
            $result->toArray(),
            location: "/admin/users/{$result->id}/verify",
        );
    }

    /**
     * PUBLIC email confirmation — the unauthenticated link a registrant clicks.
     * Token in the request body/query; no identity required. Always a generic
     * response so a bad/expired token reveals nothing.
     */
    public function verifyEmailByToken(): Response
    {
        $token = (string) $this->resolveRequest()->input('token', '');

        return $this->users->verifyEmailByToken($token)
            ? Response::json(['status' => 'verified'])
            : $this->unprocessable(['token' => 'This verification link is invalid or has expired.']);
    }

    public function show(string $id): Response
    {
        return $this->okOrNotFound(
            $this->users->find($id)?->toArray(),
            "User [{$id}] not found.",
        );
    }

    public function update(string $id): Response
    {
        $user = $this->users->update($id, UpdateUserDTO::fromRequest($this->resolveRequest()));
        return $this->okOrNotFound($user?->toArray(), "User [{$id}] not found.");
    }

    public function verifyEmail(string $id): Response
    {
        $user = $this->users->verifyEmail($id, VerifyEmailDTO::fromRequest($this->resolveRequest()));
        return $this->okOrNotFound($user?->toArray(), "User [{$id}] not found.");
    }

    public function destroy(string $id): Response
    {
        return $this->users->delete($id)
            ? $this->noContent()
            : $this->notFound("User [{$id}] not found.");
    }
}
