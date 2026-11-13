---
outline: [2, 3]
---

# Production Deployment

This page covers deploying HKM Kernel applications to production on PHP-FPM or OpenSwoole, including performance tuning, configuration, health checks, and monitoring.

## Pre-Deployment Checklist

- [ ] `APP_ENV=production` set in `.env`
- [ ] `APP_DEBUG=false` set (never debug output in production)
- [ ] `APP_KEY` generated and stored in secrets (used by CSRF token layer)
- [ ] `BOOT_CACHE=1` enabled for PHP-FPM (86× on the compile step; **9.4× measured end to end on throughput**, see below)
- [ ] `ENV_CACHE=1` set (it is off by default because mtime granularity makes it unsafe while editing — a deployed release is not being edited)
- [ ] `expose_php = Off` in `php.ini` — PHP-FPM sends `X-Powered-By: PHP/8.5.12`, naming the exact patch level, and the kernel's security headers cannot remove it because it is emitted below userland
- [ ] A **cross-process** `CachePort` bound in `withPorts()` — an in-memory adapter turns `throttle` and `CachePort::lock()` into silent no-ops under PHP-FPM ([cache and locks](/infrastructure/cache-and-locks#deployment-notes))
- [ ] The real **queue** bound in `withPorts()` too, not only by a plugin — the worker resolves `QueuePort` from the core container ([workers and jobs](/background/workers-and-jobs#enqueueing-jobs))
- [ ] Worker pool sized against the workload, not copied from a guide (see *Sizing the pool*)
- [ ] `var/cache/` directory created and writable by the web server user
- [ ] Database migrations applied (`hkm cli migrate:run`)
- [ ] Environment variables validated: `hkm install --check`
- [ ] Health check endpoint available (`GET /health` or similar)
- [ ] Error logging configured (Slack, mail, database, or file)
- [ ] Log rotation configured (`var/logs/` does not grow unbounded)
- [ ] File permissions verified: `hkm install --check --as=www-data`
- [ ] TLS certificate installed at the reverse proxy, and `TRUSTED_PROXIES` set to that proxy's address, so `Request::ip()` and `isSecure()` see the real client

## PHP-FPM Deployment

### Entry Point

The single entry point is `app/public/index.php`. Configure your web server to serve from `app/public/` and rewrite all requests to `index.php`:

```nginx
server {
    listen 80;
    server_name example.com www.example.com;
    root /var/www/app/public;

    # Rewrite all requests to index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Forward to PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### PHP-FPM Configuration

Configure a pool for your application in `/etc/php/8.4/fpm/pool.d/app.conf`:

```ini
[app]
; Process identifier
user = www-data
group = www-data

; Listen socket
listen = /run/php/php8.4-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Process management
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20

; Request timeout (increase if long-running requests are normal)
request_terminate_timeout = 300

; Environment variables
env[APP_ENV] = production
env[APP_DEBUG] = false
env[BOOT_CACHE] = 1
```

Reload PHP-FPM after changes:

```bash
sudo systemctl reload php8.4-fpm
```

### Sizing the pool

`pm.max_children` is the number of requests that can be **in flight**, and the right value
depends entirely on what a request does. Measured on one application, same code, only the
pool size changing (8 cores):

| Route | 4 children | 32 children |
|---|---|---|
| 50 ms of I/O (a DB or API call) | 70 req/s | **591 req/s** (8.1×) |
| `/ping` (no work) | 1,171 | 1,450 |
| A write path: transaction + 3 statements | **515** | 385 (**0.75×**) |
| Two local SQL reads | ~990 | 557 |

A route that **waits** scales almost linearly with the pool: throughput is
`children / request_seconds`, and four children at 50 ms can never exceed 78 req/s no matter
the concurrency offered. A route that **computes or writes** gets *slower* as the pool grows,
because the processes contend for cores and for the database's writer.

Size from the measurement, not the default: `children ≈ concurrency × (time waiting ÷ total
time)`, then verify the CPU-bound routes did not regress.

::: warning PHP-FPM does not degrade past its pool — it refuses
At concurrency 200 against a 4-child pool, 199,624 of 200,000 responses were non-2xx, each
served in 2 ms, with nginx logging `connect() failed (54: Connection reset by peer)` to the
FPM upstream. The accept queue is capped by the OS (`kern.ipc.somaxconn`, 128 on the test
machine; `net.core.somaxconn` on Linux) **regardless of `listen.backlog`**, and raising
`pm.max_children` to 32 did not move the cliff.

So `listen.backlog = 2048` is a lie unless the kernel limit is raised with it, and a load
test that reports a huge req/s with non-2xx responses is measuring the rate of refusal. Watch
non-2xx, raise `somaxconn` to match `listen.backlog`, and put a queue or a rate limit in
front of the pool.
:::

### BOOT_CACHE Optimization

Under PHP-FPM, every request re-executes `bootstrap/app.php`, which calls `Kernel::build()`. This recompiles every manifest (routes, services, views, config, etc.) — about **2 ms** and **150 KB** of disk writes per request, with identical output.

Enable BOOT_CACHE to skip recompilation when manifests are current:

```bash
# In .env or .env.production
BOOT_CACHE=1
```

With caching enabled, boot drops to **~0.02 ms** — **86× faster** on that step. End to end,
on a real application under load, the measured difference is **9.4× on throughput**:

| Configuration | req/s | vs. shipped |
|---|---|---|
| As scaffolded (`APP_DEBUG=true`, no `BOOT_CACHE`) | 163 | 1.0× |
| `BOOT_CACHE=1` | 997 | 6.1× |
| `+ APP_ENV=production`, `APP_DEBUG=false`, `ENV_CACHE=1` | 1,542 | **9.4×** |

A project deployed to PHP-FPM with the development defaults runs at roughly a tenth of its
capacity. The cache is invalidated when:

- Any `module.json` or `config/*.php` file changes (mtime + size checked)
- A config file is added or deleted (per-directory `*.php` count)
- `var/cache/manifests/` is cleared (e.g., after deploy)

**On deploy:** Always clear the cache after pushing new code:

```bash
rm -rf var/cache/manifests/
```

This forces a fresh compilation on the first request. If you skip this step, the cache treats the unchanged files as current and may miss real changes — though this is unlikely if your deploy always updates at least one `module.json`.

### Session and Cookie Configuration

Sessions typically store in Redis or the database. Configure PHP-FPM to use your chosen backend:

```ini
[app]
; Use Redis for sessions
session.save_handler = redis
session.save_path = "tcp://redis-host:6379/0?auth=your-password"
```

Cookies set by your application (including CSRF tokens) are HttpOnly and Secure by default in production. Verify via:

```bash
curl -i https://example.com/ | grep -i set-cookie
# Should see: Set-Cookie: ... HttpOnly; Secure;
```

## OpenSwoole Deployment

### Building the Server

A scaffolded project ships the server as `app/swoole/index.php`. It builds the kernel **once per worker** (in `workerStart`), not per request, and translates each OpenSwoole request into a kernel `Request` itself. Condensed:

```php
$server->on('workerStart', function () use ($rootPath, &$kernel): void {
    $kernel = require $rootPath . '/app/bootstrap/app.php';   // one build per worker
});

$server->on('request', function (SwooleRequest $req, SwooleResponse $res) use ($rootPath, &$kernel): void {
    try {
        $request = Request::build(                               // kernel Request, no superglobals
            method:  strtoupper((string) ($req->server['request_method'] ?? 'GET')),
            path:    (string) ($req->server['request_uri'] ?? '/'),
            headers: $req->header ?? [],
            query:   $req->get ?? [],
            body:    $req->post ?? [],
            rawBody: (string) $req->rawContent(),
            cookies: $req->cookie ?? [],
            files:   $files,                                     // UploadedFile::fromSwoole(...) per upload
        );

        // Route on the VALIDATED host, never the raw Host header.
        $domain = EntryHelpers::resolveDomain($rootPath, $req->header['host'] ?? null);
        if ($domain !== null) {
            $request = $request
                ->withAttribute('domain', $domain)
                ->withAttribute('route_face', $domain->type->value)
                ->withAttribute('route_host', $domain->host);
        }

        $response = $kernel->http()->handle($request);
        // ... emit status, headers, cookies; sendfile() for files, streamTo() for streams
    } finally {
        $kernel?->requestTeardown();
    }
});
```

Server settings come from the environment: `SWOOLE_HOST`, `SWOOLE_PORT`, `HKM_WORKERS`, `SWOOLE_MAX_REQUEST`, `SWOOLE_COROUTINE`, `SWOOLE_DISPATCH_MODE`, `SWOOLE_HOOK_FLAGS`, `SWOOLE_DAEMONIZE`. See [Environment variables](/reference/env-vars#openswoole-entry-point).

### Concurrency: the two settings that actually move it

A resident kernel removes the *boot* from every request. It does not make a blocking request
yield, so a worker serving a route that waits is just as occupied as an FPM child. With four
workers on a route that waits 50 ms, all of these measured the same ~76 req/s — the
`4 / 0.051 s` ceiling:

| Configuration (4 workers, 50 ms I/O) | c=4 | c=50 | c=200 |
|---|---|---|---|
| default (`enable_coroutine` off) | 38 | 75 | 77 |
| `SWOOLE_COROUTINE=true` alone | 19 | 73 | 71 |
| `SWOOLE_COROUTINE=true` + `SWOOLE_HOOK_FLAGS=2147483647` | 77 | **925** | **3,653** |

**`enable_coroutine` on its own buys nothing.** It runs the request inside a coroutine — the
coroutine id is visible in the handler — but a blocking call still holds the worker.
`hook_flags` (`SWOOLE_HOOK_ALL` = `2147483647`) is what makes `sleep()`, PDO, file and socket
calls yield, and with it the same code served **3,653 req/s at concurrency 200 with p50
unchanged at 52 ms**: 47× the throughput, and no queueing.

The hooks are off by default for real reasons, all of which are the application's to check:

- they cover **socket** I/O, so an SQLite (in-process, file) workload sees none of the gain;
- a **shared handle** — one PDO connection, one Redis client — must not be used from two
  coroutines at once, or the protocol desynchronises and one request reads another's reply.
  Per-coroutine or pooled connections only;
- anything `static` or process-global becomes concurrently reachable *mid-request*, not just
  between requests.

::: warning Swoole's default dispatch leaves workers idle behind a proxy
`dispatch_mode` defaults to **2**: a connection is pinned to a worker by hashing its fd, for
the life of that connection. Probed with four keepalive connections against four workers,
they landed on **two** of them, and the server reached half its ceiling (38 of 78 req/s).
`dispatch_mode = 3` hands each request to an idle worker: 57 req/s on the same probe, and the
ceiling reached at concurrency 8 instead of 20.

This is the normal case in production, because a reverse proxy holds a small pool of
long-lived upstream connections. Set `SWOOLE_DISPATCH_MODE=3` for plain request/response
HTTP. Keep the default only where a worker holds per-connection state (a WebSocket session),
which mode 2 is what guarantees.
:::

Unlike PHP-FPM, Swoole **queues** past its capacity rather than refusing: at concurrency 200
on four blocking workers it still served every request, at a p99 of 5.1 s. Neither behaviour
is good; the difference matters when choosing where the backpressure lives.

### Request Isolation

Each request gets a **new** `ModuleContainer` (built by `LoadStage`), which is dropped when the request finishes. The `CoreContainer` is frozen after the first request. So module bindings cannot leak from one request to the next by construction, and there is no container to reset.

`Kernel::requestTeardown()` is currently a no-op, kept so entry points are already calling it if request-scoped kernel state is ever added. Keep the `finally` call.

What **can** leak is state your own code puts somewhere long-lived:

- `static` properties and static caches in request-scoped classes;
- request data bound into the `CoreContainer`, or into a `withPorts()` closure;
- globals and singletons outside the container.

That is why the rules say no statics in request-scoped classes, and per-request (for example per-tenant) bindings belong in a module's `register()`. See [Containers](/architecture/containers).

### Memory Limits

Long-lived workers accumulate memory leaks from poorly-scoped statics. Restart workers periodically:

```php
$http->set([
    'max_request' => 10000,  // Restart worker after 10k requests
]);
```

Monitor memory usage with:

```bash
watch -n 1 'ps aux | grep "openswoole\|swoole"'
```

If workers are still growing, use a supervisor like Systemd or Supervisor to restart them on a schedule.

### TLS and Reverse Proxies

OpenSwoole does not handle TLS natively. Use a reverse proxy (nginx) in front:

```nginx
upstream openswoole {
    server 127.0.0.1:9501;
    keepalive 64;
}

server {
    listen 443 ssl http2;
    server_name example.com;

    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    location / {
        proxy_pass http://openswoole;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
    }
}
```

With nginx on the same host, set `TRUSTED_PROXIES=127.0.0.1` so the kernel believes the `X-Forwarded-*` headers this proxy sets, and only this proxy's.

## Configuration Management

### Environment Variables

All env vars must be declared in `module.json` `config[]`. The kernel validates them at boot:

```json
{
  "config": [
    "DATABASE_HOST",
    "DATABASE_PORT",
    { "key": "INVOICE_TAX_RATE", "type": "float", "required": false, "default": 0.18 }
  ]
}
```

Missing required vars fail the boot with a clear error:

```
BootFailureException: Please set `DATABASE_HOST` in your .env
```

Load `.env` and per-domain `.env.<domain>` files via `LoadEnvironment` (kernel's bootstrap):

```php
LoadEnvironment::load(dirname(__DIR__));
```

::: tip Environment Variable Priority

1. `.env.<domain>` (e.g., `.env.shop.example.com`) — domain-specific overrides
2. `.env.<env>` (e.g., `.env.production`) — environment-specific
3. `.env` — shared defaults

:::

### Secrets Management

Never commit `.env` files with real secrets. Use a secrets manager:

**AWS Secrets Manager:**

```bash
export AWS_REGION=us-east-1
export SECRETS_PROVIDER=aws

# Secrets are loaded into $_ENV at request time
php app/public/index.php
```

**Environment variables directly:**

```bash
export DATABASE_PASSWORD="your-secret-key"
php app/public/index.php
```

Inject via systemd environment file (with restricted permissions):

```bash
# /etc/systemd/system/app.service
[Service]
EnvironmentFiles=/etc/app/secrets.env
# File should be: 0400 (readable only by owner)
```

## Error Handling and Logging

### Error Pipeline Configuration

Configure how errors are logged and notified in `bootstrap/app.php`:

```php
$kernel = Kernel::configure()
    ->withErrorPipeline(
        ErrorPipeline::notifiers([
            new SlackNotifier(env('SLACK_ERROR_WEBHOOK')),
            new MailNotifier(config('errors.mail_to')),
            new DatabaseErrorLogger(),
        ])
        ->fallback(new FileNotifier(logs_path('errors.log')))
        ->rules([
            'critical' => ['slack', 'mail', 'database', 'file'],
            'warning'  => ['database', 'file'],
            'info'     => ['file'],
        ])
    )
    ->build();
```

### Log Rotation

Configure logrotate to prevent unbounded growth:

```bash
# /etc/logrotate.d/app
/var/www/app/var/logs/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        systemctl reload php8.4-fpm
    endscript
}
```

Test the configuration:

```bash
sudo logrotate -d /etc/logrotate.d/app
```

## Health Checks

Implement a health check endpoint that returns 200 OK when the application is healthy:

```php
// In your health check route handler
public function check(Request $request): Response
{
    try {
        // Check database connectivity
        $this->db->queryOne('SELECT 1');

        // Check cache connectivity
        $this->cache->set('health-check', '1', ttl: 1);

        return Response::json(['status' => 'up'], 200);
    } catch (\Throwable $e) {
        return Response::json([
            'status' => 'down',
            'error'  => $e->getMessage(),
        ], 503);
    }
}
```

Configure load balancers to check the endpoint:

```bash
# Kubernetes Liveness Probe
livenessProbe:
  httpGet:
    path: /health
    port: 9501
  initialDelaySeconds: 10
  periodSeconds: 5
```

## Supervisor Process Manager

Run OpenSwoole or other background services under Systemd or Supervisor:

```ini
# /etc/supervisor/conf.d/app.conf
[program:app]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/app/bin/openswoole.php
autostart=true
autorestart=true
user=www-data
numprocs=1
stderr_logfile=/var/log/app/supervisor.err.log
stdout_logfile=/var/log/app/supervisor.out.log
```

Or with Systemd:

```ini
# /etc/systemd/system/app.service
[Unit]
Description=HKM Kernel Application
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php bin/openswoole.php
ExecReload=/bin/kill -USR2 $MAINPID
KillMode=process
Restart=always
RestartSec=5s
StandardOutput=append:/var/log/app/systemd.log
StandardError=append:/var/log/app/systemd.err.log

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable app
sudo systemctl start app
sudo systemctl status app
```

## Pre-Deployment Verification

Use `hkm install --check` to verify file permissions and directory readability:

```bash
hkm install --check --as=www-data

# Output:
# ✓ var/cache is writable
# ✓ var/logs is writable
# ✓ app/public is readable
# ✗ .env.production is world-readable (fix: chmod 640)
```

It checks:
- That the web server user can reach the project through every parent directory
- That it can read all `.env.*` files
- That no `.env.*` file is world-readable or group-writable
- That `var/` and `userdata/` directories exist and are writable
- That the kernel can be loaded

Fix issues and re-run until all checks pass.

## Monitoring

### Key Metrics

- **Request latency** — p50, p95, p99 (HTTP pipeline timing)
- **Error rate** — 4xx and 5xx responses per minute
- **Database query time** — slow query log, connection pool usage
- **Memory usage** — per-worker process memory (especially under OpenSwoole)
- **Cache hit rate** — if you're caching expensive queries
- **Background job latency** — time from push to completion

### Observability

Log request metadata using the kernel's built-in CorrelationId:

```php
$request->header('X-Correlation-ID'); // Set by CorrelationIdStage

// Use in your logs:
$logger->info('Request handled', [
    'correlation_id' => $request->header('X-Correlation-ID'),
    'path'           => $request->path(),
    'method'         => $request->method(),
    'status'         => $response->status(),
    'duration_ms'    => $duration,
]);
```

## Database Migrations

### Before Deploy

Test all pending migrations on a staging database identical to production:

```bash
APP_ENV=production DATABASE_HOST=staging-db.internal \
    hkm cli migrate:run --pretend

# Review the SQL before applying
```

Then apply:

```bash
hkm cli migrate:run
```

### Destructive Migrations

Migrations that drop columns or tables block the batch. Separate them into a maintenance window:

```bash
# First batch (expand schema, add new columns)
hkm cli migrate:run --steps=5

# Verify application handles new structure

# Second batch (contract schema, remove old columns)
hkm cli migrate:run
```

For large tables, add indexes before the migration runs heavy queries:

```php
// In a migration: raw SQL through the schema builder
public function up(SchemaBuilderInterface $schema): void
{
    $schema->raw('CREATE INDEX idx_fast ON large_table (column_name)');
}
```

On PostgreSQL, build big indexes with `CREATE INDEX CONCURRENTLY` to avoid locking writes. It cannot run inside a transaction, so mark that migration with LetMigrate's `TransactionlessMigrationInterface`. See [let-migrate](/packages/let-migrate).

## Rollback Strategy

### Keeping Backups

Automated backups are essential. Use your database provider's snapshot or a custom script:

```bash
# Nightly backup (cron)
0 2 * * * mysqldump --all-databases > /backups/db-$(date +\%Y\%m\%d).sql
```

### Application Rollback

If a deploy introduces a bug:

1. **Revert the code push** to the previous commit
2. **Do NOT run new migrations** — the old version may not understand the new schema
3. **Restart the application** — clear `var/cache/manifests/` if PHP-FPM is running
4. **Monitor error rates** to confirm the rollback worked

If you must roll back a migration, restore from backup and reapply migrations up to a stable point.

## Source

- [templates/app/public/index.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/templates/app/public/index.php)
- [src/Kernel/Boot/BootStamp.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/BootStamp.php)
- [src/Kernel/Http/Request.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Http/Request.php)
