# Views and Languages

Views and language catalogues are resolved through an identical deterministic priority model. The project always overrides plugins by default (priority 0 vs 100), and both can be namespaced to avoid collisions. Overrides use merging (for languages) or directory precedence (for views) so a project can customize one string or template without copying the entire file.

## Views: Declaration and Resolution

The `CompileViewManifestStage` compiles every module's `views` declaration into a cascade of directories. Templates are resolved from this cascade by name, with the project searched first.

### View Sources

Declare view directories in `module.json` `views`:

```json
{
  "views": "resources/views"
}
```

Or with full configuration:

```json
{
  "views": [
    {
      "path": "resources/views",
      "namespace": "invoice",
      "priority": 100,
      "global": true
    },
    {
      "path": "resources/admin-views",
      "namespace": "admin",
      "priority": 100,
      "global": false
    }
  ]
}
```

**View source keys:**

| Key | Type | Default | Example | Notes |
|---|---|---|---|---|
| `path` | string | Required | `"resources/views"` | Relative to module dir or absolute |
| `namespace` | string | `module.name` | `"invoice"` | Used in namespaced renders: `render('invoice::view')` |
| `priority` | int | 100 (plugins) / 0 (project) | 50 | Lower number = searched first; project defaults to 0 |
| `global` | bool | true | false | Include in plain `render('view')` cascade (without namespace) |

### Priority Model

The cascade is **fully deterministic and ordered**:

- **Project sources** default to priority **0** → always highest precedence.
- **Plugin sources** default to priority **100** → searched after the project.

Lower priority numbers are searched first. Tie-breaking: within the same priority, sources are searched in declaration order.

```
Priority 0   (project views)
Priority 50  (plugin views, explicitly set)
Priority 100 (plugin views, default)
```

A project can explicitly set `"priority": -1` on a plugin view to make it lower (earlier) than the project, but that is rare — it means the plugin's version of the view overrides the project's.

### Global vs Namespaced Resolution

**Plain render** (no namespace):

```php
render('invoice-list')   // Searches global cascade in priority order
```

Searches only directories with `"global": true` (the default). Resolution stops at the first match.

**Namespaced render:**

```php
render('invoice::invoice-list')   // Search 'invoice' namespace
```

1. Check the project's `{global-path}/invoice/` folders (every path in the global cascade that is a project source).
2. Check the `invoice` namespace directories (sources with `"namespace": "invoice"`).
3. Stop at the first match.

This two-step process lets a project override one plugin view without affecting others. A project can create `resources/views/invoice/my-view.php` and that path will be found before the plugin's namespaced version.

### Project Defaults

When `proj.json` declares no `views`:

```
resources/views/        (project default cascade entry)
```

So every project has at least one view directory unless it explicitly overrides this with an empty array.

### Example: Multi-Plugin View Cascade

```
Priority 0 (project)
  → resources/views/              (global)

Priority 100 (plugins)
  → plugins/Invoice/resources/views/          (global, namespace: "invoice")
  → plugins/Auth/resources/views/             (global, namespace: "auth")
  → plugins/Auth/resources/admin-views/       (NOT global, namespace: "admin")
```

- `render('welcome')` → checks `resources/views/`, then plugin directories in order.
- `render('invoice::list')` → checks `resources/views/invoice/`, then `plugins/Invoice/resources/views/`.
- `render('admin::dashboard')` → checks `resources/views/admin/` (if it exists), then `plugins/Auth/resources/admin-views/`.

## Languages: Declaration, Resolution, and Merging

The `CompileLangManifestStage` compiles language catalogues the same way as views — but with an important difference: **translations merge instead of shadow**. Overriding one string key does not require copying the entire file.

### Language Sources

Declare language directories in `module.json` `lang`:

```json
{
  "lang": "resources/lang"
}
```

Or with full configuration:

```json
{
  "lang": {
    "path": "resources/lang",
    "namespace": "invoice",
    "priority": 100,
    "global": true
  }
}
```

**Language source keys:** Identical to view sources (same structure, same priorities).

### Default Project Language Path

When `proj.json` declares no `lang`:

```
resources/lang/        (project default)
```

### Global vs Namespaced Resolution

Plain keys resolve down the global cascade:

```php
trans('validation.required')  // Searches global cascade
__('validation.required')     // Same thing
```

Namespaced keys target one source while allowing project overrides:

```php
trans('invoice::messages.created')  // Search 'invoice' namespace
```

### Merging Strategy

When a language file is found, **every catalogue in the cascade is merged** — not replaced wholesale. This is the critical difference from views:

**Project catalogue** (`resources/lang/en/messages.php`):

```php
return [
    'welcome' => 'Welcome!',
    'goodbye' => 'Goodbye!',
];
```

**Plugin catalogue** (`plugins/Invoice/resources/lang/en/messages.php`):

```php
return [
    'welcome' => 'Welcome to Invoicing',
    'created' => 'Invoice created',
    'paid' => 'Invoice marked paid',
];
```

**Result (merged, project first):**

```php
[
    'welcome' => 'Welcome!',           // project wins (project has this key)
    'goodbye' => 'Goodbye!',           // project only
    'created' => 'Invoice created',    // plugin only
    'paid' => 'Invoice marked paid',   // plugin only
]
```

The project's version of `'welcome'` overrides the plugin's, but the plugin's `'created'` and `'paid'` are preserved. You can reword one message without maintaining a copy of the entire catalogue.

### Language Manifest Output

