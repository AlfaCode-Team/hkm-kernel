# Packages Overview

The HKM Kernel is built on top of several first-party packages that live in `modules/` and are autoloaded as Composer path repositories. These packages are framework-independent, battle-tested components that the kernel depends on.

## Package registry

| Package | Composer name | Namespace | Purpose |
|---|---|---|---|
| **bind-it** | `phpshots/bind-it` | `PHPShots\Common` | Reflection-based dependency injection container with contextual bindings and PSR-11 compliance. Foundation for `CoreContainer` and `ModuleContainer`. |
| **common-type-alias** | `phpshots/common-type-alias` | `PHPShots\Common\TypeAlias` | Type alias management system. Dependency of bind-it. |
| **http** | `alfacode-team/http` | `AlfacodeTeam\PhpServicePlatform\Kernel\Http` | Immutable HTTP Request/Response/UploadedFile value objects. Currently built on Symfony HTTPFoundation (transitional). |
| **php-io-cli** | `alfacode-team/php-io-cli` | `AlfacodeTeam\PhpIoCli` | Standalone CLI application runtime with reactive terminal components and structured command execution. Powers `CliPipeline`. |
| **let-migrate** | `alfacode-team/let-migrate` | `AlfaCode\LetMigrate` | Database migration engine supporting MySQL, PostgreSQL, SQLite, SQL Server. Per-driver folders, seeders, factories, and full transaction support. |
| **hkm-ppkg** | N/A (internal tool) | N/A | Zig-based Composer-compatible package manager embedded in the `hkm` launcher. Not a PHP package; not autoloaded. |
| **ground** | `alfacode-team/hkm-plugin-ground` | `AlfacodeTeam\Plugin\Ground` | Plugin testing harness. Boots a plugin on an isolated kernel without a project, database, or configuration. Dev/test only. |

## Dependency graph

```
CliPipeline
  ↓
php-io-cli
  ↓
bind-it ← common-type-alias

Kernel modules (CoreContainer, ModuleContainer, HttpPipeline)
  ↓
bind-it ← common-type-alias

Built-in migration commands
  ↓
let-migrate

HTTP layer
  ↓
http (Symfony HTTPFoundation—transitional)
```

## Composer path repositories

Each package above is registered in the root `composer.json` as a path repository, so Composer treats them as if they were published on Packagist:

```json
{
  "repositories": [
    {"type": "path", "url": "modules/bind-it"},
    {"type": "path", "url": "modules/common-type-alias"},
    {"type": "path", "url": "modules/http"},
    {"type": "path", "url": "modules/php-io-cli"},
    {"type": "path", "url": "modules/let-migrate"},
    {"type": "path", "url": "modules/ground"}
  ]
}
```

This lets the kernel depend on them locally during development while keeping each as a standalone package that could be published or used independently.

::: info hkm-ppkg is not a PHP package
The `hkm-ppkg` module is written in Zig and is consumed by the native `hkm` launcher as a build tool, not as a PHP dependency. It is not autoloaded and nothing in `src/` may reference it. Read `tools/` documentation for details.
:::

## Source reference

Each package documents itself in its own repository:
- **bind-it**: `modules/bind-it/README.md` + `modules/bind-it/src/`
- **php-io-cli**: `modules/php-io-cli/src/` (see `/packages/php-io-cli` for component API)
- **let-migrate**: `modules/let-migrate/README.md` + `modules/let-migrate/src/` (see `/packages/let-migrate` for full guide)
- **http**: `modules/http/src/` (see `/http/request` and `/http/response` for API)
- **ground**: `modules/ground/README.md`

## Source

- [root composer.json](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/composer.json)
- [modules/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/modules)
