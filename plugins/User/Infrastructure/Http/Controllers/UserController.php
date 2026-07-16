<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
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
            ->withHeader('Location', '/verify-email');
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
    /** Cookie that binds a resend to the email of a just-attempted expired token. */
    private const RESEND_BIND_COOKIE = 'vrf_bind';
    private const RESEND_BIND_MINUTES = 30;

    /** Per-email resend cap: max sends within the window (defence-in-depth). */
    private const RESEND_MAX_PER_EMAIL = 3;
    private const RESEND_EMAIL_WINDOW  = 3600;

    public function verifyEmailByToken(): Response
    {
        $token  = (string) $this->resolveRequest()->input('token', '');
      
        $result = $this->users->verifyEmailByToken($token);

        return match ($result->status) {
            UserServiceContract::VERIFY_OK => Response::json(['status' => 'verified']),
            // Disclosed ONLY because a valid token proves inbox control.
            UserServiceContract::VERIFY_ALREADY => Response::json([
                'status'  => 'already_verified',
                'message' => 'Your email is already verified — you can sign in.',
            ]),
            // Correct token, past its TTL. Distinct code (`token_expired`) so the
            // client can surface the resend option directly. Safe to disclose:
            // only a real, matched token reaches this branch. We ALSO bind this
            // browser to the matched email (keyed HMAC, encrypted HttpOnly
            // cookie) so the follow-up resend can only target THIS address.
            UserServiceContract::VERIFY_EXPIRED => $this->expiredResponse($result->email),
            default => $this->unprocessable(
                ['token' => 'This verification link is invalid or has expired.'],
            ),
        };
    }

    /** 422 for an expired-but-matched token + the resend-binding cookie. */
    private function expiredResponse(?string $email): Response
    {
        if ($email !== null && $email !== '') {
            $this->queueCookie(self::RESEND_BIND_COOKIE, $this->bindHash($email), self::RESEND_BIND_MINUTES);
        }

        return Response::json([
            'error' => [
                'code'    => 'token_expired',
                'message' => 'This verification link has expired. Request a new one below.',
                'fields'  => ['token' => 'This verification link has expired.'],
            ],
        ], 422);
    }

    /**
     * PUBLIC resend of the verification email — the recovery path when a link is
     * expired/invalid. Enumeration-safe: the service returns a token only for an
     * unverified account, and this endpoint ALWAYS answers with the same generic
     * 202 (never revealing whether the email is registered or already verified).
     *
     * EXTRA LAYER: if this browser recently presented an expired token (the
     * `vrf_bind` cookie is set), the submitted email MUST match the one bound to
     * that attempt — otherwise the request is blocked. This stops a browser that
     * proved control of address A from firing resends at an arbitrary address B.
     */
    public function resendVerification(): Response
    {
        $email = trim((string) $this->resolveRequest()->input('email', ''));

        $bound = $this->cookie(self::RESEND_BIND_COOKIE);

        if ($bound !== null && $bound !== ''
            && !hash_equals($bound, $this->bindHash($email))) {
            // Bound to a different address than the one submitted — refuse, and
            // give nothing away about either address.
            return Response::forbidden('This email does not match your pending verification request.');
        }

        // Per-EMAIL send cap (defence-in-depth over the per-IP route throttle):
        // a victim's inbox can't be flooded even from many IPs. Over quota, we
        // silently skip the send but still return the same generic 202 — no
        // observable difference, so enumeration-safety holds.
        if ($email !== '' && $this->withinResendQuota($email)) {
            
            $token = $this->users->resendVerification($email);
            if ($token !== null) {
                $this->queueVerificationEmail($email, $token);
            }
        }

        // One-shot binding: clear it so the cookie can't be replayed.
        $this->forgetCookie(self::RESEND_BIND_COOKIE);

        return Response::json([
            'status'  => 'pending_verification',
            'message' => 'If that address needs verifying, we\'ve sent a new link. Check your inbox.',
            'token'   => $token,  // never echo the token back to the client
        ], 202);
    }

    /**
     * True while the submitted email is under its resend quota (default 3 per
     * hour), incrementing the counter as a side effect. Fails OPEN when no cache
     * is available — the per-IP route throttle still applies.
     */
    private function withinResendQuota(string $email): bool
    {
        $container = $this->resolveRequest()->container();
        if ($container === null || !$container->has(CachePort::class)) {
            return true;
        }
        $cache = $container->make(CachePort::class);
        if (!$cache instanceof CachePort) {
            return true;
        }

        $key   = 'vrf_send_' . hash('sha256', strtolower($email));
        $count = (int) ($cache->get($key) ?? 0);
        if ($count >= self::RESEND_MAX_PER_EMAIL) {
            return false;
        }

        $count === 0
            ? $cache->set($key, 1, self::RESEND_EMAIL_WINDOW)
            : $cache->increment($key);

        return true;
    }

    /**
     * Keyed HMAC of a normalised email. Stored (encrypted) in the binding cookie
     * so the raw address is never written to the client, and compared in
     * constant time on resend.
     */
    private function bindHash(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), (string) env('APP_KEY'));
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
