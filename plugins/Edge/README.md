# Edge plugin (`Plugins\Edge`, solves `edge.routing`)

Generates the host's **web-server front config** from the platform's registered
domains, adapting to whatever is actually running on the machine.

It probes the host, picks a strategy, renders the matching config, then
validates and reloads the server.

## Strategy detection

| Detected stack | Strategy | Rendered config |
|---|---|---|
| nginx **and** Apache active, nginx has the `stream` module | `nginx-stream` | nginx **SNI (L4) stream splitter** — listed domains → nginx (`:444`), everything else → Apache (`:8443`) |
| only nginx active (or Apache present but **inactive**, or nginx lacks `stream`) | `nginx-only` | plain nginx reverse-proxy vhost (no stream) |
| only Apache active | `apache-only` | Apache SSL `VirtualHost` |
| neither active | `none` | nothing — reports and stops |

This is exactly the "check what's on the host and apply accordingly" rule:
nginx is the front; if it can stream and Apache is up, split by SNI; if Apache
is down, just nginx without stream; if only Apache, configure Apache.

## The SNI stream splitter (the `nginx-stream` output)

```nginx
stream {
    upstream nginx_backend { server 127.0.0.1:444; }
    upstream apache_ssl    { server 127.0.0.1:8443; }

    map $ssl_preread_server_name $backend_name {
        app.example.com   nginx_backend;
        ...
        default apache_ssl;
    }

    server {
        listen 443;
        proxy_pass $backend_name;
        ssl_preread on;
    }
}
```

`ssl_preread` reads the TLS ClientHello's SNI **without decrypting**, then the
raw TLS stream is forwarded to whichever backend the `map` picked. TLS is
terminated by that backend (nginx on `:444`, Apache on `:8443`) — the stream
layer never sees plaintext, so certificates live on the backends.

> The `stream {}` block must live at the nginx **main context** (top level of
> `nginx.conf`), **not** inside `http {}`. Include it: `include <path>;`

## Commands

```bash
hkm edge:status              # probe host; show stack, strategy, server + local domains
hkm edge:apply               # render + write server config + sync /etc/hosts + reload
hkm edge:apply --dry-run     # print what WOULD be written; change nothing
hkm edge:apply --no-reload   # write the file only; skip validate + reload
hkm edge:apply --no-hosts    # skip the /etc/hosts sync
hkm edge:hosts               # sync ONLY the local domains into /etc/hosts (needs sudo)
hkm edge:hosts --dry-run     # show the hosts block that would be written
hkm edge:hosts --remove      # remove the HKM-managed hosts block
```

## Per-project serving (the vhost model)

Edge is **project-aware**: it reads the global registry (`projects.json` →
name/path/domains) and renders **one vhost per project**, with:

- **docroot = `<project path>/app/public`** (never the project root — keeps
  `.env`/config/src/vendor out of the web tree), modeled on
  `templates/app/{nginx,apache}.conf.example`;
- the **run-env injected** so the served project boots: `APP_ENV`,
  `HKM_USERDATA_DIR`, and (when `EDGE_INJECT_KERNEL_ENV=true`) `HKM_KERNEL_HOME`
  + `PSP_GLOBAL_AUTOLOAD` — as `fastcgi_param` (nginx FPM) / `SetEnv` (Apache);
