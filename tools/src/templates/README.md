# {{PROJECT_NAME}}

A standalone [PhpServicePlatform](https://github.com/alfacode-team) project,
scaffolded with `hkm new`. Hybrid global-kernel model: the framework kernel is
installed globally; this project owns its plugins + `src/` (namespace `{{STUDLY}}\`).

## Getting started

```bash
composer install
# generate an APP_KEY and put it in .env:
php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
php -S localhost:8000 -t app/public
```

## Layout

| Path            | Role                                             |
|-----------------|--------------------------------------------------|
| `app/bootstrap` | Kernel autoload + project bootstrap              |
| `app/public`    | HTTP entry (`index.php`)                         |
| `app/cli`       | CLI entry (`run.php`)                            |
| `src/`          | Project-only code (namespace `{{STUDLY}}\`)      |
| `config/`       | Project configuration                            |
| `database/`     | LetMigrate migrations / seeders / factories      |
| `resources/`    | Views                                            |
| `proj.json`     | Project manifest (routes, domains, views)        |