`CompileLangManifestStage` outputs `lang-manifest.php`:

```php
[
    'global' => [
        '/abs/project/resources/lang',
        '/abs/plugin/Invoice/resources/lang',
        '/abs/plugin/Auth/resources/lang',
    ],
    'namespaces' => [
        'invoice' => [
            '/abs/project/resources/lang/invoice',   // project first
            '/abs/plugin/Invoice/resources/lang',
        ],
        'auth' => [
            '/abs/plugin/Auth/resources/lang',
        ],
    ],
]
```

The renderer (in a plugin) walks this cascade to resolve and merge group files.

## Overriding Plugin Views and Languages

### Override a Single View

Create the view in your project with the same name and namespace:

**Plugin view:** `plugins/Invoice/resources/views/invoice-list.php`

**Override:** `resources/views/invoice/invoice-list.php`

Render `render('invoice::invoice-list')` now finds the project version first.

### Override a Single Language String

Create or edit the language group file in your project:

**Plugin catalogue:** `plugins/Invoice/resources/lang/en/messages.php`

```php
return ['created' => 'Invoice successfully created'];
```

**Project override:** `resources/lang/en/messages.php`

```php
return ['created' => 'Invoice generated successfully'];  // Reword one key
```

Render `trans('messages.created')` now returns the project's version. The plugin's other strings (`'updated'`, `'deleted'`, etc.) are preserved.

### Opt-In Plugin Override (Rare)

A plugin can explicitly preempt the project by setting a lower (negative) priority:

```json
{
  "views": {
    "path": "resources/premium-views",
    "namespace": "premium",
    "priority": -1
  }
}
```

This is the only way a plugin overrides the project — and it requires an explicit, opt-in declaration. Plugins never win by default.

## Common Mistakes

### Mistake: Copying the entire plugin view file to override one line

**Wrong:**

```
Project copies plugins/Invoice/resources/views/invoice-list.php
into resources/views/invoice/invoice-list.php
and changes one line.
```

You now maintain a copy that drifts from the plugin's. When the plugin updates the view, your copy is stale.

**Correct:**

If you need to override just the view name or rendering, use namespaced render to specify which one:

```php
render('invoice::invoice-list')  // This finds your override first
```

And create only the lines you changed:

```
resources/views/invoice/invoice-list.php
(your override)
```

### Mistake: Copying the entire language file to change one string

**Wrong:**

```
Project copies plugins/Invoice/resources/lang/en/messages.php
into resources/lang/en/messages.php
and changes one string.
```

You now maintain a copy. When the plugin adds a new message, your copy lacks it.

**Correct:**

Create the language file with ONLY the keys you want to override:

```php
// resources/lang/en/messages.php
return [
    'created' => 'Invoice generated successfully',  // Override only this
];
```

The renderer merges it with the plugin's file, preserving the plugin's other keys.

### Mistake: Relying on file modification time to detect overrides

**Wrong:**

```php
if (filemtime($project_view) > filemtime($plugin_view)) {
    // Assume project is newer
}
```

The priority system is deterministic and order-based, not time-based. Use the priority numbers.

### Mistake: Assuming a missing namespace defaults to "global"

**Wrong:**

```json
{
  "lang": {
    "path": "resources/lang"
    // No "namespace" key
  }
}
```

If this is in a plugin (not the project), the namespace defaults to `module.name` ("invoice"). It does NOT become a global source unless `module.name` is empty (which it never is). Omit `namespace` for project sources only, or explicitly set it.

### Mistake: Expecting namespace resolution to fall back to global

**Wrong:**

```php
render('invoice::not-found')  // View doesn't exist in 'invoice' namespace
                              // Does NOT fall back to global cascade
```

Namespaced renders search ONLY the namespace and the project's override folders. They do not fall back to the global cascade. If the view is not there, it is not found.

## Troubleshooting

### A plugin view is not being found

1. Check the plugin's `views` declaration in `module.json`.
2. Verify the actual directory and file exist on disk.
3. Check the priority — if the project has a lower priority (higher number), the plugin's view will never be reached.
4. Use `php artisan view:list` or equivalent tooling to inspect the compiled manifest.

### A language key is missing

1. Check both the project and plugin language catalogues.
2. Verify the key name is spelled correctly (keys are case-sensitive).
3. Check the priority — project languages with lower priority win; plugin languages override the project only if they set `"priority": -1` explicitly.
4. Use `php artisan lang:list` or a direct manifest read to inspect the cascade.

### A project override is not being used

1. Verify the view or language file exists in the project at the correct path.
2. Confirm the project source is in the global cascade (priority 0 by default).
3. If namespaced, check that the path structure matches the namespace — `render('invoice::view')` looks for `resources/views/invoice/view.php`.

## Compiled Manifests

After boot, the kernel writes two files:

**`var/cache/manifests/view-manifest.php`:**

```php
[
    'global' => [
        '/path/to/project/resources/views',
        '/path/to/plugins/Invoice/resources/views',
    ],
    'namespaces' => [
        'invoice' => [
            '/path/to/plugins/Invoice/resources/views',
        ],
    ],
]
```

**`var/cache/manifests/lang-manifest.php`:**

Same structure as views. The renderer walks these lists in order, merging language files and stopping at the first view match.

## Source

- [`src/Kernel/Boot/Stages/CompileViewManifestStage.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/CompileViewManifestStage.php)
- [`src/Kernel/Boot/Stages/CompileLangManifestStage.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/CompileLangManifestStage.php)
