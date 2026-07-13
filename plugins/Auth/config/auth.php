<?php

declare(strict_types=1);

/**
 * Auth configuration — named guards + user providers managed by AuthManager.
 *
 * Resolution order at runtime:
 *   1. projects/<name>/config/auth.php  (project override — copy this file there)
 *   2. plugins/Auth/config/auth.php      (this file — framework default)
 *
 * Read it with the auth_config() helper:
 *   auth_config();                 // full array
 *   auth_config('defaults.guard'); // 'web'
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    | The guard + provider used when AuthManager::guard()/provider() is called
    | with no explicit name.
    */
    'defaults' => [
        'guard'    => env('AUTH_GUARD', 'web'),
        'provider' => env('AUTH_PROVIDER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    | Each guard binds a driver (session/jwt/token/request — filesystem-scanned
    | from Infrastructure/Auth/Drivers) to a named provider below.
    |   - web:     stateful browser session (+ remember-me via SessionAuthStage)
    |   - api:     personal access tokens (Bearer <id>.<secret>)
    |   - jwt:     stateless Bearer JWTs
    |   - request: credential-agnostic — whatever the SecurityGateway attached
    */
    'guards' => [
        'web'     => ['driver' => 'session', 'provider' => 'users'],
        'api'     => ['driver' => 'token',   'provider' => 'users'],
        'jwt'     => ['driver' => 'jwt',     'provider' => 'users'],
        'request' => ['driver' => 'request', 'provider' => 'users'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | Named user sources. 'model' = ModelUserProvider over UserServiceContract
    | (the central identity store). Add more to back a guard with another store.
    */
    'providers' => [
        'users' => ['driver' => 'model'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stateful session security (old __DEV__ flow)
    |--------------------------------------------------------------------------
    | Every web login is bound to a device fingerprint and registered in the
    | central `auth_sessions` table. Requests that can't reproduce the
    | fingerprint — or whose server-side row was revoked/expired — lose the
    | session immediately (see DeviceSessionService).
    |
    |   ttl_days      absolute device-session lifetime
    |   refresh_days  rolling window: inside the last N days the expiry slides
    |                 forward a full TTL on activity
    |   client_fingerprint_header
    |                 optional client-supplied fingerprint (e.g. FingerprintJS);
    |                 falls back to sha256(ip|user-agent)
    */
    'session' => [
        'ttl_days'                  => (int) (env('AUTH_SESSION_TTL') ?: 30),
        'refresh_days'              => (int) (env('AUTH_SESSION_REFRESH') ?: 7),
        'client_fingerprint_header' => env('AUTH_FINGERPRINT_HEADER') ?: 'X-Client-Fingerprint',
    ],
];
