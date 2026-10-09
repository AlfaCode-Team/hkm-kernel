# Security Gateway

The `SecurityGateway` is a pre-bootstrap security checkpoint that runs before any module loads into memory. A denied request never touches module code — zero cost for rejections. Layers run in order, and the first denial short-circuits all remaining layers.

## Overview

The gateway enforces a layered security model:

1. **Run each layer in order** — cheapest checks first (e.g., CSRF token before JWT verification)
2. **First denial wins** — stop and return a 401/403
3. **Optional identity attachment** — layers can resolve an authenticated identity
4. **Isolated failures** — a layer crash does NOT affect other layers (no throws allowed)

The kernel ships exactly ONE layer: `CsrfTokenLayer`. IP filtering and rate limiting are **NOT** kernel layers — they're opt-in route filters from plugins (`'shield'`, `'throttle'`).

## SecurityLayerContract

Every security layer implements this interface:

```php
<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;

interface SecurityLayerContract
{
    /**
     * Check the request against this security concern.
     * NEVER throw — return a verdict instead.
     */
    public function check(Request $request): SecurityVerdict;
}
```

**Critical rule: NEVER throw.** Return a `SecurityVerdict::deny()` instead. Exceptions leave the application in a partially-initialized state.

## SecurityVerdict

A verdict is immutable and carries:

```php
SecurityVerdict::allow($request);                   // pass, optionally with identity
SecurityVerdict::allowWithIdentity($identity);      // attach an identity
SecurityVerdict::deny(int $statusCode, string $reason);  // HTTP status + message
```

```php
$verdict = $layer->check($request);

if ($verdict->isDenied()) {
    return $verdict->statusCode();  // 401 / 403 / etc.
    return $verdict->reason();      // 'Token invalid' / 'IP not allowed'
}

$identity = $verdict->identity();  // ?Identity
```

## Identity

`Identity` is an immutable value object representing an authenticated user:

```php
final readonly class Identity
{
    public function __construct(
        public readonly string $userId,        // user ID or '' (guest)
        public readonly string $tenantId,      // tenant/organization ID
        public readonly array $roles,          // ['admin', 'editor']
        public readonly array $permissions,    // ['post.create', 'post.delete']
        public readonly string $tokenType,     // 'jwt' | 'api_key' | 'session'
        public readonly string $username = '',
        public readonly string $email = '',
        public readonly string $fullName = '', // from tenant user_profiles
        public readonly ?string $avatarUrl = null,
    ) {}

    public function hasRole(string $role): bool { /* ... */ }
    public function hasPermission(string $permission): bool { /* ... */ }
    public function isGuest(): bool { return empty($this->userId); }
}
```

### Test helpers

```php
Identity::asUser('user-1', 'tenant-abc', roles: ['user'], permissions: []);
Identity::asAdmin('tenant-abc');  // full admin (userId defaults to 'admin-user', permissions to ['*'])
Identity::asAdmin('tenant-abc', 'custom-admin-id', ['post.edit', 'post.delete']);  // custom admin
Identity::guest();                 // empty identity
```

### Key principle: Identity is a hint, not authority

The kernel carries the `tenantId` on the identity and nothing more. It does NOT resolve tenants, own a tenant registry, or know what a tenant database is — that's the Tenancy plugin's job.

**The tenant ID is a HINT.** Whatever set it (a signed JWT claim, a cookie, a host label), authorization still keys on `(userId, tenantId, role/permission)` re-checked against the store that owns memberships. A signature proves the value wasn't tampered in transit; it doesn't prove the seat still exists.

```php
// An identity claims to be user-1 in tenant-abc
// But you MUST verify in the database that user-1 is still a member of tenant-abc
// before trusting that identity for authorization
```

## Writing a Custom Security Layer

A layer checks the request and returns a verdict:

