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

### Reusing (and updating) an existing nginx stream splitter

When both servers are running **and** the running nginx **already declares an
SNI `stream {}` splitter** (a `map $ssl_preread_server_name … { … }` using
`ssl_preread`, located from `nginx -T`, excluding Edge's own managed file), Edge
does **not** write a second, conflicting splitter. Instead it:

1. emits only the internal backend vhosts (TLS-terminating on `:444`), and
2. **merges the platform's public domains INTO your existing `map` in place** —
   editing the host file (e.g. `nginx.conf`) where the splitter lives.

The merge is surgical and idempotent: your hand-written entries are left exactly
as they are, and Edge's additions live inside a marked sub-block placed just
before the `default` line. A domain already present anywhere in the map (yours or
ours) is never re-added; re-runs never duplicate.

```nginx
    map $ssl_preread_server_name $backend_name {
        migratetravel.com                  nginx_backend;   # your entries — untouched
        www.migratetravel.com              nginx_backend;
        # >>> HKM Edge (managed domains) >>>
        app.showmeuganda.com   nginx_backend;               # added by `edge:apply`
        admin.hkmvote.com      nginx_backend;
        # <<< HKM Edge (managed domains) <<<
        default                            apache_ssl;
    }
```

The upstream name the domains map to is `nginx_backend` by default (override with
`EDGE_STREAM_BACKEND` to match your `upstream { … }`). Writing `nginx.conf`
usually needs **`sudo`** — a failed write fails the whole apply loudly rather than
reporting success. Disable this reuse/merge behaviour and always write Edge's own
stream block with `EDGE_REUSE_STREAM=0`. Preview the exact map diff without
touching anything using `edge:apply --dry-run`.

### Forcing a single server (no fallback)

Auto-detection can be overridden to pin one server with **no fallback**:

```bash
hkm cli -p <project> edge:apply --nginx-only    # nginx serves everything, NO Apache fallback
hkm cli -p <project> edge:apply --apache-only   # Apache serves everything, no fallback
```

`--nginx-only` renders the plain nginx reverse-proxy vhost (no stream layer);
`--apache-only` renders the Apache SSL VirtualHost. The same choice can be set as
a deploy default with `EDGE_FORCE_STRATEGY=nginx-only|apache-only`. `edge:status`
accepts the same two flags to preview the forced strategy without writing.

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

By default every command scopes to the **current project** (read from
`base_path()/proj.json` — i.e. the project you run it in). Add **`--all`** to act
on every registered project in the global `projects.json`.

```bash
hkm cli -p <project> edge:status         # probe host; show THIS project's plan
hkm cli -p <project> edge:status --all   # every registered project
hkm cli -p <project> edge:apply          # render + write config + sync /etc/hosts + reload
hkm cli -p <project> edge:apply --dry-run   # print what WOULD be written
hkm cli -p <project> edge:apply --no-reload # write only; skip validate + reload
hkm cli -p <project> edge:apply --no-hosts  # skip the /etc/hosts sync
hkm cli -p <project> edge:apply --all       # render ALL projects into one file
sudo hkm cli -p <project> --dev edge:hosts   # sync THIS project's local domains → /etc/hosts
sudo hkm cli -p <project> --dev edge:hosts --remove   # remove the HKM-managed block
hkm cli -p <project> edge:hosts --dry-run --force     # preview outside dev mode
```

Notes:

- **`--dev`** makes `hkm` use your dev kernel checkout — and is **required** for
  `edge:hosts` (see the `/etc/hosts` rules below).
- **`sudo`** is needed to write `/etc/hosts` (and `/etc/nginx` in production).

## Per-project serving (the vhost model)

Edge is **project-aware**: it reads the global registry (`projects.json` →
name/path/domains) and renders **one vhost per project**, with:

- **docroot = `<project path>/app/public`** (never the project root — keeps
  `.env`/config/src/vendor out of the web tree), modeled on
  `templates/app/{nginx,apache}.conf.example`;
- the **run-env injected** so the served project boots (FPM workers don't
  inherit your shell/`hkm` env) — as `fastcgi_param` (nginx) / `SetEnv` (Apache).
  Edge **passes through** the kernel-resolution env the launcher already exported
  for the active context — it doesn't derive or configure it. `hkm … --dev`
  carries `HKM_DEV_HOME` + the checkout's `HKM_KERNEL_HOME` / `PSP_GLOBAL_AUTOLOAD`;
  a live `hkm cli` carries the installed kernel's paths. The pass-through set is
  `kernel_env_keys` (default `HKM_KERNEL_HOME`, `HKM_DEV_HOME`, `HKM_USERDATA_DIR`,
  `PSP_GLOBAL_AUTOLOAD`, `PSP_PROJECTS_DIR`) — only the ones actually set in the
  environment are written. Plus `APP_ENV`. Set `EDGE_INJECT_KERNEL_ENV=false` to
  skip all of them;
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

Collected from the **current project's `proj.json`** `domains[]` (or every
registered project with `--all`), plus `EDGE_EXTRA_DOMAINS`, minus
`EDGE_EXCLUDE_DOMAINS`. Every hostname is validated against a strict charset
before it can reach a rendered config, so a malformed entry can never inject
directives.

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

