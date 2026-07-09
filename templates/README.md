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

| Path            | Role                                                    |
|-----------------|---------------------------------------------------------|
| `app/bootstrap` | Kernel autoload + project bootstrap                     |
| `app/public`    | HTTP entry (`index.php`)                                |
| `app/cli`       | CLI entry (`run.php`)                                   |
| `src/`          | Project-only code (namespace `{{STUDLY}}\`)             |
| `config/`       | Project configuration                                   |
| `database/`     | LetMigrate migrations / seeders / factories             |
| `resources/`    | Views                                                   |
| `proj.json`     | Project manifest (routes, domains, views, routePolicy)  |

## Routes — three verbs over plugin routes

Plugins declare their own routes (in each plugin's `module.json`); this project
stays the final authority via `proj.json`:

- **Add** — declare project routes in `routes[]` (handler = full class path,
  `Controller@method`). Optional per-route `"requires": ["domain", …]` pulls
  specific plugins into that route; `"filters": ["auth", …]` gates it.
- **Override** — a project route with the same `METHOD path` as a plugin route
  replaces it.
- **Disable** — list plugin routes the project will not expose in
  `routePolicy.disable[]`: either `"GET /register"` (one route) or a module
  domain like `"oauth.server"` (all of that plugin's routes). A spec matching
  nothing fails the boot — typos never pass silently.

```jsonc
"routePolicy": { "disable": ["GET /register", "oauth.server"] }
```