```php
<?php
declare(strict_types=1);

namespace MyPlugin\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\{Identity, SecurityVerdict};

final class JwtVerificationLayer implements SecurityLayerContract
{
    public function __construct(
        private readonly string $jwtSecret,
    ) {}

    public function check(Request $request): SecurityVerdict
    {
        $token = $request->bearerToken();
        
        if ($token === null) {
            // No token present — allow, carry no identity
            return SecurityVerdict::allow($request);
        }
        
        try {
            // Pseudo-code: decode and verify JWT
            $claims = $this->verifyJwt($token);
        } catch (\Exception $e) {
            // Token invalid — deny
            return SecurityVerdict::deny(401, 'Token invalid: ' . $e->getMessage());
        }
        
        // Token valid — create an identity and attach it
        $identity = new Identity(
            userId: $claims['sub'],
            tenantId: $claims['tenant_id'],
            roles: $claims['roles'] ?? [],
            permissions: $claims['permissions'] ?? [],
            tokenType: 'jwt',
            username: $claims['username'] ?? '',
            email: $claims['email'] ?? '',
        );
        
        return SecurityVerdict::allowWithIdentity($identity);
    }
    
    private function verifyJwt(string $token): array
    {
        // Your JWT library here — e.g., firebase/php-jwt
        // Verify signature, expiry, etc.
        // Throw on failure
        return [
            'sub' => 'user-123',
            'tenant_id' => 'tenant-abc',
            'roles' => ['admin'],
            'permissions' => ['*'],
            'username' => 'alice',
            'email' => 'alice@example.com',
        ];
    }
}
```

## Registering a Layer

In the application bootstrap, pass layers to `Kernel::withSecurity()`. The boot **requires at least one**: an empty list fails with "No security layers configured". The generated bootstrap registers `CsrfTokenLayer` (with `/api` exempt), which satisfies it.

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;

$kernel = Kernel::configure()
    ->withSecurity([
        // Kernel's built-in CSRF layer
        new CsrfTokenLayer(
            secret: env('APP_KEY'),
            headerName: 'X-CSRF-Token',
            formField: '_csrf_token',
            bindCookie: 'hkm_session',
            lifetime: 43200,
            exemptPaths: ['/api/webhooks'],
        ),
        
        // Custom JWT layer from an Auth plugin
        new JwtVerificationLayer(
            jwtSecret: env('JWT_SECRET'),
        ),
    ])
    // ...
    ->build();
```

Layers run in the order declared. Order matters: put cheap checks (CSRF) before expensive ones (JWT verify).

## How the Gateway Works

```php
final class SecurityGateway
{
    public function inspect(Request $request): SecurityVerdict
    {
        foreach ($this->layers as $i => $layer) {
            $verdict = $layer->check($request);
            
            // First deny short-circuits
            if ($verdict->isDenied()) {
                return $verdict;
            }
            
            // Carry the identity forward (if resolved)
            $identity = $verdict->identity();
            if ($identity !== null && $i < count($this->layers) - 1) {
                // Not the last layer — attach so next layer can see it
                $request = $request->withIdentity($identity);
            }
        }
        
        return SecurityVerdict::allow($request);
    }
}
```

## SecurityStage and the Pipeline

`SecurityStage` runs the gateway in the HTTP pipeline:

```php
// In HttpPipeline::buildStages()
new SecurityStage($this->gateway),
...$this->resolveHook('after.security'),
```

If denied, it returns a 401/403 response immediately (no routing, no modules).

If allowed and an identity was attached, the request carries it for the rest of the pipeline.

## Request-Scoped Rebinding

If your security layer needs to rebind a port per-tenant (e.g., a different database connection per tenant), do it in the **request-scoped `ModuleContainer`**, never the app-lifetime `CoreContainer`:

```php
// ✗ WRONG — would leak across requests in Swoole
$core->instance(DatabasePort::class, $tenantDb);

// ✓ Correct — scoped to this request, discarded after
// (Done in a hook or a module's register(), not in SecurityGateway)
$container->instance(DatabasePort::class, $tenantDb);
```

## Authorization vs Authentication

**SecurityGateway handles authentication** — is the request from someone we know?

**Services handle authorization** — does that person have permission for this action?

```php
// SecurityGateway / Auth layer
// Q: "Are you logged in?"
if ($token === null || !$this->verifyToken($token)) {
    return SecurityVerdict::deny(401, 'You must log in');
}

