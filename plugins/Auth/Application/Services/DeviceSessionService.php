<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\Infrastructure\Persistence\DeviceSessionRepository;

/**
 * DeviceSessionService — server-side session security for stateful logins.
 *
 * The GDA port of the old __DEV__ SessionDriver's two hardening features:
 *
 *  1. FINGERPRINT — at login a device fingerprint is stored in the session
 *     (SHA-256 of the client-supplied header when present, else of ip|user-agent).
 *     Every later resolution must reproduce it (hash_equals) or the session is
 *     treated as hijacked and torn down.
 *
 *  2. DEVICE REGISTRY — every login opens a row in `auth_sessions` and stores the
 *     raw token in the PHP session. Each resolution validates the row (revoked or
 *     expired ⇒ logout even though the browser cookie is still live) and slides
 *     the expiry forward once it enters the refresh window ("rolling refresh").
 *     This is what makes "list my devices" / "sign out other devices" real.
 *
 * One orchestration surface so SessionAuthController, StatefulSessionGuard and
 * SessionAuthStage all share identical semantics.
 */
final class DeviceSessionService
{
    /** Session attribute keys (alongside AuthService::SESSION_*). */
    public const SESSION_DEVICE_TOKEN = 'auth.device_token';
    public const SESSION_FINGERPRINT  = 'auth.fingerprint';

