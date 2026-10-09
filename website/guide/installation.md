# Installation

Get the HKM Kernel running on your machine and verify that all dependencies are available. This guide covers OS-specific installers, requirements, and upgrade paths.

## System requirements

HKM requires **PHP 8.4.1 or later** plus several mandatory extensions and at least one PDO database driver.

### Required

- **PHP** ≥ 8.4.1
- **Extensions:** `json`, `mbstring`, `ctype`, `tokenizer`, `filter`, `pdo`, `openssl`, `curl`, `fileinfo`
- **One PDO driver:** `mysql` · `pgsql` · `sqlite` · `sqlsrv`

### Optional (for added features)

- `redis` — in-memory cache and queue backend
- `swoole` or `openswoole` — long-lived HTTP/worker server
- `gd` — image manipulation
- `intl` — internationalization

Run `hkm doctor` after installation to verify your environment.

## Install the native launcher

HKM ships as a **native cross-platform CLI** (`hkm`) built with Zig. There is no Composer bootstrapping; you install and upgrade it like a Go or Rust binary.

### macOS (Homebrew) — fastest

```bash
brew tap alfacode-team/hkm https://github.com/AlfaCode-Team/hkm-kernel
brew trust alfacode-team/hkm
brew install hkm
```

The `brew trust` step is required. Homebrew 6 refuses to load a formula from a third-party tap until you trust it, and it refuses at `brew install` time, not at `brew tap`.

### Linux (Debian / Ubuntu / Kali)

Download the latest `.deb` package from [Releases](https://github.com/AlfaCode-Team/hkm-kernel/releases):

```bash
sudo apt install ./hkm-kernel_<version>_amd64.deb
hkm doctor        # verify PHP + extensions
```

### macOS (installer script) — no root

The installer runs without `sudo` and puts `hkm` and `hkm-config` on your `PATH`:

```bash
curl -fsSL https://github.com/AlfaCode-Team/hkm-kernel/releases/latest/download/install-macos.sh | sh
```

This method adds the tools to `~/Applications` (your home directory), not `/Applications`. Re-running the script upgrades an existing install in place.

For a machine-wide install to `/Applications`, pass `--system`:

```bash
curl -fsSL https://github.com/AlfaCode-Team/hkm-kernel/releases/latest/download/install-macos.sh | sh -s -- --system
```

### macOS (manual)

Extract `hkm-kernel-<version>-macos-universal.tar.gz` to any directory:

```bash
tar xzf hkm-kernel-<version>-macos-universal.tar.gz
cd hkm-kernel
./HKM.app/Contents/Resources/opt/hkm-kernel/install.sh
# Then add the bin/ directory to your PATH
```

The launcher self-locates its kernel, so the bundle works from any directory.

### Windows

Extract `hkm-kernel-<version>-windows-x86_64.zip`, run `hkm-kernel\install.bat`, and add the folder to `PATH`:

```powershell
Expand-Archive hkm-kernel-1.17.1-windows-x86_64.zip -DestinationPath hkm-kernel
cd hkm-kernel
.\install.bat
```

## Verify the installation

Run `hkm doctor` to check that PHP, extensions, and the kernel path are all correctly set up:

```bash
hkm doctor
```

This will:
- Verify PHP version and location
- Check all required extensions
- Confirm at least one PDO driver is available
- Locate the kernel installation
- Report on optional extensions

If any check fails, the output tells you exactly what is missing.

## Install layout

The launcher and kernel are two separate things:

```
~/.local/share/hkm/                    (or /opt/hkm-kernel on Linux)
  ├── bin/
  │   ├── hkm                          the launcher (Zig-built binary)
  │   └── hkm-config
  └── lib/hkm-kernel/
      ├── vendor/                      PHP dependencies (matched to your PHP)
      ├── src/                         kernel source code
      ├── modules/                     first-party packages
      ├── templates/                   scaffolding templates
      ├── composer.json
      └── ...
```

The launcher **self-locates** the kernel by looking for `../lib/hkm-kernel` relative to its own executable. This means:

- No environment variables required on a standard install
- Moving the bin/ and lib/ folders together keeps it working
- Homebrew and the installer script handle the layout automatically

If you install to a non-standard location, set `HKM_KERNEL_HOME` to the kernel root for `hkm` to find it.

## Global kernel mode (development)

If you are working on the kernel itself, you can run `hkm` against your checkout:

```bash
git clone --recurse-submodules git@github.com:AlfaCode-Team/hkm-kernel.git
cd hkm-kernel
composer install
hkm --dev new ~/projects/shop    # use the dev kernel
hkm --dev run shop               # run a project against dev
```

The `--dev` flag tells `hkm` to use the development kernel checkout instead of the installed one. Set `HKM_DEV_HOME` to point to your checkout, or run `hkm-config set-dev-home <path>` once to persist it.

## Installing via Composer (library usage)

If you are using the kernel as a **PHP library** in an existing Composer project (not the common case), require it as a dependency:

```bash
composer require alfacode-team/php-service-platform
```

This gives you the kernel classes but not the `hkm` CLI. You will need to scaffold your project manually or use the CLI from an installed kernel.

## Upgrading

The launcher supports self-upgrades. Check for a new version:

```bash
hkm upgrade --check
```

If an upgrade is available, run:

```bash
hkm upgrade
```

This downloads and installs the latest release in place, replacing the current installation. Your projects and settings are untouched; they are stored separately in `~/.config/hkm/`.

On Homebrew:

```bash
brew upgrade hkm
```

On Linux, download the latest `.deb` and reinstall.

## After installation

1. **Verify the environment:**
   ```bash
   hkm doctor
   ```

2. **Create your first project:**
   ```bash
   hkm new ~/apps/shop --project=shop
   ```

3. **Run it locally:**
   ```bash
   hkm run shop
   ```

For detailed walkthrough, see [Quick Start](/guide/quick-start).

## Troubleshooting

**`hkm: command not found`**
- The kernel is installed but not on your `PATH`. Add it: `export PATH=$PATH:/path/to/hkm/bin`
- Or re-run the installer to add it to your shell profile.

**`hkm doctor` reports missing extensions**
- Install the missing PHP extension. On macOS with Homebrew: `brew install php@8.4 && brew install php-<extension>`
- Verify PHP is the right version: `php -v`

**`COMPOSER_HOME` or `APP_KEY` errors during `hkm install`**
- The bootstrapping process sets these. If you cloned a project from git and hit an error, run:
  ```bash
  hkm install .
  ```

**`Permission denied` on `~/.config/hkm/`**
- The directory was created by a previous install as a different user. Fix permissions:
  ```bash
  chmod u+rwx ~/.config/hkm
  ```

## Source

- [README.md — Install section](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/README.md#install)
- [tools/docs/hkm-cli-usage.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/tools/docs/hkm-cli-usage.md) — Complete CLI reference
- [HomebrewFormula/hkm.rb](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/HomebrewFormula/hkm.rb) — Homebrew formula