// Service layer (InvoiceService)
// Q: "Do you have permission to DELETE this invoice?"
if ($this->identity->userId !== $invoice->ownerId && !$this->identity->hasPermission('invoice:delete')) {
    throw new SecurityException('You cannot delete this invoice');
}
```

## Common Patterns

### JWT + Role-Based Access Control

```php
public function check(Request $request): SecurityVerdict
{
    $token = $request->bearerToken();
    
    if ($token === null) {
        return SecurityVerdict::allow($request);  // Allow guests (services check permissions)
    }
    
    try {
        $claims = $this->verifyJwt($token);
    } catch (\Exception) {
        return SecurityVerdict::deny(401, 'Invalid token');
    }
    
    $identity = new Identity(
        userId: $claims['sub'],
        tenantId: $claims['tenant_id'],
        roles: $claims['roles'],
        permissions: $claims['permissions'],
        tokenType: 'jwt',
    );
    
    return SecurityVerdict::allowWithIdentity($identity);
}
```

### API Key Verification

```php
public function check(Request $request): SecurityVerdict
{
    $header = $request->header('X-API-Key');
    
    if ($header === null) {
        return SecurityVerdict::allow($request);
    }
    
    $key = $this->db->queryOne(
        'SELECT * FROM api_keys WHERE token = ? AND revoked_at IS NULL',
        [hash('sha256', $header)]
    );
    
    if ($key === null) {
        return SecurityVerdict::deny(401, 'API key invalid');
    }
    
    $identity = new Identity(
        userId: 'api-' . $key['app_id'],
        tenantId: $key['tenant_id'],
        roles: ['api'],
        permissions: $key['scopes'],  // ['invoices.read', 'invoices.write']
        tokenType: 'api_key',
    );
    
    return SecurityVerdict::allowWithIdentity($identity);
}
```

### Multi-tenant routing

The gateway alone doesn't select which database to use — that's business logic. But a layer can read the tenant hint and let a module's `register()` do the rebinding:

```php
// In boot() hook (runs after SecurityStage, before modules)
$http->hook('after.security', TenantDatabaseStage::class, priority: 15);

final class TenantDatabaseStage implements HttpStageContract
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, callable $next): Response
    {
        $identity = $request->identity();
        $tenantId = $identity?->tenantId ?? $request->attribute('route_tenant');
        
        $tenant = $this->resolver->find($tenantId);
        if ($tenant === null) {
            return Response::notFound('Tenant not found');
        }
        
        $request = $request->withAttribute('tenant', $tenant);
        
        return $next($request);
    }
}
```

Then in the module's `register()`, rebind the database for this tenant:

```php
public function register(ModuleContainer $container): void
{
    $container->bind(DatabasePort::class, fn($c) => {
        $tenant = $c->make(Request::class)->attribute('tenant');
        return new TenantAwareDatabase($tenant);
    });
}
```

## Common Mistakes

::: danger Never throw from a security layer

```php
// ✗ WRONG
public function check(Request $request): SecurityVerdict
{
    $token = $request->bearerToken();
    $claims = json_decode($token, true);  // can throw JSON errors
    
    if ($claims === null) {
        throw new \Exception('Invalid JWT');  // never!
    }
    // ...
}

// ✓ Correct
public function check(Request $request): SecurityVerdict
{
    $token = $request->bearerToken();
    
    try {
        $claims = json_decode($token, true);
        if ($claims === null) {
            throw new \Exception('Invalid JSON');
        }
        // verify claims...
    } catch (\Exception $e) {
        return SecurityVerdict::deny(401, 'Token invalid');
    }
    // ...
}
```

Exceptions crash the gateway and partially initialize the kernel.

:::

::: danger Don't trust the tenantId without verification

```php
// ✗ WRONG — identity claims to be in tenant-abc, but we don't verify it
$invoice = $this->db->queryOne(
    'SELECT * FROM invoices WHERE id = ? AND tenant_id = ?',
    [$id, $request->identity()->tenantId]
);

// ✓ Correct — verify the tenant membership is real
$tenant = $this->resolver->findForUser($request->identity()->userId);
if ($tenant?->id !== $request->identity()->tenantId) {
    throw new SecurityException('Tenant mismatch');
}
```

The tenantId is a hint. A database membership lookup is the source of truth.

:::

::: info Layer order matters

```php
// ✓ Put cheap checks first
->withSecurity([
    new CsrfTokenLayer(),      // 1 timing-safe hash (microseconds)
    new JwtVerificationLayer(), // JWT verify (milliseconds)
])

// A denied CSRF token exits immediately; no JWT work needed.
```

:::

## Source

- [SecurityGateway.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Security/SecurityGateway.php)
- [SecurityLayerContract.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Security/Contracts/SecurityLayerContract.php)
- [SecurityVerdict.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Security/SecurityVerdict.php)
- [Identity.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Security/Identity.php)
