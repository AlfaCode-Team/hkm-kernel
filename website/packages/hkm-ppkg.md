# hkm-ppkg — Zig-based Package Manager

The `hkm-ppkg` module is a **Composer-compatible package manager written in Zig**, embedded in the `hkm` native launcher. It is NOT a PHP package and is NOT autoloaded; it is build tooling consumed by `tools/` and the deployment pipeline.

## What it is

`hkm-ppkg` (`ppkg` for short) is a drop-in replacement for the Composer commands developers actually use:

| Composer command | ppkg support | Notes |
|---|---|---|
| `install` | ✓ Full | Restore vendor/ from lock file |
| `update` | ✓ Full | Re-resolve and update lock file |
| `require` | ✓ Full | Add a package and update lock |
| `remove` | ✓ Full | Remove a package and update lock |
| `init` | ✓ Full | Interactive project initialization |
| `config` | ✓ Full | Manage composer.json config keys |
| `audit` | ✓ Full | Check for CVEs in dependencies |
| `dump-autoload` | ✓ Full | Generate/regenerate PSR-4 autoload |
| `search` | ✓ Full | Query Packagist for packages |
| `show` | ✓ Full | List installed packages |
| `bump` | ✓ Full | Bump version in composer.json |
| `archive` | ✓ Full | Create a zip/tarball of a package |
| `create-project` | ✓ Full | Scaffold a new project from a template |
| `diagnose` | ✓ Full | Troubleshoot the environment |
| `completion` | ✓ Full | Shell completion hints |

It reads and writes the same files Composer does — `composer.json`, `composer.lock`, `vendor/`, `vendor/bin/` — byte for byte. Projects built with Composer still load from an autoload built by ppkg, and vice versa.

## Why ppkg?

Speed — it is **~225x faster on cold VCS resolution** than Composer (benchmark: resolving 34 VCS repositories):

| Task | Composer | ppkg |
|---|---|---|
| Resolve 34 VCS repos | 375.6s | 1.67s |
| Plugin verify (one, cold) | 250.06s | 0.45s |
| Warm install | 6.56s | 2.18s |
| dump-autoload | 0.70s | 0.05s |

The difference is that Composer spawns PHP for each command, re-derives the dependency graph from scratch, and queries GitHub's limited unauthenticated API. ppkg uses native OS tools (git, curl) and raises no GitHub limits.

## What ppkg is NOT

### No Composer plugins

Composer plugins are PHP classes with access to Composer's internal object graph. ppkg cannot run them — it would need to depend on `composer/composer` to give them API access, and no shim can replicate the full surface.

However, **use `hkm ppkg compat` before switching a project over.** It inspects your `composer.json`, names any plugins you have registered, and tells you exactly what each one would have done during the last `composer install` / `composer update`. Read the output and believe it: if a plugin modified your autoload, generated a bootstrap, or altered packages, you need to understand what and potentially do it manually.

### Incomplete settings (partial support)

Two `composer config` settings are **recorded but not enforced**:

| Setting | ppkg behavior |
|---|---|
| `config.process-timeout` | Recorded; limits network fetches (not script execution or git clone) |
| `config.policy` | Recorded in the manifest; never enforced (policies are applied by Composer at install-time, and ppkg has no equivalent) |

All other config keys — `github-oauth`, `gitlab-token`, `bitbucket-account`, `http-basic`, `vendor-dir`, `bin-dir`, etc. — are fully honored.

## Verification

ppkg's output is tested against real Composer output, not documentation. Every claim below is checked mechanically:

- **Autoload is byte-identical**: all five generated files match `composer dump-autoload` exactly on 107 packages
- **Dependency resolution agrees**: 5881 constraint/version pairs tested against Composer's own `Semver` evaluator
- **composer.lock is byte-identical**: 76 real locks re-render unchanged; Composer installs from ppkg's lock without reporting it out of date
- **composer.json edits are minimal**: 1920 operations tested; each edit touches only the lines necessary (so a human can review it)
- **Advisories (audit)** match Composer's against CVE databases
- **Platform compatibility** matches `composer show --platform` exactly — same 31 libraries, same versions
- **VCS sources** (git, hg, svn) produce byte-identical trees
- **Repository types** (vcs, path, artifact, package) all supported and byte-identical
- **Binary installers** generate the same shell proxies and Windows launchers
- **extra.installer-paths** (composer/installers plugin substitute) places packages identically

