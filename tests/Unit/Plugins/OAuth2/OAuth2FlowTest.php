<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\OAuth2;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use PHPUnit\Framework\TestCase;
use Plugins\OAuth2\Application\Ports\AuthCodeStore;
use Plugins\OAuth2\Application\Ports\ClientStore;
use Plugins\OAuth2\Application\Ports\DeviceCodeStore;
use Plugins\OAuth2\Application\Ports\RefreshTokenStore;
use Plugins\OAuth2\Application\Ports\ScopeStore;
use Plugins\OAuth2\Application\Services\AuthorizationService;
use Plugins\OAuth2\Application\Services\DeviceService;
use Plugins\OAuth2\Application\Services\ScopeValidator;
use Plugins\OAuth2\Application\Services\TokenIssuer;
use Plugins\OAuth2\Application\Services\TokenService;
use Plugins\OAuth2\Domain\Entities\AuthCode;
use Plugins\OAuth2\Domain\Entities\Client;
use Plugins\OAuth2\Domain\Entities\DeviceCode;
use Plugins\OAuth2\Domain\Entities\RefreshToken;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;
use Plugins\OAuth2\Domain\ValueObjects\Pkce;

final class OAuth2FlowTest extends TestCase
{
    private const REDIRECT = 'https://app.example/callback';

    public function test_pkce_s256_roundtrip(): void
    {
        $verifier  = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        self::assertTrue(Pkce::verify($verifier, $challenge, 'S256'));
        self::assertFalse(Pkce::verify('wrong-verifier-but-long-enough-aaaaaaaaaaaaaaa', $challenge, 'S256'));
        self::assertFalse(Pkce::verify('short', $challenge, 'S256')); // too short
    }

