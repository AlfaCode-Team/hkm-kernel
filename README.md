# AlfacodeTeam PhpServicePlatform

A modular **PHP 8.4+** backend framework built on the **Gated Demand Architecture
(GDA)** pattern — security runs before any module loads, and only the modules a
request actually needs are wired in. The kernel is codenamed **Sentinel**.

It ships as a **native cross-platform CLI** (`hkm`) built with Zig, so end users
install and upgrade it like a Go/Rust binary — no Composer required to get started.

---

## Install (Linux / macOS / Windows)

Download the latest release from
[Releases](https://github.com/AlfaCode-Team/php-service-platform/releases/latest):

**Linux (Debian/Ubuntu/Kali):**
```bash
sudo apt install ./hkm-kernel_<version>_amd64.deb
hkm doctor        # verify PHP + extensions
```

**macOS:** extract `hkm-kernel-<version>-macos-universal.tar.gz`, then run
`HKM.app/Contents/Resources/opt/hkm-kernel/install.sh`.

**Windows:** extract `hkm-kernel-<version>-windows-x86_64.zip`, run
`hkm-kernel\install.bat`, add the folder to `PATH`.

The launcher **self-locates** the kernel — no environment variables required on a
standard install. Dependencies are resolved with Composer on the target at
install time (the runtime matches your exact PHP).

### Requirements (verified by `hkm doctor`)
- PHP **≥ 8.4.1**
- Extensions: `json, mbstring, ctype, tokenizer, filter, pdo, openssl, curl, fileinfo`
- At least one PDO driver (`mysql` / `pgsql` / `sqlite` / `sqlsrv`)
- Optional: `redis`, `swoole`/`openswoole`, `gd`, `intl`

---

## The `hkm` CLI

| Command | Purpose |
|---|---|
| `hkm new <path> [--project=<name>]` | Scaffold a new project (secure defaults: `.env` chmod 600, Apache **+** nginx configs) |
| `hkm run [path\|name]` | Run a project locally (PHP dev server) |
| `hkm cli [command]` | Run a project's console interactively |
| `hkm worker [args]` | Run a project's queue worker |
| `hkm list` | List registered projects |
| `hkm plugins [path\|name]` | Analyse a project's enabled plugins/modules |
| `hkm ui [sync\|list\|link\|clean]` | Federate enabled plugins' UIs into the frontend |
| `hkm doctor` | Diagnose PHP, extensions, and the resolved kernel path |
| `hkm-config` | Set up / repair the full environment (kernel + userdata) |
| `hkm upgrade [--check]` | Check for and install a newer release automatically |
| `hkm version` / `--version` / `-v` | Show the Sentinel banner + version |
| `hkm <command> --dev` | Run any command against the **development** kernel checkout instead of the installed one |

### Environment (all auto-detected — override only for non-standard layouts)
| Variable | Meaning |
|---|---|
| `HKM_KERNEL_HOME` | Kernel root (holds `composer.json`, `vendor/`, `projects/`, `templates/`) |
| `HKM_DEV_HOME` | Development kernel checkout used by `--dev` (set once: `hkm-config set-dev-home <path>`) |
| `HKM_USERDATA_DIR` | Persistent registry dir (`projects.json` + `platform.json`) that **survives updates** |
| `HKM_PHP_BIN` | Override the `php` binary |
| `HKM_CLI_PATH` / `HKM_GLOBAL_AUTOLOAD` | Override the PHP CLI script / kernel autoload |

Run `hkm-config` once and it pins `HKM_KERNEL_HOME` and provisions a persistent
`HKM_USERDATA_DIR` (migrating any existing registry) in
`~/.config/hkm/config.env`.

---

## Development (from source)

```bash
git clone --recurse-submodules git@github.com:AlfaCode-Team/php-service-platform.git
cd php-service-platform
composer install
vendor/bin/phpunit          # run the test suite

# Build the native launcher (needs Zig — see tools/.zig-version):
cd tools && zig build --release=small      # → ../bin/hkm + ../bin/hkm-config
```

### Building release bundles
```bash
VERSION=1.2.3 ./tools/bundle.sh all         # .deb + macOS .app + Windows .zip → dist/
# MODULES=git ./tools/bundle.sh linux       # fetch path-repo modules from pinned commits
```
Releases are cut by pushing a `v*` tag — CI runs the test suite first, then builds
all three OS bundles on Linux and publishes them automatically.

---

## Architecture at a glance

- **Kernel (Sentinel)** — boot pipeline, SecurityGateway, on-demand loading,
  scoped DI containers, HTTP/CLI/Worker pipelines, ports.
- **Plugins** (`plugins/`, `Plugins\` namespace) — bounded business/infrastructure
  modules (Auth, OAuth2, Tenancy, User, Storage, Session, Cookie, View, …).
- **Projects** (`projects/`) — per-project wiring; the runtime resolves an
  incoming host to a project via `DomainResolver`.

See the per-plugin `README.md` files (e.g. [Auth](plugins/Auth/README.md),
[Tenancy](plugins/Tenancy/README.md), [User](plugins/User/README.md)) and the
[CHANGELOG](CHANGELOG.md).

---

## Security defaults

Scaffolded projects are hardened by default: `.env` is `chmod 600`, debug output
is force-disabled when `APP_ENV=production`, and every new project ships web-server
configs (`app/public/.htaccess`, `app/apache.conf.example`, `app/nginx.conf.example`)
that pin the docroot to `app/public`, deny dotfiles, and add baseline security
headers. Keep secrets (`APP_KEY`, JWT signing keys, DB credentials) out of the CLI
config and, in production, behind a `SECRETS_PROVIDER`.

---

## License

MIT — see [LICENSE](LICENSE).
