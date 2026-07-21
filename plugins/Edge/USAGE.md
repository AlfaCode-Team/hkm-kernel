# Edge — Full Command Usage

Edge generates the host's web-server front config (nginx / Apache) from your
registered project domains, adapting to what is actually running on the machine.

**Invocation form** (every command):

```bash
hkm cli -p <project> <edge:command> [flags]
```

- Every command scopes to the **current project** by default (read from that
  project's `proj.json`). Add **`--all`** to act on every project in the global
  `projects.json`.
- Writing `/etc/nginx`, `/etc/hosts`, or `/etc/systemd` needs **`sudo`**.
- **`--dev`** makes `hkm` use your dev-kernel checkout (and is required for
  `edge:hosts`); the launcher strips it before the command runs.

The four commands:

| Command | Purpose | Writes? |
|---|---|---|
| `edge:status` | Probe the host, preview the strategy that would be applied | No |
| `edge:apply` | Render + write the server config, then validate & reload | Yes |
| `edge:hosts` | Sync local (`.local`/`.test`) domains into `/etc/hosts` | Yes (`/etc/hosts`) |
| `edge:service` | Render the systemd/supervisor unit for an OpenSwoole project | Optional (`--write`) |

---

## 1. `edge:status` — probe & preview (read-only)

Detects nginx/Apache, PHP-FPM, and shows the strategy that **would** be applied.
Touches nothing.

```bash
hkm cli -p <project> edge:status
hkm cli -p <project> edge:status --all
hkm cli -p <project> edge:status --nginx-only
hkm cli -p <project> edge:status --apache-only
```

| Flag | Effect |
|---|---|
| `--all` | Include every registered project (default: current only) |
| `--nginx-only` | Preview the nginx-only strategy (no Apache fallback) |
| `--apache-only` | Preview the apache-only strategy (no fallback) |

Reports: nginx installed/active/stream, **nginx stream cfg** (an existing splitter
that will be reused), apache installed/active, PHP-FPM version + socket + active
pools, the chosen **strategy**, per-project sites (model → upstream → domains),
local domains, and the target file path.

---

## 2. `edge:apply` — render + write + reload

Detects the stack, renders the matching config, writes it atomically (temp +
rename), then validates (`nginx -t` / `apachectl configtest`) and reloads.

```bash
hkm cli -p <project> edge:apply                  # write + test + reload
hkm cli -p <project> edge:apply --dry-run        # print what WOULD be written; touch nothing
hkm cli -p <project> edge:apply --no-reload      # write file only; skip test + reload
hkm cli -p <project> edge:apply --no-hosts       # skip the /etc/hosts sync
hkm cli -p <project> edge:apply --all            # render ALL projects into one file
```

### Scope / write flags

| Flag | Effect |
|---|---|
| `--dry-run` | Print the config that would be written; change nothing |
| `--no-reload` | Write the config file but do not validate or reload |
| `--no-hosts` | Skip writing local (`.local`/`.test`) domains to `/etc/hosts` |
| `--all` | Include every registered project |

### Environment → cache profile

Pick **at most one**; with none, the configured `APP_ENV`/`EDGE_APP_ENV` is used.
Anything unrecognised falls back to the DEVELOPMENT profile (never production).

| Flag | APP_ENV | Cache profile |
|---|---|---|
| `--local` (or `--dev`) | local | DEVELOPMENT — everything no-store (always refetch) |
| `--development` / `-d` | development | DEVELOPMENT |
| `--production` | production | PRODUCTION — long-lived immutable static assets, HTML never cached |

> The `hkm` launcher consumes `--dev` for **kernel** selection and strips it, so
> prefer `--local` when running via `hkm`.

### TLS mode

Default is config `tls.mode` (`ssl`). Pick one:

| Flag | Emits |
|---|---|
| `--tls=ssl` | ONE `listen 443 ssl` server (HTTP/2, TLS pinning, HSTS). No `:80`. **Needs a certificate.** |
| `--tls=none` **/** `--no-ssl` | ONE `listen 80` server, plain HTTP, no certificate, **no HSTS**. |
| `--tls=both` | TWO servers: a `listen 80` block that serves the ACME challenge then `301`-redirects to HTTPS, **plus** the `listen 443 ssl` block. |
| `--ssl-cert=/path/fullchain.pem` | Override config `ssl.cert` |
| `--ssl-key=/path/privkey.pem` | Override config `ssl.key` |

> Every `--tls` mode is validated by `nginx -t` in the test suite. The `both`
> redirect always passes ACME/Let's Encrypt HTTP-01 validation
> (`/.well-known/acme-challenge/`) through **before** the redirect, so a cert can
> still be issued/renewed over plain `:80`.

### Strategy override

Default is auto-detect. Pick **at most one** to pin a single server with **no
fallback**:

| Flag | Effect |
|---|---|
| `--nginx-only` | nginx serves everything, **no Apache fallback** (plain reverse-proxy vhost, no stream) |
| `--apache-only` | Apache serves everything, no fallback (Apache SSL/HTTP VirtualHost) |

### Strategy auto-detection (when neither `--nginx-only` nor `--apache-only`)

| Detected stack | Strategy | Rendered config |
|---|---|---|
| nginx **and** Apache active, nginx has `stream` | `nginx-stream` | nginx SNI (L4) splitter: listed domains → nginx (`:444`), rest → Apache (`:8443`) |
| both active but nginx already has a `stream {}` splitter | `nginx-stream` (**reuse + merge**) | only the internal backend vhosts; the platform's domains are merged INTO the existing `map` in place |
| only nginx active (or Apache inactive, or nginx lacks `stream`) | `nginx-only` | plain nginx reverse-proxy vhost |
| only Apache active | `apache-only` | Apache SSL/HTTP VirtualHost |
| neither active | `none` | nothing — reports & stops |

---

## 3. `edge:hosts` — sync local domains → `/etc/hosts`

Writes `.local`/`.test`/… domains to the loopback in a marked, idempotent block
(the rest of the hosts file is never touched). **Dev-only**, needs `sudo`.

```bash
sudo hkm cli -p <project> --dev edge:hosts             # add/update the managed block
sudo hkm cli -p <project> --dev edge:hosts --remove    # remove the HKM-managed block
hkm cli -p <project> edge:hosts --dry-run              # show changes; write nothing
hkm cli -p <project> edge:hosts --force                # allow outside dev mode
```

| Flag | Effect |
|---|---|
| `--dry-run` | Show what would change; write nothing |
| `--remove` | Remove the HKM-managed block from the hosts file |
| `--all` | Include every registered project |
| `--force` | Allow running outside dev mode (normally requires `--dev`) |

Outside dev mode `edge:hosts` refuses (use `--force`), and `edge:apply` silently
skips the hosts step — a live server resolves public domains via DNS. Existing
entries win: a host already mapped elsewhere is skipped, never duplicated.

---

## 4. `edge:service` — process-manager units (OpenSwoole)

Renders the systemd/supervisor unit that keeps a project's OpenSwoole server
alive behind the reverse proxy. **PHP-FPM projects yield nothing** (php-fpm
supervises those workers).

```bash
hkm cli -p <project> edge:service                  # print the systemd unit(s)
hkm cli -p <project> edge:service --supervisor     # print supervisor program block(s)
hkm cli -p <project> edge:service --all            # every registered project
hkm cli -p <project> edge:service --user=deploy    # run the service as this user
sudo hkm cli -p <project> edge:service --write     # write to the default unit dir
hkm cli -p <project> edge:service --write=/tmp/units   # write into a specific dir
```

| Flag | Effect |
|---|---|
| `--supervisor` | Render a supervisor program block instead of a systemd unit |
| `--all` | Include every registered project |
| `--user=<name>` | User/group the service runs as (default: `www-data`) |
| `--write[=dir]` | Write unit(s) to disk; optional target dir (default `/etc/systemd/system` or `/etc/supervisor/conf.d`) |
| `--local` / `--dev` / `--development` / `-d` / `--production` | Same APP_ENV flags as `edge:apply` |

After writing:

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now <unit>
# or, for supervisor:
sudo supervisorctl reread && sudo supervisorctl update
```

---

## Reusing & updating an existing nginx stream splitter

If your `nginx.conf` (or an included file) already has an SNI splitter —
`map $ssl_preread_server_name $backend_name { … }` routing SNI to nginx
(`127.0.0.1:444`) vs Apache (`127.0.0.1:8443`) — Edge **does not** write its own
`stream {}` block. It locates that file (via `nginx -T`), and on `edge:apply`
**merges the platform's public domains into your existing `map` in place**,
pointing them at `nginx_backend`.

- Your hand-written entries are left untouched.
- Additions go inside a marked, idempotent sub-block before the `default` line.
- A domain already present anywhere in the map is skipped, never duplicated.
- Writing `nginx.conf` needs **`sudo`**; a failed write fails the whole apply.

```bash
# Preview the exact map diff — writes nothing
sudo hkm cli -p <project> edge:apply --dry-run

# Merge the platform domains into the existing splitter + reload
sudo hkm cli -p <project> edge:apply --production
```

Result before `default apache_ssl;`:

```nginx
        # >>> HKM Edge (managed domains) >>>
        app.showmeuganda.com   nginx_backend;
        admin.hkmvote.com      nginx_backend;
        # <<< HKM Edge (managed domains) <<<
```

Controls: `EDGE_REUSE_STREAM=0` to disable (write Edge's own block instead);
`EDGE_STREAM_BACKEND` to change the upstream name the domains map to.

---

## Serving without SSL (no certificate on the host)

The default `ssl` mode expects a cert/key (`/etc/ssl/certs/hkm-edge.pem` …) — on
a host with no certificate, `nginx -t` fails. Serve plain HTTP instead:

```bash
# per run
hkm cli -p <project> edge:apply --no-ssl                 # = --tls=none
# or as a deploy default (.env)
EDGE_TLS_MODE=none
```

The SNI **stream splitter cannot apply without TLS** (it routes by reading the
TLS SNI), so on a no-SSL host force a single server:

```bash
hkm cli -p <project> edge:apply --no-ssl --nginx-only    # or --apache-only
```

HSTS is emitted only for TLS modes, so `--no-ssl` never adds it.

---

## Common workflows

```bash
# Local dev — serve *.local sites via nginx/Apache + /etc/hosts
sudo hkm cli -p shop --dev edge:apply --local

# Production VPS with TLS — write to /etc/nginx and reload (paths via EDGE_*_PATH)
hkm cli -p shop edge:apply --production

# Production, NO SSL — plain HTTP, nginx as sole front
hkm cli -p shop edge:apply --production --no-ssl --nginx-only

# Preview only, write nothing
hkm cli -p shop edge:status
hkm cli -p shop edge:apply --production --dry-run

# Force a single server, no fallback
hkm cli -p shop edge:apply --production --nginx-only
hkm cli -p shop edge:apply --production --apache-only

# Redirect all HTTP to HTTPS
hkm cli -p shop edge:apply --production --tls=both

# Bring an OpenSwoole project under systemd
sudo hkm cli -p shop edge:service --production --write
sudo systemctl daemon-reload && sudo systemctl enable --now hkm-shop

# Every registered project at once
hkm cli -p shop edge:apply --production --all
```

---

## Environment reference (`config/edge.php`)

### Ports & TLS

| Env | Default | Purpose |
|---|---|---|
| `EDGE_LISTEN_PORT` | `443` | public TLS port |
| `EDGE_HTTP_PORT` | `80` | public plain-HTTP port (used by `tls=none`/`both`) |
| `EDGE_TLS_MODE` | `ssl` | default TLS mode: `ssl` \| `none` \| `both` |
| `EDGE_SSL_CERT` / `EDGE_SSL_KEY` | `/etc/ssl/certs/hkm-edge.pem` / `…/private/hkm-edge.key` | cert/key for nginx-only & Apache templates |
| `EDGE_HSTS` / `EDGE_HSTS_MAX_AGE` / `EDGE_HSTS_SUBDOMAINS` / `EDGE_HSTS_PRELOAD` | `true` / `31536000` / `true` / `false` | HSTS for the PRODUCTION profile (TLS modes only); `preload` is opt-in |
| `EDGE_HSTS_DEV_MAX_AGE` | `300` | DEVELOPMENT profile HSTS max-age — always short, no subdomains, no preload |
| `EDGE_SSL_PROTOCOLS` / `EDGE_SSL_CIPHERS` / `EDGE_SSL_STAPLING` | `TLSv1.2 TLSv1.3` / *(modern)* / `false` | explicit TLS pinning (both profiles); keep stapling off for Cloudflare Origin CA |

### CORS, methods & hardening

| Env | Default | Purpose |
|---|---|---|
| `EDGE_CORS` | `off` | CORS mode: `off` \| `allowlist` \| `wildcard` (wildcard is opt-in) |
| `EDGE_CORS_ORIGINS` | — | allowlist origins (comma-separated), echoed back via a `$http_origin` map |
| `EDGE_CORS_METHODS` / `EDGE_CORS_HEADERS` / `EDGE_CORS_CREDENTIALS` | *(sane)* / *(sane)* / `false` | CORS method/header allowlists + credentials |
| `EDGE_ALLOWED_METHODS` | `GET\|HEAD\|POST\|PUT\|PATCH\|DELETE\|OPTIONS` | HTTP method guard (returns 405 otherwise); tighten to `GET\|HEAD\|POST` for form apps; empty disables |
| `EDGE_DENY_DIRS` | `vendor,node_modules,tests,.git,.github,bootstrap/cache` | directories denied (prefix-matched, before the static rule). `storage` is deliberately excluded (public/storage upload symlink); add it where the app has none. Empty disables |
| `EDGE_NGINX_DEBUG_LOG` | `false` | opt-in `error_log … debug` (needs nginx `--with-debug`; default level is `warn`) |
| `EDGE_NGINX_STATUS` | `true` | emit `/nginx-status` on DEVELOPMENT hosts (never in production) |

### Strategy & upstreams

| Env | Default | Purpose |
|---|---|---|
| `EDGE_FORCE_STRATEGY` | *(empty)* | pin a single server: `nginx-only` \| `apache-only` (no fallback); empty = auto-detect |
| `EDGE_REUSE_STREAM` | `true` | reuse an existing nginx `stream {}` splitter (merge domains into its map) instead of writing a second one |
| `EDGE_STREAM_BACKEND` | `nginx_backend` | upstream NAME the merged domains map to inside the existing splitter |
| `EDGE_NGINX_SSL_PORT` | *(auto)* | port the nginx-only vhost LISTENS on. Auto = 443 standalone, but the internal backend port (e.g. 444) when this host runs an SNI `stream {}` router that owns :443 — else nginx fails with "Address already in use". Auto-detected; force with `EDGE_BEHIND_SNI_ROUTER=1` or pin here |
| `EDGE_BEHIND_SNI_ROUTER` | `false` | force "behind an SNI router" topology (vhost listens on the internal port, redirect targets the public port) |
| `EDGE_PER_SITE_LOGS` | `true` | emit per-site access/error logs in every vhost (both profiles); false falls back to the global log |
| `EDGE_NGINX_BACKEND` | `127.0.0.1:444` | nginx TLS backend (stream) |
| `EDGE_APACHE_BACKEND` | `127.0.0.1:8443` | Apache fallback backend (stream) |
| `EDGE_APP_BACKEND` | `127.0.0.1:8080` | app upstream (nginx-only / Apache) |

### Output paths & reload commands

| Env | Default | Purpose |
|---|---|---|
| `EDGE_STREAM_PATH` / `EDGE_NGINX_PATH` / `EDGE_APACHE_PATH` | `var/edge/*.conf` | where each config is written (point at `/etc/nginx/...` in prod) |
| `EDGE_RELOAD` | `false` | reload after write by default (also per-command) |
| `EDGE_NGINX_TEST_CMD` / `EDGE_NGINX_RELOAD_CMD` | `nginx -t` / `nginx -s reload` | validate/reload nginx |
| `EDGE_APACHE_TEST_CMD` / `EDGE_APACHE_RELOAD_CMD` | `apachectl configtest` / `apachectl graceful` | validate/reload Apache |

### Domains & hosts

| Env | Default | Purpose |
|---|---|---|
| `EDGE_EXTRA_DOMAINS` / `EDGE_EXCLUDE_DOMAINS` | — | comma-separated add / drop |
| `EDGE_LOCAL_TLDS` | `local,test,localhost,example,invalid` | TLDs treated as local (→ `/etc/hosts`) |
| `EDGE_MANAGE_HOSTS` | `true` | write local domains to `/etc/hosts` on apply |
| `EDGE_HOSTS_PATH` / `EDGE_HOSTS_IP` | `/etc/hosts` / `127.0.0.1` | hosts file + loopback target |
| `EDGE_LOCAL_IN_SERVER` | `false` | also include local domains in the server config (dev turns this on) |

### Serve model & kernel env

| Env | Default | Purpose |
|---|---|---|
| `EDGE_SERVE_MODEL` | `php-fpm` | default serve model: `php-fpm` \| `openswoole` (per-project override in `proj.json`) |
| `EDGE_FPM_SOCKET` | *(auto)* | pin the FPM socket; empty = auto-resolve for the CLI PHP version |
| `EDGE_SWOOLE_HOST` / `EDGE_SWOOLE_PORT` | `127.0.0.1` / `9501` | OpenSwoole upstream host + port |
| `EDGE_INJECT_KERNEL_ENV` | `true` | inject kernel-resolution env into each vhost |
| `EDGE_APP_ENV` | `APP_ENV` or `production` | `APP_ENV` written into each vhost |

### Compression, caching & rate limiting

| Env | Default | Purpose |
|---|---|---|
| `EDGE_COMPRESSION` | `auto` | `auto` \| `brotli` \| `gzip` \| `off` (resolved per server's capability) |
| `EDGE_CACHE_ASSETS` / `EDGE_CACHE_ASSETS_TTL` | `true` / `31536000` | cache fingerprinted static assets (prod), TTL seconds |
| `EDGE_CACHE_HTML` | `false` | cache HTML/dynamic responses (almost always off) |
| `EDGE_HTTP_PRELUDE` | `false` | emit `log_format` + rate-limit zones + Cloudflare real-IP once at file top |
| `EDGE_RATE_LIMIT` / `EDGE_RATE_REQ_RATE` / `EDGE_RATE_REQ_BURST` | `true` / `10r/s` / `50` | per-vhost rate limiting (needs the prelude) |
| `EDGE_CLOUDFLARE_REAL_IP` / `EDGE_CLOUDFLARE_RANGES` | `true` / *(published list)* | restore the real visitor IP behind Cloudflare |
| `EDGE_DEV_VHOST` | *(follows APP_ENV)* | force dev vhost extras on/off (verbose logs, permissive CORS, `/nginx-status`) |

> Full config with inline comments: `plugins/Edge/config/edge.php`.
> Architecture & the SNI splitter deep-dive: `plugins/Edge/README.md`.
