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
hkm edge:status              # probe host; show stack, strategy, domains, target path
hkm edge:apply               # render + write + `nginx -t` / `apachectl configtest` + reload
hkm edge:apply --dry-run     # print the config that WOULD be written; change nothing
hkm edge:apply --no-reload   # write the file only; skip validate + reload
```

## Domains

Collected automatically from `projects/projects.json` (each project's
`domains[]`), plus `EDGE_EXTRA_DOMAINS`, minus `EDGE_EXCLUDE_DOMAINS`. Every
hostname is validated against a strict charset before it can reach a rendered
config, so a malformed registry entry can never inject directives.

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