The vendor tree is guaranteed byte-identical except for metadata that varies by tool (timestamps in zip entries, Git `.git` vs SVN `.svn` source markers). Composer installs from a tree ppkg built without reporting it out of date.

## Installation

**You do not need to install ppkg separately.** It is embedded in the `hkm` launcher:

```bash
hkm ppkg --version
```

If you want a standalone binary (to use outside an `hkm` project):

```bash
# Linux, macOS, FreeBSD
curl -fsSL https://raw.githubusercontent.com/AlfaCode-Team/hkm-ppkg/main/install.sh | sh

# Windows PowerShell
irm https://raw.githubusercontent.com/AlfaCode-Team/hkm-ppkg/main/install.ps1 | iex
```

Or build from source:

```bash
cd modules/hkm-ppkg
zig build -Doptimize=ReleaseSafe
# Binary at zig-out/bin/ppkg
```

## Usage

All commands match Composer:

```bash
# Install dependencies
ppkg install

# Update dependencies
ppkg update

# Add a package
ppkg require symfony/console:^5.0

# Remove a package
ppkg remove symfony/console

# Generate/refresh autoload
ppkg dump-autoload

# Check for CVE advisories
ppkg audit

# Search Packagist
ppkg search slim/slim

# Show installed packages
ppkg show

# Interactive project setup
ppkg init
```

Global flags work everywhere:

```bash
ppkg install -q                    # Quiet output
ppkg update -n                      # No interaction
ppkg require vendor/pkg -d dev      # Dev dependency only
ppkg install --ansi                 # Force ANSI colors
ppkg install --no-cache             # Bypass network cache
```

## Configuration layers

ppkg reads configuration in this order (last wins):

1. `composer` defaults (built-in)
2. `$COMPOSER_HOME/config.json` (usually `~/.composer/config.json`)
3. `./composer.json` (`config` section)
4. `COMPOSER_*` environment variables (e.g. `COMPOSER_AUTH`, `COMPOSER_VENDOR_DIR`)
5. Command-line flags (e.g. `-d`, `--vendor-dir`, `--no-cache`)

Same precedence as Composer itself.

## Repository types

ppkg supports every Composer repository type:

| Type | ppkg support | Notes |
|---|---|---|
| `vcs` | ✓ | Git, Mercurial, Subversion (self-hosted or GitHub) |
| `path` | ✓ | Local directory packages |
| `composer` | ✓ | Composer repository servers (Packagist, Private Packagist) |
| `artifact` | ✓ | Zip/tarball directory |
| `package` | ✓ | Inline package definitions |

## Auth and credentials

ppkg honors every `auth.json` credential scheme:

- `github-oauth`: GitHub personal access tokens
- `gitlab-token`: GitLab tokens
- `bitbucket-account`: Bitbucket credentials
- `http-basic`: HTTP Basic Auth for private registries
- `bearer`: Bearer tokens
- `custom-headers`: Arbitrary HTTP headers
- Client certificates (mTLS)

Credentials are read from:

1. `$COMPOSER_AUTH` env var (JSON)
2. `auth.json` in `$COMPOSER_HOME`
3. Fields in `composer.json`'s `config` section

## Checking compatibility

Before switching a project to ppkg, verify that no Composer plugins would be broken:

```bash
hkm ppkg compat
```

This reads your `composer.json`, lists any plugins, and tells you exactly what each one did in the last `composer install` / `composer update`. If a plugin modified autoload, generated files, or altered packages, you need to implement that logic manually.

## Limitations and trade-offs

| Aspect | ppkg | Composer |
|---|---|---|
| Speed (VCS resolution) | 1.67s (34 repos) | 375.6s |
| Speed (cached install) | 2.18s | 6.56s |
| Dependency | PHP interpreter | None (compiled binary) |
| Plugin support | None | Full |
| Config policy enforcement | None (recorded only) | Full |
| Platform check | Yes | Yes |
| CVE advisory | Yes | Yes |

The speed trade-off is worth it for deployment pipelines and package installs. Development workflows where plugins add features (code generation, tests) require either the plugin to be ported to ppkg or that step to run separately.

## Source

- [modules/hkm-ppkg/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/modules/hkm-ppkg)
- [modules/hkm-ppkg/README.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/hkm-ppkg/README.md)