    /** Skip the last-seen write when the row was stamped this recently. */
    private const TOUCH_INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly DeviceSessionRepository $sessions,
        private readonly int $ttlDays = 30,
        private readonly int $refreshDays = 7,
        private readonly string $fingerprintHeader = 'X-Client-Fingerprint',
        private readonly ?TransactionManager $transaction = null, // central-connection tx
    ) {
    }

    // ── Fingerprint ─────────────────────────────────────────────────────────────

    /**
     * Compute the device fingerprint for a request. A client-supplied header
     * (e.g. FingerprintJS) wins; otherwise ip|user-agent. Always SHA-256 hashed
     * so raw client characteristics never persist.
     */
    public function fingerprint(Request $request): string
    {
        $client = (string) ($request->header($this->fingerprintHeader) ?? '');
        if ($client !== '') {
            return hash('sha256', $client);
        }

        $ip = (string) ($request->getClientIp() ?? '0.0.0.0');
        $ua = (string) ($request->header('User-Agent') ?? '');

        return hash('sha256', $ip . '|' . $ua);
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────────

    /**
     * Bind the freshly-authenticated session to this device: store the
     * fingerprint and open a device-session row. Call right after
     * AuthService::startSession().
     */
    public function establish(SessionPort $session, Request $request, string $userId): void
    {
        $session->put(self::SESSION_FINGERPRINT, $this->fingerprint($request));

        $opened = $this->open($request, $userId);
        $session->put(self::SESSION_DEVICE_TOKEN, $opened['token']);
    }

    /**
     * Open a device-session row and return its public id + RAW token (the only
     * time the raw token exists outside the PHP session).
     *
     * @return array{id:string,token:string}
     */
    public function open(Request $request, string $userId): array
    {
        $sessionId = bin2hex(random_bytes(16));
        $token     = bin2hex(random_bytes(32));

        $this->transactional(fn () => $this->sessions->insert(
            sessionId:   $sessionId,
            userId:      $userId,
            tokenHash:   hash('sha256', $token),
            fingerprint: $this->fingerprint($request),
            ip:          $request->getClientIp(),
            userAgent:   $request->header('User-Agent'),
            expiresAt:   $this->expiry(),
        ));

        return ['id' => $sessionId, 'token' => $token];
    }

    /**
     * Verify that the session still belongs to this device and is still live
     * server-side. True when valid; false ⇒ the caller MUST tear the session down.
     *
     * Backward-compatible: a session with no stored fingerprint / device token
     * (opened before this feature, or a bare startSession()) passes.
     */
    public function verify(SessionPort $session, Request $request): bool
    {
        $stored = (string) $session->get(self::SESSION_FINGERPRINT, '');
        if ($stored !== '' && !hash_equals($stored, $this->fingerprint($request))) {
            return false;
        }

        $token = (string) $session->get(self::SESSION_DEVICE_TOKEN, '');
        if ($token === '') {
            return true;
        }

        $row = $this->sessions->findActiveByHash(hash('sha256', $token));
        if ($row === null) {
            return false;
        }

        $now       = new \DateTimeImmutable();
        $expiresAt = new \DateTimeImmutable((string) $row['expires_at']);
        if ($expiresAt <= $now) {
            return false;
        }

        // Rolling refresh: once inside the refresh window, slide the expiry a
        // full TTL forward. Otherwise just stamp last-seen (rate-limited).
        $refreshFrom = $expiresAt->sub(new \DateInterval('P' . max(1, $this->refreshDays) . 'D'));
        if ($now >= $refreshFrom) {
            $this->sessions->touch((string) $row['session_id'], $this->expiry());
        } elseif ($this->lastSeenIsStale($row['last_seen_at'] ?? null, $now)) {
            $this->sessions->touch((string) $row['session_id']);
        }

        return true;
    }

    /** Revoke this device's server-side session (logout). */
    public function teardown(SessionPort $session): void
    {
        $token = (string) $session->get(self::SESSION_DEVICE_TOKEN, '');
        if ($token !== '') {
            $this->transactional(fn () => $this->sessions->revokeByHash(hash('sha256', $token)));
        }

        $session->forget(self::SESSION_DEVICE_TOKEN);
        $session->forget(self::SESSION_FINGERPRINT);
    }

    /**
     * Revoke every OTHER device session for the user, keeping the current
     * device's row alive (old logoutOtherDevices semantics). Returns the number
     * of sessions revoked.
     */
    public function revokeOthers(SessionPort $session, Request $request, string $userId): int
    {
        $token     = (string) $session->get(self::SESSION_DEVICE_TOKEN, '');
        $currentId = null;

        if ($token !== '') {
            $row       = $this->sessions->findActiveByHash(hash('sha256', $token));
            $currentId = $row !== null ? (string) $row['session_id'] : null;
        }

        // One transaction: the sweep and the replacement row commit together,
        // so a failure cannot leave the user with every device signed out AND
        // no registered session (the shared manager nests establish → open).
        return $this->transactional(function () use ($session, $request, $userId, $currentId): int {
            $revoked = $this->sessions->revokeAllForUser($userId, $currentId);

            // No live row for this device (pre-feature session) — open one so
            // the user keeps a registered session after the sweep.
            if ($currentId === null) {
                $this->establish($session, $request, $userId);
            }

            return $revoked;
        });
    }

    // ── Device listing / targeted revocation ────────────────────────────────────

    /**
     * Active sessions for a user, flagging the caller's own device.
     *
     * @return list<array<string,mixed>>
     */
    public function listDevices(string $userId, ?SessionPort $session = null): array
    {
        $currentId = null;
        $token     = $session !== null ? (string) $session->get(self::SESSION_DEVICE_TOKEN, '') : '';
        if ($token !== '') {
            $row       = $this->sessions->findActiveByHash(hash('sha256', $token));
            $currentId = $row !== null ? (string) $row['session_id'] : null;
        }

        return array_map(static fn (array $row): array => [
            'id'        => $row['session_id'],
            'ip'        => $row['ip'],
            'userAgent' => $row['user_agent'],
            'lastSeen'  => $row['last_seen_at'],
            'createdAt' => $row['created_at'],
            'expiresAt' => $row['expires_at'],
            'current'   => $row['session_id'] === $currentId,
        ], $this->sessions->listActiveForUser($userId));
    }

    /** Revoke one of the user's sessions by public id. True when it existed. */
    public function revokeById(string $userId, string $sessionId): bool
    {
        return $this->transactional(fn (): bool => $this->sessions->revokeForUser($userId, $sessionId));
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    /**
     * Bracket a unit of work in a transaction on the central auth connection.
     * Nesting-aware (TransactionManager), and a straight pass-through when no
     * manager was injected (unit tests with in-memory stores).
     */
    private function transactional(callable $work): mixed
    {
        if ($this->transaction === null) {
            return $work();
        }

        $this->transaction->begin();
        try {
            $result = $work();
            $this->transaction->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $e;
        }
    }

    private function expiry(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->add(new \DateInterval('P' . max(1, $this->ttlDays) . 'D'));
    }

    private function lastSeenIsStale(mixed $lastSeenAt, \DateTimeImmutable $now): bool
    {
        if (!is_string($lastSeenAt) || $lastSeenAt === '') {
            return true;
        }

        try {
            $seen = new \DateTimeImmutable($lastSeenAt);
        } catch (\Exception) {
            return true;
        }

        return ($now->getTimestamp() - $seen->getTimestamp()) >= self::TOUCH_INTERVAL_SECONDS;
    }
}