Tune the local TLD set with `EDGE_LOCAL_TLDS`. **In dev mode (`hkm … --dev`, which
exports `HKM_DEV=1`) the local domains are served by the vhost automatically** —
they appear in BOTH the server config and `/etc/hosts` — so `hkm cli -p <p> --dev
edge:apply` gives you a working local nginx/Apache site with no extra flag. A
production (non `--dev`) run keeps them OUT of the server config (public domains
resolve through DNS); set `EDGE_LOCAL_IN_SERVER=true` to force local-in-server
outside dev too.

### `/etc/hosts` rules — dev only, never duplicates

**1. Requires dev mode.** `/etc/hosts` is a *developer-machine* concern: a live
server resolves its public domains through **DNS**. So the hosts sync only runs
when the launcher marks the invocation as dev (`hkm … --dev`, which exports
`HKM_DEV=1`). Outside dev:

- `edge:hosts` **refuses** with a clear message (override with `--force`),
- `edge:apply` **silently skips** the hosts step — a VPS run never touches
  `/etc/hosts`.

```bash
sudo hkm cli -p myproject --dev edge:hosts       # ✓ writes the block
hkm cli -p myproject edge:hosts                  # ✗ refuses (not dev mode)
hkm cli -p myproject edge:hosts --force          # ✓ explicit override
```

> Writing `/etc/hosts` needs root, so use `sudo`. The launcher reads your
> `config.env` via `SUDO_USER`, so `sudo hkm … --dev` still finds `HKM_DEV_HOME`.

**2. Existing entries win — a host is never duplicated.** Before writing, every
domain is checked against the rest of the hosts file (outside the managed block,
comments and multi-host lines handled). A hostname already mapped there is
**skipped and left untouched**; only genuinely missing ones are added:

```text
already in /etc/hosts (left untouched): hkm.local
Would write 2 new local domain(s) to /etc/hosts:
127.0.0.1    api.hkm.local
127.0.0.1    app.hkm.local
```

Re-runs stay idempotent, and `--remove` drops the managed block (never your own
entries).

## Configuration (`config/edge.php`, all env-driven)

| Env | Default | Purpose |
|---|---|---|
| `EDGE_LISTEN_PORT` | `443` | public TLS port |
| `EDGE_NGINX_BACKEND` | `127.0.0.1:444` | nginx TLS backend (stream) |
| `EDGE_APACHE_BACKEND` | `127.0.0.1:8443` | Apache fallback backend (stream) |
| `EDGE_APP_BACKEND` | `127.0.0.1:8080` | app upstream (nginx-only / Apache) |
| `EDGE_SSL_CERT` / `EDGE_SSL_KEY` | `/etc/ssl/...` | cert used by nginx-only / Apache templates |
| `EDGE_STREAM_PATH` / `EDGE_NGINX_PATH` / `EDGE_APACHE_PATH` | `var/edge/*.conf` | where each config is written (point at `/etc/nginx/...` in prod) |
| `EDGE_FORCE_STRATEGY` | *(empty)* | pin a single server — `nginx-only` \| `apache-only` (no fallback); empty = auto-detect |
| `EDGE_REUSE_STREAM` | `true` | reuse an existing nginx `stream {}` splitter instead of writing a second one |
| `EDGE_RELOAD` | `false` | reload after write by default (also controllable per-command) |
| `EDGE_*_TEST_CMD` / `EDGE_*_RELOAD_CMD` | `nginx -t`, `nginx -s reload`, `apachectl configtest`, `apachectl graceful` | validate/reload commands per distro |
| `EDGE_EXTRA_DOMAINS` / `EDGE_EXCLUDE_DOMAINS` | — | comma-separated add/drop |
| `EDGE_LOCAL_TLDS` | `local,test,localhost,example,invalid` | TLDs treated as local (→ /etc/hosts) |
| `EDGE_MANAGE_HOSTS` | `true` | write local domains to /etc/hosts on apply |
| `EDGE_HOSTS_PATH` / `EDGE_HOSTS_IP` | `/etc/hosts` / `127.0.0.1` | hosts file + loopback target |
| `EDGE_LOCAL_IN_SERVER` | `false` | also include local domains in the server config |
| `EDGE_SERVE_MODEL` | `fpm` | default serve model (`fpm` \| `swoole`); per-project override in `proj.json` |
| `EDGE_FPM_SOCKET` | *(auto)* | pin the FPM socket/addr; empty = auto-resolve the socket matching the CLI PHP version |
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