    public function test_authorization_code_flow_with_pkce(): void
    {
        [$authz, $tokens, $stores] = $this->build();

        $verifier  = str_repeat('b', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $req = $authz->validate([
            'client_id'             => 'public-spa',
            'redirect_uri'          => self::REDIRECT,
            'response_type'         => 'code',
            'scope'                 => 'profile',
            'state'                 => 'xyz',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $url  = $authz->issueCode($req, 'user-1');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame('xyz', $q['state']);
        self::assertNotEmpty($q['code']);

        $resp = $tokens->handle([
            'grant_type'    => 'authorization_code',
            'client_id'     => 'public-spa',
            'code'          => $q['code'],
            'redirect_uri'  => self::REDIRECT,
            'code_verifier' => $verifier,
        ], null);

        self::assertSame('Bearer', $resp['token_type']);
        self::assertNotEmpty($resp['access_token']);
        self::assertNotEmpty($resp['refresh_token']);
        self::assertSame('profile', $resp['scope']);

        // Single-use: replaying the same code fails.
        $this->expectException(OAuthException::class);
        $tokens->handle([
            'grant_type' => 'authorization_code', 'client_id' => 'public-spa',
            'code' => $q['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier,
        ], null);
    }

    public function test_public_client_requires_pkce(): void
    {
        [$authz] = $this->build();

        $this->expectException(OAuthException::class);
        $authz->validate([
            'client_id' => 'public-spa', 'redirect_uri' => self::REDIRECT,
            'response_type' => 'code', 'scope' => 'profile',
        ]);
    }

    public function test_redirect_uri_must_match_exactly(): void
    {
        [$authz] = $this->build();

        $this->expectException(OAuthException::class);
        $authz->validate([
            'client_id' => 'public-spa', 'redirect_uri' => 'https://evil.example/cb',
            'response_type' => 'code', 'code_challenge' => str_repeat('c', 43), 'code_challenge_method' => 'plain',
        ]);
    }

    public function test_pkce_verifier_mismatch_rejected(): void
    {
        [$authz, $tokens] = $this->build();

        $challenge = rtrim(strtr(base64_encode(hash('sha256', str_repeat('b', 50), true)), '+/', '-_'), '=');
        $req = $authz->validate([
            'client_id' => 'public-spa', 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'profile', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]);
        parse_str((string) parse_url($authz->issueCode($req, 'user-1'), PHP_URL_QUERY), $q);

        $this->expectException(OAuthException::class);
        $tokens->handle([
            'grant_type' => 'authorization_code', 'client_id' => 'public-spa',
            'code' => $q['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => str_repeat('z', 50),
        ], null);
    }

    public function test_client_credentials_grant(): void
    {
        [, $tokens] = $this->build();

        $resp = $tokens->handle([
            'grant_type' => 'client_credentials', 'scope' => 'reports',
        ], ['confidential-svc', 's3cret']);

        self::assertNotEmpty($resp['access_token']);
        self::assertArrayNotHasKey('refresh_token', $resp); // no refresh for client_credentials
    }

    public function test_confidential_client_bad_secret_rejected(): void
    {
        [, $tokens] = $this->build();

        $this->expectException(OAuthException::class);
        $tokens->handle(['grant_type' => 'client_credentials'], ['confidential-svc', 'wrong']);
    }

    public function test_refresh_rotation_and_reuse_detection(): void
    {
        [$authz, $tokens, $stores] = $this->build();

        // Mint an initial pair via client_credentials? No — needs refresh; use auth code.
        $verifier  = str_repeat('b', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $req = $authz->validate([
            'client_id' => 'public-spa', 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'profile', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]);
        parse_str((string) parse_url($authz->issueCode($req, 'user-1'), PHP_URL_QUERY), $q);
        $first = $tokens->handle([
            'grant_type' => 'authorization_code', 'client_id' => 'public-spa',
            'code' => $q['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier,
        ], null);

        $refresh = $first['refresh_token'];

        // Rotate once — succeeds, new refresh issued.
        $rotated = $tokens->handle([
            'grant_type' => 'refresh_token', 'client_id' => 'public-spa', 'refresh_token' => $refresh,
        ], null);
        self::assertNotEmpty($rotated['refresh_token']);
        self::assertNotSame($refresh, $rotated['refresh_token']);

        // Replay the ORIGINAL (now revoked) refresh — reuse detected, family burned.
        try {
            $tokens->handle([
                'grant_type' => 'refresh_token', 'client_id' => 'public-spa', 'refresh_token' => $refresh,
            ], null);
            self::fail('Expected reuse to be rejected');
        } catch (OAuthException) {
            // expected
        }

        // The rotated (descendant) token must now also be dead (family revoked).
        $this->expectException(OAuthException::class);
        $tokens->handle([
            'grant_type' => 'refresh_token', 'client_id' => 'public-spa', 'refresh_token' => $rotated['refresh_token'],
        ], null);
    }

    public function test_oidc_id_token_issued_for_openid_scope(): void
    {
        [$authz, $tokens] = $this->build();

        // Confidential client over HS256 (a public client would be refused — an
        // HS id_token is unverifiable without the shared secret).
        $req = $authz->validate([
            'client_id' => 'web-app', 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'openid profile', 'nonce' => 'n-123',
        ]);
        parse_str((string) parse_url($authz->issueCode($req, 'user-1'), PHP_URL_QUERY), $q);

        $resp = $tokens->handle([
            'grant_type' => 'authorization_code',
            'code' => $q['code'], 'redirect_uri' => self::REDIRECT,
        ], ['web-app', 'websecret']);

        self::assertArrayHasKey('id_token', $resp);
        $parts = explode('.', $resp['id_token']);
        self::assertCount(3, $parts);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        self::assertSame('user-1', $claims['sub']);
        self::assertSame('web-app', $claims['aud']);
        self::assertSame('n-123', $claims['nonce']);
    }

    public function test_public_client_openid_over_hs_is_rejected(): void
    {
        [$authz, $tokens] = $this->build();

        $verifier  = str_repeat('b', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $req = $authz->validate([
            'client_id' => 'public-spa', 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'openid profile', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]);
        parse_str((string) parse_url($authz->issueCode($req, 'user-1'), PHP_URL_QUERY), $q);

        $this->expectException(OAuthException::class);
        $tokens->handle([
            'grant_type' => 'authorization_code', 'client_id' => 'public-spa',
            'code' => $q['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier,
        ], null);
    }

    public function test_access_token_scopes_are_namespaced_in_permissions(): void
    {
        [, $tokens] = $this->build();

        $resp = $tokens->handle(['grant_type' => 'client_credentials', 'scope' => 'reports'], ['confidential-svc', 's3cret']);
        $claims = json_decode(base64_decode(strtr(explode('.', $resp['access_token'])[1], '-_', '+/')), true);

        self::assertSame('reports', $claims['scope']);
        self::assertSame(['scope:reports'], $claims['permissions']); // never a bare RBAC permission
    }

    public function test_device_flow_pending_then_authorized(): void
    {
        [, $tokens, $stores] = $this->build();
        /** @var DeviceService $device */
        $device = $stores['device'];

        $auth = $device->authorize(['client_id' => 'device-tv', 'scope' => 'profile'], null);
        self::assertNotEmpty($auth['device_code']);
        self::assertNotEmpty($auth['user_code']);

        // Poll before approval → authorization_pending.
        try {
            $tokens->handle([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id'  => 'device-tv', 'device_code' => $auth['device_code'],
            ], null);
            self::fail('expected authorization_pending');
        } catch (OAuthException $e) {
            self::assertSame('authorization_pending', $e->error);
        }

        // User approves out-of-band.
        $pending = $stores['devices']->findByUserCode($auth['user_code']);
        self::assertNotNull($pending);
        self::assertTrue($stores['devices']->authorize($pending->id, 'user-9'));

        // Next poll → tokens.
        $resp = $tokens->handle([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id'  => 'device-tv', 'device_code' => $auth['device_code'],
        ], null);
        self::assertNotEmpty($resp['access_token']);

        // Replaying the device code after consumption fails.
        $this->expectException(OAuthException::class);
        $tokens->handle([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id'  => 'device-tv', 'device_code' => $auth['device_code'],
        ], null);
    }

    /**
     * @return array{0:AuthorizationService,1:TokenService,2:array<string,mixed>}
     */
    private function build(): array
    {
        $hasher = new class implements HashingPort {
            public function make(string $value, array $options = []): string { return 'h:' . $value; }
            public function check(string $value, string $hashedValue): bool { return hash_equals($hashedValue, 'h:' . $value); }
            public function needsRehash(string $hashedValue, array $options = []): bool { return false; }
        };

        $clients = new InMemoryClientStore([
            Client::of('public-spa', 'SPA', null, [self::REDIRECT], ['authorization_code', 'refresh_token'], ['profile', 'openid'], false),
            Client::of('web-app', 'Web App', 'h:websecret', [self::REDIRECT], ['authorization_code', 'refresh_token'], ['profile', 'openid', 'email'], true),
            Client::of('confidential-svc', 'Service', 'h:s3cret', [], ['client_credentials'], ['reports'], true),
            Client::of('device-tv', 'TV App', null, [], ['urn:ietf:params:oauth:grant-type:device_code'], ['profile'], false),
        ]);
        $codes   = new InMemoryAuthCodeStore();
        $refresh = new InMemoryRefreshTokenStore();
        $devices = new InMemoryDeviceCodeStore();
        $scopes  = new InMemoryScopeStore(['profile', 'reports', 'email', 'openid']);
        $scopeV  = new ScopeValidator($scopes);
        $issuer  = new TokenIssuer('HS256', str_repeat('k', 64), null, 'https://issuer.test', null, 3600);

        $authz  = new AuthorizationService($clients, $codes, $scopeV, 60);
        $tokens = new TokenService($clients, $codes, $refresh, $scopeV, $issuer, $hasher, null, 1209600, $devices);
        $device = new DeviceService($clients, $devices, $scopeV, 600, 5);

        return [$authz, $tokens, ['clients' => $clients, 'codes' => $codes, 'refresh' => $refresh, 'devices' => $devices, 'device' => $device]];
    }
}

// ── in-memory fakes ──────────────────────────────────────────────────────────

final class InMemoryClientStore implements ClientStore
{
    /** @var array<string,Client> */
    private array $byId = [];

    /** @param list<Client> $clients */
    public function __construct(array $clients)
    {
        foreach ($clients as $c) {
            $this->byId[$c->id] = $c;
        }
    }

    public function find(string $clientId): ?Client { return $this->byId[$clientId] ?? null; }

    public function create(string $id, string $name, ?string $secretHash, array $redirectUris, array $grantTypes, array $scopes, bool $confidential, ?string $ownerId = null): void
    {
        $this->byId[$id] = Client::of($id, $name, $secretHash, $redirectUris, $grantTypes, $scopes, $confidential, false, $ownerId);
    }

    public function all(): array { return array_values($this->byId); }

    public function findByOwner(string $ownerId): array
    {
        return array_values(array_filter($this->byId, static fn(Client $c): bool => $c->ownerId() === $ownerId));
    }

    public function updateDetails(string $id, string $name, array $redirectUris, array $scopes): bool
    {
        $c = $this->byId[$id] ?? null;
        if ($c === null) {
            return false;
        }
        $this->byId[$id] = Client::of($c->id, $name, $c->secretHash, $redirectUris, $c->grantTypes, $scopes, $c->confidential, $c->revoked, $c->ownerId());

        return true;
    }

    public function revoke(string $id): bool
    {
        $c = $this->byId[$id] ?? null;
        if ($c === null) {
            return false;
        }
        $this->byId[$id] = Client::of($c->id, $c->name, $c->secretHash, $c->redirectUris, $c->grantTypes, $c->scopes, $c->confidential, true);

        return true;
    }

    public function updateSecret(string $id, string $secretHash): bool
    {
        $c = $this->byId[$id] ?? null;
        if ($c === null || !$c->confidential) {
            return false;
        }
        $this->byId[$id] = Client::of($c->id, $c->name, $secretHash, $c->redirectUris, $c->grantTypes, $c->scopes, true, $c->revoked);

        return true;
    }
}

final class InMemoryAuthCodeStore implements AuthCodeStore
{
    /** @var array<string,array{code:AuthCode,consumed:bool}> */
    private array $byHash = [];
    /** @var array<string,string> id→hash */
    private array $idToHash = [];

    public function store(AuthCode $code, string $codeHash): void
    {
        $this->byHash[$codeHash] = ['code' => $code, 'consumed' => false];
        $this->idToHash[$code->id] = $codeHash;
    }

    public function findByHash(string $codeHash): ?AuthCode
    {
        $row = $this->byHash[$codeHash] ?? null;
        if ($row === null) {
            return null;
        }
        $c = $row['code'];

        return AuthCode::of($c->id, $c->clientId, $c->userId, $c->redirectUri, $c->scopes, $c->codeChallenge, $c->codeChallengeMethod, $c->expiresAt, $row['consumed'], $c->nonce);
    }

    public function consume(string $codeId): bool
    {
        $hash = $this->idToHash[$codeId] ?? null;
        if ($hash === null || $this->byHash[$hash]['consumed']) {
            return false;
        }
        $this->byHash[$hash]['consumed'] = true;

        return true;
    }

    public function deleteExpired(?\DateTimeImmutable $now = null): int { return 0; }
}

final class InMemoryRefreshTokenStore implements RefreshTokenStore
{
    /** @var array<string,array{token:RefreshToken,revoked:bool}> */
    private array $byHash = [];
    /** @var array<string,string> id→hash */
    private array $idToHash = [];

    public function store(RefreshToken $token, string $tokenHash): void
    {
        $this->byHash[$tokenHash] = ['token' => $token, 'revoked' => false];
        $this->idToHash[$token->id] = $tokenHash;
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        $row = $this->byHash[$tokenHash] ?? null;
        if ($row === null) {
            return null;
        }
        $t = $row['token'];

        return RefreshToken::of($t->id, $t->familyId, $t->clientId, $t->userId, $t->scopes, $t->expiresAt, $row['revoked']);
    }

    public function findByUser(string $userId): array
    {
        $out = [];
        foreach ($this->byHash as $row) {
            $t = $row['token'];
            if ($t->userId === $userId && !$row['revoked'] && !$t->isExpired()) {
                $out[] = $t;
            }
        }

        return $out;
    }

    public function allActive(): array
    {
        $out = [];
        foreach ($this->byHash as $row) {
            $t = $row['token'];
            if (!$row['revoked'] && !$t->isExpired()) {
                $out[] = $t;
            }
        }

        return $out;
    }

    public function revokeIfActive(string $tokenId): bool
    {
        $hash = $this->idToHash[$tokenId] ?? null;
        if ($hash === null || $this->byHash[$hash]['revoked']) {
            return false;
        }
        $this->byHash[$hash]['revoked'] = true;

        return true;
    }

    public function revokeFamily(string $familyId): int
    {
        $n = 0;
        foreach ($this->byHash as $h => $row) {
            if ($row['token']->familyId === $familyId && !$row['revoked']) {
                $this->byHash[$h]['revoked'] = true;
                $n++;
            }
        }

        return $n;
    }

    public function deleteExpired(?\DateTimeImmutable $now = null): int { return 0; }
}

final class InMemoryScopeStore implements ScopeStore
{
    /** @var array<string,string> id => description */
    private array $catalogue;

    /** @param list<string> $scopes */
    public function __construct(array $scopes)
    {
        $this->catalogue = array_fill_keys($scopes, '');
    }

    public function exists(string $scope): bool { return array_key_exists($scope, $this->catalogue); }

    public function all(): array { return array_keys($this->catalogue); }

    public function describe(): array { return $this->catalogue; }

    public function put(string $id, string $description): void
    {
        $this->catalogue[$id] = $description;
    }

    public function delete(string $id): bool
    {
        if (!array_key_exists($id, $this->catalogue)) {
            return false;
        }
        unset($this->catalogue[$id]);

        return true;
    }
}

final class InMemoryDeviceCodeStore implements DeviceCodeStore
{
    /** @var array<string,DeviceCode> id→device */
    private array $byId = [];
    /** @var array<string,string> hash→id */
    private array $byHash = [];
    /** @var array<string,string> userCode→id */
    private array $byUserCode = [];

    public function store(DeviceCode $device, string $deviceCodeHash): void
    {
        $this->byId[$device->id]               = $device;
        $this->byHash[$deviceCodeHash]         = $device->id;
        $this->byUserCode[$device->userCode]   = $device->id;
    }

    public function findByDeviceHash(string $deviceCodeHash): ?DeviceCode
    {
        return $this->byId[$this->byHash[$deviceCodeHash] ?? ''] ?? null;
    }

    public function findByUserCode(string $userCode): ?DeviceCode
    {
        return $this->byId[$this->byUserCode[$userCode] ?? ''] ?? null;
    }

    public function authorize(string $id, string $userId): bool
    {
        return $this->transition($id, DeviceCode::PENDING, DeviceCode::AUTHORIZED, $userId);
    }

    public function deny(string $id): bool
    {
        return $this->transition($id, DeviceCode::PENDING, DeviceCode::DENIED);
    }

    public function markPolled(string $id, \DateTimeImmutable $at): void
    {
        $d = $this->byId[$id] ?? null;
        if ($d !== null) {
            $this->byId[$id] = DeviceCode::of($d->id, $d->userCode, $d->clientId, $d->scopes, $d->status, $d->userId, $d->interval, $at, $d->expiresAt);
        }
    }

    public function consume(string $id): bool
    {
        return $this->transition($id, DeviceCode::AUTHORIZED, DeviceCode::DENIED);
    }

    public function deleteExpired(?\DateTimeImmutable $now = null): int { return 0; }

    private function transition(string $id, string $from, string $to, ?string $userId = null): bool
    {
        $d = $this->byId[$id] ?? null;
        if ($d === null || $d->status !== $from) {
            return false;
        }
        $this->byId[$id] = DeviceCode::of($d->id, $d->userCode, $d->clientId, $d->scopes, $to, $userId ?? $d->userId, $d->interval, $d->lastPolledAt, $d->expiresAt);

        return true;
    }
}