- a **serve model** per project: `fpm` (fastcgi to PHP-FPM) or `swoole`
  (reverse-proxy to the project's OpenSwoole port).

Each project may override the model + upstream + extra env in its **`proj.json`**:

```jsonc
{
  "name": "shop",
  "edge": {
    "serve":  "swoole",          // or "fpm"
    "port":   9601,              // swoole upstream port
    "socket": "unix:/run/php/php8.4-fpm.sock",  // fpm socket (fpm model)
    "env":    { "APP_ENV": "production", "SHOP_FLAG": "1" }   // per-project extras
  }
}
```

Defaults come from `EDGE_SERVE_MODEL` / `EDGE_FPM_SOCKET` /
`EDGE_SWOOLE_HOST` / `EDGE_SWOOLE_BASE_PORT`.

## Domains — public vs local

Collected automatically from `projects/projects.json` (each project's
`domains[]`), plus `EDGE_EXTRA_DOMAINS`, minus `EDGE_EXCLUDE_DOMAINS`. Every
hostname is validated against a strict charset before it can reach a rendered
config, so a malformed registry entry can never inject directives.

Domains are then **split**:

- **Public** (real FQDN, e.g. `app.example.com`) → go into the **server config**
  (nginx stream / vhost / Apache).
- **Local** (`*.local`, `*.test`, `*.localhost`, `*.example`, `*.invalid`, or a
  single-label host like `myapp`) → are **dev-only**: kept OUT of the public
  server config and written to **`/etc/hosts`** pointing at the loopback, so they
  resolve on this machine. The managed block is delimited by markers, so the rest
  of your hosts file is never touched and re-runs are idempotent:

  ```
  # >>> HKM Edge (local domains) >>>
  127.0.0.1    api.hkm.local
  127.0.0.1    hkm.local
  # <<< HKM Edge (local domains) <<<
  ```

Tune the local TLD set with `EDGE_LOCAL_TLDS`. Set `EDGE_LOCAL_IN_SERVER=true` if
you also want nginx to serve `.local` sites locally (they then appear in BOTH the
server config and `/etc/hosts`).

## Configuration (`config/edge.php`, all env-driven)

| Env | Default | Purpose |
|---|---|---|
| `EDGE_LISTEN_PORT` | `443` | public TLS port |
| `EDGE_NGINX_BACKEND` | `127.0.0.1:444` | nginx TLS backend (stream) |
| `EDGE_APACHE_BACKEND` | `127.0.0.1:8443` | Apache fallback backend (stream) |
| `EDGE_APP_BACKEND` | `127.0.0.1:8080` | app upstream (nginx-only / Apache) |
| `EDGE_SSL_CERT` / `EDGE_SSL_KEY` | `/etc/ssl/...` | cert used by nginx-only / Apache templates |
| `EDGE_STREAM_PATH` / `EDGE_NGINX_PATH` / `EDGE_APACHE_PATH` | `var/edge/*.conf` | where each config is written (point at `/etc/nginx/...` in prod) |
| `EDGE_RELOAD` | `false` | reload after write by default (also controllable per-command) |
| `EDGE_*_TEST_CMD` / `EDGE_*_RELOAD_CMD` | `nginx -t`, `nginx -s reload`, `apachectl configtest`, `apachectl graceful` | validate/reload commands per distro |
| `EDGE_EXTRA_DOMAINS` / `EDGE_EXCLUDE_DOMAINS` | — | comma-separated add/drop |
| `EDGE_LOCAL_TLDS` | `local,test,localhost,example,invalid` | TLDs treated as local (→ /etc/hosts) |
| `EDGE_MANAGE_HOSTS` | `true` | write local domains to /etc/hosts on apply |
| `EDGE_HOSTS_PATH` / `EDGE_HOSTS_IP` | `/etc/hosts` / `127.0.0.1` | hosts file + loopback target |
| `EDGE_LOCAL_IN_SERVER` | `false` | also include local domains in the server config |
| `EDGE_SERVE_MODEL` | `fpm` | default serve model (`fpm` \| `swoole`); per-project override in `proj.json` |
| `EDGE_FPM_SOCKET` | `unix:/run/php/php-fpm.sock` | default PHP-FPM socket/addr |
| `EDGE_SWOOLE_HOST` / `EDGE_SWOOLE_BASE_PORT` | `127.0.0.1` / `9500` | Swoole upstream host + base port |
| `EDGE_INJECT_KERNEL_ENV` | `true` | inject `PSP_GLOBAL_AUTOLOAD` + `HKM_KERNEL_HOME` into each vhost |
| `EDGE_APP_ENV` | `APP_ENV` or `production` | `APP_ENV` written into each vhost |

Defaults write to `var/edge/` so no root is needed to test; in production point
`EDGE_*_PATH` at the real nginx/Apache include dirs and run `hkm` with the
privileges needed to reload.

## Notes

- ON-DEMAND module; the value is the CLI. A route that needs the contract
  declares `"requires": ["edge.routing"]`.
- Writes are atomic (temp file + rename), so a live `include` never sees a
  half-written file.
- The service is DI-free (collaborators read `edge_config()`), so it constructs
  without ports or a database.
